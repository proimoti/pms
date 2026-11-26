<?php

namespace App\Http\Controllers\Charges;

use App\Http\Controllers\Controller;
use App\Services\Charges\Month;
use App\Models\ExportBatch;
use App\Services\Export\ExportBatches;
use App\Services\Export\MicroinvestRows;
use App\Services\Export\MicroinvestWorkbook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

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

    /** Пакетът: брой редове и сверката на сумите (26.11.2026). */
    public function show(ExportBatch $batch, MicroinvestRows $rows): View
    {
        return view('charges.export.show', ['batch' => $batch->load('createdBy'), 'data' => $rows->build($batch), 'problems' => $this->batches->problems()]);
    }

    /**
     * Файлът за Микроинвест. Прави се наново от записите в пакета (винаги същият);
     * не се пази на сървъра. Без настройки или при разлика в сверката не се дава.
     */
    public function download(ExportBatch $batch, MicroinvestRows $rows, MicroinvestWorkbook $workbook): Response
    {
        $back = redirect('/export/batches/'.$batch->id);
        if (($problems = $this->batches->problems()) !== []) {
            return $back->with('warning', __('export.file.problems', ['list' => implode('; ', $problems)]));
        }
        $data = $rows->build($batch);
        if (! $data['ok']) {
            return $back->with('warning', __('export.file.check_failed'));
        }

        $path = tempnam(sys_get_temp_dir(), 'pms-mikroinvest-');
        $workbook->write($path, $data['month'], $data['rows']);

        return response()->download($path, 'mikroinvest-paket-'.$batch->number.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }
}
