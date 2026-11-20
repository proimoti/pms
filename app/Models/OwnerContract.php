<?php

namespace App\Models;

use App\Enums\CalculationMethod;
use App\Enums\OwnerContractType;
use App\Models\Concerns\HasCompanyNumber;
use App\Models\Concerns\RegistryRecord;
use App\Models\Scopes\RegistryVisibilityScope;
use App\Services\OwnerContractService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Договор за управление (договор със собственик, сметка 408) — заглавието
 * (PROJECT.md, т. 2 „Договори“, т. 3.6). Създава се и се променя с анекси
 * през OwnerContractService. „Активен“ не се пази, а се смята от датите.
 */
class OwnerContract extends Model
{
    use HasCompanyNumber, RegistryRecord {
        RegistryRecord::archive as archiveWithoutChecks;
    }

    protected $table = 'owner_contracts';

    protected $fillable = ['number', 'type', 'owner_id', 'signed_on', 'end_on', 'terminated_on', 'note'];

    protected function casts(): array
    {
        return [
            'type' => OwnerContractType::class,
            'calculation' => CalculationMethod::class,
            'signed_on' => 'immutable_date',
            'end_on' => 'immutable_date',
            'terminated_on' => 'immutable_date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (OwnerContract $contract) {
            $contract->calculation = $contract->type->calculation();
            $contract->ensureOwnerKindFitsType();
        });

        static::updating(function (OwnerContract $contract) {
            if ($contract->isDirty('type')) {
                throw ValidationException::withMessages(['type' => __('contracts.messages.type_locked')]);
            }

            $start = $contract->startsOn();
            if ($contract->isDirty('terminated_on') && $contract->terminated_on !== null
                && $start !== null && $contract->terminated_on->lte($start)) {
                throw ValidationException::withMessages(['terminated_on' => __('contracts.messages.terminated_before_start')]);
            }
        });

        // Отмяна на прекратяване или връщане от архива може да застъпи друг договор.
        static::saved(function (OwnerContract $contract) {
            if ($contract->wasChanged('terminated_on')
                || ($contract->wasChanged('archived_at') && $contract->archived_at === null)) {
                app(OwnerContractService::class)->ensurePropertiesAreFree($contract);
            }
        });
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

    /** Начисленията и книгата още ги няма. Ще стане „да“ при първо начисление. */
    public function hasCharges(): bool
    {
        return false;
    }

    /** До част 4 служител с обхват „само прикачените“ не вижда договорите. */
    protected static function assignedScopeRule(): string
    {
        return RegistryVisibilityScope::NONE;
    }

    private function ensureOwnerKindFitsType(): void
    {
        $required = $this->type->requiredOwnerKind();
        if ($required === null || $this->owner_id === null) {
            return;
        }

        $owner = Owner::query()->withoutGlobalScopes()
            ->where('company_id', $this->company_id)->find($this->owner_id);

        if ($owner !== null && $owner->kind !== $required) {
            throw ValidationException::withMessages(['type' => __('contracts.messages.owner_kind_mismatch', [
                'type' => __('contracts.types.'.$this->type->value),
                'kind' => mb_strtolower(__('registry.kinds.'.$required->value)),
            ])]);
        }
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function annexes(): HasMany
    {
        return $this->hasMany(OwnerContractAnnex::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OwnerContractLine::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(OwnerContractCommission::class);
    }

    public function payees(): HasMany
    {
        return $this->hasMany(OwnerContractPayee::class);
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

    /** Процентът на комисионата към датата (само при тип с процент). */
    public function commissionOn($date): ?string
    {
        return $this->versionOn($this->commissions(), $date)?->percent;
    }

    public function payeeOn($date): ?OwnerContractPayee
    {
        return $this->versionOn($this->payees(), $date);
    }

    /** Редовете (имотите), които действат на датата. */
    public function linesOn($date): Collection
    {
        $day = CarbonImmutable::parse($date)->toDateString();

        return $this->lines()
            ->where('valid_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $day))
            ->get();
    }

    public function lineOn(Property|int $property, $date): ?OwnerContractLine
    {
        $id = $property instanceof Property ? $property->id : $property;

        return $this->linesOn($date)->firstWhere('property_id', $id);
    }

    private function versionOn(HasMany $versions, $date): ?Model
    {
        $day = CarbonImmutable::parse($date)->toDateString();

        return $versions
            ->where('valid_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>', $day))
            ->first();
    }
}
