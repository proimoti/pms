@extends('layouts.app')

@section('title', __('utilities.import.issues_title'))
@section('width', 'board')

@section('content')
    <h1>{{ __('utilities.import.issues_title') }}</h1>
    <p class="muted">{{ __('utilities.import.issues_hint') }}</p>

    <div class="row" style="justify-content: flex-start;">
        <span class="muted">
            @foreach (['open' => 'show_open', 'ignored' => 'show_ignored', 'imported' => 'show_imported'] as $key => $label)
                @if ($status === $key)
                    <strong>{{ __('utilities.import.'.$label) }}</strong>
                @else
                    <a href="/utility-issues{{ $key === 'open' ? '' : '?status='.$key }}">{{ __('utilities.import.'.$label) }}</a>
                @endif
                {{ $loop->last ? '' : '·' }}
            @endforeach
        </span>
        @if ($status === 'open' && $issues->isNotEmpty())
            <form method="POST" action="/utility-issues/retry">
                @csrf
                <button type="submit" class="secondary" style="margin-top: 0;">{{ __('utilities.import.retry') }}</button>
            </form>
        @endif
    </div>

    @if ($status === 'ignored')
        <p class="muted">{{ __('utilities.import.unignore_hint') }}</p>
    @endif

    @if ($issues->isEmpty())
        <p class="muted">{{ __('registry.empty') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('utilities.import.paid_at') }}</th>
                    <th>{{ __('utilities.import.provider_in_file') }}</th>
                    <th>{{ __('utilities.import.number_in_file') }}</th>
                    <th class="num">{{ __('utilities.import.amount') }}</th>
                    <th>{{ __('utilities.import.reason') }}</th>
                    @if ($status !== 'open')
                        <th>{{ __('utilities.import.decided') }}</th>
                    @endif
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($issues as $issue)
                    <tr>
                        <td>{{ $issue->paid_at?->format('d.m.Y') }}</td>
                        <td>{{ $issue->provider_name }} <span class="muted">({{ __('utilities.sources.'.$issue->source) }})</span></td>
                        <td>{{ $issue->number }}@if ($issue->description && $issue->source === 'epay')<div class="muted">{{ $issue->description }}</div>@endif</td>
                        <td class="num">{{ $issue->amount === null ? '' : \App\Support\Format::money($issue->amount) }}</td>
                        <td>
                            {{ __('utilities.reasons.'.$issue->reason) }}
                            @if ($issue->status === 'ignored')
                                <div class="muted">{{ __('utilities.import.ignore_reason_shown', ['reason' => $issue->ignore_reason]) }}</div>
                            @endif
                        </td>
                        @if ($status !== 'open')
                            <td>{{ $issue->decidedBy?->name }}<div class="muted">{{ $issue->decided_at?->format('d.m.Y H:i') }}</div></td>
                        @endif
                        <td>
                            @if ($issue->status === 'ignored')
                                <form method="POST" action="/utility-issues/{{ $issue->id }}/unignore">
                                    @csrf
                                    <button type="submit" class="secondary" style="margin-top: 0;">{{ __('utilities.import.unignore') }}</button>
                                </form>
                            @endif
                            @if ($issue->status === 'open')
                                @if ($issue->reason === 'unknown_number' && $issue->provider_id)
                                    <a class="button" style="margin-top: 0;" href="/utility-accounts/create?{{ http_build_query(['provider_id' => $issue->provider_id, 'number' => $issue->number]) }}">{{ __('utilities.import.link_account') }}</a>
                                @elseif ($issue->reason === 'unknown_provider')
                                    <form method="POST" action="/utility-issues/{{ $issue->id }}/provider" class="row" style="justify-content: flex-start;">
                                        @csrf
                                        <select name="provider_id" aria-label="{{ __('utilities.fields.provider') }}" required>
                                            <option value=""></option>
                                            @foreach ($providers as $provider)
                                                <option value="{{ $provider->id }}">{{ $provider->name }}</option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="secondary" style="margin-top: 0;">{{ __('utilities.import.link_provider') }}</button>
                                    </form>
                                @endif
                                <form method="POST" action="/utility-issues/{{ $issue->id }}/ignore" class="row" style="justify-content: flex-start;">
                                    @csrf
                                    <input type="text" name="reason" placeholder="{{ __('utilities.import.ignore_reason') }}" aria-label="{{ __('utilities.import.ignore_reason') }}" required>
                                    <button type="submit" class="secondary" style="margin-top: 0;">{{ __('utilities.import.ignore') }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @include('registry.partials.pagination', ['paginator' => $issues])
    @endif
@endsection
