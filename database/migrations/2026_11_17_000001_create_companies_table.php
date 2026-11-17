<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('eik', 13);
            $table->string('vat_number', 15)->nullable();
            $table->string('manager_name')->nullable(); // МОЛ
            $table->string('address')->nullable();
            $table->string('email')->nullable();
            // Откъде идват плащанията на наемателите (PROJECT.md, т. 5.1).
            $table->string('payment_source', 30)->default('manual');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
