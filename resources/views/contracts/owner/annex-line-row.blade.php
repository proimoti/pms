{{-- Един ред „имот“ в нов анекс. $index е номерът на реда (или __INDEX__ в образеца). --}}
<tr>
    <td>
        <select name="lines[{{ $index }}][action]" aria-label="{{ __('contracts.screens.fields.action') }}">
            @foreach ($actions as $action)
                <option value="{{ $action }}" @selected(old("lines.$index.action") === $action)>{{ __('contracts.screens.actions.'.$action) }}</option>
            @endforeach
        </select>
    </td>
    <td>
        <select name="lines[{{ $index }}][property_id]" aria-label="{{ __('contracts.screens.fields.property') }}">
            <option value=""></option>
            @foreach ($properties as $property)
                <option value="{{ $property->id }}" @selected((string) old("lines.$index.property_id") === (string) $property->id)>
                    № {{ $property->number }} {{ \App\Support\Address::format($property) }}
                </option>
            @endforeach
        </select>
    </td>
    <td><input type="text" inputmode="numeric" maxlength="10" autocomplete="off" placeholder="{{ __('registry.date_placeholder') }}" name="lines[{{ $index }}][from]" value="{{ \App\Support\BgDate::toInput(old("lines.$index.from")) }}" aria-label="{{ __('contracts.screens.fields.from') }}"></td>
    @if ($fixed)
        <td><input type="text" inputmode="decimal" name="lines[{{ $index }}][fixed_amount]" value="{{ old("lines.$index.fixed_amount") }}" aria-label="{{ __('contracts.screens.fields.fixed_amount') }}"></td>
    @endif
</tr>
