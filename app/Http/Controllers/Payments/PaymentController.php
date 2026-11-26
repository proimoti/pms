<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\LeaseContract;
use App\Models\Payment;
use App\Services\Dossiers\Dossier;
use App\Services\Payments\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Плащане от наемател и сторно (PROJECT.md, „Плащания и салда“, 26.11.2026).
 * Само управляващият начисленията (в адресите). Договор или плащане на друга
 * фирма — 404.
 */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function create(LeaseContract $contract, Dossier $dossier): View
    {
        $accounts = BankAccount::query()->whereNull('archived_at')->orderByDesc('for_statements')->orderBy('id')->get();

        return view('payments.create', ['contract' => $contract->load('tenant'), 'accounts' => $accounts, 'owes' => $dossier->leaseOwes($contract)]);
    }

    public function store(Request $request, LeaseContract $contract): RedirectResponse
    {
        $payment = $this->payments->record($contract, $request->only(['amount', 'paid_on', 'method', 'bank_account_id', 'reference', 'note']));

        return redirect('/lease-contracts/'.$contract->id)->with('status', __('payments.saved', ['amount' => \App\Support\Format::money($payment->amount)]));
    }

    public function reverse(Request $request, Payment $payment): RedirectResponse
    {
        abort_if($payment->isReversal(), 404);
        $this->payments->reverse($payment, $request->input('reason'));

        return redirect('/lease-contracts/'.$payment->lease_contract_id)->with('status', __('payments.reversed_ok'));
    }
}
