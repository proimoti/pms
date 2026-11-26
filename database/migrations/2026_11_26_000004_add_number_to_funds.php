<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Аналитиката на фонда е пореден номер (PROJECT.md, „Общ фонд“, уточнено
 * 26.11.2026): от 1 във фирмата, само цифри, едно ниво; в експорта е
 * 406/номер. Сегашните фондове получават 1, 2, 3… по реда на създаване, отделно
 * за всяка фирма. Старото поле „аналитика“ остава в базата непроменено (вече не
 * се ползва); старата стойност се записва и в журнала. Нищо не се губи.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funds', function (Blueprint $table) {
            $table->unsignedInteger('number')->nullable()->after('company_id');
            $table->unique(['company_id', 'number']);
        });

        $this->backfill();
    }

    /** Номер на фондовете без номер: следващите свободни във фирмата, по реда на създаване. Втори път не прави нищо. */
    public function backfill(): void
    {
        $funds = DB::table('funds')->whereNull('number')->orderBy('company_id')->orderBy('created_at')->orderBy('id')->get(['id', 'company_id', 'analytic']);

        foreach ($funds->groupBy('company_id') as $companyId => $records) {
            $next = (int) DB::table('funds')->where('company_id', $companyId)->max('number');
            foreach ($records as $fund) {
                $next++;
                DB::table('funds')->where('id', $fund->id)->update(['number' => $next]);
                DB::table('audit_log')->insert([
                    'company_id' => $companyId, 'user_id' => null, 'auditable_type' => 'funds', 'auditable_id' => $fund->id, 'event' => 'updated',
                    'changes' => json_encode(['number' => ['old' => null, 'new' => $next], 'analytic' => ['old' => $fund->analytic, 'new' => $fund->analytic]], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('funds', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'number']);
            $table->dropColumn('number');
        });
    }
};
