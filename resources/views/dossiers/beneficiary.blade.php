{{-- Досието на бенефициента: договорите, в които е получател (начин на плащане и период), имотите и анексите. IBAN е само в полето горе. --}}
@use('App\Support\Format')

<h2>{{ __('dossiers.sections.payee_contracts') }}</h2>
@if ($contracts->isEmpty())
    <p class="muted">{{ __('dossiers.none') }}</p>
@else
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>{{ __('dossiers.fields.contract') }}</th>
                <th>{{ __('dossiers.fields.owner') }}</th>
                <th>{{ __('dossiers.fields.payment_method') }}</th>
                <th>{{ __('dossiers.fields.period') }}</th>
                <th>{{ __('dossiers.fields.state') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($contracts as $contract)
                @foreach ($contract->payees as $payee)
                    <tr>
                        <td><a href="/owner-contracts/{{ $contract->id }}">№ {{ $contract->number }}</a></td>
                        <td>@if ($contract->owner)<a href="/owners/{{ $contract->owner->id }}">{{ $contract->owner->name }}</a>@endif</td>
                        <td>{{ $payee->payment_method ? __('contracts.payment_methods.'.$payee->payment_method->value) : __('registry.none') }}</td>
                        <td>{{ Format::date($payee->valid_from) }} – {{ $payee->valid_to ? Format::date($payee->valid_to) : __('dossiers.until_now') }}</td>
                        <td>{{ __('contracts.screens.states.'.$contract->stateOn()) }}</td>
                    </tr>
                @endforeach
            @endforeach
            </tbody>
        </table>
    </div>
@endif

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

<h2>{{ __('dossiers.sections.annexes') }}</h2>
@include('dossiers.annexes', ['annexes' => $annexes])
