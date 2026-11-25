<?php

namespace App\Http\Controllers\Charges;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use App\Models\Statement;
use App\Services\Charges\Month;
use App\Services\Statements\StatementSender;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Известията за дължими суми (не фактури): списък по месец и едно известие. PDF и имейл са в част 4. */
class StatementController extends Controller
{
    /**
     * Списък и търсене (т. 2.11, 24.11.2026): по месец, по № на известие и по №
     * на договор за наем (точно: „26“ намира само 26) и по име на наемател
     * (част от името). Без нито един филтър: текущият месец.
     */
    public function index(Request $request): View|RedirectResponse
    {
        if (! $request->hasAny(['month', 'number', 'contract', 'tenant'])) {
            return redirect('/statements?month='.Month::key(CarbonImmutable::now()->startOfMonth()));
        }

        $monthText = trim((string) $request->query('month', ''));
        $month = $monthText === '' ? null : (Month::tryParse($monthText) ?? abort(404));
        $number = trim((string) $request->query('number', ''));
        $contract = trim((string) $request->query('contract', ''));
        $tenant = trim((string) $request->query('tenant', ''));

        $statements = Statement::query()->with(['leaseContract', 'tenant'])
            ->when($month, fn ($q) => $q->whereDate('month', $month))
            ->when($number !== '', fn ($q) => ctype_digit($number) ? $q->where('number', (int) $number) : $q->whereRaw('1 = 0'))
            ->when($contract !== '', fn ($q) => ctype_digit($contract)
                ? $q->whereHas('leaseContract', fn ($l) => $l->where('number', (int) $contract)) : $q->whereRaw('1 = 0'))
            ->when($tenant !== '', fn ($q) => $q->whereHas('tenant', fn ($t) => $t->where('name', 'like', '%'.addcslashes($tenant, '\\%_').'%')))
            ->orderByDesc('month')->orderBy('number')->get();

        return view('charges.statements.index', [
            'month' => $month,
            'previous' => $month ? Month::key($month->subMonth()) : null,
            'next' => $month ? Month::key($month->addMonth()) : null,
            'filters' => ['month' => $month?->format('m.Y') ?? '', 'number' => $number, 'contract' => $contract, 'tenant' => $tenant],
            'statements' => $statements,
            // Последното изпращане на всяко известие (24.11.2026).
            'lastSend' => EmailLog::query()->whereIn('statement_id', $statements->pluck('id'))->orderBy('id')->get()->keyBy('statement_id'),
            'canManage' => \App\Models\Concerns\WorksheetRules::canManage($request->user()),
        ]);
    }

    public function show(Request $request, Statement $statement): View
    {
        return view('charges.statements.show', [
            'statement' => $statement->load(['leaseContract', 'tenant', 'issuedBy', 'lines.property', 'lines.paidUtilityBill', 'lines.utilityAccount.provider']),
            'sends' => EmailLog::query()->with('user')->where('statement_id', $statement->id)->orderBy('id')->get(),
            'alreadySent' => app(StatementSender::class)->wasSent($statement),
            'canManage' => \App\Models\Concerns\WorksheetRules::canManage($request->user()),
        ]);
    }

    /** PDF на известието (24.11.2026): при поискване, без пазене на файл. */
    public function pdf(Statement $statement, \App\Services\Statements\StatementPdf $pdf): \Symfony\Component\HttpFoundation\Response
    {
        return response($pdf->pdf($statement), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->filename($statement).'"',
        ]);
    }

    /** Изпращане на едно известие; повторно — само с потвърждение (24.11.2026). */
    public function send(Request $request, Statement $statement, StatementSender $sender): RedirectResponse
    {
        $back = redirect('/statements/'.$statement->id);
        if ($sender->wasSent($statement) && ! $request->boolean('confirm_resend')) {
            return $back->with('warning', __('statements.send.already_sent'));
        }

        $log = $sender->send($statement);

        return match ($log->status) {
            EmailLog::NO_EMAIL => $back->with('warning', __('statements.send.no_email_one', ['number' => $statement->number])),
            EmailLog::FAILED => $back->with('warning', __('statements.send.failed_one', ['number' => $statement->number, 'error' => $log->error])),
            default => $back->with('status', __('statements.send.sent_one', ['number' => $statement->number])),
        };
    }

    /** „Изпрати всички за месеца“: само неизпратените (24.11.2026). */
    public function sendMonth(Request $request, StatementSender $sender): RedirectResponse
    {
        $month = Month::parse($request->input('month'));
        $result = $sender->sendMonth($month);
        $numbers = fn (array $list) => implode(', ', array_map(fn ($n) => '№ '.$n, $list));
        $warnings = array_filter([
            $result['no_email'] === [] ? null : __('statements.send.skipped_no_email', ['numbers' => $numbers($result['no_email'])]),
            $result['failed'] === [] ? null : __('statements.send.failed_month', ['numbers' => $numbers($result['failed'])]),
        ]);

        $redirect = redirect('/statements?month='.Month::key($month))->with('status', __('statements.send.sent_month', ['sent' => $result['sent']]));

        return $warnings === [] ? $redirect : $redirect->with('warning', implode(' ', $warnings));
    }
}
