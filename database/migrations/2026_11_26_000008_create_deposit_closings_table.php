<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Приключване на депозит (PROJECT.md, „Приключване на депозит“, 26.11.2026):
 * едно приключване по договор за наем с един или няколко начина. В книгата:
 * прихващане „deposit_offset“ (491 − и 418 −), връщане „deposit_refund“ (491 −),
 * приход „deposit_income“ (491 −), с връзка към приключването. Сторното е нов
 * ред с reversal_of_id (само веднъж) и причина; записите му са със същите видове
 * и обратен знак. Само добавяне. Нова таблица и нова колона: сегашните данни не се пипат.
 */
return new class extends Migration
{
    private const KINDS_BEFORE = "'issue', 'correction', 'manual', 'utility_paid', 'fund_income', 'fund_expense', 'manager_cost', 'payment', 'payment_reversal', 'payout', 'payout_reversal', 'opening', 'opening_reversal'";

    private const RULES_BEFORE = "
                AND (kind <> 'utility_paid' OR (account = '407' AND paid_utility_bill_id IS NOT NULL AND fund_id IS NULL))
                AND (kind <> 'fund_expense' OR (account = '407' AND fund_id IS NOT NULL AND paid_utility_bill_id IS NOT NULL AND amount > 0))
                AND (kind <> 'fund_income' OR (account = '407' AND fund_id IS NOT NULL AND amount < 0))
                AND (kind <> 'manager_cost' OR (account = '407' AND fund_id IS NULL AND property_id IS NOT NULL
                    AND paid_utility_bill_id IS NOT NULL AND utility_decision_id IS NOT NULL))
                AND (kind NOT IN ('payment', 'payment_reversal') OR (account = '418' AND payment_id IS NOT NULL AND type = 'payment'))
                AND (kind <> 'payment' OR amount < 0) AND (kind <> 'payment_reversal' OR amount > 0)
                AND (kind NOT IN ('payout', 'payout_reversal') OR (account = '408' AND owner_payout_id IS NOT NULL AND type = 'payment'))
                AND (kind <> 'payout' OR amount < 0) AND (kind <> 'payout_reversal' OR amount > 0)
                AND (kind NOT IN ('opening', 'opening_reversal') OR (account IN ('418', '491', '407') AND opening_import_id IS NOT NULL))";

    public function up(): void
    {
        Schema::create('deposit_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('lease_contract_id');
            $table->unsignedBigInteger('tenant_id');
            $table->date('closed_on');
            $table->string('refund_method', 10)->nullable();          // bank, cash (само при връщане)
            $table->unsignedBigInteger('bank_account_id')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('reversal_of_id')->nullable(); // сторно на кое приключване
            $table->string('reason', 500)->nullable();               // причината за сторното
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['company_id', 'id']);
            $table->unique('reversal_of_id');
            $table->index(['company_id', 'lease_contract_id']);
            $table->foreign(['company_id', 'lease_contract_id'])->references(['company_id', 'id'])->on('lease_contracts')->restrictOnDelete();
            $table->foreign(['company_id', 'tenant_id'])->references(['company_id', 'id'])->on('tenants')->restrictOnDelete();
            $table->foreign(['company_id', 'bank_account_id'])->references(['company_id', 'id'])->on('bank_accounts')->restrictOnDelete();
            $table->foreign(['company_id', 'reversal_of_id'])->references(['company_id', 'id'])->on('deposit_closings')->restrictOnDelete();
            $table->foreign(['company_id', 'created_by'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('deposit_closing_id')->nullable()->after('opening_import_id');
            $table->foreign(['company_id', 'deposit_closing_id'])->references(['company_id', 'id'])->on('deposit_closings')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_kind_check');
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_kind_check
            CHECK (kind IN (".self::KINDS_BEFORE.", 'deposit_offset', 'deposit_refund', 'deposit_income')".self::RULES_BEFORE."
                AND (kind NOT IN ('deposit_offset', 'deposit_refund', 'deposit_income') OR (deposit_closing_id IS NOT NULL AND type = 'deposit'
                    AND (account = '491' OR (kind = 'deposit_offset' AND account = '418')))))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_kind_check');
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_kind_check CHECK (kind IN (".self::KINDS_BEFORE.')'.self::RULES_BEFORE.')');
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'deposit_closing_id']);
            $table->dropColumn('deposit_closing_id');
        });
        Schema::dropIfExists('deposit_closings');
    }
};
