<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Имот, прикачен към служител с обхват „само прикачените“ (PROJECT.md, т. 2).
 * Екранът за прикачване идва в стъпката „Потребители на фирмата“.
 */
class UserProperty extends Model
{
    use Audited, BelongsToCompany;

    protected $table = 'user_properties';

    protected $fillable = ['user_id', 'property_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
