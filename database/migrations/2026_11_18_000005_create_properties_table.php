<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Имоти (PROJECT.md, т. 2 „Регистър, полета“). Собственикът не се пази тук:
 * определя се от договора за управление.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            // Цяло число, уникално във фирмата. Част от аналитиката в счетоводството.
            $table->unsignedInteger('number');
            $table->unsignedBigInteger('property_type_id');
            $table->unsignedBigInteger('broker_id')->nullable();

            // Структуриран адрес.
            $table->string('postal_code', 10)->nullable();
            $table->string('city', 100);
            $table->string('district', 100)->nullable();
            $table->string('street', 150)->nullable();
            $table->string('street_number', 20)->nullable();
            $table->string('entrance', 20)->nullable();
            $table->string('floor', 20)->nullable();
            $table->string('apartment', 20)->nullable();

            $table->decimal('area', 10, 2)->nullable(); // кв. м, по избор
            $table->text('note')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'city', 'street']);

            // Видът и отговорното лице са от същата фирма.
            $table->foreign(['company_id', 'property_type_id'])->references(['company_id', 'id'])->on('property_types')->restrictOnDelete();
            $table->foreign(['company_id', 'broker_id'])->references(['company_id', 'id'])->on('brokers')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE properties ADD CONSTRAINT properties_number_check CHECK (number > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
