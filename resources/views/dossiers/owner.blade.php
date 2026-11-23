{{-- Досието на собственика: имоти, договори за управление, анекси, свързани бенефициенти (без IBAN). --}}
<h2>{{ __('dossiers.sections.properties') }}</h2>
@if ($properties->isEmpty())
    <p class="muted">{{ __('dossiers.none') }}</p>
@else
    <ul>
        @foreach ($properties as $property)
            <li><a href="/properties/{{ $property->id }}">№ {{ $property->number }}</a> {{ \App\Support\Address::format($property) }}</li>
        @endforeach
    </ul>
@endif

<h2>{{ __('dossiers.sections.owner_contracts') }}</h2>
@include('dossiers.contracts-list', ['contracts' => $contracts, 'kind' => 'owner', 'showParty' => false, 'showProperties' => true])

<h2>{{ __('dossiers.sections.annexes') }}</h2>
@include('dossiers.annexes', ['annexes' => $annexes])

<h2>{{ __('dossiers.sections.beneficiaries') }}</h2>
@if ($beneficiaries->isEmpty())
    <p class="muted">{{ __('dossiers.none') }}</p>
@else
    <ul>
        @foreach ($beneficiaries as $beneficiary)
            <li><a href="/beneficiaries/{{ $beneficiary->id }}">{{ $beneficiary->name }}</a></li>
        @endforeach
    </ul>
@endif
