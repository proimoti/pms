<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Вид услуга при доставчика (PROJECT.md, „Поправки по съдържанието на
 * екраните“, т. 2.13, 24.11.2026): един доставчик – една услуга; при нов
 * абонат видът се попълва сам от доставчика. Видът при абонатите не се мени.
 *
 * Сегашните доставчици: ако всички техни абонати (и архивираните) са от един
 * вид, той се попълва; иначе (или без абонати) остава празно и се избира на
 * ръка. Промените се записват в журнала (без потребител).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->string('kind', 20)->nullable()->after('name');
        });

        $this->backfill();
    }

    /** Попълва вида само при един вид у абонатите. Втори път не прави нищо. Публично, за теста. */
    public function backfill(): void
    {
        $kinds = DB::table('utility_accounts')->whereNotNull('provider_id')
            ->select('provider_id')->selectRaw('MIN(kind) as kind, COUNT(DISTINCT kind) as kinds')
            ->groupBy('provider_id')->get();

        foreach ($kinds as $row) {
            if ((int) $row->kinds !== 1) {
                continue;
            }
            $provider = DB::table('providers')->where('id', $row->provider_id)->whereNull('kind')->first(['id', 'company_id']);
            if ($provider === null) {
                continue;
            }
            DB::table('providers')->where('id', $provider->id)->update(['kind' => $row->kind]);
            DB::table('audit_log')->insert([
                'company_id' => $provider->company_id, 'user_id' => null, 'auditable_type' => 'providers', 'auditable_id' => $provider->id,
                'event' => 'updated', 'changes' => json_encode(['kind' => ['old' => null, 'new' => $row->kind]], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
