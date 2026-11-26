{{-- Досието на наемателя: договори, „Дължи“ по 418 (общо и по договор) и вдясно „Депозити“ (т. 2.6, 24.11.2026). --}}
@use('App\Support\Format')

<h2>{{ __('dossiers.sections.leases') }}</h2>
@include('dossiers.contracts-list', ['contracts' => $leases, 'kind' => 'lease', 'showParty' => false, 'showProperties' => true])

<div class="dossier-pair">
    <section>
        <h2>{{ __('dossiers.sections.owes') }}</h2>
        <dl>
            <dt>{{ __('dossiers.owes_total') }}</dt><dd><strong>@include('dossiers.owes-amount', ['amount' => $owes['total']])</strong></dd>
            @foreach ($leases as $lease)
                <dt><a href="/lease-contracts/{{ $lease->id }}">{{ __('registry.one.lease-contracts') }} № {{ $lease->number }}</a></dt>
                <dd>@include('dossiers.owes-amount', ['amount' => $owes['contracts'][$lease->id] ?? '0.00'])
                    @if (\App\Models\Concerns\WorksheetRules::canManage(auth()->user())) · <a href="/lease-contracts/{{ $lease->id }}/payments/create">{{ __('payments.new') }}</a>@endif</dd>
            @endforeach
        </dl>
    </section>
    <section id="deposits">
        <h2>{{ __('dossiers.sections.deposits') }}</h2>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('dossiers.deposits.contract') }}</th>
                    <th class="num">{{ __('dossiers.deposits.contracted') }}</th>
                    <th class="num">{{ __('dossiers.deposits.charged') }}</th>
                    <th class="num">{{ __('dossiers.deposits.paid') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($leases as $lease)
                    <tr>
                        <td><a href="/lease-contracts/{{ $lease->id }}">№ {{ $lease->number }}</a></td>
                        <td class="num">{{ Format::money($deposits[$lease->id]['contracted'] ?? '0.00') }}</td>
                        <td class="num">{{ Format::money($deposits[$lease->id]['charged'] ?? '0.00') }}</td>
                        <td class="num">{{ __('registry.none') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>
