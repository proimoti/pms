{{-- Един ред „имот и дял“ във формата на абоната. $index е номерът на реда (или __INDEX__ в образеца). --}}
<tr>
    <td>
        <select name="properties[{{ $index }}][property_id]" aria-label="{{ __('utilities.fields.property') }}">
            <option value=""></option>
            @foreach ($properties as $property)
                <option value="{{ $property->id }}" @selected((string) ($row['property_id'] ?? '') === (string) $property->id)>
                    № {{ $property->number }} {{ \App\Support\Address::format($property) }}
                </option>
            @endforeach
        </select>
    </td>
    <td><input type="text" inputmode="decimal" name="properties[{{ $index }}][share]" value="{{ $row['share'] ?? '' }}" aria-label="{{ __('utilities.fields.share') }}"></td>
</tr>
