<?php

namespace App\Http\Controllers\Utilities;

use App\Http\Controllers\Controller;
use App\Models\ImportBatch;
use App\Models\Property;
use App\Models\Scopes\CompanyScope;
use App\Services\Utilities\UtilityImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Внос на платените битови сметки (PROJECT.md, т. 5.3, 21–22.11.2026):
 * качване → преглед без запис → „Внеси“ или „Откажи“. Файлът стои временно в
 * папката на фирмата само до „Внеси“ или „Откажи“, после се трие.
 */
class UtilityImportController extends Controller
{
    /** Временни файлове, по-стари от това, се трият при следващото качване. */
    private const KEEP_HOURS = 24;

    public function __construct(private readonly UtilityImporter $importer) {}

    public function index(): View
    {
        return view('utilities.imports.index', [
            'batches' => ImportBatch::query()->with('importedBy')->latest('id')->paginate(50),
        ]);
    }

    public function preview(Request $request): View|RedirectResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240']], [], ['file' => __('utilities.import.file')]);
        $file = $request->file('file');

        $preview = $this->importer->preview($file->getRealPath()); // грешен файл → назад с грешка

        $this->removeOldFiles();
        $token = Str::random(40);
        Storage::disk('local')->putFileAs($this->folder(), $file, "{$token}.xlsx");
        $request->session()->put("utility_imports.{$token}", mb_substr($file->getClientOriginalName(), 0, 255));

        return view('utilities.imports.preview', [
            'preview' => $preview,
            'token' => $token,
            'fileName' => $file->getClientOriginalName(),
            'properties' => Property::query()->pluck('number', 'id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $path = $this->tokenPath($request);
        $name = (string) $request->session()->pull("utility_imports.{$request->input('token')}", 'file.xlsx');

        try {
            $batch = $this->importer->import(Storage::disk('local')->path($path), $name);
        } finally {
            Storage::disk('local')->delete($path);
        }

        return redirect("/utility-imports/{$batch->id}")->with('status', __('utilities.import.done', [
            'bills' => $batch->bills_count, 'issues' => $batch->for_decision, 'duplicates' => $batch->duplicates,
        ]));
    }

    public function cancel(Request $request): RedirectResponse
    {
        $path = $this->tokenPath($request);
        Storage::disk('local')->delete($path);
        $request->session()->forget("utility_imports.{$request->input('token')}");

        return redirect('/utility-imports')->with('status', __('utilities.import.cancelled'));
    }

    public function show(ImportBatch $batch): View
    {
        $batch->load(['bills.account.provider', 'bills.parts.property', 'issues', 'importedBy']);

        return view('utilities.imports.show', ['batch' => $batch]);
    }

    /** Папката с временните файлове на фирмата (номерът на фирмата е в пътя). */
    private function folder(): string
    {
        return 'imports/'.CompanyScope::restrictedCompanyId();
    }

    /** Пътят на временния файл по знака от прегледа; само в папката на своята фирма. */
    private function tokenPath(Request $request): string
    {
        $token = (string) $request->input('token');
        $path = $this->folder()."/{$token}.xlsx";

        if (! preg_match('/^[A-Za-z0-9]{40}$/', $token) || ! Storage::disk('local')->exists($path)) {
            throw ValidationException::withMessages(['token' => __('utilities.import.errors.token')]);
        }

        return $path;
    }

    private function removeOldFiles(): void
    {
        $disk = Storage::disk('local');
        foreach ($disk->files($this->folder()) as $old) {
            if ($disk->lastModified($old) < now()->subHours(self::KEEP_HOURS)->getTimestamp()) {
                $disk->delete($old);
            }
        }
    }
}
