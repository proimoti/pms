<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Покана по имейл (план „Портал и служители“, 27.11.2026). Пази се само
 * отпечатъкът (sha256) на връзката, не самата връзка. Нова покана заменя
 * старата. Връзката важи до invitation_expires_at и само веднъж.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('invitation_token', 64)->nullable()->unique()->after('disabled_at');
            $table->timestamp('invitation_expires_at')->nullable()->after('invitation_token');
            $table->timestamp('invitation_accepted_at')->nullable()->after('invitation_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['invitation_token']);
            $table->dropColumn(['invitation_token', 'invitation_expires_at', 'invitation_accepted_at']);
        });
    }
};
