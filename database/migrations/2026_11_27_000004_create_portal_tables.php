<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Основата на портала (план „Портал и служители“, 27.11.2026):
 * - нова роля „client“ (профил в портала; какво вижда, решават връзките);
 * - portal_links: профил ↔ наемател или собственик от същата фирма (точно един
 *   от двата; един запис — един профил);
 * - trusted_devices: запомнени устройства за 30 дни (само отпечатъкът).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check
            CHECK (role IN ('app_admin', 'staff', 'owner', 'tenant', 'client'))");

        Schema::create('portal_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestamps();

            // Всичко е от една фирма.
            $table->foreign(['company_id', 'user_id'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['company_id', 'tenant_id'])->references(['company_id', 'id'])->on('tenants')->restrictOnDelete();
            $table->foreign(['company_id', 'owner_id'])->references(['company_id', 'id'])->on('owners')->restrictOnDelete();
            $table->unique(['company_id', 'tenant_id']);
            $table->unique(['company_id', 'owner_id']);
            $table->index(['company_id', 'user_id']);
        });
        DB::statement('ALTER TABLE portal_links ADD CONSTRAINT portal_links_one_record_check
            CHECK ((tenant_id IS NULL) <> (owner_id IS NULL))');

        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->char('token', 64)->unique(); // sha256 на бисквитката
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->foreign(['company_id', 'user_id'])->references(['company_id', 'id'])->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');
        Schema::dropIfExists('portal_links');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check
            CHECK (role IN ('app_admin', 'staff', 'owner', 'tenant'))");
    }
};
