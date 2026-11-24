{{-- Номерата на договорите като връзки към картите им (списъците, т. 2.3–2.5). --}}
@foreach ($contracts as $contract)<a href="/{{ $path }}/{{ $contract->id }}">№ {{ $contract->number }}</a>@if (! $loop->last), @endif @endforeach
