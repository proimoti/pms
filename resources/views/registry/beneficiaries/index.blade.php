@extends('layouts.app')

@section('title', __('registry.sections.beneficiaries'))
@section('width', 'full')

@section('content')
    @include('registry.partials.toolbar', ['section' => 'beneficiaries'])

    @if ($beneficiaries->isEmpty())
        <p class="muted">{{ __('registry.empty') }}</p>
    @else
        {{-- IBAN не излиза в списъка (PROJECT.md, т. 2). --}}
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('registry.fields.name') }}</th>
                    <th>{{ __('registry.fields.beneficiary_owner') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($beneficiaries as $beneficiary)
                    <tr>
                        <td><a href="/beneficiaries/{{ $beneficiary->id }}">{{ $beneficiary->name }}</a></td>
                        <td>
                            @if ($beneficiary->owner)
                                {{ __('registry.owner_himself') }}: № {{ $beneficiary->owner->number }}
                            @else
                                {{ __('registry.none') }}
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @include('registry.partials.pagination', ['paginator' => $beneficiaries])
    @endif
@endsection
