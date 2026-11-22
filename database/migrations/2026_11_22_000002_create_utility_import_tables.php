<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Внос на платените битови сметки (PROJECT.md, т. 5.3 „Внос на платените
 * сметки“, „Внос, уточнения“, „Внос, допълнения“, 21–22.11.2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Внесените файлове (името е от т. 6; по-късно и за банковите файлове).
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('source', 20);                 // easypay, epay
            $table->string('file_name');
            $table->unsignedInteger('rows_count')->default(0);
            $table->unsignedInteger('bills_count')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->unsignedInteger('for_decision')->default(0);
            $table->unsignedInteger('skipped_fees')->default(0);
            $table->unsignedBigInteger('imported_by')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->foreign(['company_id', 'imported_by'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });

        // Платена сметка: един ред от файла. Само се добавя (не се променя и не се трие).
        Schema::create('paid_utility_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('import_batch_id');
            $table->unsignedBigInteger('utility_account_id');
            $table->string('source', 20);
            $table->string('reference', 40);              // номер на превода (EasyPay) или системен код (ePay)
            $table->dateTime('paid_at');
            $table->decimal('amount', 12, 2);
            $table->string('provider_name')->nullable();  // както е във файла
            $table->string('file_number', 50)->nullable(); // номерът, както е във файла
            $table->string('description', 500)->nullable();
            $table->text('details')->nullable();          // „Подробно описание“ от ePay, непроменено
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            // Защита от двоен внос и в базата.
            $table->unique(['company_id', 'source', 'reference']);
            $table->index(['company_id', 'utility_account_id']);
            $table->foreign(['company_id', 'import_batch_id'])->references(['company_id', 'id'])->on('import_batches')->restrictOnDelete();
            $table->foreign(['company_id', 'utility_account_id'])->references(['company_id', 'id'])->on('utility_accounts')->restrictOnDelete();
        });

        // Частите на сметката по имоти (при общ абонат — по съотношението; остатъкът към последния).
        Schema::create('paid_utility_bill_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('paid_utility_bill_id');
            $table->unsignedBigInteger('property_id');
            $table->decimal('share', 5, 2);
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['paid_utility_bill_id', 'property_id']);
            $table->index(['company_id', 'property_id']);
            $table->foreign(['company_id', 'paid_utility_bill_id'])->references(['company_id', 'id'])->on('paid_utility_bills')->restrictOnDelete();
            $table->foreign(['company_id', 'property_id'])->references(['company_id', 'id'])->on('properties')->restrictOnDelete();
        });

        // Редовете „за решение“; игнорираните се помнят и не излизат пак при повторен внос.
        Schema::create('utility_import_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('import_batch_id');
            $table->string('source', 20);
            $table->string('reference', 40)->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('provider_name')->nullable();
            $table->unsignedBigInteger('provider_id')->nullable(); // ако доставчикът е познат
            $table->string('number', 50)->nullable();
            $table->string('description', 500)->nullable();
            $table->text('details')->nullable();
            $table->string('reason', 30);
            $table->string('status', 20)->default('open');         // open, ignored, imported
            $table->string('ignore_reason', 500)->nullable();
            $table->unsignedBigInteger('paid_utility_bill_id')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'source', 'reference']);
            $table->index(['company_id', 'status']);
            $table->foreign(['company_id', 'import_batch_id'])->references(['company_id', 'id'])->on('import_batches')->restrictOnDelete();
            $table->foreign(['company_id', 'provider_id'])->references(['company_id', 'id'])->on('providers')->restrictOnDelete();
            $table->foreign(['company_id', 'paid_utility_bill_id'])->references(['company_id', 'id'])->on('paid_utility_bills')->restrictOnDelete();
            $table->foreign(['company_id', 'decided_by'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });

        // Записът в книгата (407) сочи платената сметка.
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('paid_utility_bill_id')->nullable()->after('statement_id');
            $table->foreign(['company_id', 'paid_utility_bill_id'])->references(['company_id', 'id'])->on('paid_utility_bills')->restrictOnDelete();
        });

        // Проверките в базата: и 407 (битови сметки, по имот, без договор за управление);
        // внесената платена сметка е само по 407 и сочи сметката.
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_account_check');
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_account_check
            CHECK ((account IN ('418', '491') AND lease_contract_id IS NOT NULL AND owner_contract_id IS NULL)
                OR (account = '408' AND owner_contract_id IS NOT NULL)
                OR (account = '407' AND owner_contract_id IS NULL))");
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_kind_check');
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_kind_check
            CHECK (kind IN ('issue', 'correction', 'manual', 'utility_paid')
                AND (kind <> 'utility_paid' OR (account = '407' AND paid_utility_bill_id IS NOT NULL)))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_kind_check');
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_kind_check CHECK (kind IN ('issue', 'correction', 'manual'))");
        DB::statement('ALTER TABLE ledger_entries DROP CONSTRAINT ledger_entries_account_check');
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_account_check
            CHECK ((account IN ('418', '491') AND lease_contract_id IS NOT NULL AND owner_contract_id IS NULL)
                OR (account = '408' AND owner_contract_id IS NOT NULL))");
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'paid_utility_bill_id']);
            $table->dropColumn('paid_utility_bill_id');
        });
        Schema::dropIfExists('utility_import_issues');
        Schema::dropIfExists('paid_utility_bill_parts');
        Schema::dropIfExists('paid_utility_bills');
        Schema::dropIfExists('import_batches');
    }
};
