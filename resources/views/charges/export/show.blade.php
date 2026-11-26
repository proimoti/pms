@extends('layouts.app')

@use('App\Support\Format')
@use('App\Services\Charges\Month')

@php($heading = __('export.file.title', ['number' => $batch->number, 'month' => Month::label($batch->month)]))
@section('title', $heading)
@section('width', 'full')

@section('content')
    <div class="row">
        <h1 style="margin: 0;">{{ $heading }}</h1>
        @if ($data['ok'] && $problems === [])
            <a class="button" style="margin-top: 0;" href="/export/batches/{{ $batch->id }}/download">{{ __('export.file.download') }}</a>
        @endif
    </div>
    <p class="muted">{{ __('export.batches.batch', ['number' => $batch->number, 'count' => $batch->entries_count,
        'date' => $batch->created_at?->format('d.m.Y H:i'), 'user' => $batch->createdBy?->name]) }} · {{ __('export.file.rows', ['count' => count($data['rows'])]) }}</p>

    @if ($problems !== [])
        <div class="warning">{{ __('export.file.problems', ['list' => implode('; ', $problems)]) }} <a href="/company/export">{{ __('export.batches.settings_link') }}</a></div>
    @endif

    {{-- Сверка (26.11.2026): по всяка сметка на разчетите „дебит минус кредит“ във файла = сборът в книгата. --}}
    <h2>{{ __('export.file.check_title') }}</h2>
    <div @class(['status' => $data['ok'], 'warning' => ! $data['ok']])>{{ $data['ok'] ? __('export.file.check_ok') : __('export.file.check_failed') }}</div>
    @php($c = 'export.file.columns.')
    <div class="table-wrap">
        <table>
            <thead><tr><th>{{ __($c.'account') }}</th><th class="num">{{ __($c.'ledger') }}</th><th class="num">{{ __($c.'file') }}</th><th class="num">{{ __($c.'difference') }}</th></tr></thead>
            <tbody>
            @foreach ($data['check'] as $line)
                <tr @class(['flagged' => $line['ledger'] !== $line['file']])>
                    <td>{{ $line['account'] }}</td>
                    <td class="num">{{ Format::money(\App\Services\Charges\Cents::format($line['ledger'])) }}</td>
                    <td class="num">{{ Format::money(\App\Services\Charges\Cents::format($line['file'])) }}</td>
                    <td class="num">{{ Format::money(\App\Services\Charges\Cents::format($line['file'] - $line['ledger'])) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <p><a href="/export">{{ __('registry.back_to_list') }}</a></p>
@endsection
