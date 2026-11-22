<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Битовите сметки в работната таблица (PROJECT.md, „Битови сметки в месеца“,
 * 22.11.2026; част 4а): редът „битова сметка“ сочи платената сметка и абоната,
 * редът „фиксирана сума“ — само абоната; и двата пазят периода (от–до).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('charge_drafts', function (Blueprint $table) {
            $table->unsignedBigInteger('paid_utility_bill_id')->nullable()->after('upcoming_charge_id');
            $table->unsignedBigInteger('utility_account_id')->nullable()->after('paid_utility_bill_id');
            $table->date('period_from')->nullable()->after('utility_account_id');
            $table->date('period_to')->nullable()->after('period_from');

            $table->index(['company_id', 'paid_utility_bill_id']);
            $table->foreign(['company_id', 'paid_utility_bill_id'])->references(['company_id', 'id'])->on('paid_utility_bills')->restrictOnDelete();
            $table->foreign(['company_id', 'utility_account_id'])->references(['company_id', 'id'])->on('utility_accounts')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE charge_drafts ADD CONSTRAINT charge_drafts_utility_check
            CHECK ((type = 'utility' AND paid_utility_bill_id IS NOT NULL AND utility_account_id IS NOT NULL AND period_from IS NOT NULL AND period_to IS NOT NULL)
                OR (type = 'fixed_fee' AND paid_utility_bill_id IS NULL AND utility_account_id IS NOT NULL AND period_from IS NOT NULL AND period_to IS NOT NULL)
                OR (type NOT IN ('utility', 'fixed_fee') AND paid_utility_bill_id IS NULL AND utility_account_id IS NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE charge_drafts DROP CONSTRAINT charge_drafts_utility_check');

        Schema::table('charge_drafts', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'paid_utility_bill_id']);
            $table->dropForeign(['company_id', 'utility_account_id']);
            $table->dropIndex(['company_id', 'paid_utility_bill_id']);
            $table->dropColumn(['paid_utility_bill_id', 'utility_account_id', 'period_from', 'period_to']);
        });
    }
};
