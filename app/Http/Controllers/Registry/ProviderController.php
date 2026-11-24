<?php

namespace App\Http\Controllers\Registry;

use App\Models\Provider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Екранът „Доставчици“ (PROJECT.md, т. 5.3, 21.11.2026). */
class ProviderController extends RegistryController
{
    protected function section(): string
    {
        return 'providers';
    }

    protected function applySearch(Builder $query, string $term, string $likeTerm): void
    {
        foreach (['name', 'easypay_name', 'epay_name'] as $column) {
            $query->orWhere($column, 'like', $likeTerm);
        }
    }

    public function index(Request $request): View
    {
        $query = $this->listQuery($request, Provider::query()->orderBy('name'));

        return view('registry.providers.index', ['providers' => $this->paginate($query)]);
    }

    public function create(): View
    {
        return view('registry.providers.form', ['provider' => new Provider]);
    }

    public function store(Request $request): RedirectResponse
    {
        $provider = Provider::query()->create($this->validated($request));

        return redirect("/providers/{$provider->id}")->with('status', __('registry.saved'));
    }

    public function show(Provider $provider): View
    {
        return view('registry.providers.show', ['provider' => $provider]);
    }

    public function edit(Provider $provider): View
    {
        return view('registry.providers.form', ['provider' => $provider]);
    }

    public function update(Request $request, Provider $provider): RedirectResponse
    {
        $provider->update($this->validated($request));

        return redirect("/providers/{$provider->id}")->with('status', __('registry.saved'));
    }

    public function archive(Provider $provider): RedirectResponse
    {
        return $this->archiveRecord($provider);
    }

    public function restore(Provider $provider): RedirectResponse
    {
        return $this->restoreRecord($provider);
    }

    /** Празно поле за име във файл = няма име. */
    private function validated(Request $request): array
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'kind' => ['nullable', \Illuminate\Validation\Rule::enum(\App\Enums\UtilityKind::class)],
            'easypay_name' => ['nullable', 'string', 'max:255'],
            'epay_name' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        return [
            'name' => $request->input('name'),
            'kind' => $request->input('kind') ?: null,
            'easypay_name' => $request->input('easypay_name'),
            'epay_name' => $request->input('epay_name'),
            'note' => $request->input('note'),
        ];
    }
}
