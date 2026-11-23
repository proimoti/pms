<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Charges\Month;
use App\Services\Reports\Reports;
use App\Support\Format;
use App\Support\Xlsx\XlsxWriter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Справките (PROJECT.md, „Досиета и първи справки“, 23.11.2026): екран и
 * изтегляне в Excel (?export=xlsx) със същите колони и филтри. Файлът се
 * прави във временна папка и се трие след изпращането (не се пази на сървъра).
 */
class ReportController extends Controller
{
    public function __construct(private readonly Reports $reports) {}

    public function expiring(Request $request): Response
    {
        $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:365']], ['days.*' => __('reports.days_error')]);
        $days = (int) ($request->query('days') ?: 30);
        $rows = $this->reports->expiring($days);

        if ($request->query('export') === 'xlsx') {
            $c = 'reports.expiring.columns.';
            return $this->xlsx('izticashti-dogovori', [__($c.'contract'), __($c.'properties'), __($c.'tenant'), __($c.'tenant_phone'), __($c.'tenant_email'),
                __($c.'owners'), __($c.'owner_phone'), __($c.'owner_email'), __($c.'end_on'), __($c.'days_left')],
                array_map(fn ($r) => [
                    '№ '.$r['contract']->number, $this->numbers($r['properties']), $r['contract']->tenant?->name, $r['contract']->tenant?->phone, $r['contract']->tenant?->email,
                    $r['owners']->pluck('name')->implode(', '), $r['owners']->pluck('phone')->filter()->implode(', '), $r['owners']->pluck('email')->filter()->implode(', '),
                    Format::date($r['end_on']), $r['days'],
                ], $rows));
        }

        return response()->view('reports.expiring', ['rows' => $rows, 'days' => $days]);
    }

    public function vacant(Request $request): Response
    {
        $managed = $request->boolean('managed');
        $rows = $this->reports->vacant($managed);

        if ($request->query('export') === 'xlsx') {
            $c = 'reports.vacant.columns.';
            return $this->xlsx('svobodni-imoti', [__($c.'property'), __($c.'address'), __($c.'type'), __($c.'since'), __($c.'last_tenant'), __($c.'managed')],
                array_map(fn ($r) => [
                    '№ '.$r['property']->number, \App\Support\Address::format($r['property']), $r['property']->propertyType?->name,
                    $r['since'] ? Format::date($r['since']) : __('reports.vacant.never'), $r['last_tenant'],
                    $r['managed'] ? __('reports.vacant.yes') : __('reports.vacant.no'),
                ], $rows));
        }

        return response()->view('reports.vacant', ['rows' => $rows, 'managed' => $managed]);
    }

    public function expired(Request $request): Response
    {
        $kind = in_array($request->query('kind'), ['lease', 'owner'], true) ? $request->query('kind') : null;
        $rows = $this->reports->expired($kind);

        if ($request->query('export') === 'xlsx') {
            $c = 'reports.expired.columns.';
            return $this->xlsx('iztekal-srok', [__($c.'kind'), __($c.'contract'), __($c.'party'), __($c.'properties'), __($c.'end_on'), __($c.'days_after')],
                array_map(fn ($r) => [
                    __('reports.expired.kinds.'.$r['kind']), '№ '.$r['contract']->number, $r['party'], $this->numbers($r['properties']),
                    Format::date($r['end_on']), $r['days'],
                ], $rows));
        }

        return response()->view('reports.expired', ['rows' => $rows, 'kind' => $kind]);
    }

    public function payouts(Request $request): Response
    {
        $text = $request->query('month');
        $month = $text === null || $text === '' ? CarbonImmutable::today()->startOfMonth()->subMonth() : Month::tryParse((string) $text);
        if ($month === null) {
            throw ValidationException::withMessages(['month' => __('reports.month_error')]);
        }
        $data = $this->reports->payouts($month);

        if ($request->query('export') === 'xlsx') {
            $c = 'reports.payouts.columns.';
            $rows = [];
            foreach ($data['groups'] as $group) {
                foreach ($group['rows'] as $row) {
                    $rows[] = [
                        $group['name'], $group['method'] ? __('contracts.payment_methods.'.$group['method']) : '',
                        $group['iban'] ?? ($group['missing_iban'] ? __('reports.payouts.missing_iban') : ''),
                        '№ '.$row['contract']->number, $row['owner']?->name, $this->numbers($row['properties']),
                        (float) $row['amount'], $row['changed'] ? __('reports.payouts.changed') : '',
                    ];
                }
            }

            return $this->xlsx('za-izplashtane-'.$month->format('Y-m'), [__($c.'beneficiary'), __($c.'payment_method'), __($c.'iban'), __($c.'contract'),
                __($c.'owner'), __($c.'properties'), __('reports.payouts.amount'), __($c.'note')], $rows);
        }

        return response()->view('reports.payouts', ['data' => $data, 'month' => $month]);
    }

    /** @param list<list<mixed>> $rows */
    private function xlsx(string $name, array $header, array $rows): Response
    {
        $path = tempnam(sys_get_temp_dir(), 'pms-report-');
        (new XlsxWriter)->write($path, array_merge([$header], $rows));

        return response()->download($path, $name.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    private function numbers($properties): string
    {
        return $properties->map(fn ($p) => '№ '.$p->number)->implode(', ');
    }
}
