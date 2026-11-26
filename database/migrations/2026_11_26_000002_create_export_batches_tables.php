<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Пакетите за експорта към Микроинвест (PROJECT.md, т. 6 `export_batches` и
 * „Експорт към Микроинвест“, 26.11.2026). Пакетът помни кои записи от книгата
 * съдържа; един запис е само в един пакет (уникален ключ в базата). Книгата не
 * се пипа. Само добавяне. Нови таблици: сегашните данни не се пипат.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');            // пореден във фирмата
            $table->date('month');                         // месецът на записите
            $table->unsignedInteger('entries_count');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'month']);
            $table->foreign(['company_id', 'created_by'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('export_batch_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('export_batch_id');
            $table->unsignedBigInteger('ledger_entry_id');

            $table->unique(['company_id', 'ledger_entry_id']);   // един запис — само в един пакет
            $table->index(['company_id', 'export_batch_id']);
            $table->foreign(['company_id', 'export_batch_id'])->references(['company_id', 'id'])->on('export_batches')->restrictOnDelete();
            $table->foreign('ledger_entry_id')->references('id')->on('ledger_entries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_batch_entries');
        Schema::dropIfExists('export_batches');
    }
};
