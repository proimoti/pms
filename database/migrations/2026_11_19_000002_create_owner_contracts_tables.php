<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Договор за управление (PROJECT.md, т. 2 „Договори“, „Договори, уточнения“,
 * „Комисиона с история“, т. 3.6).
 *
 * - owner_contracts: заглавието. Типът е един от петте и определя начина на
 *   изчисление (calculation), който се пази и тук, за да могат редовете да
 *   се свържат с него и базата да откаже грешна комбинация.
 * - owner_contract_annexes: анексите (основанието за всеки нов вариант).
 * - owner_contract_lines: имотите; всеки вариант (по анекс) е отделен ред „от–до“.
 * - owner_contract_commissions: процентът с история (само при тип с процент).
 * - owner_contract_payees: бенефициент и начин на плащане с история.
 *
 * Периодите са „от“ включително, „до“ без него (както при наема, т. 3.1).
 * Празно „до“ = без край.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('type', 30);
            $table->string('calculation', 10);
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->date('signed_on');                  // дата на договора (подписване), информация
            $table->date('end_on')->nullable();         // срок по договор, информация
            $table->date('terminated_on')->nullable();  // действителен край, денят не се брои
            $table->text('note')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'id', 'calculation']);

            $table->foreign(['company_id', 'owner_id'])->references(['company_id', 'id'])->on('owners')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE owner_contracts ADD CONSTRAINT owner_contracts_number_check CHECK (number > 0)');
        DB::statement("ALTER TABLE owner_contracts ADD CONSTRAINT owner_contracts_type_check
            CHECK (type IN ('representative_percent', 'representative_fixed', 'sublease_individual', 'sublease_legal', 'own'))");
        DB::statement("ALTER TABLE owner_contracts ADD CONSTRAINT owner_contracts_calculation_check
            CHECK ((type = 'representative_percent' AND calculation = 'percent')
                OR (type IN ('representative_fixed', 'sublease_individual', 'sublease_legal') AND calculation = 'fixed')
                OR (type = 'own' AND calculation = 'own'))");
        DB::statement("ALTER TABLE owner_contracts ADD CONSTRAINT owner_contracts_owner_check
            CHECK (type = 'own' OR owner_id IS NOT NULL)");

        Schema::create('owner_contract_annexes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('owner_contract_id');
            $table->unsignedInteger('number');   // анекс №
            $table->date('signed_on');           // дата на анекса
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'owner_contract_id', 'number'], 'owner_contract_annexes_number_unique');
            $table->unique(['company_id', 'owner_contract_id', 'id'], 'owner_contract_annexes_contract_unique');

            $table->foreign(['company_id', 'owner_contract_id'], 'owner_contract_annexes_contract_foreign')->references(['company_id', 'id'])->on('owner_contracts')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE owner_contract_annexes ADD CONSTRAINT owner_contract_annexes_number_check CHECK (number > 0)');

        // Общите колони на таблиците с история: договор, начин на изчисление, период, анекс.
        $versioned = function (Blueprint $table, string $name) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('owner_contract_id');
            $table->string('calculation', 10);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->unsignedBigInteger('annex_id')->nullable(); // празно = от самия договор

            // Начинът на изчисление трябва да е същият като на договора.
            $table->foreign(['company_id', 'owner_contract_id', 'calculation'], "{$name}_contract_foreign")
                ->references(['company_id', 'id', 'calculation'])->on('owner_contracts')->restrictOnDelete();
            // Анексът трябва да е от същия договор.
            $table->foreign(['company_id', 'owner_contract_id', 'annex_id'], "{$name}_annex_foreign")
                ->references(['company_id', 'owner_contract_id', 'id'])->on('owner_contract_annexes')->restrictOnDelete();
        };

        Schema::create('owner_contract_lines', function (Blueprint $table) use ($versioned) {
            $versioned($table, 'owner_contract_lines');
            $table->unsignedBigInteger('property_id');
            $table->decimal('fixed_amount', 12, 2)->nullable();          // само при фиксиран
            $table->unsignedBigInteger('closed_by_annex_id')->nullable(); // анекс, с който имотът е махнат
            $table->timestamps();

            $table->index(['company_id', 'property_id', 'valid_from'], 'owner_contract_lines_property_index');

            $table->foreign(['company_id', 'property_id'])->references(['company_id', 'id'])->on('properties')->restrictOnDelete();
            $table->foreign(['company_id', 'owner_contract_id', 'closed_by_annex_id'], 'owner_contract_lines_closed_by_annex_foreign')
                ->references(['company_id', 'owner_contract_id', 'id'])->on('owner_contract_annexes')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE owner_contract_lines ADD CONSTRAINT owner_contract_lines_amount_check
            CHECK ((calculation = 'fixed' AND fixed_amount IS NOT NULL AND fixed_amount > 0)
                OR (calculation <> 'fixed' AND fixed_amount IS NULL))");

        Schema::create('owner_contract_commissions', function (Blueprint $table) use ($versioned) {
            $versioned($table, 'owner_contract_commissions');
            $table->decimal('percent', 5, 2);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE owner_contract_commissions ADD CONSTRAINT owner_contract_commissions_calculation_check
            CHECK (calculation = 'percent')");
        DB::statement('ALTER TABLE owner_contract_commissions ADD CONSTRAINT owner_contract_commissions_percent_check
            CHECK (percent > 0 AND percent < 100)');

        Schema::create('owner_contract_payees', function (Blueprint $table) use ($versioned) {
            $versioned($table, 'owner_contract_payees');
            $table->unsignedBigInteger('beneficiary_id');
            $table->string('payment_method', 10);
            $table->timestamps();

            $table->foreign(['company_id', 'beneficiary_id'])->references(['company_id', 'id'])->on('beneficiaries')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE owner_contract_payees ADD CONSTRAINT owner_contract_payees_calculation_check
            CHECK (calculation IN ('percent', 'fixed'))");
        DB::statement("ALTER TABLE owner_contract_payees ADD CONSTRAINT owner_contract_payees_method_check
            CHECK (payment_method IN ('cash', 'bank'))");

        foreach (['owner_contract_lines', 'owner_contract_commissions', 'owner_contract_payees'] as $name) {
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_period_check
                CHECK (valid_to IS NULL OR valid_to > valid_from)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_contract_payees');
        Schema::dropIfExists('owner_contract_commissions');
        Schema::dropIfExists('owner_contract_lines');
        Schema::dropIfExists('owner_contract_annexes');
        Schema::dropIfExists('owner_contracts');
    }
};
