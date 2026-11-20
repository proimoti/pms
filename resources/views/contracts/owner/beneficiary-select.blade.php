{{-- Бенефициент: „Самият собственик“ (по подразбиране) и всички бенефициенти на фирмата
     (PROJECT.md, т. 2 „Бенефициент в договора“). Записът, свързан със собственика на
     договора, не се показва втори път: той е „Самият собственик“.
     $id, $name, $beneficiaries, $selected, $ownerId (собственикът на договора или null). --}}
<select id="{{ $id }}" name="{{ $name }}">
    <option value="" @selected((string) $selected === '')>{{ __('contracts.screens.owner_himself_option') }}</option>
    @foreach ($beneficiaries as $beneficiary)
        @continue($ownerId !== null && $beneficiary->owner_id === $ownerId)
        <option value="{{ $beneficiary->id }}" @if ($beneficiary->owner_id) data-owner="{{ $beneficiary->owner_id }}" @endif
            @selected((string) $selected === (string) $beneficiary->id)>{{ $beneficiary->name }}</option>
    @endforeach
</select>
