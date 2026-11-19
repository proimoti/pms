@extends('layouts.app')

@section('title', __('registry.sections.'.$section))
@section('width', 'full')

@section('content')
    @include('registry.partials.toolbar', ['section' => $section])

    @if ($parties->isEmpty())
        <p class="muted">{{ __('registry.empty') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th class="num">{{ __('registry.fields.number') }}</th>
                    <th>{{ __('registry.fields.name') }}</th>
                    <th>{{ __('registry.fields.kind') }}</th>
                    <th>{{ __('registry.fields.eik') }}</th>
                    <th>{{ __('registry.fields.phone') }}</th>
                    <th>{{ __('registry.fields.email') }}</th>
                    <th>{{ __('registry.fields.city') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($parties as $party)
                    <tr>
                        <td class="num"><a href="/{{ $section }}/{{ $party->id }}">{{ $party->number }}</a></td>
                        <td><a href="/{{ $section }}/{{ $party->id }}">{{ $party->name }}</a></td>
                        <td>{{ __('registry.kinds.'.$party->kind->value) }}</td>
                        <td>{{ $party->eik }}</td>
                        <td>{{ $party->phone }}</td>
                        <td>{{ $party->email }}</td>
                        <td>{{ $party->city }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @include('registry.partials.pagination', ['paginator' => $parties])
    @endif
@endsection
