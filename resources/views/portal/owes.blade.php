{{-- Сума по 418 за портала: „X €“ или „Надплатено X €“ без минус (27.11.2026). --}}
@if (\App\Services\Charges\Cents::from((string) $amount) < 0){{ __('dossiers.overpaid', ['amount' => \App\Support\Format::money(\App\Services\Charges\Cents::format(-\App\Services\Charges\Cents::from((string) $amount)))]) }}@else{{ \App\Support\Format::money($amount) }} €@endif
