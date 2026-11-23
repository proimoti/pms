{{-- Имотите на абоната в един ред: „№ 101 (40,00 %), № 102 (60,00 %)“; при един имот и при „плаща се от фонд“ без дял. --}}
@php($lines = $account->properties)
@foreach ($lines as $line)
    № {{ $line->property?->number }}@if ($lines->count() > 1 && ! $account->paid_from_fund) ({{ \App\Support\Format::percent($line->share) }})@endif{{ $loop->last ? '' : ',' }}
@endforeach
