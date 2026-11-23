@extends('layouts.app')

@use('App\Support\Format')
@use('App\Services\Charges\Month')

@section('title', __('registry.sections.utility-unallocated'))
@section('width', 'full')

{{-- Общите колони на един ред: сметка, имот, период и суми. --}}
@php($cells = function ($item) use ($properties) {
    $account = $item->bill->account;
    return [
        Format::date($item->bill->paid_at),
        __('utilities.kinds.'.$account?->kind?->value).' · '.$account?->provider?->name.' · № '.$account?->number,
        '№ '.($properties[$item->propertyId]->number ?? $item->propertyId),
        $item->periodFrom->format('d.m').'–'.$item->periodTo->format('d.m.Y'),
    ];
})

@section('content')
    <h1>{{ __('registry.sections.utility-unallocated') }}</h1>
    <p class="muted">{{ __('utilities.unallocated.intro') }}</p>

    <h2>{{ __('utilities.unallocated.decide') }}</h2>
    @if ($decide === [])
        <p class="muted">{{ __('utilities.unallocated.empty') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('utilities.unallocated.fields.paid_at') }}</th>
                    <th>{{ __('utilities.unallocated.fields.bill') }}</th>
                    <th>{{ __('utilities.unallocated.fields.property') }}</th>
                    <th>{{ __('utilities.unallocated.fields.period') }}</th>
                    <th class="num">{{ __('utilities.unallocated.fields.paid') }}</th>
                    <th class="num">{{ __('utilities.unallocated.fields.charged') }}</th>
                    <th class="num">{{ __('utilities.unallocated.fields.left') }}</th>
                    <th>{{ __('utilities.unallocated.fields.reason') }}</th>
                    @if ($canManage)<th>{{ __('utilities.unallocated.fields.decision') }}</th>@endif
                </tr>
                </thead>
                <tbody>
                @foreach ($decide as $item)
                    <tr>
                        @foreach ($cells($item) as $cell)<td>{{ $cell }}</td>@endforeach
                        <td class="num">{{ Format::money($item->paid) }}</td>
                        <td class="num">{{ Format::money($item->charged) }}</td>
                        <td class="num"><strong>{{ Format::money($item->left) }}</strong></td>
                        <td>
                            {{ __('utilities.unallocated.reasons.'.$item->reason) }}
                            <div class="muted">{{ __('utilities.unallocated.statuses.'.$item->status) }}</div>
                            @if ($item->suggested)<div class="muted">{{ __('utilities.unallocated.suggested') }}</div>@endif
                        </td>
                        @if ($canManage)
                            <td style="min-width: 280px;">
                                <form method="POST" action="/utility-unallocated/manager">
                                    @csrf
                                    <input type="hidden" name="bill" value="{{ $item->bill->id }}">
                                    <input type="hidden" name="property" value="{{ $item->propertyId }}">
                                    <input type="text" name="reason" required maxlength="500" placeholder="{{ __('utilities.unallocated.fields.decision_reason') }}" aria-label="{{ __('utilities.unallocated.fields.decision_reason') }}">
                                    <button type="submit" @class(['secondary' => ! $item->suggested]) style="margin-top: 6px;">{{ __('utilities.unallocated.to_manager') }}</button>
                                </form>
                                @if ($item->nextLeases !== [])
                                    <form method="POST" action="/utility-unallocated/next-tenant" style="margin-top: 10px;">
                                        @csrf
                                        <input type="hidden" name="bill" value="{{ $item->bill->id }}">
                                        <input type="hidden" name="property" value="{{ $item->propertyId }}">
                                        <select name="lease_contract_id" aria-label="{{ __('utilities.unallocated.fields.lease') }}">
                                            @foreach ($item->nextLeases as $leaseId)
                                                @if ($leases->has($leaseId))
                                                    <option value="{{ $leaseId }}">№ {{ $leases[$leaseId]->number }} — {{ $leases[$leaseId]->tenant?->name }}</option>
                                                @endif
                                            @endforeach
                                        </select>
                                        <input type="text" name="reason" required maxlength="500" placeholder="{{ __('utilities.unallocated.fields.decision_reason') }}" aria-label="{{ __('utilities.unallocated.fields.decision_reason') }}">
                                        <button type="submit" class="secondary" style="margin-top: 6px;">{{ __('utilities.unallocated.to_next_tenant') }}</button>
                                    </form>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2>{{ __('utilities.unallocated.waiting') }}</h2>
    <p class="muted">{{ __('utilities.unallocated.waiting_intro') }}</p>
    @if ($waiting === [])
        <p class="muted">{{ __('utilities.unallocated.none_waiting') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('utilities.unallocated.fields.paid_at') }}</th>
                    <th>{{ __('utilities.unallocated.fields.bill') }}</th>
                    <th>{{ __('utilities.unallocated.fields.property') }}</th>
                    <th>{{ __('utilities.unallocated.fields.period') }}</th>
                    <th class="num">{{ __('utilities.unallocated.fields.paid') }}</th>
                    <th class="num">{{ __('utilities.unallocated.fields.charged') }}</th>
                    <th class="num">{{ __('utilities.unallocated.fields.left') }}</th>
                    <th>{{ __('utilities.unallocated.fields.status') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($waiting as $item)
                    <tr>
                        @foreach ($cells($item) as $cell)<td>{{ $cell }}</td>@endforeach
                        <td class="num">{{ Format::money($item->paid) }}</td>
                        <td class="num">{{ Format::money($item->charged) }}</td>
                        <td class="num">{{ Format::money($item->left) }}</td>
                        <td>{{ __('utilities.unallocated.waiting_reasons.'.$item->waiting, ['month' => $item->month ? Month::label($item->month) : '']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <h2>{{ __('utilities.unallocated.decisions') }}</h2>
    @if ($decisions->isEmpty())
        <p class="muted">{{ __('registry.empty') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('utilities.unallocated.fields.decision') }}</th>
                    <th>{{ __('utilities.unallocated.fields.bill') }}</th>
                    <th>{{ __('utilities.unallocated.fields.property') }}</th>
                    <th class="num">{{ __('utilities.unallocated.fields.left') }}</th>
                    <th>{{ __('utilities.unallocated.fields.decision_reason') }}</th>
                    @if ($canManage)<th></th>@endif
                </tr>
                </thead>
                <tbody>
                @foreach ($decisions as $decision)
                    <tr>
                        <td>
                            {{ __('utilities.unallocated.kinds.'.$decision->kind) }}
                            @if ($decision->leaseContract) · № {{ $decision->leaseContract->number }} {{ $decision->leaseContract->tenant?->name }} @endif
                            <div class="muted">{{ $decision->decided_at->format('d.m.Y') }} · {{ $decision->decidedBy?->name }}</div>
                        </td>
                        <td>{{ $decision->bill?->provider_name }} · № {{ $decision->bill?->file_number }} · {{ Format::date($decision->bill?->paid_at) }}</td>
                        <td>№ {{ $decision->property?->number }}</td>
                        <td class="num">{{ Format::money($decision->amount) }}</td>
                        <td>{{ $decision->reason }}</td>
                        @if ($canManage)
                            <td>
                                @if ($cancellable[$decision->id] ?? false)
                                    <form method="POST" action="/utility-decisions/{{ $decision->id }}/cancel" onsubmit="return confirm(@js(__('utilities.unallocated.cancel_confirm')));">
                                        @csrf
                                        <input type="text" name="reason" required maxlength="500" placeholder="{{ __('utilities.unallocated.cancel_reason') }}" aria-label="{{ __('utilities.unallocated.cancel_reason') }}">
                                        <button type="submit" class="secondary" style="margin-top: 6px;">{{ __('utilities.unallocated.cancel') }}</button>
                                    </form>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
