<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал: кой, кога и какво е променил (PROJECT.md, т. 2 и т. 6).
 * Само се добавя. Защитените полета (например IBAN) се записват без стойности.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            // Празно, когато промяната е от команда на сървъра (без влязъл потребител).
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('auditable_type', 50);
            $table->unsignedBigInteger('auditable_id');
            $table->string('event', 20);
            $table->json('changes');
            $table->timestamp('created_at')->useCurrent();

            // Потребителят е от същата фирма.
            $table->foreign(['company_id', 'user_id'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
            $table->index(['company_id', 'auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
