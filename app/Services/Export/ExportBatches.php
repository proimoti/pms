<?php

namespace App\Services\Export;

use App\Models\ExportBatch;
use App\Models\ExportBatchEntry;
use App\Models\LedgerEntry;
use App\Models\PeriodLock;
use App\Models\Scopes\CompanyScope;
use App\Models\Scopes\RegistryVisibilityScope;
use App\Services\Charges\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Пакетите за експорта (PROJECT.md, „Експорт към Микроинвест“, 26.11.2026).
 * В пакет влизат записите от книгата за издаден (заключен) месец, които още не
 * са в пакет. Книгата не се пипа: връзката е в export_batch_entries (уникален
 * ключ по записа). Корекциите след пакета влизат в следващия. Без попълнени
 * настройки пакет не се прави (фондът винаги има номер, 26.11.2026).
 */
final class ExportBatches
{
    public function __construct(private readonly ExportSettings $settings) {}

    private function companyId(): int
    {
        return CompanyScope::restrictedCompanyId() ?? throw new \LogicException('Експортът иска влязъл служител на фирма.');
    }

    /** Записите на фирмата от книгата (всички имоти и фондове). */
    private function ledger(): Builder
    {
        return LedgerEntry::query()->withoutGlobalScope(RegistryVisibilityScope::class)->where('company_id', $this->companyId());
    }

    /** Неекспортираните записи за месеца. */
    public function pending(CarbonImmutable $month): Builder
    {
        return $this->ledger()->whereDate('month', $month->startOfMonth()->toDateString())
            ->whereNotIn('id', ExportBatchEntry::query()->select('ledger_entry_id'));
    }

    /** @return list<array{month: CarbonImmutable, pending: int, batches: \Illuminate\Support\Collection}> издадените месеци, най-новите първи */
    public function months(): array
    {
        $batches = ExportBatch::query()->with('createdBy')->orderBy('number')->get()->groupBy(fn (ExportBatch $b) => $b->month->format('Y-m'));

        return PeriodLock::query()->orderByDesc('month')->get()->map(function (PeriodLock $lock) use ($batches) {
            $month = CarbonImmutable::parse($lock->month)->startOfMonth();

            return ['month' => $month, 'pending' => $this->pending($month)->count(), 'batches' => $batches->get($month->format('Y-m'), collect())];
        })->all();
    }

    /** @return list<string> какво спира експорта (на български) */
    public function problems(): array
    {
        $problems = array_map(fn ($key) => str_starts_with($key, 'kind_') ? __('utilities.kinds.'.substr($key, 5)) : __('export.settings.keys.'.$key),
            $this->settings->missing());

        return $problems;
    }

    public function create(CarbonImmutable $month): ExportBatch
    {
        $month = $month->startOfMonth();

        return DB::transaction(function () use ($month) {
            Ledger::lockCompany($this->companyId());

            if (! PeriodLock::isLocked($month)) {
                throw ValidationException::withMessages(['month' => __('export.batches.not_issued')]);
            }
            if (($problems = $this->problems()) !== []) {
                throw ValidationException::withMessages(['month' => __('export.batches.problems', ['list' => implode('; ', $problems)])]);
            }
            $ids = $this->pending($month)->orderBy('id')->pluck('id');
            if ($ids->isEmpty()) {
                throw ValidationException::withMessages(['month' => __('export.batches.nothing_new')]);
            }

            $batch = new ExportBatch;
            $batch->forceFill([
                'number' => (int) ExportBatch::query()->max('number') + 1, 'month' => $month,
                'entries_count' => $ids->count(), 'created_by' => Auth::id(), 'created_at' => now(),
            ])->save();
            foreach ($ids as $id) {
                (new ExportBatchEntry)->forceFill(['export_batch_id' => $batch->id, 'ledger_entry_id' => $id])->save();
            }

            return $batch;
        });
    }
}
