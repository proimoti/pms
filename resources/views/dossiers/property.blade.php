{{-- Досието на имота (PROJECT.md, „Досиета и първи справки“, 23.11.2026). --}}
@use('App\Support\Format')

<h2>{{ __('dossiers.sections.management') }}</h2>
@include('dossiers.contracts-list', ['contracts' => $managements, 'kind' => 'owner'])

<h2>{{ __('dossiers.sections.leases') }}</h2>
@include('dossiers.contracts-list', ['contracts' => $leases, 'kind' => 'lease',
    'rentFor' => fn ($lease) => $lease->lines->sortByDesc('valid_from')->first()?->rent])

<h2>{{ __('dossiers.sections.accounts') }}</h2>
@if ($accounts->isEmpty())
    <p class="muted">{{ __('dossiers.none') }}</p>
@else
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>{{ __('dossiers.fields.number') }}</th>
                <th>{{ __('dossiers.fields.kind') }}</th>
                <th>{{ __('dossiers.fields.provider') }}</th>
                <th>{{ __('dossiers.fields.billing') }}</th>
                <th class="num">{{ __('dossiers.fields.share') }}</th>
                <th>{{ __('dossiers.fields.fund') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($accounts as $account)
                <tr>
                    <td><a href="/utility-accounts/{{ $account->id }}">№ {{ $account->number ?? '—' }}</a>@if ($account->isArchived()) <span class="muted">({{ __('contracts.screens.states.archived') }})</span>@endif</td>
                    <td>{{ __('utilities.kinds.'.$account->kind->value) }}</td>
                    <td>{{ $account->provider?->name ?? __('registry.none') }}</td>
                    <td>{{ __('utilities.billing.'.$account->billing->value) }}@if ($account->isFixed()) · {{ Format::money($account->fixed_amount) }} €@endif @if ($account->note) <div class="muted">{{ $account->note }}</div>@endif</td>
                    <td class="num">{{ Format::percent($account->properties->first()?->share) }}</td>
                    <td>@if ($account->fund)<a href="/funds/{{ $account->fund->id }}">{{ $account->fund->name }}</a>@else {{ __('registry.none') }} @endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif

<h2>{{ __('dossiers.sections.funds') }}</h2>
@if ($funds->isEmpty())
    <p class="muted">{{ __('dossiers.none') }}</p>
@else
    <ul>
        @foreach ($funds as $fund)
            <li><a href="/funds/{{ $fund->id }}">{{ $fund->name }}</a></li>
        @endforeach
    </ul>
@endif

<h2>{{ __('dossiers.sections.unallocated') }}</h2>
@if ($unallocated['decide'] === [] && $unallocated['waiting'] === [])
    <p class="muted">{{ __('dossiers.none') }}</p>
@else
    <div class="table-wrap">
        <table>
            <tbody>
            @foreach (['decide' => $unallocated['decide'], 'waiting' => $unallocated['waiting']] as $group => $items)
                @foreach ($items as $item)
                    <tr>
                        <td>{{ __('dossiers.unallocated_'.$group) }}</td>
                        <td>{{ Format::date($item->bill->paid_at) }} · {{ $item->bill->account?->provider?->name }} · № {{ $item->bill->account?->number }}</td>
                        <td>{{ $item->periodFrom->format('d.m') }}–{{ $item->periodTo->format('d.m.Y') }}</td>
                        <td class="num">{{ Format::money($item->left) }}</td>
                    </tr>
                @endforeach
            @endforeach
            </tbody>
        </table>
    </div>
    <p><a href="/utility-unallocated">{{ __('dossiers.unallocated_link') }}</a></p>
@endif

<h2>{{ __('dossiers.sections.balances') }}</h2>
<dl>
    <dt>{{ __('dossiers.balances.tenant') }}</dt><dd>{{ Format::money($balances['tenant']) }} €</dd>
    <dt>{{ __('dossiers.balances.owner') }}</dt><dd>{{ Format::money($balances['owner']) }} €</dd>
    <dt>{{ __('dossiers.balances.utility') }}</dt><dd>{{ Format::money($balances['utility']) }} €</dd>
</dl>
<p class="muted">{{ __('dossiers.balances_hint') }}</p>
