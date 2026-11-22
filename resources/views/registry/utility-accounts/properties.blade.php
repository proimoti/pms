{{-- Имотите на абоната в един ред: „№ 101 (40,00 %), № 102 (60,00 %)“; при един имот без дял. --}}
@php($lines = $account->properties)
@foreach ($lines as $line)
    № {{ $line->property?->number }}@if ($lines->count() > 1) ({{ \App\Support\Format::percent($line->share) }})@endif{{ $loop->last ? '' : ',' }}
@endforeach
