<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Неразпределени битови сметки (PROJECT.md, част 5, решено 23.11.2026).
 *
 * - utility_decisions: решението за остатъка на една платена сметка за един
 *   имот — „за сметка на управителя“ или „към следващия наемател“ (с договора),
 *   с причина; отмяна с причина (самото решение остава, с дата на отмяна);
 * - ledger_entries: нов вид „за сметка на управителя“ (407 на имота, със
 *   сметката и решението; − при решението, + при отмяната);
 * - charge_drafts: редът „към следващия наемател“ сочи решението.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utility_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('paid_utility_bill_id');
            $table->unsignedBigInteger('property_id');
            $table->string('kind', 20);                        // manager, next_tenant
            $table->unsignedBigInteger('lease_contract_id')->nullable();
            $table->decimal('amount', 12, 2);                  // остатъкът при решението
            $table->string('reason', 500);
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'paid_utility_bill_id', 'property_id'], 'utility_decisions_bill_property_index');
            $table->foreign(['company_id', 'paid_utility_bill_id'])->references(['company_id', 'id'])->on('paid_utility_bills')->restrictOnDelete();
            $table->foreign(['company_id', 'property_id'])->references(['company_id', 'id'])->on('properties')->restrictOnDelete();
            $table->foreign(['company_id', 'lease_contract_id'])->references(['company_id', 'id'])->on('lease_contracts')->restrictOnDelete();
            $table->foreign(['company_id', 'decided_by'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['company_id', 'cancelled_by'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE utility_decisions ADD CONSTRAINT utility_decisions_kind_check
            CHECK ((kind = 'manager' AND lease_contract_id IS NULL) OR (kind = 'next_tenant' AND lease_contract_id IS NOT NULL))");
        DB::statement('ALTER TABLE utility_decisions ADD CONSTRAINT utility_decisions_amount_check CHECK (amount > 0)');

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('utility_decision_id')->nullable()->after('fund_id');
            $table->foreign(['company_id', 'utility_decision_id'])->references(['company_id', 'id'])->on('utility_decisions')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_kind_check');
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_kind_check
            CHECK (kind IN ('issue', 'correction', 'manual', 'utility_paid', 'fund_income', 'fund_expense', 'manager_cost')
                AND (kind <> 'utility_paid' OR (account = '407' AND paid_utility_bill_id IS NOT NULL AND fund_id IS NULL))
                AND (kind <> 'fund_expense' OR (account = '407' AND fund_id IS NOT NULL AND paid_utility_bill_id IS NOT NULL AND amount > 0))
                AND (kind <> 'fund_income' OR (account = '407' AND fund_id IS NOT NULL AND amount < 0))
                AND (kind <> 'manager_cost' OR (account = '407' AND fund_id IS NULL AND property_id IS NOT NULL
                    AND paid_utility_bill_id IS NOT NULL AND utility_decision_id IS NOT NULL)))");

        Schema::table('charge_drafts', function (Blueprint $table) {
            $table->unsignedBigInteger('utility_decision_id')->nullable()->after('utility_account_id');
            $table->foreign(['company_id', 'utility_decision_id'])->references(['company_id', 'id'])->on('utility_decisions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('charge_drafts', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'utility_decision_id']);
            $table->dropColumn('utility_decision_id');
        });
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_kind_check');
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_kind_check
            CHECK (kind IN ('issue', 'correction', 'manual', 'utility_paid', 'fund_income', 'fund_expense')
                AND (kind <> 'utility_paid' OR (account = '407' AND paid_utility_bill_id IS NOT NULL AND fund_id IS NULL))
                AND (kind <> 'fund_expense' OR (account = '407' AND fund_id IS NOT NULL AND paid_utility_bill_id IS NOT NULL AND amount > 0))
                AND (kind <> 'fund_income' OR (account = '407' AND fund_id IS NOT NULL AND amount < 0)))");
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'utility_decision_id']);
            $table->dropColumn('utility_decision_id');
        });
        Schema::dropIfExists('utility_decisions');
    }
};
