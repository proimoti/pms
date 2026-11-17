<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Празно само за администратора на приложението.
            $table->foreignId('company_id')->nullable()->after('id')
                ->constrained()->restrictOnDelete();
            $table->string('role', 20)->after('password');
            $table->boolean('is_company_admin')->default(false)->after('role');
            $table->string('property_scope', 20)->default('assigned')->after('is_company_admin');
            $table->string('access_level', 20)->default('read')->after('property_scope');
            // Право да вижда ЕГН и IBAN.
            $table->boolean('can_view_sensitive')->default(false)->after('access_level');
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check
            CHECK (role IN ('app_admin', 'staff', 'owner', 'tenant'))");

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_company_check
            CHECK ((role = 'app_admin' AND company_id IS NULL)
                OR (role <> 'app_admin' AND company_id IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_company_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['role', 'is_company_admin', 'property_scope', 'access_level', 'can_view_sensitive']);
        });
    }
};
