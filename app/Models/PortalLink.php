<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Връзка на профил в портала с наемател или собственик (27.11.2026).
 * Точно един от двата; всичко от една фирма (правила в базата).
 */
class PortalLink extends Model
{
    use Audited, BelongsToCompany;

    protected $table = 'portal_links';

    protected $fillable = ['user_id', 'tenant_id', 'owner_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
