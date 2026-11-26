{{-- Ред в таблицата „Много плащания наведнъж“; грешките са до полето. --}}
@php($e = fn ($field) => is_int($i) ? $errors->first("rows.$i.$field") : '')
<tr>
    <td>
        <input type="text" name="rows[{{ $i }}][contract]" value="{{ $row['contract'] ?? '' }}" list="leases" aria-label="{{ __('payments.batch.contract') }}" style="min-width: 220px;">
        @if ($e('contract'))<div class="errors" style="margin: 4px 0 0; padding: 4px 8px;">{{ $e('contract') }}</div>@endif
    </td>
    <td>
        <input type="text" inputmode="decimal" name="rows[{{ $i }}][amount]" value="{{ $row['amount'] ?? '' }}" aria-label="{{ __('payments.fields.amount') }}" style="max-width: 120px;">
        @if ($e('amount'))<div class="errors" style="margin: 4px 0 0; padding: 4px 8px;">{{ $e('amount') }}</div>@endif
    </td>
    <td><input type="text" name="rows[{{ $i }}][reference]" value="{{ $row['reference'] ?? '' }}" maxlength="255" aria-label="{{ __('payments.fields.reference') }}"></td>
    <td>
        <input type="text" name="rows[{{ $i }}][paid_on]" value="{{ $row['paid_on'] ?? '' }}" placeholder="{{ __('payments.batch.same') }}" aria-label="{{ __('payments.fields.paid_on') }}" style="max-width: 120px;">
        @if ($e('paid_on'))<div class="errors" style="margin: 4px 0 0; padding: 4px 8px;">{{ $e('paid_on') }}</div>@endif
    </td>
    <td>
        <select name="rows[{{ $i }}][method]" aria-label="{{ __('payments.fields.method') }}">
            <option value="">{{ __('payments.batch.same') }}</option>
            @foreach (['bank', 'cash'] as $method)
                <option value="{{ $method }}" @selected(($row['method'] ?? '') === $method)>{{ __('payments.methods.'.$method) }}</option>
            @endforeach
        </select>
        @if ($e('method'))<div class="errors" style="margin: 4px 0 0; padding: 4px 8px;">{{ $e('method') }}</div>@endif
    </td>
    <td>
        <select name="rows[{{ $i }}][bank_account_id]" aria-label="{{ __('payments.fields.bank_account_id') }}">
            <option value="">{{ __('payments.batch.same') }}</option>
            @foreach ($accounts as $account)
                <option value="{{ $account->id }}" @selected((string) ($row['bank_account_id'] ?? '') === (string) $account->id)>{{ $account->formattedIban() }}</option>
            @endforeach
        </select>
        @if ($e('bank_account_id'))<div class="errors" style="margin: 4px 0 0; padding: 4px 8px;">{{ $e('bank_account_id') }}</div>@endif
    </td>
</tr>
