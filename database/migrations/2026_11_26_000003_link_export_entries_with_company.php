<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Връзката „запис в пакет → запис от книгата“ да минава и през фирмата (като
 * всички връзки между таблиците на фирмата). В ledger_entries се добавя само
 * ключ (фирма, номер на записа); записите в книгата не се пипат.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->unique(['company_id', 'id']);
        });

        Schema::table('export_batch_entries', function (Blueprint $table) {
            $table->dropForeign(['ledger_entry_id']);
            $table->foreign(['company_id', 'ledger_entry_id'])->references(['company_id', 'id'])->on('ledger_entries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('export_batch_entries', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'ledger_entry_id']);
            $table->foreign('ledger_entry_id')->references('id')->on('ledger_entries')->restrictOnDelete();
        });

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'id']);
        });
    }
};
