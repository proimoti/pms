<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Резервирани номера на имоти (PROJECT.md, т. 2 „Регистър, уточнения“ и
 * „Договори, уточнения“): номер, който вече не е на имот, но не се освобождава
 * (ръчно сменен или внесен от Микроинвест). Само се добавя, не се трие.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reserved_property_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('reason', 30); // changed (ръчно сменен) или imported (от вноса)
            $table->timestamp('created_at')->nullable();

            $table->unique(['company_id', 'number']);
        });

        DB::statement('ALTER TABLE reserved_property_numbers ADD CONSTRAINT reserved_property_numbers_number_check CHECK (number > 0)');
        DB::statement("ALTER TABLE reserved_property_numbers ADD CONSTRAINT reserved_property_numbers_reason_check CHECK (reason IN ('changed', 'imported'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('reserved_property_numbers');
    }
};
