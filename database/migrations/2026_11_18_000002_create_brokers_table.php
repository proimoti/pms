<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Отговорни лица (PROJECT.md, т. 2 „Регистър, уточнения“). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brokers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // За връзки „отговорно лице от същата фирма“ от други таблици.
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brokers');
    }
};
