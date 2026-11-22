<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Доставчици и абонати на битовите услуги (PROJECT.md, т. 3.4 „Битови сметки,
 * уточнения“, т. 5.3 „Внос на платените сметки“ и „Внос, уточнения“, 21.11.2026).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Доставчик: име в приложението и постоянните имена във файловете на EasyPay и ePay.
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('easypay_name')->nullable();
            $table->string('epay_name')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'name']);
            $table->unique(['company_id', 'easypay_name']);
            $table->unique(['company_id', 'epay_name']);
        });

        // Абонат. Номерът се пази точно (с нулите отпред); number_key е същият
        // номер без нулите отпред, само за сравнение (EasyPay ги губи).
        Schema::create('utility_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('provider_id')->nullable();
            $table->string('number', 50)->nullable();
            $table->string('number_key', 50)->nullable();
            $table->string('kind', 20);                      // електроенергия, вода, ...
            $table->string('billing', 10);                   // реален или фиксиран
            $table->decimal('fixed_amount', 12, 2)->nullable();
            $table->unsignedTinyInteger('period_start_day')->default(1);
            $table->unsignedTinyInteger('period_end_day')->nullable(); // празно = последният ден
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'provider_id', 'number_key']);
            $table->foreign(['company_id', 'provider_id'])->references(['company_id', 'id'])->on('providers')->restrictOnDelete();
        });

        // Имотите на абоната със съотношение; последният (по position) поема остатъка.
        Schema::create('utility_account_properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('utility_account_id');
            $table->unsignedBigInteger('property_id');
            $table->decimal('share', 5, 2);
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['utility_account_id', 'property_id']);
            $table->index(['company_id', 'property_id']);
            $table->foreign(['company_id', 'utility_account_id'])->references(['company_id', 'id'])->on('utility_accounts')->restrictOnDelete();
            $table->foreign(['company_id', 'property_id'])->references(['company_id', 'id'])->on('properties')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utility_account_properties');
        Schema::dropIfExists('utility_accounts');
        Schema::dropIfExists('providers');
    }
};
