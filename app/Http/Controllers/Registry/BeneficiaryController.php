<?php

namespace App\Http\Controllers\Registry;

use App\Http\Requests\Registry\BeneficiaryRequest;
use App\Models\Beneficiary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Екранът „Бенефициенти“: получателите на плащанията към собствениците.
 * IBAN не излиза в списъка; вижда се и се сменя само с правото за ЕГН и IBAN.
 */
class BeneficiaryController extends RegistryController
{
    protected function section(): string
    {
        return 'beneficiaries';
    }

    protected function applySearch(Builder $query, string $term, string $likeTerm): void
    {
        $query->orWhere('name', 'like', $likeTerm);
    }

    public function index(Request $request): View
    {
        $query = $this->listQuery($request, Beneficiary::query()->with('owner')->orderBy('name'));

        return view('registry.beneficiaries.index', ['beneficiaries' => $this->paginate($query)]);
    }

    public function create(): View
    {
        return view('registry.beneficiaries.form', ['beneficiary' => new Beneficiary]);
    }

    /** От екрана „Бенефициенти“ или направо от формата на договор („+ Нов бенефициент“). */
    public function store(BeneficiaryRequest $request): RedirectResponse|JsonResponse
    {
        $beneficiary = Beneficiary::query()->create($this->values($request));

        // От формата на договора: само номерът и името (без IBAN), за да се добави в списъка.
        if ($request->expectsJson()) {
            return response()->json(['id' => $beneficiary->id, 'name' => $beneficiary->name], 201);
        }

        return redirect("/beneficiaries/{$beneficiary->id}")->with('status', __('registry.saved'));
    }

    public function show(Beneficiary $beneficiary): View
    {
        $dossier = app(\App\Services\Dossiers\Dossier::class);
        $contracts = $dossier->beneficiaryContracts($beneficiary);

        return view('registry.beneficiaries.show', [
            'beneficiary' => $beneficiary->load('owner'),
            'contracts' => $contracts,
            'properties' => $dossier->contractProperties($contracts),
            'annexes' => $dossier->annexes($contracts),
        ]);
    }

    public function edit(Beneficiary $beneficiary): View
    {
        return view('registry.beneficiaries.form', ['beneficiary' => $beneficiary]);
    }

    public function update(BeneficiaryRequest $request, Beneficiary $beneficiary): RedirectResponse
    {
        $beneficiary->update($this->values($request));

        return redirect("/beneficiaries/{$beneficiary->id}")->with('status', __('registry.saved'));
    }

    public function archive(Beneficiary $beneficiary): RedirectResponse
    {
        return $this->archiveRecord($beneficiary);
    }

    public function restore(Beneficiary $beneficiary): RedirectResponse
    {
        return $this->restoreRecord($beneficiary);
    }

    /** Без правото IBAN не се пипа (нито се трие). */
    private function values(BeneficiaryRequest $request): array
    {
        $values = ['name' => $request->validated('name')];

        if ($request->user()->can_view_sensitive) {
            $values['iban'] = $request->validated('iban');
        }

        return $values;
    }
}
