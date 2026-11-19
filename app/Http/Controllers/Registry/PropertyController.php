<?php

namespace App\Http\Controllers\Registry;

use App\Http\Requests\Registry\PropertyRequest;
use App\Models\Broker;
use App\Models\Property;
use App\Models\PropertyType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Екранът „Имоти“. */
class PropertyController extends RegistryController
{
    protected function section(): string
    {
        return 'properties';
    }

    protected function applySearch(Builder $query, string $term, string $likeTerm): void
    {
        if (ctype_digit($term)) {
            $query->orWhere('number', (int) $term);
        }

        foreach (['city', 'district', 'street', 'street_number'] as $column) {
            $query->orWhere($column, 'like', $likeTerm);
        }
    }

    public function index(Request $request): View
    {
        $query = $this->listQuery($request, Property::query()->with(['propertyType', 'broker'])->orderBy('number'));

        return view('registry.properties.index', ['properties' => $this->paginate($query)]);
    }

    public function create(): View
    {
        return view('registry.properties.form', ['property' => new Property] + $this->choices());
    }

    public function store(PropertyRequest $request): RedirectResponse
    {
        $property = Property::query()->create($request->validated());

        return redirect("/properties/{$property->id}")->with('status', __('registry.saved'));
    }

    public function show(Property $property): View
    {
        return view('registry.properties.show', ['property' => $property->load(['propertyType', 'broker'])]);
    }

    public function edit(Property $property): View
    {
        return view('registry.properties.form', ['property' => $property] + $this->choices());
    }

    public function update(PropertyRequest $request, Property $property): RedirectResponse
    {
        $property->update($request->validated());

        return redirect("/properties/{$property->id}")->with('status', __('registry.saved'));
    }

    public function archive(Property $property): RedirectResponse
    {
        return $this->archiveRecord($property);
    }

    public function restore(Property $property): RedirectResponse
    {
        return $this->restoreRecord($property);
    }

    /** Видовете и отговорните лица на фирмата за падащите списъци. */
    private function choices(): array
    {
        return [
            'types' => PropertyType::query()->active()->orderBy('sort_order')->orderBy('name')->get(),
            'brokers' => Broker::query()->active()->orderBy('name')->get(),
        ];
    }
}
