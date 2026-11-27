<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Фирмата по адреса (PROJECT.md, „Портал и служители“, 27.11.2026): адресите
 * (домейн или поддомейн) на всяка фирма и имейл, уникален в рамките на фирмата.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            // Само името на адреса, с малки букви, без http:// и без порт (например pms.proimoti.bg).
            $table->string('host')->unique();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
            $table->unique(['company_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'email']);
            $table->unique('email');
        });
        Schema::dropIfExists('company_domains');
    }
};
