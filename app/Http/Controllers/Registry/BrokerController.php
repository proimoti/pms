<?php

namespace App\Http\Controllers\Registry;

use App\Http\Requests\Registry\BrokerRequest;
use App\Models\Broker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Екранът „Отговорни лица“. */
class BrokerController extends RegistryController
{
    protected function section(): string
    {
        return 'brokers';
    }

    protected function applySearch(Builder $query, string $term, string $likeTerm): void
    {
        if (ctype_digit($term)) {
            $query->orWhere('number', (int) $term);
        }
        foreach (['name', 'phone', 'email'] as $column) {
            $query->orWhere($column, 'like', $likeTerm);
        }
    }

    public function index(Request $request): View
    {
        $query = $this->listQuery($request, Broker::query()->orderBy('name')->orderBy('number'));

        return view('registry.brokers.index', ['brokers' => $this->paginate($query)]);
    }

    public function create(): View
    {
        return view('registry.brokers.form', ['broker' => new Broker]);
    }

    public function store(BrokerRequest $request): RedirectResponse
    {
        $broker = Broker::query()->create($request->validated());

        return redirect("/brokers/{$broker->id}")->with('status', __('registry.saved'));
    }

    public function show(Broker $broker): View
    {
        // Имотите, към които е прикачено лицето, по собственик (т. 2.8, 24.11.2026).
        $properties = \App\Models\Property::query()->where('broker_id', $broker->id)->orderBy('number')->get();
        $parties = app(\App\Services\Registry\ListColumns::class)->propertyParties($properties);
        $properties = $properties->sortBy(fn ($property) => [$parties[$property->id]['owner'] === null ? 1 : 0, mb_strtolower($parties[$property->id]['owner']?->name ?? ''), $property->number])->values();

        return view('registry.brokers.show', ['broker' => $broker, 'properties' => $properties, 'parties' => $parties]);
    }

    public function edit(Broker $broker): View
    {
        return view('registry.brokers.form', ['broker' => $broker]);
    }

    public function update(BrokerRequest $request, Broker $broker): RedirectResponse
    {
        $broker->update($request->validated());

        return redirect("/brokers/{$broker->id}")->with('status', __('registry.saved'));
    }

    public function archive(Broker $broker): RedirectResponse
    {
        return $this->archiveRecord($broker);
    }

    public function restore(Broker $broker): RedirectResponse
    {
        return $this->restoreRecord($broker);
    }
}
