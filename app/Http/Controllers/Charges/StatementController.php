<?php

namespace App\Http\Controllers\Charges;

use App\Http\Controllers\Controller;
use App\Models\Statement;
use App\Services\Charges\Month;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Известията за дължими суми (не фактури): списък по месец и едно известие. PDF и имейл са в част 4. */
class StatementController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (! $request->filled('month')) {
            return redirect('/statements?month='.Month::key(CarbonImmutable::now()->startOfMonth()));
        }

        $month = Month::tryParse($request->query('month')) ?? abort(404);

        return view('charges.statements.index', [
            'month' => $month,
            'previous' => Month::key($month->subMonth()),
            'next' => Month::key($month->addMonth()),
            'statements' => Statement::query()->with(['leaseContract', 'tenant'])->whereDate('month', $month)->orderBy('number')->get(),
        ]);
    }

    public function show(Statement $statement): View
    {
        return view('charges.statements.show', [
            'statement' => $statement->load(['leaseContract', 'tenant', 'issuedBy', 'lines.property', 'lines.paidUtilityBill', 'lines.utilityAccount.provider']),
        ]);
    }
}
