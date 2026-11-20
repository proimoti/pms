<?php

namespace App\Models;

use App\Enums\ChargeType;
use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\WorksheetRules;
use App\Models\Scopes\RegistryVisibilityScope;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

/** Ред от известието: копие на реда от работната таблица при издаването. */
class StatementLine extends Model
{
    use AppendOnly, WorksheetRules;

    protected $table = 'statement_lines';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['type' => ChargeType::class, 'amount' => 'decimal:2'];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(Statement::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** Вижда се с известието (целият договор). */
    protected static function worksheetScopeRule(): string|Closure
    {
        return fn (Builder $builder, Model $model, User $user) => $builder->whereIn(
            $model->qualifyColumn('statement_id'),
            fn (QueryBuilder $query) => $query->select('id')->from('statements')
                ->whereIn('lease_contract_id', RegistryVisibilityScope::visibleContractIds('lease_contract_lines', 'lease_contract_id', $user)),
        );
    }
}
