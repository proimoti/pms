<?php

namespace App\Http\Controllers\Charges;

use App\Enums\ChargeType;
use App\Http\Controllers\Controller;
use App\Models\LeaseContract;
use App\Models\OwnerContract;
use App\Models\Property;
use App\Services\Charges\Month;
use App\Services\Charges\Reconciliation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Корекции за издаден месец (PROJECT.md, „Промяна в издаден месец“): по
 * разлика спрямо договорите или ръчно, винаги с причина и за същия месец.
 */
class CorrectionController extends Controller
{
    public function __construct(private readonly Reconciliation $reconciliation) {}

    public function correct(Request $request): RedirectResponse
    {
        $month = Month::parse($request->input('month'));
        $this->reconciliation->correct(Month::key($month), (string) $request->input('key'), $request->input('reason'));

        return redirect('/charges?month='.Month::key($month))->with('status', __('charges.issue.correction_ok'));
    }

    public function create(Request $request): View
    {
        $month = Month::tryParse($request->query('month')) ?? abort(404);

        return view('charges.correction', [
            'month' => $month,
            'types' => ChargeType::manual(),
            'leases' => LeaseContract::query()->with('tenant')->orderByDesc('number')->get(),
            'managements' => OwnerContract::query()->with('owner')->orderByDesc('number')->get(),
            'properties' => Property::query()->orderBy('number')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $month = Month::parse($request->input('month'));
        $this->reconciliation->manual(Month::key($month),
            $request->only(['type', 'lease_contract_id', 'owner_contract_id', 'property_id', 'amount', 'reason']));

        return redirect('/charges?month='.Month::key($month))->with('status', __('charges.issue.correction_ok'));
    }
}
