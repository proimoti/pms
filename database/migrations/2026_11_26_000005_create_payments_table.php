<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Плащания от наематели (PROJECT.md, „Плащания и салда“, 26.11.2026): ръчно
 * въведени, към един договор за наем. Сторното е нов ред със
 * reversal_of_id (само веднъж — уникален ключ). Само добавяне.
 * В книгата: 418 − с вид „payment“ (сторно: „payment_reversal“, 418 +), по имот,
 * с връзка към плащането. Нова таблица и нова колона: сегашните данни не се пипат.
 */
return new class extends Migration
{
    private const KINDS_BEFORE = "'issue', 'correction', 'manual', 'utility_paid', 'fund_income', 'fund_expense', 'manager_cost'";

    private const RULES_BEFORE = "
                AND (kind <> 'utility_paid' OR (account = '407' AND paid_utility_bill_id IS NOT NULL AND fund_id IS NULL))
                AND (kind <> 'fund_expense' OR (account = '407' AND fund_id IS NOT NULL AND paid_utility_bill_id IS NOT NULL AND amount > 0))
                AND (kind <> 'fund_income' OR (account = '407' AND fund_id IS NOT NULL AND amount < 0))
                AND (kind <> 'manager_cost' OR (account = '407' AND fund_id IS NULL AND property_id IS NOT NULL
                    AND paid_utility_bill_id IS NOT NULL AND utility_decision_id IS NOT NULL))";

    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('lease_contract_id');
            $table->unsignedBigInteger('tenant_id');
            $table->decimal('amount', 12, 2);                       // сторното е с минус
            $table->date('paid_on');
            $table->string('method', 10);                            // bank, cash
            $table->unsignedBigInteger('bank_account_id')->nullable();
            $table->string('reference', 255)->nullable();            // основание
            $table->text('note')->nullable();
            $table->unsignedBigInteger('reversal_of_id')->nullable(); // сторно на кое плащане
            $table->string('reason', 500)->nullable();               // причината за сторното
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['company_id', 'id']);
            $table->unique('reversal_of_id');
            $table->index(['company_id', 'lease_contract_id']);
            $table->index(['company_id', 'paid_on']);
            $table->foreign(['company_id', 'lease_contract_id'])->references(['company_id', 'id'])->on('lease_contracts')->restrictOnDelete();
            $table->foreign(['company_id', 'tenant_id'])->references(['company_id', 'id'])->on('tenants')->restrictOnDelete();
            $table->foreign(['company_id', 'bank_account_id'])->references(['company_id', 'id'])->on('bank_accounts')->restrictOnDelete();
            $table->foreign(['company_id', 'reversal_of_id'])->references(['company_id', 'id'])->on('payments')->restrictOnDelete();
            $table->foreign(['company_id', 'created_by'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_check CHECK (method IN ('bank', 'cash')
            AND (reversal_of_id IS NULL AND amount > 0 OR reversal_of_id IS NOT NULL AND amount < 0 AND reason IS NOT NULL))");

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_id')->nullable()->after('utility_decision_id');
            $table->foreign(['company_id', 'payment_id'])->references(['company_id', 'id'])->on('payments')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_kind_check');
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_kind_check
            CHECK (kind IN (".self::KINDS_BEFORE.", 'payment', 'payment_reversal')".self::RULES_BEFORE."
                AND (kind NOT IN ('payment', 'payment_reversal') OR (account = '418' AND payment_id IS NOT NULL AND type = 'payment'))
                AND (kind <> 'payment' OR amount < 0) AND (kind <> 'payment_reversal' OR amount > 0))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_kind_check');
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_kind_check CHECK (kind IN (".self::KINDS_BEFORE.")".self::RULES_BEFORE.')');
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'payment_id']);
            $table->dropColumn('payment_id');
        });
        Schema::dropIfExists('payments');
    }
};
