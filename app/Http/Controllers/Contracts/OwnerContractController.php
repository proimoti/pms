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
use App\Rules\BgDateRule;
use App\Services\OwnerContractService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Екраните „Договори за управление“ (PROJECT.md, т. 2 „Договори“, „Поправки“).
 *
 * Правилата са в OwnerContractService и в модела; тук е само формата.
 * „Промени“: информационните полета и прекратяването (винаги). „Нов анекс“:
 * промени от дадена дата с история. „Поправка“: полетата, от които зависят
 * сумите, на място, само докато няма начисления.
 */
class OwnerContractController extends RegistryController
{
    use ContractForm;

    /** Редове за имоти при отваряне на формата; бутонът „Добави още имоти“ добавя още. */
    private const EMPTY_LINES = 1;

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
            'beneficiaries' => Beneficiary::query()->active()->orderBy('name')->get(['id', 'name', 'owner_id']),
            'properties' => Property::query()->active()->orderBy('number')->get(),
            'lineCount' => max(self::EMPTY_LINES, count((array) $request->old('lines', []))),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$data, $errors] = $this->formInput($request, [
            'type' => ['nullable', 'string'],
            'number' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'owner_id' => ['nullable', 'integer'],
            'signed_on' => ['nullable', new BgDateRule],
            'end_on' => ['nullable', new BgDateRule],
            'note' => ['nullable', 'string', 'max:5000'],
            'commission_percent' => ['nullable', 'string', 'max:10'],
            'payment_method' => ['nullable', 'string'],
            'beneficiary_id' => ['nullable', 'integer'],
            'lines' => ['nullable', 'array'],
            'lines.*.property_id' => ['nullable', 'integer'],
            'lines.*.start_on' => ['nullable', new BgDateRule],
            'lines.*.fixed_amount' => ['nullable', 'string', 'max:20'],
        ]);

        // Празните редове (без имот) се пропускат.
        $data['lines'] = array_values(array_filter($data['lines'] ?? [], fn ($line) => ($line['property_id'] ?? null) !== null));

        $contract = $this->submit($errors, fn () => $this->contracts->create($data));

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
        [$data, $errors] = $this->formInput($request, [
            'number' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'signed_on' => ['required', new BgDateRule],
            'end_on' => ['nullable', new BgDateRule],
            'terminated_on' => ['nullable', new BgDateRule],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->submit($errors, fn () => $this->updateHeader($contract, $this->headerValues($request, $data)));

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

    /** Нов анекс: няколко промени наведнъж, от обща дата. */
    public function createAnnex(Request $request, OwnerContract $contract): View
    {
        $contract->load(['lines' => fn ($q) => $q->whereNull('valid_to')->orderBy('valid_from'), 'lines.property' => $this->anyProperty()]);

        return view('contracts.owner.annex', [
            'contract' => $contract,
            'nextNumber' => $this->contracts->nextAnnexNumber($contract),
            'currentProperties' => $contract->lines->pluck('property')->filter()->sortBy('number'),
            'properties' => Property::query()->active()->orderBy('number')->get(),
            'beneficiaries' => Beneficiary::query()->active()->orderBy('name')->get(['id', 'name', 'owner_id']),
            'methods' => PaymentMethod::cases(),
            'lineCount' => max(1, count((array) $request->old('lines', []))),
        ]);
    }

    public function storeAnnex(Request $request, OwnerContract $contract): RedirectResponse
    {
        [$data, $errors] = $this->formInput($request, [
            'number' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'signed_on' => ['nullable', new BgDateRule],
            'note' => ['nullable', 'string', 'max:5000'],
            'from' => ['nullable', new BgDateRule],
            'commission_percent' => ['nullable', 'string', 'max:10'],
            'change_payee' => ['nullable', 'boolean'],
            'payee.beneficiary_id' => ['nullable', 'integer'],
            'payee.payment_method' => ['nullable', 'string'],
            'end_on' => ['nullable', new BgDateRule],
            'lines' => ['nullable', 'array'],
            'lines.*.action' => ['nullable', 'string'],
            'lines.*.property_id' => ['nullable', 'integer'],
            'lines.*.from' => ['nullable', new BgDateRule],
            'lines.*.fixed_amount' => ['nullable', 'string', 'max:20'],
        ]);

        // Бенефициентът се сменя само ако е отметнато; празните редове се пропускат.
        $data['payee'] = $request->boolean('change_payee') ? ($data['payee'] ?? []) : null;
        $data['lines'] = array_values(array_filter($data['lines'] ?? [], fn ($line) => ($line['property_id'] ?? null) !== null));

        $annex = $this->submit($errors, fn () => $this->contracts->applyAnnex($contract, $data));

        $redirect = redirect("/owner-contracts/{$contract->id}")
            ->with('status', __('contracts.screens.annex_saved', ['number' => $annex->number]));

        return $data['payee'] !== null ? $this->withIbanWarning($redirect, $contract) : $redirect;
    }

    /** Поправка на полетата, от които зависят сумите, докато няма начисления. */
    public function editCorrection(OwnerContract $contract): View
    {
        $contract->load([
            'lines' => fn ($q) => $q->orderBy('valid_from')->orderBy('id'),
            'lines.property' => $this->anyProperty(),
            'commissions' => fn ($q) => $q->orderBy('valid_from'),
            'payees' => fn ($q) => $q->orderBy('valid_from'),
            'payees.beneficiary',
            'annexes',
        ]);

        return view('contracts.owner.correct', [
            'contract' => $contract,
            'annexNumbers' => $contract->annexes->pluck('number', 'id'),
            'owners' => Owner::query()->active()->orderBy('name')->get(['id', 'number', 'name', 'kind']),
            'properties' => Property::query()->active()->orderBy('number')->get(),
            'beneficiaries' => Beneficiary::query()->active()->orderBy('name')->get(['id', 'name', 'owner_id']),
            'methods' => PaymentMethod::cases(),
        ]);
    }

    public function updateCorrection(Request $request, OwnerContract $contract): RedirectResponse
    {
        [$data, $errors] = $this->formInput($request, [
            'owner_id' => ['nullable', 'integer'],
            'lines' => ['nullable', 'array'],
            'lines.*.property_id' => ['nullable', 'integer'],
            'lines.*.valid_from' => ['nullable', new BgDateRule],
            'lines.*.valid_to' => ['nullable', new BgDateRule],
            'lines.*.fixed_amount' => ['nullable', 'string', 'max:20'],
            'commissions' => ['nullable', 'array'],
            'commissions.*' => ['nullable', 'string', 'max:10'],
            'payees' => ['nullable', 'array'],
            'payees.*.beneficiary_id' => ['nullable', 'integer'],
            'payees.*.payment_method' => ['nullable', 'string'],
        ]);

        // Собственикът се пипа само ако е във формата.
        if (! $request->exists('owner_id')) {
            unset($data['owner_id']);
        } else {
            $data['owner_id'] ??= null;
        }

        $this->submit($errors, fn () => $this->contracts->correct($contract, $data));

        return $this->withIbanWarning(
            redirect("/owner-contracts/{$contract->id}")->with('status', __('registry.saved')),
            $contract,
        );
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
