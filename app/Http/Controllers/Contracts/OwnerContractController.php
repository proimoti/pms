<?php

namespace App\Http\Controllers\Contracts;

use App\Enums\OwnerContractType;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Registry\RegistryController;
use App\Models\Beneficiary;
use App\Models\Owner;
use App\Models\OwnerContract;
use App\Models\Property;
use App\Models\Scopes\RegistryVisibilityScope;
use App\Services\OwnerContractService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Екраните „Договори за управление“ (PROJECT.md, т. 2 „Договори“, „Поправки“).
 *
 * Правилата са в OwnerContractService и в модела; тук е само формата. Тук се
 * поправят информационните полета и се прекратява договорът. Полетата, от
 * които зависят сумите, се сменят с анекс (следваща стъпка).
 */
class OwnerContractController extends RegistryController
{
    /** Празни редове за имоти в нов договор. */
    private const EMPTY_LINES = 5;

    public function __construct(private readonly OwnerContractService $contracts) {}

    protected function section(): string
    {
        return 'owner-contracts';
    }

    protected function applySearch(Builder $query, string $term, string $likeTerm): void
    {
        if (ctype_digit($term)) {
            $query->orWhere('number', (int) $term)
                ->orWhereHas('lines.property', fn (Builder $q) => $q->where('number', (int) $term));
        }

        $query->orWhereHas('owner', fn (Builder $q) => $q->where('name', 'like', $likeTerm));
    }

    public function index(Request $request): View
    {
        $query = $this->listQuery($request, OwnerContract::query()
            ->with(['owner', 'lines' => fn ($q) => $q->orderBy('valid_from'), 'lines.property' => $this->anyProperty()])
            ->orderByDesc('number'));

        return view('contracts.owner.index', ['contracts' => $this->paginate($query)]);
    }

    public function create(Request $request): View
    {
        return view('contracts.owner.create', [
            'types' => OwnerContractType::cases(),
            'methods' => PaymentMethod::cases(),
            'owners' => Owner::query()->active()->orderBy('name')->get(['id', 'number', 'name', 'kind']),
            'beneficiaries' => Beneficiary::query()->active()->orderBy('name')->get(['id', 'name']),
            'properties' => Property::query()->active()->orderBy('number')->get(),
            'lineCount' => max(self::EMPTY_LINES, count((array) $request->old('lines', []))),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['nullable', 'string'],
            'number' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'owner_id' => ['nullable', 'integer'],
            'signed_on' => ['nullable', 'date'],
            'end_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:5000'],
            'commission_percent' => ['nullable', 'string', 'max:10'],
            'payment_method' => ['nullable', 'string'],
            'beneficiary_id' => ['nullable', 'integer'],
            'lines' => ['nullable', 'array'],
            'lines.*.property_id' => ['nullable', 'integer'],
            'lines.*.start_on' => ['nullable', 'date'],
            'lines.*.fixed_amount' => ['nullable', 'string', 'max:20'],
        ]);

        // Празните редове (без имот) се пропускат.
        $data['lines'] = array_values(array_filter($data['lines'] ?? [], fn ($line) => ($line['property_id'] ?? null) !== null));

        $contract = $this->contracts->create($data);

        $redirect = redirect("/owner-contracts/{$contract->id}")->with('status', __('registry.saved'));

        return $this->withIbanWarning($redirect, $contract);
    }

    public function show(OwnerContract $contract): View
    {
        $contract->load([
            'owner',
            'annexes' => fn ($q) => $q->orderBy('number'),
            'lines' => fn ($q) => $q->orderBy('valid_from')->orderBy('id'),
            'lines.property' => $this->anyProperty(),
            'commissions' => fn ($q) => $q->orderBy('valid_from'),
            'payees' => fn ($q) => $q->orderBy('valid_from'),
            'payees.beneficiary',
            'extensions' => fn ($q) => $q->orderBy('end_on'),
        ]);

        return view('contracts.owner.show', [
            'contract' => $contract,
            'annexNumbers' => $contract->annexes->pluck('number', 'id'),
        ]);
    }

    public function edit(OwnerContract $contract): View
    {
        return view('contracts.owner.edit', [
            'contract' => $contract,
            'endLocked' => $contract->extensions()->exists(),
        ]);
    }

    /** Информационните полета и прекратяването (PROJECT.md, „Поправки“). */
    public function update(Request $request, OwnerContract $contract): RedirectResponse
    {
        $data = $request->validate([
            'number' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'signed_on' => ['required', 'date'],
            'end_on' => ['nullable', 'date'],
            'terminated_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        // Поле, което не е изпратено (например заключеният срок), не се пипа.
        $values = [];
        foreach (['number', 'signed_on', 'end_on', 'terminated_on', 'note'] as $field) {
            if ($request->exists($field)) {
                $values[$field] = $data[$field] ?? null;
            }
        }

        $contract->update($values);

        return redirect("/owner-contracts/{$contract->id}")->with('status', __('registry.saved'));
    }

    public function archive(OwnerContract $contract): RedirectResponse
    {
        return $this->archiveRecord($contract);
    }

    public function restore(OwnerContract $contract): RedirectResponse
    {
        return $this->restoreRecord($contract);
    }

    /**
     * Договорът се вижда целият (PROJECT.md, „Обхват и договори“): номерът и
     * адресът на имот в него се показват, дори имотът да не е прикачен.
     */
    private function anyProperty(): \Closure
    {
        return fn ($q) => $q->withoutGlobalScope(RegistryVisibilityScope::class);
    }

    /** Плащане по банка без IBAN: записва се, но с предупреждение (PROJECT.md, 20.11.2026). */
    private function withIbanWarning(RedirectResponse $redirect, OwnerContract $contract): RedirectResponse
    {
        $payee = $contract->payees()->whereNull('valid_to')->with('beneficiary')->first();

        if ($payee?->payment_method === PaymentMethod::Bank && $payee->beneficiary?->getRawOriginal('iban') === null) {
            $redirect->with('warning', __('contracts.screens.no_iban_warning', ['name' => $payee->beneficiary->name]));
        }

        return $redirect;
    }
}
