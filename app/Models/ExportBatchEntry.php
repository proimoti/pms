<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Запис от книгата в пакет за експорта. Един запис е само в един пакет (уникален ключ в базата). Само добавяне. */
class ExportBatchEntry extends Model
{
    use AppendOnly, BelongsToCompany;

    protected $table = 'export_batch_entries';

    public $timestamps = false;

    protected $guarded = ['id', 'company_id'];
}
