<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Абонатите и общият фонд (PROJECT.md, „Общ фонд“, решено 22.11.2026,
 * уточнено 23.11.2026).
 *
 * - всеки фиксиран абонат е към фонд (задължително);
 * - реален абонат може да е „плаща се от фонд“ (тогава е към фонд и сметката
 *   му не се дели; дяловете на имотите не се попълват — share става празно);
 * - обикновен реален абонат е без фонд.
 *
 * Старите фиксирани абонати получават по един фонд с техните имоти и име като
 * „Фонд: Такса вход“, без аналитика (излиза предупреждение „липсва
 * аналитика“). Нищо не се губи; създаденото и промененото се записват в
 * журнала (без потребител, като команда на сървъра).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utility_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('fund_id')->nullable()->after('provider_id');
            $table->boolean('paid_from_fund')->default(false)->after('billing');
            $table->index(['company_id', 'fund_id']);
            $table->foreign(['company_id', 'fund_id'])->references(['company_id', 'id'])->on('funds')->restrictOnDelete();
        });

        Schema::table('utility_account_properties', function (Blueprint $table) {
            $table->decimal('share', 5, 2)->nullable()->change();
        });

        $this->backfill();

        DB::statement("ALTER TABLE utility_accounts ADD CONSTRAINT utility_accounts_fund_check
            CHECK ((billing = 'fixed' AND fund_id IS NOT NULL AND paid_from_fund = 0)
                OR (billing <> 'fixed' AND paid_from_fund = 1 AND fund_id IS NOT NULL)
                OR (billing <> 'fixed' AND paid_from_fund = 0 AND fund_id IS NULL))");
    }

    /**
     * По един фонд за всеки фиксиран абонат без фонд, с неговите имоти.
     * Втори път не прави нищо. Публично, за теста на миграцията.
     */
    public function backfill(): void
    {
        $accounts = DB::table('utility_accounts')->where('billing', 'fixed')->whereNull('fund_id')->orderBy('id')->get();

        foreach ($accounts as $account) {
            $now = now();
            $name = $this->freeName((int) $account->company_id, $this->baseName($account));
            $note = "Създаден автоматично при обновяването за фиксирания абонат (ID {$account->id}). Попълни аналитиката.";

            $fundId = DB::table('funds')->insertGetId([
                'company_id' => $account->company_id, 'name' => $name, 'analytic' => null, 'note' => $note,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->audit((int) $account->company_id, 'funds', $fundId, 'created', [
                'name' => ['old' => null, 'new' => $name], 'analytic' => ['old' => null, 'new' => null], 'note' => ['old' => null, 'new' => $note],
            ]);

            $propertyIds = DB::table('utility_account_properties')->where('utility_account_id', $account->id)->orderBy('position')->pluck('property_id');
            foreach ($propertyIds as $propertyId) {
                $lineId = DB::table('fund_properties')->insertGetId([
                    'company_id' => $account->company_id, 'fund_id' => $fundId, 'property_id' => $propertyId,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->audit((int) $account->company_id, 'fund_properties', $lineId, 'created', [
                    'fund_id' => ['old' => null, 'new' => $fundId], 'property_id' => ['old' => null, 'new' => $propertyId],
                ]);
            }

            DB::table('utility_accounts')->where('id', $account->id)->update(['fund_id' => $fundId, 'updated_at' => $now]);
            $this->audit((int) $account->company_id, 'utility_accounts', (int) $account->id, 'updated', [
                'fund_id' => ['old' => null, 'new' => $fundId],
            ]);
        }
    }

    /** „Фонд: “ + бележката на абоната (например „Такса вход“), или видът му, ако няма бележка. */
    private function baseName(object $account): string
    {
        $note = trim((string) preg_replace('/\s+/u', ' ', (string) $account->note));
        $label = $note !== '' ? mb_substr($note, 0, 200) : __('utilities.kinds.'.$account->kind);

        return 'Фонд: '.$label;
    }

    /** Името е уникално във фирмата: при повторение — „(2)“, „(3)“ … */
    private function freeName(int $companyId, string $base): string
    {
        $name = $base;
        for ($n = 2; DB::table('funds')->where('company_id', $companyId)->where('name', $name)->exists(); $n++) {
            $name = "{$base} ({$n})";
        }

        return $name;
    }

    private function audit(int $companyId, string $type, int $id, string $event, array $changes): void
    {
        DB::table('audit_log')->insert([
            'company_id' => $companyId, 'user_id' => null, 'auditable_type' => $type, 'auditable_id' => $id,
            'event' => $event, 'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE), 'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE utility_accounts DROP CONSTRAINT utility_accounts_fund_check');
        Schema::table('utility_accounts', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'fund_id']);
            $table->dropIndex(['company_id', 'fund_id']);
            $table->dropColumn(['fund_id', 'paid_from_fund']);
        });
        Schema::table('utility_account_properties', function (Blueprint $table) {
            $table->decimal('share', 5, 2)->nullable(false)->change();
        });
        // Фондовете остават: изтриват се с предходната миграция.
    }
};
