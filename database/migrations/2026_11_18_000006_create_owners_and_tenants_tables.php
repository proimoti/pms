<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Собственици и наематели (PROJECT.md, т. 2 „Регистър, полета“ и „уточнения“).
 * Два отделни регистъра с еднакви полета. Човек, който е и двете, е два записа.
 * ЕГН не се въвежда във версия 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['owners', 'tenants'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('number');
                $table->string('kind', 20)->default('individual'); // физическо или юридическо лице
                $table->string('name');
                $table->string('eik', 13)->nullable();          // само при юридическо лице
                $table->string('manager_name')->nullable();      // МОЛ, само при юридическо лице

                // Адрес: по лична карта при физическо лице, седалище при юридическо.
                $table->char('country', 2)->default('BG');
                $table->string('postal_code', 10)->nullable();
                $table->string('city', 100)->nullable();
                $table->string('district', 100)->nullable();
                $table->string('street', 150)->nullable();
                $table->string('street_number', 20)->nullable();
                $table->string('entrance', 20)->nullable();
                $table->string('floor', 20)->nullable();
                $table->string('apartment', 20)->nullable();

                $table->string('phone', 50)->nullable();
                $table->string('email')->nullable();
                $table->text('note')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();

                $table->unique(['company_id', 'number']);
                $table->unique(['company_id', 'id']);
                $table->index(['company_id', 'name']);
            });

            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_number_check CHECK (number > 0)");
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_kind_check CHECK (kind IN ('individual', 'legal'))");
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_legal_fields_check
                CHECK (kind = 'legal' OR (eik IS NULL AND manager_name IS NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('owners');
    }
};
