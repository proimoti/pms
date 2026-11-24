<?php

namespace App\Models;

use App\Models\Concerns\Archivable;
use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\CompanyAdminOnly;
use App\Support\Iban;
use Illuminate\Database\Eloquent\Model;

/**
 * Банкова сметка на фирмата (PROJECT.md, „PDF и имейл на известията“,
 * 24.11.2026): може да са няколко и да се сменят (архивират, не се трият);
 * една е „за известията“ и се печата в тях. IBAN не е шифрован.
 */
class BankAccount extends Model
{
    use Archivable, Audited, BelongsToCompany, CompanyAdminOnly;

    protected $table = 'bank_accounts';

    protected $fillable = ['bank_name', 'iban', 'bic'];

    protected function casts(): array
    {
        return ['for_statements' => 'boolean'];
    }

    /** IBAN по четири знака: „BG80 BNBG 9661 …“. */
    public function formattedIban(): string
    {
        return trim(chunk_split(Iban::normalize($this->iban), 4, ' '));
    }
}
