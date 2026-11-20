<?php

namespace App\Http\Controllers\Charges;

use App\Http\Controllers\Controller;
use App\Models\LeaseContract;
use App\Models\Property;
use App\Services\Charges\Cents;
use App\Services\Charges\Issuer;
use App\Services\Charges\Month;
use App\Services\Charges\MonthWorksheet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * „Издай месеца“ и „Издай сега“ (PROJECT.md, „Издаване и заключване“): първо
 * преглед, после потвърждение с отметка „Прегледах предупрежденията“.
 */
class IssueController extends Controller
{
    public function __construct(
        private readonly Issuer $issuer,
        private readonly MonthWorksheet $sheet,
    ) {}

    public function preview(Request $request): View
    {
        $month = Month::tryParse($request->query('month')) ?? abort(404);
        $key = Month::key($month);
        $preview = $this->issuer->preview($key);

        return view('charges.issue', [
            'month' => $month,
            'key' => $key,
            'tenants' => $preview['tenants'],
            'owners' => $preview['owners'],
            'issued' => LeaseContract::query()->with('tenant')->whereIn('id', $preview['issued'])->get(),
            'warnings' => $this->sheet->warnings($key),
            'forDecision' => $this->sheet->forDecision($key),
            'totals' => [
                'tenants' => Cents::format($preview['tenants']->flatten()->sum(fn ($row) => Cents::from($row->amount))),
                'owners' => Cents::format($preview['owners']->sum(fn ($row) => Cents::from($row->amount))),
            ],
        ]);
    }

    public function issue(Request $request): RedirectResponse
    {
        $month = Month::parse($request->input('month'));
        $this->reviewed($request);

        $statements = $this->issuer->issueMonth(Month::key($month));

        return redirect('/charges?month='.Month::key($month))
            ->with('status', __('charges.issue.issued_ok', ['count' => $statements->count()]));
    }

    public function previewNow(Request $request, LeaseContract $contract): View
    {
        $month = Month::tryParse($request->query('month'));
        $preview = $month === null ? null : $this->issuer->previewNow($contract, Month::key($month));

        return view('charges.issue-now', [
            'contract' => $contract->load('tenant'),
            'month' => $month,
            'rows' => $preview['rows'] ?? [],
            'owners' => $preview['owners'] ?? [],
            'properties' => Property::query()->withoutGlobalScopes()->where('company_id', $contract->company_id)->pluck('number', 'id'),
        ]);
    }

    public function issueNow(Request $request, LeaseContract $contract): RedirectResponse
    {
        $month = Month::parse($request->input('month'));
        $this->reviewed($request);

        $statement = $this->issuer->issueNow($contract, Month::key($month));

        return redirect("/statements/{$statement->id}")->with('status', __('charges.issue.now_ok', ['number' => $statement->number]));
    }

    private function reviewed(Request $request): void
    {
        if (! $request->boolean('reviewed')) {
            throw ValidationException::withMessages(['reviewed' => __('charges.messages.reviewed_required')]);
        }
    }
}
