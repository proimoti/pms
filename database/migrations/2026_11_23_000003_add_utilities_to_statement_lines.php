<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Битовите редове в известието (PROJECT.md, „Битови сметки в месеца“, 22.11.2026;
 * част 4б): редът пази платената сметка, абоната и периода, както са били при
 * издаването (известието не се променя).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statement_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('paid_utility_bill_id')->nullable()->after('type');
            $table->unsignedBigInteger('utility_account_id')->nullable()->after('paid_utility_bill_id');
            $table->date('period_from')->nullable()->after('utility_account_id');
            $table->date('period_to')->nullable()->after('period_from');

            $table->index(['company_id', 'paid_utility_bill_id']);
            $table->index(['company_id', 'utility_account_id']);
            $table->foreign(['company_id', 'paid_utility_bill_id'])->references(['company_id', 'id'])->on('paid_utility_bills')->restrictOnDelete();
            $table->foreign(['company_id', 'utility_account_id'])->references(['company_id', 'id'])->on('utility_accounts')->restrictOnDelete();
        });

        // Както в работната таблица: битовият ред има сметка, абонат и период; фиксираната такса — абонат и период.
        DB::statement("ALTER TABLE statement_lines ADD CONSTRAINT statement_lines_utility_check
            CHECK ((type = 'utility' AND paid_utility_bill_id IS NOT NULL AND utility_account_id IS NOT NULL AND period_from IS NOT NULL AND period_to IS NOT NULL)
                OR (type = 'fixed_fee' AND paid_utility_bill_id IS NULL AND utility_account_id IS NOT NULL AND period_from IS NOT NULL AND period_to IS NOT NULL)
                OR (type NOT IN ('utility', 'fixed_fee') AND paid_utility_bill_id IS NULL AND utility_account_id IS NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE statement_lines DROP CONSTRAINT statement_lines_utility_check');
        Schema::table('statement_lines', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'paid_utility_bill_id']);
            $table->dropForeign(['company_id', 'utility_account_id']);
            $table->dropIndex(['company_id', 'paid_utility_bill_id']);
            $table->dropIndex(['company_id', 'utility_account_id']);
            $table->dropColumn(['paid_utility_bill_id', 'utility_account_id', 'period_from', 'period_to']);
        });
    }
};
