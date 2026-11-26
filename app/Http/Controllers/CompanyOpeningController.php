<?php

namespace App\Http\Controllers;

use App\Models\Fund;
use App\Models\OpeningImport;
use App\Services\Opening\OpeningBalances;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

/**
 * „Начални салда“ в „Фирма“ (PROJECT.md, „Начални салда“, 26.11.2026): само за
 * администратора на фирмата (иначе 403). Прегледът се пази в сесията до
 * потвърждението; сторното иска код от телефона и причина.
 */
class CompanyOpeningController extends Controller
{
    private const SESSION = 'opening.preview';

    private function admin(Request $request): void
    {
        abort_unless($request->user()->is_company_admin, 403);
    }

    private function form(?array $preview = null): View
    {
        $import = OpeningImport::active();

        return view('company.opening', [
            'import' => $import,
            'funds' => $import ? collect() : Fund::query()->orderBy('number')->get(),
            'preview' => $preview,
        ]);
    }

    public function show(Request $request): View
    {
        $this->admin($request);

        return $this->form();
    }

    public function preview(Request $request, OpeningBalances $opening): View
    {
        $this->admin($request);
        $request->validate([
            'tenants' => ['nullable', 'file', 'max:5120'],
            'deposits' => ['nullable', 'file', 'max:5120'],
            'funds' => ['nullable', 'array'],
        ]);
        $request->session()->forget(self::SESSION);

        $preview = $opening->preview($request->input('as_of'), $request->file('tenants'), $request->file('deposits'), (array) $request->input('funds', []));
        $request->session()->put(self::SESSION, $preview);

        return $this->form($preview);
    }

    public function confirm(Request $request, OpeningBalances $opening): RedirectResponse
    {
        $this->admin($request);
        $opening->confirm($request->session()->pull(self::SESSION));

        return redirect('/company/opening')->with('status', __('opening.saved'));
    }

    public function reverse(Request $request, OpeningBalances $opening): RedirectResponse
    {
        $this->admin($request);
        $user = $request->user();
        $code = trim((string) $request->input('code'));
        $valid = $code !== '' && $user->two_factor_secret !== null
            && app(TwoFactorAuthenticationProvider::class)->verify(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), $code);
        if (! $valid) {
            throw ValidationException::withMessages(['code' => __('opening.errors.code')]);
        }
        $opening->reverse($request->input('reason'));

        return redirect('/company/opening')->with('status', __('opening.reversed'));
    }
}
