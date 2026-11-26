<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Beneficiary;
use App\Models\OwnerContract;
use App\Models\OwnerPayout;
use App\Services\Charges\Cents;
use App\Services\Payments\OwnerAllocation;
use App\Services\Payments\PayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Изплащане към собственик или бенефициент и сторно (PROJECT.md, „Плащания и
 * салда“, 26.11.2026). Само управляващият начисленията (в адресите). Договор
 * или изплащане на друга фирма — 404.
 */
class PayoutController extends Controller
{
    public function __construct(private readonly PayoutService $payouts) {}

    public function create(Request $request, OwnerContract $contract, OwnerAllocation $allocation): View
    {
        $state = $allocation->forContract((int) $contract->company_id, $contract->id);
        $payee = $contract->payeeOn(now());

        return view('payouts.create', [
            'contract' => $contract->load('owner'),
            'left' => Cents::format($state['accrued'] - $state['paid_out']),
            'amount' => (string) $request->query('amount', ''),
            'beneficiary' => $payee?->beneficiary_id ? Beneficiary::query()->find($payee->beneficiary_id) : null,
            'method' => $payee?->payment_method?->value ?? 'bank',
            'accounts' => BankAccount::query()->whereNull('archived_at')->orderByDesc('for_statements')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request, OwnerContract $contract): RedirectResponse
    {
        $payout = $this->payouts->record($contract, $request->only(['amount', 'paid_on', 'method', 'bank_account_id', 'note']));

        return redirect('/owner-contracts/'.$contract->id)->with('status', __('payouts.saved', ['amount' => \App\Support\Format::money($payout->amount)]));
    }

    public function reverse(Request $request, OwnerPayout $payout): RedirectResponse
    {
        abort_if($payout->isReversal(), 404);
        $this->payouts->reverse($payout, $request->input('reason'));

        return redirect('/owner-contracts/'.$payout->owner_contract_id)->with('status', __('payouts.reversed_ok'));
    }
}
