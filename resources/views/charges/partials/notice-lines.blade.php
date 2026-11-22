{{-- Редовете на едно известие (преглед преди издаване). Битов ред със сума 0 не се показва
     (PROJECT.md, „Битови сметки в месеца“, 22.11.2026); наем 0 (например гараж) остава. --}}
@use('App\Support\Format')
@use('App\Services\Charges\Cents')
@php($lines = $rows->reject(fn ($row) => $row->isUtility() && Cents::from($row->amount) === 0))
<div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>{{ __('charges.fields.property') }}</th>
            <th>{{ __('charges.fields.type') }}</th>
            <th class="num">{{ __('charges.fields.days') }}</th>
            <th class="num">{{ __('charges.fields.amount') }}</th>
            <th>{{ __('charges.fields.note') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($lines as $row)
            <tr @class(['zero' => Cents::from($row->amount) === 0])>
                <td>№ {{ $row->property?->number }} {{ \App\Support\Address::format($row->property) }}</td>
                <td>{{ __('charges.types.'.$row->type->value) }}</td>
                <td class="num">{{ $row->days }}</td>
                <td class="num">{{ Format::money($row->amount) }}</td>
                <td>
                    @if ($row->isUtility())
                        @include('charges.partials.utility-label', ['row' => $row, 'details' => true])
                    @endif
                    {{ $row->note }}
                </td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
        <tr>
            <th colspan="3">{{ __('charges.statements.total') }}</th>
            <th class="num">{{ Format::money(Cents::format($lines->sum(fn ($row) => Cents::from($row->amount)))) }}</th>
            <th></th>
        </tr>
        </tfoot>
    </table>
</div>
