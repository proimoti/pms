<?php

namespace App\Models\Base;

use App\Enums\PartyKind;
use App\Models\Concerns\HasCompanyNumber;
use App\Models\Concerns\RegistryRecord;
use App\Models\Scopes\RegistryVisibilityScope;
use Closure;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

    /** По подразбиране служител с „само прикачените имоти“ не ги вижда. Собственикът и наемателят го сменят: виждат се през договорите. */
    protected static function assignedScopeRule(): string|Closure
    {
        return RegistryVisibilityScope::NONE;
    }

    /** Без интервали в началото и края и без двойни интервали вътре. */
    public static function normalizeName(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    protected function name(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : self::normalizeName($value));
    }

    public function isLegalEntity(): bool
    {
        return $this->kind === PartyKind::Legal;
    }
}
