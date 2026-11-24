<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Номер на бенефициента и на отговорното лице (PROJECT.md, „Поправки по
 * съдържанието на екраните“, т. 2.4 и 2.7, 24.11.2026). Номерът е уникален във
 * фирмата; новите получават най-малкия свободен. Сегашните записи получават
 * 1, 2, 3… по реда на създаване, отделно за всяка фирма (и архивираните).
 * Нищо не се губи; промените се записват в журнала (без потребител).
 */
return new class extends Migration
{
    private const TABLES = ['beneficiaries', 'brokers'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedInteger('number')->nullable()->after('company_id');
                $blueprint->unique(['company_id', 'number']);
            });
        }

        $this->backfill();
    }

    /**
     * Номер на записите без номер: следващите свободни във фирмата, по реда на
     * създаване. Втори път не прави нищо. Публично, за теста на миграцията.
     */
    public function backfill(): void
    {
        foreach (self::TABLES as $table) {
            $rows = DB::table($table)->whereNull('number')->orderBy('company_id')->orderBy('created_at')->orderBy('id')->get(['id', 'company_id']);

            foreach ($rows->groupBy('company_id') as $companyId => $records) {
                $taken = DB::table($table)->where('company_id', $companyId)->whereNotNull('number')->pluck('number')
                    ->map(fn ($number) => (int) $number)->flip();
                $next = 1;

                foreach ($records as $record) {
                    while ($taken->has($next)) {
                        $next++;
                    }
                    DB::table($table)->where('id', $record->id)->update(['number' => $next]);
                    DB::table('audit_log')->insert([
                        'company_id' => $companyId, 'user_id' => null, 'auditable_type' => $table, 'auditable_id' => $record->id,
                        'event' => 'updated', 'changes' => json_encode(['number' => ['old' => null, 'new' => $next]], JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                    ]);
                    $taken->put($next, true);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropUnique(['company_id', 'number']);
                $blueprint->dropColumn('number');
            });
        }
    }
};
