{{-- Досието на наемателя: договори, известия и „Дължи“ по 418 (общо и по договор). --}}
@use('App\Support\Format')
@use('App\Services\Charges\Month')

<h2>{{ __('dossiers.sections.leases') }}</h2>
@include('dossiers.contracts-list', ['contracts' => $leases, 'kind' => 'lease', 'showParty' => false, 'showProperties' => true])

<h2>{{ __('dossiers.sections.owes') }}</h2>
<dl>
    <dt>{{ __('dossiers.owes_total') }}</dt><dd><strong>{{ Format::money($owes['total']) }} €</strong></dd>
    @foreach ($leases as $lease)
        <dt><a href="/lease-contracts/{{ $lease->id }}">{{ __('registry.one.lease-contracts') }} № {{ $lease->number }}</a></dt>
        <dd>{{ Format::money($owes['contracts'][$lease->id] ?? '0.00') }} €</dd>
    @endforeach
</dl>
<p class="muted">{{ __('dossiers.balances_hint') }}</p>

<h2>{{ __('dossiers.sections.statements') }}</h2>
@if ($statements->isEmpty())
    <p class="muted">{{ __('dossiers.none') }}</p>
@else
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>{{ __('dossiers.fields.statement') }}</th>
                <th>{{ __('dossiers.fields.month') }}</th>
                <th>{{ __('dossiers.fields.contract') }}</th>
                <th class="num">{{ __('dossiers.fields.amount') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($statements as $statement)
                <tr>
                    <td><a href="/statements/{{ $statement->id }}">№ {{ $statement->number }}</a></td>
                    <td>{{ Month::label($statement->month) }}</td>
                    <td>@if ($statement->leaseContract)<a href="/lease-contracts/{{ $statement->lease_contract_id }}">№ {{ $statement->leaseContract->number }}</a>@endif</td>
                    <td class="num">{{ Format::money($statement->total) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
