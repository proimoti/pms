<?php

namespace App\Http\Controllers;

use App\Enums\AccessLevel;
use App\Enums\PropertyScope;
use App\Enums\Role;
use App\Models\Property;
use App\Models\Scopes\RegistryVisibilityScope;
use App\Models\User;
use App\Models\UserProperty;
use App\Support\Invitation;
use App\Support\StaffTemplates;
use App\Support\UserAccess;
use App\Support\UserAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use LogicException;

/**
 * „Служители“ (план „Портал и служители“, 27.11.2026). Само за администратора
 * на фирмата (иначе 403). Служител на друга фирма, наемател или собственик — 404.
 * Администраторът не пипа своя профил оттук (403), затова фирмата винаги
 * остава с поне един администратор. Всяка промяна е в журнала.
 */
class StaffController extends Controller
{
    /** Полетата с правата, които се записват в журнала при промяна. */
    private const RIGHTS = ['name', 'access_level', 'property_scope', 'can_view_sensitive', 'is_company_admin'];

    private function admin(Request $request): User
    {
        abort_unless($request->user()->is_company_admin, 403);

        return $request->user();
    }

    /** Служител на същата фирма (филтърът по фирма е на модела), не самият администратор. */
    private function staff(Request $request, int $id, bool $allowSelf = false): User
    {
        $admin = $this->admin($request);
        $staff = User::query()->where('role', Role::Staff->value)->findOrFail($id);
        abort_if(! $allowSelf && $staff->is($admin), 403, __('staff.not_self'));

        return $staff;
    }

    public function index(Request $request): View
    {
        $this->admin($request);

        return view('staff.index', [
            'staff' => User::query()->where('role', Role::Staff->value)->orderByRaw('disabled_at is not null')->orderBy('name')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->admin($request);

        return view('staff.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $admin = $this->admin($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->where('company_id', $admin->company_id)],
            'template' => ['required', Rule::in(array_keys(StaffTemplates::all()))],
        ]);

        $staff = DB::transaction(function () use ($admin, $data, $request) {
            $staff = new User;
            // Ролята, фирмата и правата се задават тук, не от формата. Паролата е
            // случайна и непозната: служителят си задава своя по поканата.
            $staff->forceFill(StaffTemplates::all()[$data['template']] + [
                'company_id' => $admin->company_id,
                'role' => Role::Staff,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Str::random(64),
                'is_company_admin' => $request->boolean('is_company_admin'),
            ])->save();
            UserAudit::write($staff, $admin, 'created', $this->rightsChanges($staff, fresh: true) + ['email' => ['old' => null, 'new' => $staff->email]]);

            return $staff;
        });

        return $this->sendInvitation($staff, $admin, 'staff.created');
    }

    public function show(Request $request, int $staff): View
    {
        $staff = $this->staff($request, $staff, allowSelf: true);

        // Администраторът вижда всички имоти на фирмата за прикачване, и с обхват „само прикачените“.
        $attached = UserProperty::query()->where('user_id', $staff->id)->pluck('property_id')->all();
        $properties = Property::query()->withoutGlobalScope(RegistryVisibilityScope::class)
            ->where(fn ($q) => $q->whereNull('archived_at')->orWhereIn('id', $attached))
            ->orderBy('number')->get();

        return view('staff.show', [
            'staff' => $staff,
            'self' => $staff->is($request->user()),
            'template' => StaffTemplates::of($staff),
            'properties' => $properties,
            'attached' => $attached,
        ]);
    }

    public function update(Request $request, int $staff): RedirectResponse
    {
        $staff = $this->staff($request, $staff);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'template' => ['nullable', Rule::in(array_keys(StaffTemplates::all()))],
            'access_level' => ['required_without:template', 'nullable', Rule::enum(AccessLevel::class)],
            'property_scope' => ['required_without:template', 'nullable', Rule::enum(PropertyScope::class)],
        ]);

        $rights = ! empty($data['template']) ? StaffTemplates::all()[$data['template']] : [
            'access_level' => AccessLevel::from($data['access_level']),
            'property_scope' => PropertyScope::from($data['property_scope']),
            'can_view_sensitive' => $request->boolean('can_view_sensitive'),
        ];

        DB::transaction(function () use ($staff, $rights, $data, $request) {
            $staff->forceFill($rights + ['name' => $data['name'], 'is_company_admin' => $request->boolean('is_company_admin')]);
            $changes = $this->rightsChanges($staff);
            $staff->save();
            if ($changes !== []) {
                UserAudit::write($staff, $request->user(), 'updated', $changes);
            }
        });

