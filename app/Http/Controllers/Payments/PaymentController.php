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

    /**
     * „Плащания“ (26.11.2026): всички плащания за период (по подразбиране
     * текущият месец), с филтри по начин и банкова сметка и сбор; сторната са
     * отделни редове с минус. Служител с „само прикачените имоти“ вижда
     * плащанията само по своите договори.
     */
    public function index(Request $request): View
    {
        $from = \App\Support\BgDate::parse($request->query('from')) ?? now()->toImmutable()->startOfMonth()->startOfDay();
        $to = \App\Support\BgDate::parse($request->query('to')) ?? now()->toImmutable()->endOfMonth()->startOfDay();
        $method = in_array($request->query('method'), [Payment::BANK, Payment::CASH], true) ? $request->query('method') : null;
        $bankId = $request->filled('bank_account_id') ? (int) $request->query('bank_account_id') : null;

        $payments = Payment::query()->with(['leaseContract.tenant', 'bankAccount', 'createdBy'])
            ->whereIn('lease_contract_id', LeaseContract::query()->select('id'))
            ->whereDate('paid_on', '>=', $from->toDateString())->whereDate('paid_on', '<=', $to->toDateString())
            ->when($method, fn ($q) => $q->where('method', $method))
            ->when($bankId, fn ($q) => $q->where('bank_account_id', $bankId))
            ->orderBy('paid_on')->orderBy('id')->get();

        return view('payments.index', [
            'payments' => $payments,
            'total' => \App\Services\Charges\Cents::format($payments->sum(fn ($p) => \App\Services\Charges\Cents::from((string) $p->amount))),
            'filters' => ['from' => $from->format('d.m.Y'), 'to' => $to->format('d.m.Y'), 'method' => $method, 'bank_account_id' => $bankId],
            'accounts' => BankAccount::query()->orderBy('archived_at')->orderBy('id')->get(),
            'canPay' => \App\Models\Concerns\WorksheetRules::canManage($request->user()),
        ]);
    }

    /** Много плащания наведнъж: таблица (26.11.2026). */
    public function batchForm(): View
    {
        return view('payments.batch', [
            'accounts' => BankAccount::query()->whereNull('archived_at')->orderByDesc('for_statements')->orderBy('id')->get(),
            'leases' => LeaseContract::query()->with('tenant')->whereNull('archived_at')->orderBy('number')->get(),
        ]);
    }

    public function batchStore(Request $request, \App\Services\Payments\BatchPayments $batch): RedirectResponse
    {
        $saved = $batch->record($request->only(['paid_on', 'method', 'bank_account_id']), (array) $request->input('rows', []));

        return redirect('/payments')->with('status', __('payments.batch.saved', ['count' => count($saved)]));
    }

    public function reverse(Request $request, Payment $payment): RedirectResponse
    {
        abort_if($payment->isReversal(), 404);
        $this->payments->reverse($payment, $request->input('reason'));

        return redirect('/lease-contracts/'.$payment->lease_contract_id)->with('status', __('payments.reversed_ok'));
    }
}
