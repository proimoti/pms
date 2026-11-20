<?php

namespace App\Models;

use App\Enums\ChargeSource;
use App\Enums\ChargeType;
use App\Models\Concerns\WorksheetRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ред от работната таблица на месеца (PROJECT.md, „Работна таблица на
 * месеца“). Свободно се променя до издаването (част 3). Ръчната промяна и
 * махането пазят причината и кой ги е направил; махнатият ред остава (за да
 * не го върне „Генерирай отново“). Пресметнат ред без ръчна намеса, който
 * вече не се предлага, отпада при „Генерирай отново“.
 */
class ChargeDraft extends Model
{
    use WorksheetRules;

    protected $table = 'charge_drafts';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [
            'month' => 'immutable_date',
            'type' => ChargeType::class,
            'source' => ChargeSource::class,
            'amount' => 'decimal:2',
            'proposed_amount' => 'decimal:2',
            'computed_amount' => 'decimal:2',
            'edited_proposed_amount' => 'decimal:2',
            'edited_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
        ];
    }

    public function isManual(): bool
    {
        return $this->source === ChargeSource::Manual || $this->edited_at !== null || $this->removed_at !== null;
    }

    public function leaseContract(): BelongsTo
    {
        return $this->belongsTo(LeaseContract::class);
    }

    public function ownerContract(): BelongsTo
    {
        return $this->belongsTo(OwnerContract::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Owner::class);
    }

    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }
}
