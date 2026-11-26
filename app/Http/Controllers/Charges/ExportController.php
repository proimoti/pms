<?php

namespace App\Http\Controllers\Charges;

use App\Http\Controllers\Controller;
use App\Services\Charges\Month;
use App\Services\Export\ExportBatches;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * „Експорт“ към Микроинвест (PROJECT.md, „Експорт към Микроинвест“, 26.11.2026):
 * издадените месеци, колко записа чакат и пакетите. Само управляващият
 * начисленията (в адресите). Никога не се пише в базата на Микроинвест — само файл.
 */
class ExportController extends Controller
{
    public function __construct(private readonly ExportBatches $batches) {}

    public function index(): View
    {
        return view('charges.export.index', ['months' => $this->batches->months(), 'problems' => $this->batches->problems()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $batch = $this->batches->create(Month::parse($request->input('month')));

        return redirect('/export')->with('status', __('export.batches.created', ['number' => $batch->number, 'count' => $batch->entries_count]));
    }
}
