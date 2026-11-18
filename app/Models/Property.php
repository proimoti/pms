<?php

namespace App\Models;

use App\Models\Concerns\HasCompanyNumber;
use App\Models\Concerns\RegistryRecord;
use App\Models\Scopes\RegistryVisibilityScope;
use Database\Factories\PropertyFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Имот (PROJECT.md, т. 2 „Регистър, полета“). Собственикът му се определя
 * от договора за управление, не се пази тук.
 */
#[UseFactory(PropertyFactory::class)]
class Property extends Model
{
    /** @use HasFactory<PropertyFactory> */
    use HasCompanyNumber, HasFactory, RegistryRecord;

    protected $table = 'properties';

    protected $fillable = [
        'number', 'property_type_id', 'broker_id',
        'postal_code', 'city', 'district', 'street', 'street_number', 'entrance', 'floor', 'apartment',
        'area', 'note',
    ];

    protected function casts(): array
    {
        return ['area' => 'decimal:2'];
    }

    protected static function assignedScopeRule(): string
    {
        return RegistryVisibilityScope::ASSIGNED_PROPERTIES;
    }

    public function propertyType(): BelongsTo
    {
        return $this->belongsTo(PropertyType::class);
    }

    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_properties');
    }
}
