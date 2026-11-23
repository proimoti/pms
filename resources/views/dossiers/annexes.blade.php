{{-- Анексите в досие: само номер, дата и договор, с връзка към договора (подробностите са там). --}}
@use('App\Support\Format')
@if ($annexes->isEmpty())
    <p class="muted">{{ __('dossiers.none') }}</p>
@else
    <ul>
        @foreach ($annexes as $annex)
            <li>
                {{ __('contracts.screens.annex', ['number' => $annex->number]) }} от {{ Format::date($annex->signed_on) }}
                · <a href="/owner-contracts/{{ $annex->owner_contract_id }}">{{ __('registry.one.owner-contracts') }} № {{ $annex->contract?->number }}</a>
                @if ($annex->note) <span class="muted">· {{ $annex->note }}</span>@endif
            </li>
        @endforeach
    </ul>
@endif
