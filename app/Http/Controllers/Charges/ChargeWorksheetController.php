<?php

namespace App\Http\Controllers\Charges;

use App\Enums\ChargeType;
use App\Http\Controllers\Controller;
use App\Models\ChargeDraft;
use App\Models\ChargeMonth;
use App\Models\Concerns\WorksheetRules;
use App\Models\LeaseContract;
use App\Models\OwnerContract;
use App\Models\Property;
use App\Services\Charges\Cents;
use App\Services\Charges\MonthWorksheet;
use App\Services\Charges\Month;
use App\Services\Charges\WorksheetWarning;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Екранът „Начисления“: работната таблица на месеца (PROJECT.md, т. 2
 * „Работна таблица на месеца“). Една сметка е за един договор за наем, затова
 * редовете към наемателите са по договор. Правилата са в MonthWorksheet.
 */
class ChargeWorksheetController extends Controller
{
    public function __construct(private readonly MonthWorksheet $sheet) {}

    public function index(Request $request): View|RedirectResponse
    {
        if (! $request->filled('month')) {
            // Наемът се начислява предварително: по подразбиране следващият месец.
            return redirect('/charges?month='.Month::key(CarbonImmutable::now()->startOfMonth()->addMonth()));
        }

        $month = Month::tryParse($request->query('month')) ?? abort(404);
        $key = Month::key($month);

        $rows = $this->sheet->rows($key);
        $warnings = $this->sheet->warnings($key);
        $flagged = $this->flagged($warnings, $rows);

        $shown = $this->filter($rows, trim((string) $request->query('q', '')), (string) $request->query('filter', ''), $flagged);
        $active = $rows->whereNull('removed_at');
        // Приход: наемът, неустойките и „Друго“ минус дължимото към собственика; депозитът не е приход (PROJECT.md, „Дял на собственика“).
        $rent = fn (Collection $rows) => $rows->filter(fn ($row) => in_array($row->type, [ChargeType::Rent, ChargeType::Penalty, ChargeType::Other], true))
            ->sum(fn ($row) => Cents::from($row->amount));
        $owner = fn (Collection $rows) => $rows->where('type', ChargeType::Owner)->sum(fn ($row) => Cents::from($row->amount));

        return view('charges.index', [
            'month' => $month,
            'key' => $key,
            'previous' => Month::key($month->subMonth()),
            'next' => Month::key($month->addMonth()),
            'stamp' => ChargeMonth::query()->with('generatedBy')->whereDate('month', $month)->first(),
            'warnings' => $warnings,
            'flagged' => $flagged,
            'tenantGroups' => $shown->filter(fn ($row) => $row->type->forTenant())->groupBy('lease_contract_id'),
            'ownerGroups' => $shown->where('type', ChargeType::Owner)->groupBy('owner_contract_id'),
            'rentByProperty' => $active->where('type', ChargeType::Rent)->groupBy('property_id')
                ->map(fn ($rows) => Cents::format($rows->sum(fn ($row) => Cents::from($row->amount)))),
            'forDecision' => $this->forDecision($key),
            'totals' => [
                'tenants' => Cents::format($active->filter(fn ($row) => $row->type->forTenant())->sum(fn ($row) => Cents::from($row->amount))),
                'owners' => Cents::format($owner($active)),
                'revenue' => Cents::format($rent($active) - $owner($active)),
                'contracts' => $active->whereNotNull('lease_contract_id')->pluck('lease_contract_id')->unique()->count(),
            ],
            'canManage' => WorksheetRules::canManage($request->user()),
            'q' => trim((string) $request->query('q', '')),
            'filter' => (string) $request->query('filter', ''),
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $month = Month::parse($request->input('month'));
        $this->sheet->generate(Month::key($month));

        return redirect('/charges?month='.Month::key($month))->with('status', __('charges.screens.generated_ok'));
    }

    public function create(Request $request): View
    {
        $month = Month::tryParse($request->query('month')) ?? abort(404);

        return view('charges.form', [
            'row' => null,
            'month' => $month,
            'types' => ChargeType::manual(),
            'leases' => LeaseContract::query()->active()->with('tenant')->orderByDesc('number')->get(),
            'managements' => OwnerContract::query()->active()->with('owner')->orderByDesc('number')->get(),
            'properties' => Property::query()->active()->orderBy('number')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $month = Month::parse($request->input('month'));
        $this->sheet->addManual(Month::key($month), $request->only(['type', 'lease_contract_id', 'owner_contract_id', 'property_id', 'amount', 'note', 'reason']));

        return redirect('/charges?month='.Month::key($month))->with('status', __('charges.screens.saved'));
    }

    public function edit(ChargeDraft $charge): View
    {
        return view('charges.form', [
            'row' => $charge->load(['leaseContract.tenant', 'ownerContract.owner', 'property']),
            'month' => $charge->month,
        ]);
    }

    public function update(Request $request, ChargeDraft $charge): RedirectResponse
    {
        $this->sheet->change($charge, $request->only(['amount', 'note', 'reason']));

        return $this->back($charge, 'charges.screens.saved');
    }

    public function remove(Request $request, ChargeDraft $charge): RedirectResponse
    {
        $this->sheet->remove($charge, $request->input('reason'));

        return $this->back($charge, 'charges.screens.removed_ok');
    }

    public function restore(Request $request, ChargeDraft $charge): RedirectResponse
    {
        $this->sheet->restore($charge, $request->input('reason'));

        return $this->back($charge, 'charges.screens.restored_ok');
    }

    private function back(ChargeDraft $charge, string $message): RedirectResponse
    {
        return redirect('/charges?month='.Month::key($charge->month))->with('status', __($message));
    }

    /**
     * Кои редове имат предупреждение: по номера на реда или по договор и имот.
     *
     * @param  list<WorksheetWarning>  $warnings
     * @return array<int, list<string>> ред → текстовете
     */
    private function flagged(array $warnings, Collection $rows): array
    {
        $flagged = [];

        foreach ($warnings as $warning) {
            foreach ($rows as $row) {
                $match = $warning->chargeDraftId !== null
                    ? $warning->chargeDraftId === $row->id
                    : $warning->leaseContractId === $row->lease_contract_id && $warning->propertyId === $row->property_id;

                if ($match) {
                    $flagged[$row->id][] = $warning->message;
                }
            }
        }

        return $flagged;
    }

    /** Търсене (наемател, собственик, № на имот) и филтър („само с предупреждение“, „само ръчни“). */
    private function filter(Collection $rows, string $q, string $filter, array $flagged): Collection
    {
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $rows = $rows->filter(fn (ChargeDraft $row) => (ctype_digit($q) && (int) $q === $row->property?->number)
                || str_contains(mb_strtolower((string) $row->tenant?->name), $needle)
                || str_contains(mb_strtolower((string) $row->owner?->name), $needle));
        }

        return match ($filter) {
            'warnings' => $rows->filter(fn (ChargeDraft $row) => isset($flagged[$row->id])),
            'manual' => $rows->filter(fn (ChargeDraft $row) => $row->isManual()),
            default => $rows,
        };
    }

    /** „За решение“ с наемателя и имота за показване. */
    private function forDecision(string $key): Collection
    {
        return collect($this->sheet->forDecision($key))->map(fn ($item) => [
            'item' => $item,
            'lease' => LeaseContract::query()->with('tenant')->find($item->leaseContractId),
            'property' => Property::query()->find($item->propertyId),
        ]);
    }
}
