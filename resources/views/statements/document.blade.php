{{-- Известието (PROJECT.md, „PDF и имейл на известията“, 24.11.2026): същото съдържание в PDF и в писмото.
     Данните на фирмата, наемателя и банковата сметка са запомнените при издаването ($statement->snapshot).
     Логото ($logo, адрес data:) е текущото на фирмата (25.11.2026). Само вградени стилове (за PDF и за пощенските програми). ЕГН и IBAN на хора не се показват. --}}
@php($s = $statement->snapshot ?? ['company' => [], 'tenant' => [], 'bank' => null])
@php($company = $s['company'] ?? [])
@php($tenant = $s['tenant'] ?? [])
@php($bank = $s['bank'] ?? null)
@php($p = 'statements.pdf.')
@php($cell = 'padding: 6px 8px; border-bottom: 1px solid #e3e6eb; vertical-align: top;')
<div style="font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 11px; color: #111827; line-height: 1.45; max-width: 760px;">
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="vertical-align: top; width: 55%;" id="company">
                @if (! empty($logo) && ! empty($logoSize))
                    {{-- В писмото (25.11.2026): с точни размери до 200 × 60 px, защото пощенските програми не спазват max-height. --}}
                    <img src="{{ $logo }}" alt="" width="{{ $logoSize[0] }}" height="{{ $logoSize[1] }}" style="display: block; border: 0; margin-bottom: 8px;">
                @elseif (! empty($logo))<img src="{{ $logo }}" alt="" style="max-height: 60px; max-width: 220px; margin-bottom: 8px;"><br>@endif
                <div style="font-size: 14px; font-weight: bold;">{{ $company['name'] ?? '' }}</div>
                @if (! empty($company['eik']))<div>{{ __($p.'eik') }} {{ $company['eik'] }}@if (! empty($company['vat_number'])) · {{ __($p.'vat') }} {{ $company['vat_number'] }}@endif</div>@endif
                @if (! empty($company['address']))<div>{{ $company['address'] }}</div>@endif
                @if (! empty($company['email']) || ! empty($company['phone']))<div>{{ $company['email'] ?? '' }}@if (! empty($company['email']) && ! empty($company['phone'])) · @endif @if (! empty($company['phone'])){{ __($p.'phone') }} {{ $company['phone'] }}@endif</div>@endif
            </td>
            <td style="vertical-align: top; text-align: right;">
                <div style="font-size: 15px; font-weight: bold;">{{ __('charges.statements.notice') }} № {{ $statement->number }}</div>
                <div>{{ __($p.'month') }}: {{ \App\Services\Charges\Month::label($statement->month) }}</div>
                <div>{{ __($p.'issued_on') }}: {{ $statement->issued_at->format('d.m.Y') }}</div>
                <div>{{ __($p.'due_on') }}: {{ isset($s['due_on']) ? \Carbon\CarbonImmutable::parse($s['due_on'])->format('d.m.Y') : '' }}</div>
            </td>
        </tr>
    </table>

    <table style="width: 100%; border-collapse: collapse; margin-top: 16px;">
        <tr>
            <td style="vertical-align: top; width: 55%;" id="tenant">
                <div style="color: #5b6472;">{{ __($p.'tenant') }}</div>
                <div style="font-weight: bold;">{{ $tenant['name'] ?? '' }}</div>
                @if (! empty($tenant['eik']))<div>{{ __($p.'eik') }} {{ $tenant['eik'] }}</div>@endif
                @if (! empty($tenant['address']))<div>{{ $tenant['address'] }}</div>@endif
            </td>
            <td style="vertical-align: top;">
                <div style="color: #5b6472;">{{ __($p.'contract') }}</div>
                <div style="font-weight: bold;">{{ __('registry.one.lease-contracts') }} № {{ $statement->leaseContract?->number }}</div>
                @foreach ($statement->lines->pluck('property')->filter()->unique('id')->sortBy('number') as $property)
                    <div>{{ __($p.'property_line', ['number' => $property->number]) }}, {{ \App\Support\Address::format($property) }}</div>
                @endforeach
            </td>
        </tr>
    </table>

    <table style="width: 100%; border-collapse: collapse; margin-top: 16px;">
        <thead>
        <tr style="background: #eef0f4;">
            <th style="{{ $cell }} text-align: left;">{{ __($p.'property') }}</th>
            <th style="{{ $cell }} text-align: left;">{{ __($p.'description') }}</th>
            <th style="{{ $cell }} text-align: right;">{{ __($p.'days') }}</th>
            <th style="{{ $cell }} text-align: right;">{{ __($p.'amount') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($statement->lines as $line)
            <tr>
                <td style="{{ $cell }} white-space: nowrap;">№ {{ $line->property?->number }}</td>
                <td style="{{ $cell }}">
                    {{ __('charges.types.'.$line->type->value) }}
                    @if ($line->isUtility())
                        <div style="color: #374151;">@include('charges.partials.utility-label', ['row' => $line, 'details' => true])</div>
                    @endif
                    @if (filled($line->note))<div style="color: #374151;">{{ $line->note }}</div>@endif
                </td>
                <td style="{{ $cell }} text-align: right;">{{ $line->days }}</td>
                <td style="{{ $cell }} text-align: right; white-space: nowrap;">{{ \App\Support\Format::money($line->amount) }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
        <tr>
            <td colspan="3" style="padding: 8px; text-align: right; font-weight: bold;">{{ __($p.'total') }}</td>
            <td style="padding: 8px; text-align: right; font-weight: bold; white-space: nowrap;">{{ \App\Support\Format::money($statement->total) }} €</td>
        </tr>
        </tfoot>
    </table>

    <div style="margin-top: 16px;">
        @if ($bank)
            <div><strong>{{ __($p.'bank_account') }}:</strong> {{ trim(chunk_split((string) $bank['iban'], 4, ' ')) }}@if (! empty($bank['bank_name'])) · {{ __($p.'bank') }}: {{ $bank['bank_name'] }}@endif @if (! empty($bank['bic'])) · BIC {{ $bank['bic'] }}@endif</div>
        @endif
        <div><strong>{{ __($p.'reference_label') }}:</strong> {{ __($p.'reference', ['contract' => $statement->leaseContract?->number]) }}</div>
    </div>
</div>
