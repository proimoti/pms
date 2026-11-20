<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Фиксираната сума и наемът са 0 или повече (PROJECT.md, „Дати на договорите“
 * и „Договор за наем“, променено 20.11.2026): 0 е за придружаващ имот,
 * например гараж към апартамент. Отрицателна сума базата не приема.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE owner_contract_lines DROP CONSTRAINT owner_contract_lines_amount_check');
        DB::statement("ALTER TABLE owner_contract_lines ADD CONSTRAINT owner_contract_lines_amount_check
            CHECK ((calculation = 'fixed' AND fixed_amount IS NOT NULL AND fixed_amount >= 0)
                OR (calculation <> 'fixed' AND fixed_amount IS NULL))");

        DB::statement('ALTER TABLE lease_contract_lines DROP CONSTRAINT lease_contract_lines_rent_check');
        DB::statement('ALTER TABLE lease_contract_lines ADD CONSTRAINT lease_contract_lines_rent_check CHECK (rent >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE owner_contract_lines DROP CONSTRAINT owner_contract_lines_amount_check');
        DB::statement("ALTER TABLE owner_contract_lines ADD CONSTRAINT owner_contract_lines_amount_check
            CHECK ((calculation = 'fixed' AND fixed_amount IS NOT NULL AND fixed_amount > 0)
                OR (calculation <> 'fixed' AND fixed_amount IS NULL))");

        DB::statement('ALTER TABLE lease_contract_lines DROP CONSTRAINT lease_contract_lines_rent_check');
        DB::statement('ALTER TABLE lease_contract_lines ADD CONSTRAINT lease_contract_lines_rent_check CHECK (rent > 0)');
    }
};
