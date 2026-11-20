{{-- Един ред „имот“ в нов анекс към договор за наем. $index е номерът на реда (или __INDEX__ в образеца). --}}
<tr>
    <td>
        <select name="lines[{{ $index }}][action]" aria-label="{{ __('contracts.screens.fields.action') }}">
            @foreach (['change', 'add', 'remove'] as $action)
                <option value="{{ $action }}" @selected(old("lines.$index.action") === $action)>{{ __('contracts.screens.actions.'.$action) }}</option>
            @endforeach
        </select>
    </td>
    <td>
        <div class="with-new">
        <select data-quick-section="properties" name="lines[{{ $index }}][property_id]" aria-label="{{ __('contracts.screens.fields.property') }}">
            <option value=""></option>
            @foreach ($properties as $property)
                <option value="{{ $property->id }}" @selected((string) old("lines.$index.property_id") === (string) $property->id)>
                    № {{ $property->number }} {{ \App\Support\Address::format($property) }}
                </option>
            @endforeach
        </select>
            @include('contracts.partials.new-button', ['section' => 'properties'])
        </div>
    </td>
    <td><x-date-input name="lines[{{ $index }}][from]" aria-label="{{ __('contracts.screens.fields.from') }}" :value="old('lines.'.$index.'.from')" /></td>
    <td><input type="text" inputmode="decimal" name="lines[{{ $index }}][rent]" value="{{ old("lines.$index.rent") }}" aria-label="{{ __('contracts.screens.fields.new_rent') }}"></td>
    <td><input type="text" inputmode="decimal" name="lines[{{ $index }}][deposit]" value="{{ old("lines.$index.deposit") }}" aria-label="{{ __('contracts.screens.fields.new_deposit') }}"></td>
</tr>
