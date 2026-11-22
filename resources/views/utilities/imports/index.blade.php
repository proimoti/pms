@extends('layouts.app')

@section('title', __('registry.sections.utility-imports'))
@section('width', 'board')

@section('content')
    <h1>{{ __('registry.sections.utility-imports') }}</h1>

    <div class="panel">
        <h2 style="margin-top: 0;">{{ __('utilities.import.upload_title') }}</h2>
        <p class="muted">{{ __('utilities.import.upload_hint') }}</p>
        <form method="POST" action="/utility-imports/preview" enctype="multipart/form-data">
            @csrf
            <label for="file">{{ __('utilities.import.file') }} *</label>
            <input id="file" type="file" name="file" accept=".xlsx" required>
            <div class="actions">
                <button type="submit">{{ __('utilities.import.show_preview') }}</button>
            </div>
        </form>
    </div>

    <h2>{{ __('utilities.import.history') }}</h2>
    @if ($batches->isEmpty())
        <p class="muted">{{ __('registry.empty') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('utilities.import.imported_at') }}</th>
                    <th>{{ __('utilities.import.file') }}</th>
                    <th>{{ __('utilities.import.imported_by') }}</th>
                    <th class="num">{{ __('utilities.import.bills') }}</th>
                    <th class="num">{{ __('utilities.import.for_decision') }}</th>
                    <th class="num">{{ __('utilities.import.duplicates') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($batches as $batch)
                    <tr>
                        <td><a href="/utility-imports/{{ $batch->id }}">{{ $batch->created_at?->format('d.m.Y H:i') }}</a></td>
                        <td>{{ $batch->file_name }} <span class="muted">({{ __('utilities.sources.'.$batch->source) }})</span></td>
                        <td>{{ $batch->importedBy?->name }}</td>
                        <td class="num">{{ $batch->bills_count }}</td>
                        <td class="num">{{ $batch->for_decision }}</td>
                        <td class="num">{{ $batch->duplicates }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @include('registry.partials.pagination', ['paginator' => $batches])
    @endif
@endsection
