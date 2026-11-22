{{-- Редовете на един договор в работната таблица. $side: tenant или owner. --}}
@use('App\Support\Format')
@use('App\Services\Charges\Cents')
<div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>{{ __('charges.fields.property') }}</th>
            <th>{{ __('charges.fields.type') }}</th>
            <th class="num">{{ __('charges.fields.days') }}</th>
            @if ($side === 'owner')<th class="num">{{ __('charges.screens.rent_info') }}</th>@endif
            <th class="num">{{ __('charges.fields.amount') }}</th>
            <th>{{ __('charges.fields.note') }}</th>
            <th>{{ __('charges.fields.source') }}</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            <tr @class(['zero' => $row->removed_at === null && Cents::from($row->amount) === 0, 'removed' => $row->removed_at !== null, 'flagged' => isset($flagged[$row->id])])
                @if (isset($flagged[$row->id])) title="{{ implode(' ', $flagged[$row->id]) }}" @endif>
                <td><a href="/properties/{{ $row->property_id }}">№ {{ $row->property?->number }}</a> {{ \App\Support\Address::format($row->property) }}</td>
                <td>{{ __('charges.types.'.$row->type->value) }}</td>
                <td class="num">{{ $row->days }}</td>
                @if ($side === 'owner')<td class="num">{{ isset($rentByProperty[$row->property_id]) ? Format::money($rentByProperty[$row->property_id]) : '' }}</td>@endif
                <td class="num">
                    {{ Format::money($row->amount) }}
                    @if ($row->edited_at && $row->proposed_amount !== null && $row->proposed_amount !== $row->amount)
                        <div class="muted">{{ __('charges.screens.proposed_now', ['amount' => Format::money($row->proposed_amount)]) }}</div>
                    @endif
                    @if (Cents::from($row->amount) === 0 && $row->removed_at === null)
                        <div class="muted">{{ __('charges.screens.zero_hint') }}</div>
                    @endif
                </td>
                <td>
                    @if ($row->isUtility())
                        @include('charges.partials.utility-label', ['row' => $row])
                    @endif
                    {{ $row->note }}
                    @if ($row->removed_at)
                        <div><strong>{{ __('charges.screens.removed') }}:</strong> {{ $row->remove_reason }}</div>
                    @elseif ($row->edit_reason)
                        <div class="muted">{{ __('charges.fields.reason') }}: {{ $row->edit_reason }}</div>
                    @endif
                </td>
                <td>
                    {{ __('charges.sources.'.$row->source->value) }}@if ($row->edited_at && $row->source->value !== 'manual'), {{ __('charges.screens.manual_mark') }}@endif
                </td>
                <td>
                    @if ($editable && $row->statement_id === null)
                        <a href="/charges/{{ $row->id }}/edit">{{ $row->removed_at ? __('charges.screens.restore') : __('charges.screens.change') }}</a>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
        @php($total = $rows->whereNull('removed_at')->sum(fn ($row) => Cents::from($row->amount)))
        <tfoot>
        <tr>
            <th colspan="{{ $side === 'owner' ? 4 : 3 }}">{{ __('charges.screens.contract_total') }}</th>
            <th class="num">{{ Format::money(Cents::format($total)) }}</th>
            <th colspan="3"></th>
        </tr>
        </tfoot>
    </table>
</div>
