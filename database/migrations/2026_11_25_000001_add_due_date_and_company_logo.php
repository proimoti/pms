<?php

use App\Services\Statements\StatementSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Вид на известието (PROJECT.md, 25.11.2026):
 * - лого на фирмата: пътят до файла в storage (не в public); показва се текущото;
 * - срок за плащане, запомнен при издаване: за вече издадените известия се
 *   попълва по същото правило (5-о число на месеца или 5 дни след издаването,
 *   ако е издадено след 5-о). Редовете и сумите не се пипат.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('logo_path')->nullable()->after('phone');
        });

        $this->backfill();
    }

    /** Попълва срока само там, където го няма. Втори път не прави нищо. Публично, за теста. */
    public function backfill(): void
    {
        foreach (DB::table('statements')->orderBy('id')->get(['id', 'month', 'issued_at', 'snapshot']) as $statement) {
            $snapshot = json_decode((string) $statement->snapshot, true) ?: [];
            if (isset($snapshot['due_on'])) {
                continue;
            }
            $snapshot['due_on'] = StatementSnapshot::dueOn(CarbonImmutable::parse($statement->month), CarbonImmutable::parse($statement->issued_at));
            DB::table('statements')->where('id', $statement->id)->update(['snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('logo_path');
        });
    }
};
