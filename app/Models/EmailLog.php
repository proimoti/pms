<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Запис за изпратено (или неизпратено) писмо (PROJECT.md, „PDF и имейл на
 * известията“, 24.11.2026): кое известие, до кого е трябвало да отиде, къде е
 * отишло реално, кога, от кого и с какъв резултат. Без съдържанието. Само
 * добавяне.
 */
class EmailLog extends Model
{
    use AppendOnly, BelongsToCompany;

    public const SENT = 'sent';

    public const LOGGED = 'logged';

    public const FAILED = 'failed';

    public const NO_EMAIL = 'no_email';

    protected $table = 'email_log';

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'company_id', 'user_id'];

    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime'];
    }

    /** Нов запис от името на влезлия потребител, с днешния момент. */
    public static function record(array $attributes): self
    {
        $entry = new self;
        $entry->forceFill($attributes + ['sent_at' => now(), 'user_id' => Auth::id()])->save();

        return $entry;
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(Statement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
