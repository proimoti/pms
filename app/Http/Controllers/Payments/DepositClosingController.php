<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\DepositClosing;
use App\Models\LeaseContract;
use App\Services\Dossiers\Dossier;
use App\Services\Payments\DepositClosingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Приключване на депозит от картата на договора за наем (PROJECT.md,
 * „Приключване на депозит“, 26.11.2026). Само управляващият начисленията
 * (пазачът „manage“ на пътя); договор или приключване на друга фирма е 404.
 */
class DepositClosingController extends Controller
{
    public function __construct(private readonly DepositClosingService $closings) {}

    public function create(LeaseContract $contract, Dossier $dossier): View
    {
        $contract->load(['tenant', 'lines.property']);
        $held = $this->closings->held($contract);
        $properties = $contract->lines->pluck('property')->filter()->unique('id')->sortBy('number')
            ->filter(fn ($property) => ($held[$property->id] ?? 0) !== 0)->values();

        return view('deposits.create', [
            'contract' => $contract,
            'properties' => $properties,
            'held' => $held,
            'owes' => $dossier->leaseOwes($contract),
            'accounts' => BankAccount::query()->whereNull('archived_at')->orderByDesc('for_statements')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request, LeaseContract $contract): RedirectResponse
    {
        $this->closings->record($contract, $request->all());

        return redirect('/lease-contracts/'.$contract->id)->with('status', __('deposits.saved'));
    }

    public function reverse(Request $request, DepositClosing $closing): RedirectResponse
    {
        $this->closings->reverse($closing, $request->input('reason'));

        return redirect('/lease-contracts/'.$closing->lease_contract_id)->with('status', __('deposits.reversed_status'));
    }
}
