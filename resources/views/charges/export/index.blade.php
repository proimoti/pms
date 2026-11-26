@extends('layouts.app')

@use('App\Services\Charges\Month')

@section('title', __('export.batches.title'))
@section('width', 'list')

@section('content')
    <h1>{{ __('export.batches.title') }}</h1>
    <p class="muted">{{ __('export.batches.intro') }}</p>

    @if ($problems !== [])
        <div class="warning">
            {{ __('export.batches.problems_title') }} {{ implode('; ', $problems) }}.
            <a href="/company/export">{{ __('export.batches.settings_link') }}</a>
        </div>
    @endif

    @if ($months === [])
        <p class="muted">{{ __('export.batches.none') }}</p>
    @else
        @php($c = 'export.batches.columns.')
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>{{ __($c.'month') }}</th><th>{{ __($c.'pending') }}</th><th>{{ __($c.'batches') }}</th><th></th></tr>
                </thead>
                <tbody>
                @foreach ($months as $row)
                    <tr>
                        <td class="nowrap">{{ Month::label($row['month']) }}</td>
                        <td class="nowrap">{{ __('export.batches.pending', ['count' => $row['pending']]) }}</td>
                        <td>
                            @foreach ($row['batches'] as $batch)
                                <div>{{ __('export.batches.batch', ['number' => $batch->number, 'count' => $batch->entries_count,
                                    'date' => $batch->created_at?->format('d.m.Y H:i'), 'user' => $batch->createdBy?->name]) }}</div>
                            @endforeach
                        </td>
                        <td>
                            @if ($row['pending'] > 0 && $problems === [])
                                <form method="POST" action="/export/batches" style="margin: 0;">
                                    @csrf
                                    <input type="hidden" name="month" value="{{ Month::key($row['month']) }}">
                                    <button type="submit" style="margin-top: 0;">{{ __('export.batches.create') }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
