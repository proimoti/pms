<?php

namespace App\Http\Controllers\Registry;

use App\Http\Requests\Registry\PartyRequest;
use App\Models\Base\Party;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Общо за екраните „Собственици“ и „Наематели“.
 * Имената не са уникални: при подобно име първо се показва предупреждение.
 */
abstract class PartyController extends RegistryController
{
    /** @return class-string<Party> */
    abstract protected function modelClass(): string;

    protected function applySearch(Builder $query, string $term, string $likeTerm): void
    {
        if (ctype_digit($term)) {
            $query->orWhere('number', (int) $term)->orWhere('eik', $term);
        }

        foreach (['name', 'phone', 'email', 'city'] as $column) {
            $query->orWhere($column, 'like', $likeTerm);
        }
    }

    public function index(Request $request): View
    {
        // По име (т. 2.3 и 2.5, 24.11.2026), с номерата на договорите.
        $parties = $this->paginate($this->listQuery($request, $this->modelClass()::query()->orderBy('name')->orderBy('number')));
        $columns = app(\App\Services\Registry\ListColumns::class);
        $ids = $parties->getCollection()->pluck('id');

        return view('registry.parties.index', [
            'parties' => $parties,
            'section' => $this->section(),
            'contracts' => $this->section() === 'owners' ? $columns->ownerContracts($ids) : $columns->currentLeases($ids),
        ]);
    }

    public function create(): View
    {
        $class = $this->modelClass();

        return view('registry.parties.form', ['party' => new $class, 'section' => $this->section()]);
    }

    public function store(PartyRequest $request): Response
    {
        $data = $request->validated();
        $class = $this->modelClass();

        if (! $request->boolean('confirm_similar_name')) {
            $similar = $class::query()->where('name', Party::normalizeName($data['name']))
                ->get(['id', 'number', 'name'])->toArray();

            if ($similar !== []) {
                return back()->withInput()->with('similar_names', $similar);
            }
        }

        $party = $class::query()->create($data);

        return $this->created($request, $party,
            '№ '.$party->number.' '.$party->name.' ('.__('registry.kinds.'.$party->kind->value).')');
    }

    protected function showRecord(Party $party): View
    {
        $dossier = app(\App\Services\Dossiers\Dossier::class);
        $data = ['party' => $party, 'section' => $this->section()];

        // Досието (PROJECT.md, „Досиета и първи справки“, 23.11.2026).
        if ($party instanceof \App\Models\Owner) {
            $contracts = $dossier->ownerContracts($party);
            $data += [
                'contracts' => $contracts,
                'properties' => $dossier->contractProperties($contracts),
                'annexes' => $dossier->annexes($contracts),
                'beneficiaries' => $dossier->ownerBeneficiaries($party),
            ];
        } elseif ($party instanceof \App\Models\Tenant) {
            $leases = $dossier->tenantLeases($party);
            $data += [
                'leases' => $leases,
                'owes' => $dossier->tenantOwes($party),
                'deposits' => $dossier->tenantDeposits($leases),
            ];
        }

        return view('registry.parties.show', $data);
    }

    protected function editRecord(Party $party): View
    {
        return view('registry.parties.form', ['party' => $party, 'section' => $this->section()]);
    }

    protected function updateRecord(PartyRequest $request, Party $party): RedirectResponse
    {
        $party->update($request->validated());

        return redirect("/{$this->section()}/{$party->id}")->with('status', __('registry.saved'));
    }
}
