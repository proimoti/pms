<?php

namespace App\Http\Controllers\Registry;

use App\Models\Property;
use App\Models\Provider;
use App\Models\UtilityAccount;
use App\Services\Utilities\UtilityAccountService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Екранът „Абонати“ (PROJECT.md, т. 3.4 „Битови сметки, уточнения“, 21.11.2026). */
class UtilityAccountController extends RegistryController
{
    /** Празни редове за имоти в новата форма. */
    private const NEW_ROWS = 3;

    public function __construct(private readonly UtilityAccountService $accounts) {}

    protected function section(): string
    {
        return 'utility-accounts';
    }

    /** По номер, по име на доставчика или по номер на имот. */
    protected function applySearch(Builder $query, string $term, string $likeTerm): void
    {
        $query->orWhere('number', 'like', $likeTerm)
            ->orWhereHas('provider', fn (Builder $provider) => $provider->where('name', 'like', $likeTerm));

        if (ctype_digit($term)) {
            $query->orWhereHas('properties.property', fn (Builder $property) => $property->where('number', (int) $term));
        }
    }

    public function index(Request $request): View
    {
        $query = $this->listQuery($request, UtilityAccount::query()->with(['provider', 'properties.property'])->orderBy('number')->orderBy('id'));

        return view('registry.utility-accounts.index', ['accounts' => $this->paginate($query)]);
    }

    public function create(): View
    {
        return $this->form(new UtilityAccount(['period_start_day' => 1]), []);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = $this->accounts->create($this->input($request));

        return redirect("/utility-accounts/{$account->id}")->with('status', __('registry.saved'));
    }

    public function show(UtilityAccount $account): View
    {
        $account->load(['provider', 'properties.property']);

        return view('registry.utility-accounts.show', ['account' => $account]);
    }

    public function edit(UtilityAccount $account): View
    {
        $rows = $account->properties()->get()->map(fn ($line) => ['property_id' => $line->property_id, 'share' => $line->share])->all();

        return $this->form($account, $rows);
    }

    public function update(Request $request, UtilityAccount $account): RedirectResponse
    {
        $this->accounts->update($account, $this->input($request));

        return redirect("/utility-accounts/{$account->id}")->with('status', __('registry.saved'));
    }

    public function archive(UtilityAccount $account): RedirectResponse
    {
        return $this->archiveRecord($account);
    }

    public function restore(UtilityAccount $account): RedirectResponse
    {
        return $this->restoreRecord($account);
    }

    private function form(UtilityAccount $account, array $rows): View
    {
        $rows = old('properties', $rows);
        $rows = array_values(is_array($rows) ? $rows : []);
        $target = max(self::NEW_ROWS, count($rows) + 1); // винаги поне един празен ред
        while (count($rows) < $target) {
            $rows[] = ['property_id' => '', 'share' => ''];
        }

        return view('registry.utility-accounts.form', [
            'account' => $account,
            'rows' => $rows,
            'providers' => Provider::query()->active()->orderBy('name')->get(),
            'properties' => Property::query()->active()->orderBy('number')->get(),
        ]);
    }

    private function input(Request $request): array
    {
        return $request->only([
            'provider_id', 'number', 'kind', 'billing', 'fixed_amount',
            'period_start_day', 'period_end_day', 'valid_from', 'valid_to', 'note',
        ]) + ['properties' => (array) $request->input('properties', [])];
    }
}
