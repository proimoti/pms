<?php

namespace App\Models\Scopes;

use App\Enums\PropertyScope;
use App\Enums\Role;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;

/**
 * Кой какво вижда в регистъра на своята фирма (PROJECT.md, т. 2 „Достъп“,
 * „Регистър“, „Договори, уточнения“).
 *
 * - собственици и наематели (като потребители): нищо, докато не дойде порталът;
 * - служител с обхват „само прикачените имоти“: само прикачените имоти; целия
 *   договор (с редовете, анексите и историята), ако поне един имот в него е
 *   прикачен; собствениците и бенефициентите през видимите договори за
 *   управление, наемателите през видимите договори за наем; отговорните лица
 *   и видовете имоти вижда.
 */
final class RegistryVisibilityScope implements Scope
{
    public const ALL = 'all';

    public const NONE = 'none';

    public const ASSIGNED_PROPERTIES = 'assigned_properties';

    /**
     * @param  string|Closure(Builder, Model, User): void  $assignedRule  какво вижда
     *     служител с обхват „само прикачените“: едно от правилата горе или своя проверка
     */
    public function __construct(private readonly string|Closure $assignedRule) {}

    public function apply(Builder $builder, Model $model): void
    {
        if (! Auth::hasUser()) {
            return;
        }

        /** @var User $user */
        $user = Auth::user();

        if ($user->role === Role::AppAdmin) {
            return; // филтърът по фирма вече го скрива
        }

        if ($user->role !== Role::Staff) {
            $builder->whereRaw('0 = 1');

            return;
        }

        if ($user->property_scope !== PropertyScope::Assigned) {
            return;
        }

        if ($this->assignedRule instanceof Closure) {
            ($this->assignedRule)($builder, $model, $user);

            return;
        }

        match ($this->assignedRule) {
            self::ALL => null,
            self::NONE => $builder->whereRaw('0 = 1'),
            self::ASSIGNED_PROPERTIES => $builder->whereIn($model->qualifyColumn('id'), self::assignedPropertyIds($user)),
        };
    }

    /** Имотите, прикачени към служителя (подзаявка). */
    public static function assignedPropertyIds(User $user): Closure
    {
        return fn (QueryBuilder $query) => $query
            ->select('property_id')
            ->from('user_properties')
            ->where('user_id', $user->id);
    }

    /**
     * Договорите от даден вид, в които има прикачен имот (подзаявка с номерата
     * на договорите). Броят се всички редове, и минали варианти.
     */
    public static function visibleContractIds(string $linesTable, string $contractKey, User $user): Closure
    {
        return fn (QueryBuilder $query) => $query
            ->select($contractKey)
            ->from($linesTable)
            ->whereIn('property_id', self::assignedPropertyIds($user));
    }

    /** Правило: записът се вижда, ако колоната му сочи видим договор. */
    public static function throughContracts(string $linesTable, string $contractKey, string $column): Closure
    {
        return fn (Builder $builder, Model $model, User $user) => $builder->whereIn(
            $model->qualifyColumn($column),
            self::visibleContractIds($linesTable, $contractKey, $user),
        );
    }

    /**
     * Правило: записът се вижда, ако е посочен (в $pointer) във видим договор.
     * Например собственик в owner_contracts.owner_id.
     */
    public static function referencedByContracts(string $table, string $pointer, string $linesTable, string $contractKey, string $contractColumn = 'id'): Closure
    {
        return fn (Builder $builder, Model $model, User $user) => $builder->whereIn(
            $model->qualifyColumn('id'),
            fn (QueryBuilder $query) => $query
                ->select($pointer)
                ->from($table)
                ->whereNotNull($pointer)
                ->whereIn($contractColumn, self::visibleContractIds($linesTable, $contractKey, $user)),
        );
    }
}
