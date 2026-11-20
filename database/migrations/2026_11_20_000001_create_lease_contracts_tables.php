<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Договор за наем (PROJECT.md, т. 2 „Договори“, „Договори, уточнения“).
 *
 * - lease_contracts: заглавието (номер в отделна поредица, наемател, дати).
 * - lease_contract_annexes: анексите (основанието за всеки нов вариант).
 * - lease_contract_lines: имотите с наем и договорен депозит; всеки вариант
 *   (по анекс) е отделен ред „от–до“.
 *
 * Периодите са „от“ включително, „до“ без него (т. 3.1). Празно „до“ = без край.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->unsignedBigInteger('tenant_id');
            $table->date('signed_on');                  // дата на договора (подписване), информация
            $table->date('end_on')->nullable();         // срок по договор, информация
            $table->date('terminated_on')->nullable();  // действителен край, денят не се брои
            $table->text('note')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'id']);

            $table->foreign(['company_id', 'tenant_id'])->references(['company_id', 'id'])->on('tenants')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE lease_contracts ADD CONSTRAINT lease_contracts_number_check CHECK (number > 0)');

        Schema::create('lease_contract_annexes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('lease_contract_id');
            $table->unsignedInteger('number');   // анекс №
            $table->date('signed_on');           // дата на анекса
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'lease_contract_id', 'number'], 'lease_contract_annexes_number_unique');
            $table->unique(['company_id', 'lease_contract_id', 'id'], 'lease_contract_annexes_contract_unique');

            $table->foreign(['company_id', 'lease_contract_id'], 'lease_contract_annexes_contract_foreign')
                ->references(['company_id', 'id'])->on('lease_contracts')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE lease_contract_annexes ADD CONSTRAINT lease_contract_annexes_number_check CHECK (number > 0)');

        Schema::create('lease_contract_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('lease_contract_id');
            $table->unsignedBigInteger('property_id');
            $table->decimal('rent', 12, 2);                 // месечен наем
            $table->decimal('deposit', 12, 2)->default(0);  // договорен депозит (491/договор/имот)
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->unsignedBigInteger('annex_id')->nullable();           // празно = от самия договор
            $table->unsignedBigInteger('closed_by_annex_id')->nullable(); // анекс, с който имотът е махнат
            $table->timestamps();

            $table->index(['company_id', 'property_id', 'valid_from'], 'lease_contract_lines_property_index');

            $table->foreign(['company_id', 'lease_contract_id'], 'lease_contract_lines_contract_foreign')
                ->references(['company_id', 'id'])->on('lease_contracts')->restrictOnDelete();
            $table->foreign(['company_id', 'property_id'], 'lease_contract_lines_property_foreign')
                ->references(['company_id', 'id'])->on('properties')->restrictOnDelete();
            // Анексът трябва да е от същия договор.
            $table->foreign(['company_id', 'lease_contract_id', 'annex_id'], 'lease_contract_lines_annex_foreign')
                ->references(['company_id', 'lease_contract_id', 'id'])->on('lease_contract_annexes')->restrictOnDelete();
            $table->foreign(['company_id', 'lease_contract_id', 'closed_by_annex_id'], 'lease_contract_lines_closed_by_annex_foreign')
                ->references(['company_id', 'lease_contract_id', 'id'])->on('lease_contract_annexes')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE lease_contract_lines ADD CONSTRAINT lease_contract_lines_rent_check CHECK (rent > 0)');
        DB::statement('ALTER TABLE lease_contract_lines ADD CONSTRAINT lease_contract_lines_deposit_check CHECK (deposit >= 0)');
        DB::statement('ALTER TABLE lease_contract_lines ADD CONSTRAINT lease_contract_lines_period_check
            CHECK (valid_to IS NULL OR valid_to > valid_from)');
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_contract_lines');
        Schema::dropIfExists('lease_contract_annexes');
        Schema::dropIfExists('lease_contracts');
    }
};
