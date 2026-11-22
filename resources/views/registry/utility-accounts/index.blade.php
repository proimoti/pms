@extends('layouts.app')

@section('title', __('registry.sections.utility-accounts'))
@section('width', 'full')

@section('content')
    @include('registry.partials.toolbar', ['section' => 'utility-accounts'])

    @if ($accounts->isEmpty())
        <p class="muted">{{ __('registry.empty') }}</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('utilities.fields.number') }}</th>
                    <th>{{ __('utilities.fields.provider') }}</th>
                    <th>{{ __('utilities.fields.kind') }}</th>
                    <th>{{ __('utilities.fields.billing') }}</th>
                    <th>{{ __('utilities.fields.properties') }}</th>
                    <th>{{ __('utilities.fields.valid_from') }}</th>
                    <th>{{ __('utilities.fields.valid_to') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($accounts as $account)
                    <tr>
                        <td><a href="/utility-accounts/{{ $account->id }}">{{ $account->number ?? __('registry.none') }}</a></td>
                        <td>{{ $account->provider?->name ?? __('registry.none') }}</td>
                        <td>{{ __('utilities.kinds.'.$account->kind->value) }}</td>
                        <td>
                            {{ __('utilities.billing.'.$account->billing->value) }}
                            @if ($account->isFixed())<div class="muted">{{ \App\Support\Format::money($account->fixed_amount) }} €</div>@endif
                        </td>
                        <td>@include('registry.utility-accounts.properties', ['account' => $account])</td>
                        <td>{{ \App\Support\Format::date($account->valid_from) }}</td>
                        <td>{{ \App\Support\Format::date($account->valid_to) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @include('registry.partials.pagination', ['paginator' => $accounts])
    @endif
@endsection
