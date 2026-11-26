<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * „Настройки за експорта“ (PROJECT.md, т. 6 `export_rules` и „Експорт към
 * Микроинвест“, 26.11.2026): по един ред за всяка настройка на фирмата
 * (ключ → стойност). Без начални стойности: всичко въвежда администраторът.
 * Нова таблица: сегашните данни не се пипат.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('key', 50);
            $table->string('value', 50)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_rules');
    }
};
