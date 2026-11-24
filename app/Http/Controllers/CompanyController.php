<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Company;
use App\Support\Iban;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * „Фирма“ (PROJECT.md, „PDF и имейл на известията“, 24.11.2026): данните на
 * фирмата и банковите ѝ сметки. Само за администратора на фирмата (иначе
 * 403); моделите пазят същото правило. Сметка на друга фирма е 404.
 */
class CompanyController extends Controller
{
    private function company(Request $request): Company
    {
        abort_unless($request->user()->is_company_admin, 403);

        return Company::query()->findOrFail($request->user()->company_id);
    }

    public function show(Request $request): View
    {
        $company = $this->company($request);

        return view('company.show', [
            'company' => $company,
            'accounts' => $company->bankAccounts()->orderBy('archived_at')->orderByDesc('for_statements')->orderBy('id')->get(),
            'statementAccount' => $company->statementAccount(),
        ]);
    }

    public function edit(Request $request): View
    {
        return view('company.edit', ['company' => $this->company($request)]);
    }

    public function update(Request $request): RedirectResponse
    {
        $company = $this->company($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'eik' => ['required', 'regex:/^(\d{9}|\d{13})$/', Rule::unique('companies', 'eik')->ignore($company->id)],
            'vat_number' => ['nullable', 'string', 'max:15'],
            'manager_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ], ['eik.regex' => __('company.eik_invalid')]);
        $company->update($data);

        return redirect('/company')->with('status', __('company.saved'));
    }

    public function createAccount(Request $request): View
    {
        $this->company($request);

        return view('company.bank-account', ['account' => new BankAccount]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $company = $this->company($request);
        $data = $this->accountData($request, null);

        DB::transaction(function () use ($company, $data, $request) {
            $account = BankAccount::query()->create($data);
            if ($request->boolean('for_statements')) {
                $this->makeForStatements($company, $account);
            }
        });

        return redirect('/company')->with('status', __('company.saved'));
    }

    public function editAccount(Request $request, BankAccount $account): View
    {
        $this->company($request);

        return view('company.bank-account', ['account' => $account]);
    }

    public function updateAccount(Request $request, BankAccount $account): RedirectResponse
    {
        $this->company($request);
        $account->update($this->accountData($request, $account));

        return redirect('/company')->with('status', __('company.saved'));
    }

    public function forStatements(Request $request, BankAccount $account): RedirectResponse
    {
        $company = $this->company($request);
        abort_if($account->isArchived(), 404);
        DB::transaction(fn () => $this->makeForStatements($company, $account));

        return redirect('/company')->with('status', __('company.saved'));
    }

    public function archiveAccount(Request $request, BankAccount $account): RedirectResponse
    {
        $this->company($request);
        // Архивираната сметка не е „за известията“.
        $account->forceFill(['archived_at' => now(), 'for_statements' => false])->save();

        return redirect('/company')->with('status', __('company.saved'));
    }

    public function restoreAccount(Request $request, BankAccount $account): RedirectResponse
    {
        $this->company($request);
        $account->restoreFromArchive();

        return redirect('/company')->with('status', __('company.saved'));
    }

    /** Само една сметка е „за известията“. */
    private function makeForStatements(Company $company, BankAccount $account): void
    {
        $company->bankAccounts()->where('for_statements', true)->whereKeyNot($account->id)->get()
            ->each(fn (BankAccount $other) => $other->forceFill(['for_statements' => false])->save());
        $account->forceFill(['for_statements' => true])->save();
    }

    private function accountData(Request $request, ?BankAccount $account): array
    {
        $request->merge(['iban' => Iban::normalize((string) $request->input('iban', '')), 'bic' => strtoupper(trim((string) $request->input('bic', '')))]);
        $data = $request->validate([
            'bank_name' => ['nullable', 'string', 'max:255'],
            'iban' => ['required', 'string', 'max:34', function ($attribute, $value, $fail) {
                if (! Iban::isValid($value)) {
                    $fail(__('company.bank.iban_invalid'));
                }
            }, Rule::unique('bank_accounts', 'iban')->where('company_id', $request->user()->company_id)->ignore($account?->id)],
            'bic' => ['nullable', 'regex:/^[A-Z0-9]{8}([A-Z0-9]{3})?$/'],
        ], ['iban.unique' => __('company.bank.iban_taken')]);

        return ['bank_name' => $data['bank_name'] ?? null, 'iban' => $data['iban'], 'bic' => ($data['bic'] ?? '') === '' ? null : $data['bic']];
    }
}
