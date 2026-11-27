<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Адрес на фирма (PROJECT.md, „Портал и служители“, 27.11.2026): домейн или
 * поддомейн, по който се определя фирмата при вход, забравена парола и покани.
 */
class CompanyDomain extends Model
{
    use BelongsToCompany;

    protected $guarded = ['*'];

    /** Името на адреса както се пази: малки букви, без порт и без точка накрая. */
    public static function normalize(string $host): string
    {
        return rtrim(mb_strtolower(trim($host)), '.');
    }

}
