<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Банковите сметки на фирмата и телефонът ѝ (PROJECT.md, „PDF и имейл на
 * известията“, 24.11.2026; таблицата е предвидена в т. 6 и за вноса на
 * банкови файлове). Може да са няколко и да се сменят (архивират); една е
 * „за известията“. IBAN на фирмата не е шифрован: печата се в известието.
 * Сегашните данни не се пипат.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('phone', 50)->nullable()->after('email');
        });

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('bank_name')->nullable();
            $table->string('iban', 34);
            $table->string('bic', 11)->nullable();
            $table->boolean('for_statements')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->unique(['company_id', 'iban']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
