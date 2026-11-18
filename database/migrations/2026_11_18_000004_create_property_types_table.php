<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Видове имоти, отделно за всяка фирма (PROJECT.md, т. 2 „Регистър, уточнения“). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'name']);
            $table->unique(['company_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_types');
    }
};
