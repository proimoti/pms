<?php

namespace App\Http\Controllers\Utilities;

use App\Http\Controllers\Controller;
use App\Models\Concerns\WorksheetRules;
use App\Models\LeaseContract;
use App\Models\PaidUtilityBill;
use App\Models\Property;
use App\Models\Scopes\RegistryVisibilityScope;
use App\Models\UtilityDecision;
use App\Services\Utilities\UnallocatedUtilities;
use App\Services\Utilities\UtilityDecisions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Екранът „Неразпределени битови сметки“ (PROJECT.md, част 5, 23.11.2026):
 * основен списък с решенията, „Чакат месеца“ без бутони, взетите решения с
 * отмяна. Вижда всеки служител (със своите имоти); решава само „четене и
 * запис“ + „всички имоти“.
 */
class UnallocatedController extends Controller
{
    public function __construct(
        private readonly UnallocatedUtilities $unallocated,
        private readonly UtilityDecisions $decisions,
    ) {}

    public function index(Request $request): View
    {
        $items = $this->unallocated->items();
        $all = array_merge($items['decide'], $items['waiting']);
        $leaseIds = array_merge([], ...array_map(fn ($item) => $item->nextLeases, $all));

        $decisions = UtilityDecision::query()->whereNull('cancelled_at')
            ->with(['bill', 'property', 'leaseContract.tenant', 'decidedBy'])->orderByDesc('id')->limit(100)->get();

        return view('utilities.unallocated.index', [
            'decide' => $items['decide'],
            'waiting' => $items['waiting'],
            'decisions' => $decisions,
            'cancellable' => $decisions->mapWithKeys(fn ($d) => [$d->id => $this->decisions->cancellable($d)]),
            'properties' => Property::query()->whereIn('id', array_map(fn ($item) => $item->propertyId, $all))->get()->keyBy('id'),
            'leases' => LeaseContract::query()->with('tenant')->whereIn('id', $leaseIds)->get()->keyBy('id'),
            'canManage' => WorksheetRules::canManage($request->user()),
        ]);
    }

    public function toManager(Request $request): RedirectResponse
    {
        $this->decisions->toManager($this->bill($request), (int) $request->input('property'), $request->input('reason'));

        return redirect('/utility-unallocated')->with('status', __('utilities.unallocated.decided'));
    }

    public function toNextTenant(Request $request): RedirectResponse
    {
        $lease = LeaseContract::query()->find((int) $request->input('lease_contract_id'))
            ?? throw ValidationException::withMessages(['lease_contract_id' => __('utilities.unallocated.errors.not_next_lease')]);
        $this->decisions->toNextTenant($this->bill($request), (int) $request->input('property'), $lease, $request->input('reason'));

        return redirect('/utility-unallocated')->with('status', __('utilities.unallocated.decided'));
    }

    public function cancel(Request $request, UtilityDecision $decision): RedirectResponse
    {
        $this->decisions->cancel($decision, $request->input('reason'));

        return redirect('/utility-unallocated')->with('status', __('utilities.unallocated.cancelled'));
    }

    /** Сметката от формата; чужда или непозната — „не иска решение“. */
    private function bill(Request $request): PaidUtilityBill
    {
        return PaidUtilityBill::query()->withoutGlobalScope(RegistryVisibilityScope::class)->find((int) $request->input('bill'))
            ?? throw ValidationException::withMessages(['decision' => __('utilities.unallocated.errors.not_for_decision')]);
    }
}
