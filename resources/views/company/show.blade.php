@extends('layouts.app')

@section('title', __('company.title'))
@section('width', 'full')

@section('content')
    <div class="row">
        <h1 style="margin: 0;">{{ __('company.title') }}: {{ $company->name }}</h1>
        <a class="button" style="margin-top: 0;" href="/company/edit">{{ __('company.edit') }}</a>
    </div>
    <p class="muted">{{ __('company.intro') }}</p>
    <p><a href="/company/export">{{ __('export.settings.link') }}</a></p>

    @php($f = 'company.fields.')
    <dl>
        <dt>{{ __($f.'name') }}</dt><dd>{{ $company->name }}</dd>
        <dt>{{ __($f.'eik') }}</dt><dd>{{ $company->eik }}</dd>
        <dt>{{ __($f.'vat_number') }}</dt><dd>{{ $company->vat_number ?? __('registry.none') }}</dd>
        <dt>{{ __($f.'manager_name') }}</dt><dd>{{ $company->manager_name ?? __('registry.none') }}</dd>
        <dt>{{ __($f.'address') }}</dt><dd>{{ $company->address ?? __('registry.none') }}</dd>
        <dt>{{ __($f.'email') }}</dt><dd>{{ $company->email ?? __('registry.none') }}</dd>
        <dt>{{ __($f.'phone') }}</dt><dd>{{ $company->phone ?? __('registry.none') }}</dd>
    </dl>

    {{-- Лого (25.11.2026): в storage, показва се оттук само на администратора. --}}
    <h2>{{ __('company.logo') }}</h2>
    <div class="panel" style="margin-top: 0;">
        @if ($company->logo_path)
            <img src="/company/logo" alt="{{ __('company.logo') }}" style="max-height: 70px; max-width: 260px; display: block; margin-bottom: 10px;">
        @else
            <p class="muted" style="margin-top: 0;">{{ __('company.logo_none') }}</p>
        @endif
        <form method="POST" action="/company/logo" enctype="multipart/form-data" class="row" style="justify-content: flex-start;">
            @csrf
            <input type="file" name="logo" accept="image/png,image/jpeg" aria-label="{{ __('company.logo') }}" style="max-width: 320px;">
            <button type="submit" class="secondary" style="margin-top: 0;">{{ __('company.logo_upload') }}</button>
        </form>
        @if ($company->logo_path)
            <form method="POST" action="/company/logo/remove" style="margin-top: 8px;">@csrf<button type="submit" class="secondary" style="margin-top: 0;">{{ __('company.logo_remove') }}</button></form>
        @endif
        <p class="muted" style="margin-bottom: 0;">{{ __('company.logo_hint') }}</p>
    </div>

    <div class="row" style="margin-top: 22px;">
        <h2 style="margin: 0;">{{ __('company.bank_accounts') }}</h2>
        <a class="button secondary" style="margin-top: 0;" href="/company/bank-accounts/create">{{ __('company.bank.new') }}</a>
    </div>
    @unless ($statementAccount)
        <div class="warning" style="margin-top: 12px;">{{ __('company.no_statement_account') }}</div>
    @endunless
    @if ($accounts->isEmpty())
        <p class="muted">{{ __('company.no_accounts') }}</p>
    @else
        @php($b = 'company.bank.')
        <div class="table-wrap" style="margin-top: 12px;">
            <table>
                <thead>
                <tr><th>{{ __($b.'bank_name') }}</th><th>{{ __($b.'iban') }}</th><th>{{ __($b.'bic') }}</th><th>{{ __($b.'for_statements') }}</th><th></th></tr>
                </thead>
                <tbody>
                @foreach ($accounts as $account)
                    <tr @class(['zero' => $account->isArchived()])>
                        <td>{{ $account->bank_name ?? __('registry.none') }}</td>
                        <td class="nowrap">{{ $account->formattedIban() }}</td>
                        <td>{{ $account->bic ?? __('registry.none') }}</td>
                        <td>
                            @if ($account->for_statements)
                                <span class="badge state-active">{{ __('company.for_statements') }}</span>
                            @elseif ($account->isArchived())
                                <span class="badge state-archived">{{ __('contracts.screens.states.archived') }}</span>
                            @endif
                        </td>
                        <td>
                            <div class="actions">
                                <a href="/company/bank-accounts/{{ $account->id }}/edit">{{ __('registry.edit') }}</a>
                                @if ($account->isArchived())
                                    <form method="POST" action="/company/bank-accounts/{{ $account->id }}/restore">@csrf<button type="submit" class="secondary" style="margin-top: 0;">{{ __('registry.restore') }}</button></form>
                                @else
                                    @unless ($account->for_statements)
                                        <form method="POST" action="/company/bank-accounts/{{ $account->id }}/for-statements">@csrf<button type="submit" class="secondary" style="margin-top: 0;">{{ __('company.bank.make_for_statements') }}</button></form>
                                    @endunless
                                    <form method="POST" action="/company/bank-accounts/{{ $account->id }}/archive" onsubmit="return confirm(@js(__('company.bank.archive_confirm')))">@csrf<button type="submit" class="secondary" style="margin-top: 0;">{{ __('registry.archive') }}</button></form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
