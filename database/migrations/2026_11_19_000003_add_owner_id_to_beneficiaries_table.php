<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Бенефициент „това е самият собственик“ (PROJECT.md, т. 2 „Договори, уточнения“):
 * връзка към собственика, за да не се въвежда IBAN наново. Най-много един
 * такъв бенефициент на собственик. Връзката не ограничава избора в договорите.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->unsignedBigInteger('owner_id')->nullable()->after('company_id');

            $table->unique(['company_id', 'owner_id']);
            $table->foreign(['company_id', 'owner_id'])->references(['company_id', 'id'])->on('owners')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('beneficiaries', function (Blueprint $table) {
            $table->dropForeign(['company_id', 'owner_id']);
            $table->dropUnique(['company_id', 'owner_id']);
            $table->dropColumn('owner_id');
        });
    }
};
