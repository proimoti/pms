<?php

namespace App\Models\Base;

use App\Enums\PartyKind;
use App\Models\Concerns\HasCompanyNumber;
use App\Models\Concerns\RegistryRecord;
use App\Models\Scopes\RegistryVisibilityScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Общото за собственик и наемател (PROJECT.md, т. 2 „Регистър, полета“):
 * вид, име, ЕИК и МОЛ само при юридическо лице, адрес, телефон, имейл, бележка.
 * ЕГН не се въвежда във версия 1. Имената не са уникални.
 */
abstract class Party extends Model
{
    use HasCompanyNumber, HasFactory, RegistryRecord;

    protected $fillable = [
        'number', 'kind', 'name', 'eik', 'manager_name',
        'country', 'postal_code', 'city', 'district', 'street', 'street_number', 'entrance', 'floor', 'apartment',
        'phone', 'email', 'note',
    ];

    protected function casts(): array
    {
        return ['kind' => PartyKind::class];
    }

    /** Служител с „само прикачените имоти“ не ги вижда, докато не дойдат договорите. */
    protected static function assignedScopeRule(): string
    {
        return RegistryVisibilityScope::NONE;
    }

    public function isLegalEntity(): bool
    {
        return $this->kind === PartyKind::Legal;
    }
}
