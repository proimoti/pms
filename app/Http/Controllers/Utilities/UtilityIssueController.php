<?php

namespace App\Http\Controllers\Utilities;

use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Models\UtilityImportIssue;
use App\Services\Utilities\UtilityImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Редовете „за решение“ от вноса (PROJECT.md, т. 5.3 „Внос, уточнения“):
 * „Свържи“ (нов абонат или име на доставчик), „Игнорирай“ с причина и
 * „Премахни игнорирането“.
 */
class UtilityIssueController extends Controller
{
    public function __construct(private readonly UtilityImporter $importer) {}

    public function index(Request $request): View
    {
        $status = in_array($request->query('status'), [UtilityImportIssue::IGNORED, UtilityImportIssue::IMPORTED], true)
            ? $request->query('status') : UtilityImportIssue::OPEN;

        return view('utilities.issues.index', [
            'status' => $status,
            'issues' => UtilityImportIssue::query()->with(['provider', 'batch', 'decidedBy'])
                ->where('status', $status)->orderBy('paid_at')->orderBy('id')->paginate(100)->withQueryString(),
            'providers' => Provider::query()->active()->orderBy('name')->get(),
        ]);
    }

    public function ignore(Request $request, UtilityImportIssue $issue): RedirectResponse
    {
        $this->importer->ignore($issue, $request->input('reason'));

        return redirect('/utility-issues')->with('status', __('utilities.import.ignored'));
    }

    public function unignore(UtilityImportIssue $issue): RedirectResponse
    {
        $this->importer->unignore($issue);

        return redirect('/utility-issues?status=ignored')->with('status', __('utilities.import.unignored'));
    }

    public function linkProvider(Request $request, UtilityImportIssue $issue): RedirectResponse
    {
        $provider = Provider::query()->find($request->input('provider_id'))
            ?? throw ValidationException::withMessages(['provider_id' => __('utilities.errors.provider_not_found')]);

        $count = $this->importer->linkProvider($issue, $provider);

        return redirect('/utility-issues')->with('status', __('utilities.import.retried', ['count' => $count]));
    }

    public function retry(): RedirectResponse
    {
        return redirect('/utility-issues')->with('status', __('utilities.import.retried', ['count' => $this->importer->retryOpen()]));
    }
}
