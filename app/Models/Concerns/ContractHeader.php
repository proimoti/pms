<?php

namespace App\Models\Concerns;

use App\Models\Property;
use App\Services\ContractService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Общото за заглавието на договор за управление и договор за наем: номер
 * (най-голям + 1), архив само при грешно въведен договор, дати, „активен“ се
 * смята, проверка за застъпване при прекратяване и връщане от архива.
 * Моделът има връзки lines() (имотите с периоди) и extensions() (удължавания) и сам
 * дава правилото за обхват „само прикачените“ (assignedScopeRule).
 */
trait ContractHeader
{
    use HasCompanyNumber, RegistryRecord {
        RegistryRecord::archive as archiveWithoutChecks;
    }

    /** Услугата, която пази правилата на този вид договор. */
    abstract protected static function contractService(): ContractService;

    public static function bootContractHeader(): void
    {
        static::updating(function (self $contract) {
            $start = $contract->startsOn();
            if ($contract->isDirty('terminated_on') && $contract->terminated_on !== null
                && $start !== null && $contract->terminated_on->lte($start)) {
                throw ValidationException::withMessages(['terminated_on' => __('contracts.messages.terminated_before_start')]);
            }

            // След удължаване с анекс срокът е този от последното удължаване:
            // направо се поправя само дотогава (PROJECT.md, „Обхват и договори“).
            if ($contract->isDirty('end_on')) {
                $latest = $contract->extensions()->withoutGlobalScopes()->orderByDesc('end_on')->value('end_on');
                if ($latest !== null && $contract->end_on?->toDateString() !== CarbonImmutable::parse($latest)->toDateString()) {
                    throw ValidationException::withMessages(['end_on' => __('contracts.messages.end_on_locked')]);
                }
            }
        });

        // Отмяна на прекратяване или връщане от архива може да застъпи друг договор.
        static::saved(function (self $contract) {
            if ($contract->wasChanged('terminated_on')
                || ($contract->wasChanged('archived_at') && $contract->archived_at === null)) {
                static::contractService()->ensurePropertiesAreFree($contract);
            }
        });
    }

    public function initializeContractHeader(): void
    {
        $this->mergeCasts([
            'signed_on' => 'immutable_date',
            'end_on' => 'immutable_date',
            'terminated_on' => 'immutable_date',
        ]);
    }

    /** Записът и проверките след него са едно цяло: при отказ нищо не остава. */
    public function save(array $options = [])
    {
        return DB::transaction(fn () => parent::save($options));
    }

    /** Договор с начисления не се архивира (PROJECT.md, т. 2 „Договори, уточнения“). */
    public function archive(): void
    {
        if ($this->hasCharges()) {
            throw ValidationException::withMessages(['archived_at' => __('contracts.messages.has_charges')]);
        }

        $this->archiveWithoutChecks();
    }

    /** Има ли договорът начисления (записи в книгата). Тогава полетата, от които зависят сумите, са заключени. */
    public function hasCharges(): bool
    {
        return $this->isNumberLocked();
    }

    /** Началото на договора = най-ранното начало на ред (имот). */
    public function startsOn(): ?CarbonImmutable
    {
        $first = $this->lines()->min('valid_from');

        return $first === null ? null : CarbonImmutable::parse($first)->startOfDay();
    }

    /** Действа ли договорът на тази дата (по подразбиране днес). */
    public function isActiveOn($date = null): bool
    {
        if ($this->isArchived()) {
            return false;
        }

        $day = CarbonImmutable::parse($date ?? today())->startOfDay();

        if ($this->terminated_on !== null && $day->gte($this->terminated_on)) {
            return false;
        }

        return $this->linesOn($day)->isNotEmpty();
    }

    /** Състоянието за екраните: archived, terminated, active, upcoming или empty. */
    public function stateOn($date = null): string
    {
        $day = CarbonImmutable::parse($date ?? today())->startOfDay();

        return match (true) {
            $this->isArchived() => 'archived',
            $this->terminated_on !== null && $day->gte($this->terminated_on) => 'terminated',
            $this->isActiveOn($day) => 'active',
            ($start = $this->startsOn()) !== null && $start->gt($day) => 'upcoming',
            default => 'empty',
        };
    }

    /** Редовете (имотите), които действат на датата. */
    public function linesOn($date): Collection
    {
        return $this->versionsOn($this->lines(), $date)->get();
    }

    public function lineOn(Property|int $property, $date)
    {
        $id = $property instanceof Property ? $property->id : $property;

        return $this->linesOn($date)->firstWhere('property_id', $id);
    }

    /** Вариантите, които действат на датата („от“ включително, „до“ без него). */
    protected function versionsOn($versions, $date)
    {
        $day = CarbonImmutable::parse($date)->toDateString();

        return $versions
            ->where('valid_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $day));
    }
}
