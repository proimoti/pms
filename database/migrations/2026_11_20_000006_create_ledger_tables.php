<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Издаване и заключване (PROJECT.md, т. 2 „Издаване и заключване“; т. 6).
 *
 * - statements, statement_lines: известията за дължими суми (не фактури), по
 *   едно за договор за наем и месец (базата не допуска второ), с пореден номер
 *   във фирмата. Не се променят.
 * - ledger_entries: книгата, само добавяне. Сметки: 418 наемател, 491 депозит,
 *   408 собственик. Пазят се месецът и датата на записа. Сумата е със знак
 *   (корекция може да намалява); 0 не се записва.
 * - period_locks: издадените (заключени) месеци. Не се отключват.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->date('month');
            $table->timestamp('locked_at');
            $table->unsignedBigInteger('locked_by')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'month']);
            $table->foreign(['company_id', 'locked_by'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->date('month');
            $table->unsignedBigInteger('lease_contract_id');
            $table->unsignedBigInteger('tenant_id');
            $table->decimal('total', 12, 2);
            $table->timestamp('issued_at');
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'lease_contract_id', 'month'], 'statements_contract_month_unique');
            $table->foreign(['company_id', 'lease_contract_id'])->references(['company_id', 'id'])->on('lease_contracts')->restrictOnDelete();
            $table->foreign(['company_id', 'tenant_id'])->references(['company_id', 'id'])->on('tenants')->restrictOnDelete();
            $table->foreign(['company_id', 'issued_by'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE statements ADD CONSTRAINT statements_number_check CHECK (number > 0)');
        DB::statement('ALTER TABLE statements ADD CONSTRAINT statements_month_check CHECK (DAYOFMONTH(month) = 1)');

        Schema::create('statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('statement_id');
            $table->unsignedBigInteger('property_id');
            $table->string('type', 20);
            $table->decimal('amount', 12, 2);
            $table->unsignedTinyInteger('days')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->foreign(['company_id', 'statement_id'])->references(['company_id', 'id'])->on('statements')->restrictOnDelete();
            $table->foreign(['company_id', 'property_id'])->references(['company_id', 'id'])->on('properties')->restrictOnDelete();
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->date('month');                     // за кой месец
            $table->date('entry_date');                // кога е записано (издаване или корекция)
            $table->string('account', 3);              // 418, 491, 408
            $table->string('kind', 20);                // издаване, корекция, ръчна корекция
            $table->string('type', 20);                // вид начисление
            $table->unsignedBigInteger('lease_contract_id')->nullable();
            $table->unsignedBigInteger('owner_contract_id')->nullable();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->unsignedBigInteger('statement_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'month', 'account']);
            $table->index(['company_id', 'lease_contract_id']);
            $table->index(['company_id', 'owner_contract_id']);
            $table->index(['company_id', 'property_id']);
            $table->foreign(['company_id', 'lease_contract_id'])->references(['company_id', 'id'])->on('lease_contracts')->restrictOnDelete();
            $table->foreign(['company_id', 'owner_contract_id'])->references(['company_id', 'id'])->on('owner_contracts')->restrictOnDelete();
            $table->foreign(['company_id', 'property_id'])->references(['company_id', 'id'])->on('properties')->restrictOnDelete();
            $table->foreign(['company_id', 'tenant_id'])->references(['company_id', 'id'])->on('tenants')->restrictOnDelete();
            $table->foreign(['company_id', 'owner_id'])->references(['company_id', 'id'])->on('owners')->restrictOnDelete();
            $table->foreign(['company_id', 'statement_id'])->references(['company_id', 'id'])->on('statements')->restrictOnDelete();
            $table->foreign(['company_id', 'created_by'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_account_check
            CHECK ((account IN ('418', '491') AND lease_contract_id IS NOT NULL AND owner_contract_id IS NULL)
                OR (account = '408' AND owner_contract_id IS NOT NULL))");
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_kind_check CHECK (kind IN ('issue', 'correction', 'manual'))");
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_amount_check CHECK (amount <> 0)');
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_month_check CHECK (DAYOFMONTH(month) = 1)');

        Schema::table('charge_drafts', function (Blueprint $table) {
            $table->unsignedBigInteger('statement_id')->nullable()->after('upcoming_charge_id');
            $table->foreign(['company_id', 'statement_id'])->references(['company_id', 'id'])->on('statements')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('charge_drafts', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'statement_id']);
            $table->dropColumn('statement_id');
        });
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('statement_lines');
        Schema::dropIfExists('statements');
        Schema::dropIfExists('period_locks');
    }
};
