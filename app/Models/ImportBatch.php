<?php

namespace App\Models;

use App\Models\Concerns\WorksheetRules;
use App\Models\Scopes\RegistryVisibilityScope;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Внесен файл (PROJECT.md, т. 6 `import_batches`; т. 5.3). Самият файл не се
 * пази, а само броят на редовете по вид. Вижда се от служител с „всички имоти“.
 */
class ImportBatch extends Model
{
    use WorksheetRules;

    protected $table = 'import_batches';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [
            'rows_count' => 'integer', 'bills_count' => 'integer', 'duplicates' => 'integer',
            'for_decision' => 'integer', 'skipped_fees' => 'integer',
        ];
    }

    protected static function worksheetScopeRule(): string|Closure
    {
        return RegistryVisibilityScope::NONE;
    }

    public function bills(): HasMany
    {
        return $this->hasMany(PaidUtilityBill::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(UtilityImportIssue::class);
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
