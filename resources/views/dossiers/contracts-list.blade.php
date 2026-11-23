{{-- Списък с договори в досие: $contracts, $kind ('owner' или 'lease'); по избор $showParty (собственика/наемателя), $showProperties. --}}
@use('App\Support\Format')
@php($section = $kind === 'owner' ? 'owner-contracts' : 'lease-contracts')
@if ($contracts->isEmpty())
    <p class="muted">{{ __('dossiers.none') }}</p>
@else
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>{{ __('dossiers.fields.state') }}</th>
                <th>{{ __('dossiers.fields.contract') }}</th>
                @if ($kind === 'owner')<th>{{ __('dossiers.fields.type') }}</th>@endif
                @if ($showParty ?? true)<th>{{ $kind === 'owner' ? __('dossiers.fields.owner') : __('dossiers.fields.tenant') }}</th>@endif
                @if ($showProperties ?? false)<th>{{ __('dossiers.fields.properties') }}</th>@endif
                <th>{{ __('dossiers.fields.from') }}</th>
                <th>{{ __('dossiers.fields.to') }}</th>
                @if ($kind === 'lease' && isset($rentFor))<th class="num">{{ __('dossiers.fields.rent') }}</th>@endif
            </tr>
            </thead>
            <tbody>
            @foreach ($contracts as $contract)
                @php($state = $contract->stateOn())
                <tr>
                    <td>
                        {{ match ($state) { 'active' => __('dossiers.current'), 'upcoming' => __('dossiers.upcoming'), default => __('dossiers.past') } }}
                        <div class="muted">{{ __('contracts.screens.states.'.$state) }}</div>
                    </td>
                    <td><a href="/{{ $section }}/{{ $contract->id }}">№ {{ $contract->number }}</a></td>
                    @if ($kind === 'owner')<td>{{ __('contracts.types.'.$contract->type->value) }}</td>@endif
                    @if ($showParty ?? true)
                        <td>
                            @php($party = $kind === 'owner' ? $contract->owner : $contract->tenant)
                            @if ($party)
                                <a href="/{{ $kind === 'owner' ? 'owners' : 'tenants' }}/{{ $party->id }}">{{ $party->name }}</a>
                            @else
                                {{ __('registry.none') }}
                            @endif
                        </td>
                    @endif
                    @if ($showProperties ?? false)
                        <td>
                            @foreach ($contract->lines->pluck('property')->filter()->unique('id') as $property)
                                <a href="/properties/{{ $property->id }}">№ {{ $property->number }}</a>{{ $loop->last ? '' : ',' }}
                            @endforeach
                        </td>
                    @endif
                    <td>{{ Format::date($contract->startsOn()) }}</td>
                    <td>{{ $contract->terminated_on ? Format::date($contract->terminated_on) : ($contract->end_on ? Format::date($contract->end_on) : __('dossiers.until_now')) }}</td>
                    @if ($kind === 'lease' && isset($rentFor))<td class="num">{{ Format::money($rentFor($contract)) }}</td>@endif
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
