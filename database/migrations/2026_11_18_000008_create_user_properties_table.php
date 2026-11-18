<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Прикачени имоти към служител с обхват „само прикачените“ (PROJECT.md, т. 2).
 * Потребителят и имотът са от една и съща фирма.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('property_id');
            $table->timestamps();

            $table->unique(['company_id', 'user_id', 'property_id']);
            $table->index(['user_id', 'property_id']);

            $table->foreign(['company_id', 'user_id'])->references(['company_id', 'id'])->on('users')->cascadeOnDelete();
            $table->foreign(['company_id', 'property_id'])->references(['company_id', 'id'])->on('properties')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_properties');
    }
};
