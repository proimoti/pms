<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Изплащания към собственици и бенефициенти (PROJECT.md, „Плащания и салда“,
 * 26.11.2026): по договор за управление; бенефициентът към датата се запомня.
 * Сторното е нов ред с reversal_of_id (само веднъж). Само добавяне.
 * В книгата: 408 − с вид „payout“ (сторно: „payout_reversal“, 408 +), по имот, с
 * връзка към изплащането. Нова таблица и нова колона: сегашните данни не се пипат.
 */
return new class extends Migration
{
    private const KINDS_BEFORE = "'issue', 'correction', 'manual', 'utility_paid', 'fund_income', 'fund_expense', 'manager_cost', 'payment', 'payment_reversal'";

    private const RULES_BEFORE = "
                AND (kind <> 'utility_paid' OR (account = '407' AND paid_utility_bill_id IS NOT NULL AND fund_id IS NULL))
                AND (kind <> 'fund_expense' OR (account = '407' AND fund_id IS NOT NULL AND paid_utility_bill_id IS NOT NULL AND amount > 0))
                AND (kind <> 'fund_income' OR (account = '407' AND fund_id IS NOT NULL AND amount < 0))
                AND (kind <> 'manager_cost' OR (account = '407' AND fund_id IS NULL AND property_id IS NOT NULL
                    AND paid_utility_bill_id IS NOT NULL AND utility_decision_id IS NOT NULL))
                AND (kind NOT IN ('payment', 'payment_reversal') OR (account = '418' AND payment_id IS NOT NULL AND type = 'payment'))
                AND (kind <> 'payment' OR amount < 0) AND (kind <> 'payment_reversal' OR amount > 0)";

    public function up(): void
    {
        Schema::create('owner_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('owner_contract_id');
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->unsignedBigInteger('beneficiary_id')->nullable();   // получателят към датата
            $table->decimal('amount', 12, 2);                            // сторното е с минус
            $table->date('paid_on');
            $table->string('method', 10);                                 // bank, cash
            $table->unsignedBigInteger('bank_account_id')->nullable();   // сметката на фирмата
            $table->text('note')->nullable();
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['company_id', 'id']);
            $table->unique('reversal_of_id');
            $table->index(['company_id', 'owner_contract_id']);
            $table->foreign(['company_id', 'owner_contract_id'])->references(['company_id', 'id'])->on('owner_contracts')->restrictOnDelete();
            $table->foreign(['company_id', 'owner_id'])->references(['company_id', 'id'])->on('owners')->restrictOnDelete();
            $table->foreign(['company_id', 'beneficiary_id'])->references(['company_id', 'id'])->on('beneficiaries')->restrictOnDelete();
            $table->foreign(['company_id', 'bank_account_id'])->references(['company_id', 'id'])->on('bank_accounts')->restrictOnDelete();
            $table->foreign(['company_id', 'reversal_of_id'])->references(['company_id', 'id'])->on('owner_payouts')->restrictOnDelete();
            $table->foreign(['company_id', 'created_by'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE owner_payouts ADD CONSTRAINT owner_payouts_check CHECK (method IN ('bank', 'cash')
            AND (reversal_of_id IS NULL AND amount > 0 OR reversal_of_id IS NOT NULL AND amount < 0 AND reason IS NOT NULL))");

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('owner_payout_id')->nullable()->after('payment_id');
            $table->foreign(['company_id', 'owner_payout_id'])->references(['company_id', 'id'])->on('owner_payouts')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_kind_check');
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_kind_check
            CHECK (kind IN (".self::KINDS_BEFORE.", 'payout', 'payout_reversal')".self::RULES_BEFORE."
                AND (kind NOT IN ('payout', 'payout_reversal') OR (account = '408' AND owner_payout_id IS NOT NULL AND type = 'payment'))
                AND (kind <> 'payout' OR amount < 0) AND (kind <> 'payout_reversal' OR amount > 0))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_kind_check');
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_kind_check CHECK (kind IN (".self::KINDS_BEFORE.')'.self::RULES_BEFORE.')');
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'owner_payout_id']);
            $table->dropColumn('owner_payout_id');
        });
        Schema::dropIfExists('owner_payouts');
    }
};
