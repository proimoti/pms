<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * „Договорен наем за първия период“ (PROJECT.md, т. 2 „Начисления, правила“ и
 * „Начисления, уточнения“, 20.11.2026): незадължителна сума на реда в договора
 * за наем. Ползва се вместо пресметнатия наем за първия месец на имота, само
 * ако той е непълен. Пази се само в първия вариант на реда; анекс не го пренася.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lease_contract_lines', function (Blueprint $table) {
            $table->decimal('first_period_rent', 12, 2)->nullable()->after('deposit');
        });

        DB::statement('ALTER TABLE lease_contract_lines ADD CONSTRAINT lease_contract_lines_first_period_rent_check
            CHECK (first_period_rent IS NULL OR first_period_rent >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE lease_contract_lines DROP CONSTRAINT lease_contract_lines_first_period_rent_check');

        Schema::table('lease_contract_lines', function (Blueprint $table) {
            $table->dropColumn('first_period_rent');
        });
    }
};
