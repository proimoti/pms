<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Начални салда (PROJECT.md, „Начални салда“, 26.11.2026): един внос за фирма
 * (418, 491 и фондовете) към дата; сторното е нов ред с reversal_of_id (само
 * веднъж) и причина. В книгата: вид „opening“ (сторно: „opening_reversal“), с
 * връзка към вноса, по 418, 491 и 407 на фонда. Само добавяне. Нова таблица и
 * нова колона: сегашните данни не се пипат.
 */
return new class extends Migration
{
    private const KINDS_BEFORE = "'issue', 'correction', 'manual', 'utility_paid', 'fund_income', 'fund_expense', 'manager_cost', 'payment', 'payment_reversal', 'payout', 'payout_reversal'";

    private const RULES_BEFORE = "
                AND (kind <> 'utility_paid' OR (account = '407' AND paid_utility_bill_id IS NOT NULL AND fund_id IS NULL))
                AND (kind <> 'fund_expense' OR (account = '407' AND fund_id IS NOT NULL AND paid_utility_bill_id IS NOT NULL AND amount > 0))
                AND (kind <> 'fund_income' OR (account = '407' AND fund_id IS NOT NULL AND amount < 0))
                AND (kind <> 'manager_cost' OR (account = '407' AND fund_id IS NULL AND property_id IS NOT NULL
                    AND paid_utility_bill_id IS NOT NULL AND utility_decision_id IS NOT NULL))
                AND (kind NOT IN ('payment', 'payment_reversal') OR (account = '418' AND payment_id IS NOT NULL AND type = 'payment'))
                AND (kind <> 'payment' OR amount < 0) AND (kind <> 'payment_reversal' OR amount > 0)
                AND (kind NOT IN ('payout', 'payout_reversal') OR (account = '408' AND owner_payout_id IS NOT NULL AND type = 'payment'))
                AND (kind <> 'payout' OR amount < 0) AND (kind <> 'payout_reversal' OR amount > 0)";

    public function up(): void
    {
        Schema::create('opening_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->date('as_of');
            $table->unsignedInteger('rows_count')->default(0);
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['company_id', 'id']);
            $table->unique('reversal_of_id');
            $table->foreign(['company_id', 'reversal_of_id'])->references(['company_id', 'id'])->on('opening_imports')->restrictOnDelete();
            $table->foreign(['company_id', 'created_by'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('opening_import_id')->nullable()->after('owner_payout_id');
            $table->foreign(['company_id', 'opening_import_id'])->references(['company_id', 'id'])->on('opening_imports')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_kind_check');
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_kind_check
            CHECK (kind IN (".self::KINDS_BEFORE.", 'opening', 'opening_reversal')".self::RULES_BEFORE."
                AND (kind NOT IN ('opening', 'opening_reversal') OR (account IN ('418', '491', '407') AND opening_import_id IS NOT NULL)))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_kind_check');
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_kind_check CHECK (kind IN (".self::KINDS_BEFORE.')'.self::RULES_BEFORE.')');
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'opening_import_id']);
            $table->dropColumn('opening_import_id');
        });
        Schema::dropIfExists('opening_imports');
    }
};
