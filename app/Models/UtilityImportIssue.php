<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Builders\NoDeleteBuilder;
use App\Models\Concerns\WorksheetRules;
use App\Models\Scopes\RegistryVisibilityScope;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Ред от файла „за решение“ (PROJECT.md, т. 5.3 „Внос, уточнения“): непознат
 * доставчик или номер, фиксиран или недействащ абонат, сума 0 или отрицателна.
 * Решава се със „Свържи“ (после се внася) или „Игнорирай“ с причина;
 * игнорираният се помни и не излиза пак при повторен внос.
 *
 * Трие се само игнориран ред, един по един („Премахни игнорирането“, решено
 * 22.11.2026), със запис в журнала; после редът се чете отново като нов.
 * Наведнъж не се трие нищо.
 */
class UtilityImportIssue extends Model
{
    use Audited, WorksheetRules;

    public const OPEN = 'open';

    public const IGNORED = 'ignored';

    public const IMPORTED = 'imported';

    protected $table = 'utility_import_issues';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['paid_at' => 'immutable_datetime', 'amount' => 'decimal:2', 'decided_at' => 'immutable_datetime'];
    }

    public function newEloquentBuilder($query): NoDeleteBuilder
    {
        return new NoDeleteBuilder($query);
    }

    public function delete()
    {
        if ($this->status !== self::IGNORED) {
            throw new LogicException(__('registry.no_delete'));
        }

        return parent::delete();
    }

    public function forceDelete()
    {
        return $this->delete();
    }

    /** Само този ред (заявката за масово триене е забранена). */
    protected function performDeleteOnModel()
    {
        $this->setKeysForSaveQuery($this->newModelQuery())->toBase()->delete();
        $this->exists = false;
    }

    protected static function worksheetScopeRule(): string|Closure
    {
        return RegistryVisibilityScope::NONE;
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
