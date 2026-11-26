@extends('layouts.app')

@section('title', __('opening.title'))
@section('width', 'wide')

@php($money = fn (int $cents) => \App\Support\Format::money(\App\Services\Charges\Cents::format($cents)))

@section('content')
    <h1>{{ __('opening.title') }}</h1>
    <p><a href="/company">{{ __('company.title') }}</a></p>

    @if ($import)
        <p class="status">{{ __('opening.done', ['date' => $import->as_of->format('d.m.Y')]) }}</p>
        <p class="muted">{{ __('opening.done_by', ['name' => $import->createdBy?->name ?? '—', 'at' => $import->created_at?->format('d.m.Y H:i'), 'rows' => $import->rows_count]) }}</p>

        <h2>{{ __('opening.reverse.title') }}</h2>
        <div class="errors" style="font-weight: 600;">{{ __('opening.reverse.warning') }}</div>
        <form method="POST" action="/company/opening/reverse">
            @csrf
            <div class="grid">
                <div>
                    <label for="code">{{ __('opening.reverse.code') }}</label>
                    <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
                </div>
                <div>
                    <label for="reason">{{ __('opening.reverse.reason') }}</label>
                    <input id="reason" type="text" name="reason" value="{{ old('reason') }}" maxlength="500" required>
                </div>
            </div>
            <div class="actions">
                <button type="submit" style="background: var(--error); border-color: var(--error);">{{ __('opening.reverse.button') }}</button>
            </div>
        </form>
    @else
        <p class="muted">{{ __('opening.intro') }}</p>

        @if ($preview)
            <h2>{{ __('opening.preview') }}</h2>
            <p>
                {{ __('opening.totals.debit') }}: {{ $money($preview['totals']['debit']) }} ·
                {{ __('opening.totals.credit') }}: {{ $money($preview['totals']['credit']) }} ·
                {{ __('opening.totals.deposits') }}: {{ $money($preview['totals']['deposits']) }} ·
                {{ __('opening.totals.funds') }}: {{ $money($preview['totals']['funds']) }}
            </p>
            @if ($preview['rows'] !== [])
                <table>
                    <thead>
                        <tr>
                            @foreach (['number', 'tenant', 'property'] as $c)<th>{{ __('opening.columns.'.$c) }}</th>@endforeach
                            <th class="num">{{ __('opening.columns.debit') }}</th>
                            <th class="num">{{ __('opening.columns.credit') }}</th>
                            <th>{{ __('opening.columns.error') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($preview['rows'] as $row)
                            <tr @if ($row['error']) class="row-error" style="color: var(--error);" @endif>
                                <td>{{ $row['number'] }}</td>
                                <td>{{ $row['tenant'] ?? '' }}</td>
                                <td>{{ $row['property'] ? '№ '.$row['property'] : '' }}</td>
                                <td class="num">{{ $money($row['debit']) }}</td>
                                <td class="num">{{ $money($row['credit']) }}</td>
                                <td>{{ $row['error'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            @if ($preview['funds'] !== [])
                <h3>{{ __('opening.fund_rows') }}</h3>
                <table>
                    <tbody>
                        @foreach ($funds->whereIn('id', array_keys($preview['funds'])) as $fund)
                            <tr><td>{{ $fund->name }}</td><td class="num">{{ $money($preview['funds'][$fund->id]) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if ($preview['has_errors'])
                <div class="errors">{{ __('opening.has_errors') }}</div>
            @else
                <form method="POST" action="/company/opening/confirm">
                    @csrf
                    <div class="actions"><button type="submit">{{ __('opening.confirm') }}</button></div>
                </form>
            @endif
            <hr>
        @endif

        <form method="POST" action="/company/opening/preview" enctype="multipart/form-data">
            @csrf
            <div class="grid">
                <div>
                    <label for="as_of">{{ __('opening.as_of') }}</label>
                    <input id="as_of" type="text" name="as_of" value="{{ old('as_of') }}" placeholder="дд.мм.гггг" required>
                    <div class="muted">{{ __('opening.as_of_hint') }}</div>
                </div>
                <div>
                    <label for="tenants">{{ __('opening.tenants') }}</label>
                    <input id="tenants" type="file" name="tenants" accept=".xlsx">
                </div>
                <div>
                    <label for="deposits">{{ __('opening.deposits') }}</label>
                    <input id="deposits" type="file" name="deposits" accept=".xlsx">
                </div>
            </div>
            <p class="muted">{{ __('opening.files_hint') }}</p>

            <h2>{{ __('opening.funds') }}</h2>
            <p class="muted">{{ __('opening.funds_hint') }}</p>
            @forelse ($funds as $fund)
                <div class="grid">
                    <div>
                        <label for="fund-{{ $fund->id }}">№ {{ $fund->number }} {{ $fund->name }}</label>
                        <input id="fund-{{ $fund->id }}" type="text" name="funds[{{ $fund->id }}]" value="{{ old('funds.'.$fund->id) }}" inputmode="decimal">
                    </div>
                </div>
            @empty
                <p class="muted">{{ __('opening.no_funds') }}</p>
            @endforelse

            <div class="actions"><button type="submit">{{ __('opening.show_preview') }}</button></div>
        </form>
    @endif
@endsection
