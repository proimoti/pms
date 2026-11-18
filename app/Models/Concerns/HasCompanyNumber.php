<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Номер, уникален във фирмата (PROJECT.md, т. 2 „Регистър“ и „уточнения“):
 * цяло число; ако не е зададено, следващото = най-голямото във фирмата + 1
 * (включително архивираните, за да не се ползва повторно); може да се смени
 * на ръка, с проверка за дубликат. Заключването идва с договорите и книгата.
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
    }

    public function initializeHasCompanyNumber(): void
    {
        $this->mergeCasts(['number' => 'integer']);
    }

    /**
     * Заключен ли е номерът. Ще стане „да“, щом имотът/лицето има договор
     * или записи в книгата. Тези таблици още ги няма.
     */
    public function isNumberLocked(): bool
    {
        return false;
    }

    protected function nextNumber(): int
    {
        return (int) $this->sameCompanyQuery()->max('number') + 1;
    }

    protected function ensureNumberIsFree(): void
    {
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
    private function sameCompanyQuery()
    {
        return static::query()->withoutGlobalScopes()->where('company_id', $this->company_id);
    }
}
