@extends('layouts.app')

@section('title', __('staff.title'))
@section('width', 'list')

@section('content')
    <div class="row">
        <h1 style="margin: 0;">{{ __('staff.title') }}</h1>
        <a class="button" style="margin-top: 0;" href="/staff/create">{{ __('staff.new') }}</a>
    </div>
    <p class="muted">{{ __('staff.intro') }}</p>

    @if ($staff->isEmpty())
        <p class="muted">{{ __('staff.empty') }}</p>
    @else
        @php($c = 'staff.columns.')
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __($c.'name') }}</th><th>{{ __($c.'email') }}</th><th>{{ __($c.'template') }}</th>
                    <th>{{ __($c.'admin') }}</th><th>{{ __($c.'state') }}</th><th>{{ __($c.'two_factor') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($staff as $member)
                    @php($template = \App\Support\StaffTemplates::of($member))
                    <tr data-href="/staff/{{ $member->id }}">
                        <td><a href="/staff/{{ $member->id }}">{{ $member->name }}</a></td>
                        <td>{{ $member->email }}</td>
                        <td>{{ $template ? __('staff.templates.'.$template) : __('staff.custom') }}</td>
                        <td>{{ $member->is_company_admin ? __('staff.yes') : '' }}</td>
                        <td>@include('staff.state', ['member' => $member])</td>
                        <td>{{ $member->hasEnabledTwoFactorAuthentication() ? __('staff.two_factor_on') : __('staff.two_factor_off') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
