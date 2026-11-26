<?php

namespace App\Http\Controllers\Contracts;

use App\Http\Controllers\Registry\RegistryController;
use App\Models\Concerns\WorksheetRules;
use App\Models\LeaseContract;
use App\Models\LedgerEntry;
use App\Models\Property;
use App\Models\Scopes\RegistryVisibilityScope;
use App\Models\Tenant;
use App\Rules\BgDateRule;
use App\Services\LeaseContractService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Екраните „Договори за наем“ (PROJECT.md, т. 2 „Договори“, „Договор за наем“,
 * „Поправки“). Правилата са в LeaseContractService и в модела.
 * „Промени“: информационните полета и прекратяването (винаги). „Нов анекс“:
 * промени от дадена дата с история. „Поправка“: наемател, имоти, дати, наем
 * и депозит на място, само докато няма начисления.
 */
class LeaseContractController extends RegistryController
{
    use ContractForm;

    public function __construct(private readonly LeaseContractService $leases) {}

    protected function section(): string
    {
        return 'lease-contracts';
    }

    protected function applySearch(Builder $query, string $term, string $likeTerm): void
    {
        if (ctype_digit($term)) {
            $query->orWhere('number', (int) $term)
                ->orWhereHas('lines.property', fn (Builder $q) => $q->where('number', (int) $term));
        }

        $query->orWhereHas('tenant', fn (Builder $q) => $q->where('name', 'like', $likeTerm));
    }

    public function index(Request $request): View
    {
        $query = $this->listQuery($request, LeaseContract::query()
            ->with(['tenant', 'lines' => fn ($q) => $q->orderBy('valid_from'), 'lines.property' => $this->anyProperty()])
            ->orderByDesc('number'));

        return view('contracts.lease.index', ['contracts' => $this->paginate($query)]);
    }

