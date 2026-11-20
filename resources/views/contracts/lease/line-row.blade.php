{{-- Един ред „имот“ в нов договор за наем. $index е номерът на реда (или __INDEX__ в образеца). --}}
<tr>
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
    <td><input type="text" inputmode="numeric" maxlength="10" autocomplete="off" placeholder="{{ __('registry.date_placeholder') }}" name="lines[{{ $index }}][start_on]" value="{{ \App\Support\BgDate::toInput(old("lines.$index.start_on")) }}" aria-label="{{ __('contracts.screens.fields.start_on') }}"></td>
    <td><input type="text" inputmode="decimal" name="lines[{{ $index }}][rent]" value="{{ old("lines.$index.rent") }}" aria-label="{{ __('contracts.screens.fields.rent') }}"></td>
    <td><input type="text" inputmode="decimal" name="lines[{{ $index }}][deposit]" value="{{ old("lines.$index.deposit") }}" aria-label="{{ __('contracts.screens.fields.deposit') }}"></td>
</tr>
