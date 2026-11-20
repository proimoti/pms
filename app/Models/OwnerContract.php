<?php

namespace App\Models;

use App\Enums\CalculationMethod;
use App\Enums\OwnerContractType;
use App\Models\Concerns\ContractHeader;
use App\Models\Scopes\RegistryVisibilityScope;
use App\Services\ContractService;
use App\Services\OwnerContractService;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * Договор за управление (договор със собственик, сметка 408) — заглавието
 * (PROJECT.md, т. 2 „Договори“, т. 3.6). Създава се и се променя с анекси
 * през OwnerContractService. „Активен“ не се пази, а се смята от датите.
 */
class OwnerContract extends Model
{
    use ContractHeader;

    protected $table = 'owner_contracts';

    protected $fillable = ['number', 'type', 'owner_id', 'signed_on', 'end_on', 'terminated_on', 'note'];

    protected function casts(): array
    {
        return [
            'type' => OwnerContractType::class,
            'calculation' => CalculationMethod::class,
        ];
    }

    protected static function contractService(): ContractService
    {
        return app(OwnerContractService::class);
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
        });
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

    public function extensions(): HasMany
    {
        return $this->hasMany(OwnerContractExtension::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(OwnerContractCommission::class);
    }

    public function payees(): HasMany
    {
        return $this->hasMany(OwnerContractPayee::class);
    }

    /** Процентът на комисионата към датата (само при тип с процент). */
    public function commissionOn($date): ?string
    {
        return $this->versionsOn($this->commissions(), $date)->first()?->percent;
    }

    public function payeeOn($date): ?OwnerContractPayee
    {
        return $this->versionsOn($this->payees(), $date)->first();
    }

    /** Обхват „само прикачените имоти“: вижда се, ако поне един имот в договора е прикачен. */
    protected static function assignedScopeRule(): string|Closure
    {
        return RegistryVisibilityScope::throughContracts('owner_contract_lines', 'owner_contract_id', 'id');
    }
}