    public function create(Request $request): View
    {
        return view('contracts.lease.create', [
            'tenants' => Tenant::query()->active()->orderBy('name')->get(['id', 'number', 'name', 'kind']),
            'properties' => Property::query()->active()->orderBy('number')->get(),
            'lineCount' => max(1, count((array) $request->old('lines', []))),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$data, $errors] = $this->formInput($request, [
            'number' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'tenant_id' => ['nullable', 'integer'],
            'signed_on' => ['nullable', new BgDateRule],
            'end_on' => ['nullable', new BgDateRule],
            'note' => ['nullable', 'string', 'max:5000'],
            'lines' => ['nullable', 'array'],
            'lines.*.property_id' => ['nullable', 'integer'],
            'lines.*.start_on' => ['nullable', new BgDateRule],
            'lines.*.rent' => ['nullable', 'string', 'max:20'],
            'lines.*.deposit' => ['nullable', 'string', 'max:20'],
            'lines.*.first_period_rent' => ['nullable', 'string', 'max:20'],
        ]);

        // Празните редове (без имот) се пропускат.
        $data['lines'] = array_values(array_filter($data['lines'] ?? [], fn ($line) => ($line['property_id'] ?? null) !== null));

        $contract = $this->submit($errors, fn () => $this->leases->create($data));

        return $this->withManagementWarning(
            redirect("/lease-contracts/{$contract->id}")->with('status', __('registry.saved')), $contract);
    }

    public function show(LeaseContract $contract): View
    {
        $contract->load([
            'tenant',
            'annexes' => fn ($q) => $q->orderBy('number'),
            'lines' => fn ($q) => $q->orderBy('valid_from')->orderBy('id'),
            'lines.property' => $this->anyProperty(),
            'extensions' => fn ($q) => $q->orderBy('end_on'),
        ]);

        return view('contracts.lease.show', [
            'contract' => $contract,
            'annexNumbers' => $contract->annexes->pluck('number', 'id'),
            'withoutManagement' => $this->leases->propertiesWithoutManagement($contract),
            'entries' => LedgerEntry::query()->where('lease_contract_id', $contract->id)->orderBy('month')->orderBy('id')->get(),
            'owes' => app(\App\Services\Dossiers\Dossier::class)->leaseOwes($contract),
            'statements' => app(\App\Services\Dossiers\Dossier::class)->leaseStatements($contract),
            // Плащанията по договора (26.11.2026), най-новите първи.
            'payments' => \App\Models\Payment::query()->with(['bankAccount', 'createdBy', 'reversal'])->where('lease_contract_id', $contract->id)
                ->whereNull('reversal_of_id')->orderByDesc('paid_on')->orderByDesc('id')->get(),
            'canPay' => \App\Models\Concerns\WorksheetRules::canManage(auth()->user()),
            'canIssue' => WorksheetRules::canManage(auth()->user()) && ! $contract->isArchived(),
        ]);
    }

    public function edit(LeaseContract $contract): View
    {
        return view('contracts.lease.edit', [
            'contract' => $contract->load('tenant'),
            'endLocked' => $contract->extensions()->exists(),
        ]);
    }

    /** Информационните полета и прекратяването (PROJECT.md, „Поправки“). */
    public function update(Request $request, LeaseContract $contract): RedirectResponse
    {
        [$data, $errors] = $this->formInput($request, [
            'number' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'signed_on' => ['required', new BgDateRule],
            'end_on' => ['nullable', new BgDateRule],
            'terminated_on' => ['nullable', new BgDateRule],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->submit($errors, fn () => $this->updateHeader($contract, $this->headerValues($request, $data)));

        return $this->withIssuedDifferences(redirect("/lease-contracts/{$contract->id}")->with('status', __('registry.saved')), $contract);
    }

    public function archive(LeaseContract $contract): RedirectResponse
    {
        return $this->archiveRecord($contract);
    }

    public function restore(LeaseContract $contract): RedirectResponse
    {
        return $this->restoreRecord($contract);
    }

    /** Нов анекс: няколко промени наведнъж, от обща дата. */
    public function createAnnex(Request $request, LeaseContract $contract): View
    {
        $contract->load(['lines' => fn ($q) => $q->whereNull('valid_to')->orderBy('valid_from'), 'lines.property' => $this->anyProperty()]);

        return view('contracts.lease.annex', [
            'contract' => $contract,
            'nextNumber' => $this->leases->nextAnnexNumber($contract),
            'currentLines' => $contract->lines->filter(fn ($line) => $line->property !== null)->sortBy('property.number'),
            'properties' => Property::query()->active()->orderBy('number')->get(),
            'lineCount' => max(1, count((array) $request->old('lines', []))),
        ]);
    }

    public function storeAnnex(Request $request, LeaseContract $contract): RedirectResponse
    {
        [$data, $errors] = $this->formInput($request, [
            'number' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'signed_on' => ['nullable', new BgDateRule],
            'note' => ['nullable', 'string', 'max:5000'],
            'from' => ['nullable', new BgDateRule],
            'end_on' => ['nullable', new BgDateRule],
            'lines' => ['nullable', 'array'],
            'lines.*.action' => ['nullable', 'string'],
            'lines.*.property_id' => ['nullable', 'integer'],
            'lines.*.from' => ['nullable', new BgDateRule],
            'lines.*.rent' => ['nullable', 'string', 'max:20'],
            'lines.*.deposit' => ['nullable', 'string', 'max:20'],
            'lines.*.first_period_rent' => ['nullable', 'string', 'max:20'],
        ]);

        $data['lines'] = array_values(array_filter($data['lines'] ?? [], fn ($line) => ($line['property_id'] ?? null) !== null));

        $annex = $this->submit($errors, fn () => $this->leases->applyAnnex($contract, $data));

        return $this->withIssuedDifferences($this->withManagementWarning(redirect("/lease-contracts/{$contract->id}")
            ->with('status', __('contracts.screens.annex_saved', ['number' => $annex->number])), $contract), $contract);
    }

    /** Поправка на полетата, от които зависят сумите, докато няма начисления. */
    public function editCorrection(LeaseContract $contract): View
    {
        $contract->load([
            'lines' => fn ($q) => $q->orderBy('valid_from')->orderBy('id'),
            'lines.property' => $this->anyProperty(),
            'annexes',
        ]);

        return view('contracts.lease.correct', [
            'contract' => $contract,
            // Договореният наем за първия период е само в първия вариант на всеки имот.
            'firstLineIds' => $contract->lines->groupBy('property_id')->map(fn ($lines) => $lines->first()->id)->values(),
            'annexNumbers' => $contract->annexes->pluck('number', 'id'),
            'tenants' => Tenant::query()->active()->orderBy('name')->get(['id', 'number', 'name', 'kind']),
            'properties' => Property::query()->active()->orderBy('number')->get(),
        ]);
    }

    public function updateCorrection(Request $request, LeaseContract $contract): RedirectResponse
    {
        [$data, $errors] = $this->formInput($request, [
            'tenant_id' => ['nullable', 'integer'],
            'lines' => ['nullable', 'array'],
            'lines.*.property_id' => ['nullable', 'integer'],
            'lines.*.valid_from' => ['nullable', new BgDateRule],
            'lines.*.valid_to' => ['nullable', new BgDateRule],
            'lines.*.rent' => ['nullable', 'string', 'max:20'],
            'lines.*.deposit' => ['nullable', 'string', 'max:20'],
            'lines.*.first_period_rent' => ['nullable', 'string', 'max:20'],
        ]);

        // Наемателят се пипа само ако е във формата.
        if (! $request->exists('tenant_id')) {
            unset($data['tenant_id']);
        } else {
            $data['tenant_id'] ??= null;
        }

        $this->submit($errors, fn () => $this->leases->correct($contract, $data));

        return $this->withManagementWarning(
            redirect("/lease-contracts/{$contract->id}")->with('status', __('registry.saved')), $contract);
    }

    /** Целият договор се вижда, с номерата на всичките му имоти (PROJECT.md, „Обхват и договори“). */
    private function anyProperty(): \Closure
    {
        return fn ($q) => $q->withoutGlobalScope(RegistryVisibilityScope::class);
    }

    /** Имот без договор за управление за същия период: записва се, с предупреждение (PROJECT.md, „Договор за наем“). */
    private function withManagementWarning(RedirectResponse $redirect, LeaseContract $contract): RedirectResponse
    {
        $missing = $this->leases->propertiesWithoutManagement($contract->fresh());

        if ($missing !== []) {
            $redirect->with('warning', __('contracts.screens.no_management_warning', [
                'properties' => implode(', ', array_map(fn ($number) => __('contracts.screens.property_number', ['number' => $number]), $missing)),
            ]));
        }

        return $redirect;
    }
}
