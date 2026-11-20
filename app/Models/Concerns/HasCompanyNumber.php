<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Номер, уникален във фирмата (PROJECT.md, т. 2 „Регистър“ и „уточнения“):
 * цяло число; ако не е зададено, следващото = най-голямото във фирмата + 1
 * (включително архивираните, за да не се ползва повторно); може да се смени
 * на ръка, с проверка за дубликат. Имотът променя правилото (най-малкият
 * свободен и резервирани номера). Заключването идва с първото начисление.
 */
trait HasCompanyNumber
{
    public static function bootHasCompanyNumber(): void
    {
        static::creating(function (Model $model) {
            if ($model->number === null) {
                $model->number = $model->nextNumber();
            }

            $model->ensureNumberIsFree();
        });

        static::updating(function (Model $model) {
            if (! $model->isDirty('number')) {
                return;
            }

            if ($model->isNumberLocked()) {
                throw ValidationException::withMessages(['number' => __('registry.number_locked')]);
            }

            $model->ensureNumberIsFree();
        });

        static::updated(function (Model $model) {
            if ($model->wasChanged('number')) {
                $model->numberWasChanged((int) $model->getRawOriginal('number'));
            }
        });
    }

    public function initializeHasCompanyNumber(): void
    {
        $this->mergeCasts(['number' => 'integer']);
    }

    /**
     * Заключен ли е номерът. Ще стане „да“ при първото начисление
     * (записи в книгата). Тези таблици още ги няма.
     */
    public function isNumberLocked(): bool
    {
        return false;
    }

    protected function nextNumber(): int
    {
        return (int) $this->sameCompanyQuery()->max('number') + 1;
    }

    /** Резервиран ли е номерът (зает, без да е на запис). Само при имотите. */
    protected function isNumberReserved(): bool
    {
        return false;
    }

    /** След ръчна смяна на номера. Имотът резервира стария номер. */
    protected function numberWasChanged(int $oldNumber): void {}

    public function ensureNumberIsFree(): void
    {
        if ($this->isNumberReserved()) {
            throw ValidationException::withMessages([
                'number' => __('registry.number_reserved', ['number' => $this->number]),
            ]);
        }

        $taken = $this->sameCompanyQuery()
            ->where('number', $this->number)
            ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'number' => __('registry.number_taken', ['number' => $this->number]),
            ]);
        }
    }

    /** Всички записи на фирмата, независимо какво вижда текущият потребител. */
    protected function sameCompanyQuery()
    {
        return static::query()->withoutGlobalScopes()->where('company_id', $this->company_id);
    }
}
