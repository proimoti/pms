<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Общ фонд (PROJECT.md, „Общ фонд“, решено 22.11.2026, уточнено 23.11.2026).
 *
 * - funds: име, аналитика за Микроинвест (празна само при фондовете от
 *   следващата миграция), бележка; архив вместо триене;
 * - fund_properties: имотите на фонда (един или няколко);
 * - ledger_entries: сметка 407 на фонда — с връзка към фонда и без имот.
 *   Записът по 407 е или на имот (без фонд), или на фонд (без имот и без
 *   договор). Два нови вида: „приход във фонда“ (407 −, фиксираните такси)
 *   и „разход от фонда“ (407 +, платена сметка).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('analytic', 50)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'name']);
            $table->unique(['company_id', 'analytic']); // празните (NULL) не си пречат
        });

        Schema::create('fund_properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('fund_id');
            $table->unsignedBigInteger('property_id');
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['fund_id', 'property_id']);
            $table->index(['company_id', 'property_id']);
            $table->foreign(['company_id', 'fund_id'])->references(['company_id', 'id'])->on('funds')->restrictOnDelete();
            $table->foreign(['company_id', 'property_id'])->references(['company_id', 'id'])->on('properties')->restrictOnDelete();
        });

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('fund_id')->nullable()->after('paid_utility_bill_id');
            $table->unsignedBigInteger('property_id')->nullable()->change();
            $table->index(['company_id', 'fund_id']);
            $table->foreign(['company_id', 'fund_id'])->references(['company_id', 'id'])->on('funds')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_account_check');
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_account_check
            CHECK ((account IN ('418', '491') AND lease_contract_id IS NOT NULL AND owner_contract_id IS NULL AND property_id IS NOT NULL AND fund_id IS NULL)
                OR (account = '408' AND owner_contract_id IS NOT NULL AND property_id IS NOT NULL AND fund_id IS NULL)
                OR (account = '407' AND owner_contract_id IS NULL AND property_id IS NOT NULL AND fund_id IS NULL)
                OR (account = '407' AND owner_contract_id IS NULL AND lease_contract_id IS NULL AND property_id IS NULL AND fund_id IS NOT NULL))");
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_kind_check');
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_kind_check
            CHECK (kind IN ('issue', 'correction', 'manual', 'utility_paid', 'fund_income', 'fund_expense')
                AND (kind <> 'utility_paid' OR (account = '407' AND paid_utility_bill_id IS NOT NULL AND fund_id IS NULL))
                AND (kind <> 'fund_expense' OR (account = '407' AND fund_id IS NOT NULL AND paid_utility_bill_id IS NOT NULL AND amount > 0))
                AND (kind <> 'fund_income' OR (account = '407' AND fund_id IS NOT NULL AND amount < 0)))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_kind_check');
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_kind_check
            CHECK (kind IN ('issue', 'correction', 'manual', 'utility_paid')
                AND (kind <> 'utility_paid' OR (account = '407' AND paid_utility_bill_id IS NOT NULL)))");
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_account_check');
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_account_check
            CHECK ((account IN ('418', '491') AND lease_contract_id IS NOT NULL AND owner_contract_id IS NULL)
                OR (account = '408' AND owner_contract_id IS NOT NULL)
                OR (account = '407' AND owner_contract_id IS NULL))");
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'fund_id']);
            $table->dropIndex(['company_id', 'fund_id']);
            $table->dropColumn('fund_id');
            $table->unsignedBigInteger('property_id')->nullable(false)->change();
        });
        Schema::dropIfExists('fund_properties');
        Schema::dropIfExists('funds');
    }
};
