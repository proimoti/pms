{{-- Един ред „имот“ в нов договор за наем. $index е номерът на реда (или __INDEX__ в образеца). --}}
<tr>
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
    <td><x-date-input name="lines[{{ $index }}][start_on]" aria-label="{{ __('contracts.screens.fields.start_on') }}" :value="old('lines.'.$index.'.start_on')" /></td>
    <td><input type="text" inputmode="decimal" name="lines[{{ $index }}][rent]" value="{{ old("lines.$index.rent") }}" aria-label="{{ __('contracts.screens.fields.rent') }}"></td>
    <td><input type="text" inputmode="decimal" name="lines[{{ $index }}][deposit]" value="{{ old("lines.$index.deposit") }}" aria-label="{{ __('contracts.screens.fields.deposit') }}"></td>
    <td><input type="text" inputmode="decimal" name="lines[{{ $index }}][first_period_rent]" value="{{ old("lines.$index.first_period_rent") }}" aria-label="{{ __('contracts.screens.fields.first_period_rent') }}"></td>
</tr>
