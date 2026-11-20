<?php

namespace App\Http\Controllers\Charges;

use App\Enums\ChargeType;
use App\Http\Controllers\Controller;
use App\Models\Concerns\WorksheetRules;
use App\Models\LeaseContract;
use App\Models\Property;
use App\Models\UpcomingCharge;
use App\Services\Charges\UpcomingChargeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Екранът „Предстоящи начисления“ (PROJECT.md, т. 2 „Начисления“): депозити,
 * неустойки и други суми за своя месец. При депозит се вижда колко остава до
 * договорения размер. Не се трият: отказват се с причина.
 */
class UpcomingChargeController extends Controller
{
    public function __construct(private readonly UpcomingChargeService $upcoming) {}

    public function index(Request $request): View
    {
        $charges = UpcomingCharge::query()
            ->with(['leaseContract.tenant', 'property'])
            ->when(! $request->boolean('cancelled'), fn ($query) => $query->whereNull('cancelled_at'))
            ->orderBy('month')->orderBy('id')
            ->get();

        $deposits = $charges->where('type', ChargeType::Deposit)
            ->unique(fn ($charge) => $charge->lease_contract_id.'-'.$charge->property_id)
            ->mapWithKeys(fn ($charge) => [
                $charge->lease_contract_id.'-'.$charge->property_id => $this->upcoming->deposit($charge->leaseContract, $charge->property_id),
            ]);

        return view('charges.upcoming.index', [
            'charges' => $charges,
            'deposits' => $deposits,
            'showCancelled' => $request->boolean('cancelled'),
            'canManage' => WorksheetRules::canManage($request->user()),
        ]);
    }

    public function create(): View
    {
        return view('charges.upcoming.form', ['upcoming' => null] + $this->choices());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->upcoming->create($request->only(['lease_contract_id', 'property_id', 'type', 'month', 'amount', 'note']));

        return redirect('/upcoming-charges')->with('status', __('charges.screens.saved'));
    }

    public function edit(UpcomingCharge $upcoming): View
    {
        return view('charges.upcoming.form', ['upcoming' => $upcoming->load(['leaseContract.tenant', 'property'])] + $this->choices());
    }

    public function update(Request $request, UpcomingCharge $upcoming): RedirectResponse
    {
        $this->upcoming->update($upcoming, $request->only(['month', 'amount', 'note']));

        return redirect('/upcoming-charges')->with('status', __('charges.screens.saved'));
    }

    public function cancel(Request $request, UpcomingCharge $upcoming): RedirectResponse
    {
        $this->upcoming->cancel($upcoming, $request->input('reason'));

        return redirect('/upcoming-charges')->with('status', __('charges.upcoming.cancelled_ok'));
    }

    private function choices(): array
    {
        return [
            'types' => ChargeType::upcoming(),
            'leases' => LeaseContract::query()->active()->with('tenant')->orderByDesc('number')->get(),
            'properties' => Property::query()->active()->orderBy('number')->get(),
        ];
    }
}
