@extends('layouts.app')

@section('title', __('registry.sections.funds'))
@section('width', 'list')

@section('content')
    @include('registry.partials.toolbar', ['section' => 'funds'])
    <p class="muted">{{ __('utilities.funds.hint') }}</p>

    @if ($funds->isEmpty())
        <p class="muted">{{ __('registry.empty') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('utilities.funds.fields.name') }}</th>
                    <th>{{ __('utilities.funds.columns.analytic') }}</th>
                    <th>{{ __('utilities.funds.fields.properties') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($funds as $fund)
                    <tr data-href="/funds/{{ $fund->id }}">
                        <td><a href="/funds/{{ $fund->id }}">{{ $fund->name }}</a></td>
                        <td>
                            @if ($fund->missingAnalytic())
                                <span class="warning" style="display: inline-block; margin: 0; padding: 2px 8px;">{{ __('utilities.funds.missing_analytic') }}</span>
                            @else
                                {{ $fund->analytic }}
                            @endif
                        </td>
                        <td>
                            @foreach ($fund->properties as $line)
                                № {{ $line->property?->number }}{{ $loop->last ? '' : ',' }}
                            @endforeach
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @include('registry.partials.pagination', ['paginator' => $funds])
    @endif
@endsection
