<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Изпратените писма (PROJECT.md, т. 6 и „PDF и имейл на известията“,
 * 24.11.2026): кое известие, на кой адрес е трябвало да отиде, къде е отишло
 * реално (пазачът на пощата), кога, от кого и с какъв резултат. Без
 * съдържанието. Само добавяне. Нова таблица: сегашните данни не се пипат.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);                               // statement, ...
            $table->unsignedBigInteger('statement_id')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('intended_to')->nullable();                // празно = няма имейл
            $table->string('delivered_to')->nullable();               // празно = само в лога или неизпратено
            $table->string('mode', 10)->nullable();                   // real, redirect, log
            $table->string('status', 10);                             // sent, logged, failed, no_email
            $table->string('error', 500)->nullable();                 // кратко съобщение при грешка
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('sent_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'statement_id']);
            $table->foreign(['company_id', 'statement_id'])->references(['company_id', 'id'])->on('statements')->restrictOnDelete();
            $table->foreign(['company_id', 'tenant_id'])->references(['company_id', 'id'])->on('tenants')->restrictOnDelete();
            $table->foreign(['company_id', 'user_id'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_log');
    }
};
