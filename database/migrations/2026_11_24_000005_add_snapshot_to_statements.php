<?php

use App\Services\Statements\StatementSnapshot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Известието запомня данните на фирмата, наемателя и банковата сметка към
 * момента на издаване (PROJECT.md, „PDF и имейл на известията“, 24.11.2026).
 * За вече издадените известия се попълва сегашното състояние. Редовете и
 * сумите на известията не се пипат.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statements', function (Blueprint $table) {
            $table->json('snapshot')->nullable()->after('total');
        });

        $this->backfill();
    }

    /** Попълва само известията без запомнени данни. Втори път не прави нищо. Публично, за теста. */
    public function backfill(): void
    {
        $snapshot = new StatementSnapshot;
        foreach (DB::table('statements')->whereNull('snapshot')->orderBy('id')->get(['id', 'company_id', 'tenant_id']) as $statement) {
            DB::table('statements')->where('id', $statement->id)->update([
                'snapshot' => json_encode($snapshot->build((int) $statement->company_id, $statement->tenant_id === null ? null : (int) $statement->tenant_id), JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('statements', function (Blueprint $table) {
            $table->dropColumn('snapshot');
        });
    }
};