        return redirect("/staff/{$staff->id}")->with('status', __('staff.saved'));
    }

    public function properties(Request $request, int $staff): RedirectResponse
    {
        $staff = $this->staff($request, $staff);
        $data = $request->validate([
            'property_ids' => ['nullable', 'array'], // без отметки формата не праща нищо = няма прикачени
            'property_ids.*' => ['integer', Rule::exists('properties', 'id')->where('company_id', $staff->company_id)],
        ]);
        $wanted = array_values(array_unique(array_map('intval', $data['property_ids'] ?? [])));

        DB::transaction(function () use ($staff, $wanted) {
            // Поотделно, за да влезе всяко прикачване и махане в журнала.
            $current = UserProperty::query()->where('user_id', $staff->id)->get();
            foreach ($current as $row) {
                if (! in_array((int) $row->property_id, $wanted, true)) {
                    $row->delete();
                }
            }
            foreach (array_diff($wanted, $current->pluck('property_id')->map(fn ($id) => (int) $id)->all()) as $propertyId) {
                UserProperty::query()->create(['user_id' => $staff->id, 'property_id' => $propertyId]);
            }
        });

        return redirect("/staff/{$staff->id}")->with('status', __('staff.properties_saved'));
    }

    public function stop(Request $request, int $staff): RedirectResponse
    {
        $staff = $this->staff($request, $staff);
        UserAccess::stop($staff, $request->user());

        return redirect("/staff/{$staff->id}")->with('status', __('staff.stopped'));
    }

    public function restore(Request $request, int $staff): RedirectResponse
    {
        $staff = $this->staff($request, $staff);
        UserAccess::restore($staff, $request->user());

        return redirect("/staff/{$staff->id}")->with('status', __('staff.restored'));
    }

    public function invite(Request $request, int $staff): RedirectResponse
    {
        $staff = $this->staff($request, $staff);

        return $this->sendInvitation($staff, $request->user(), 'staff.invited');
    }

    /** Нулиране на втория фактор с кода от телефона на администратора (не на служителя). */
    public function resetTwoFactor(Request $request, int $staff, TwoFactorAuthenticationProvider $provider, DisableTwoFactorAuthentication $disable): RedirectResponse
    {
        $staff = $this->staff($request, $staff);
        $admin = $request->user();
        $request->validate(['code' => ['required', 'string']]);

        $key = 'staff-reset-two-factor:'.$admin->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)])]);
        }
        $secret = $admin->two_factor_secret ? Fortify::currentEncrypter()->decrypt($admin->two_factor_secret) : null;
        if ($secret === null || ! $provider->verify($secret, (string) $request->input('code'))) {
            RateLimiter::hit($key);
            throw ValidationException::withMessages(['code' => __('staff.two_factor.wrong_code')]);
        }
        RateLimiter::clear($key);
        if ($staff->two_factor_secret === null) {
            throw ValidationException::withMessages(['code' => __('staff.two_factor.none')]);
        }

        DB::transaction(function () use ($staff, $admin, $disable) {
            $disable($staff);
            UserAudit::write($staff, $admin, 'two_factor_reset', ['two_factor' => ['old' => 'on', 'new' => 'off']]);
        });

        return redirect("/staff/{$staff->id}")->with('status', __('staff.two_factor.done'));
    }

    private function sendInvitation(User $staff, User $admin, string $message): RedirectResponse
    {
        try {
            $log = Invitation::send($staff, $admin);
        } catch (LogicException $e) {
            return redirect("/staff/{$staff->id}")->withErrors(['invite' => $e->getMessage()]);
        }

        $redirect = redirect("/staff/{$staff->id}");

        return $log->status === \App\Models\EmailLog::FAILED
            ? $redirect->with('warning', __('staff.invite_failed'))
            : $redirect->with('status', __($message, ['email' => $staff->email]));
    }

    /** Променените права като {поле: {old, new}}; при нов профил — всички със старо празно. */
    private function rightsChanges(User $staff, bool $fresh = false): array
    {
        $changes = [];
        foreach (self::RIGHTS as $field) {
            $old = $fresh ? null : $staff->getRawOriginal($field);
            $new = $staff->getAttributes()[$field] ?? null;
            $new = $new instanceof \BackedEnum ? $new->value : $new;
            if ($fresh || self::differs($old, $new)) {
                $changes[$field] = ['old' => $old, 'new' => $new];
            }
        }

        return $changes;
    }

    private static function differs(mixed $old, mixed $new): bool
    {
        if (is_bool($old) || is_bool($new)) {
            return (bool) $old !== (bool) $new;
        }

        return (string) $old !== (string) $new;
    }
}
