<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Работната таблица на месеца (PROJECT.md, т. 2 „Работна таблица на месеца“,
 * „Начисления“, „Начисления, уточнения“; т. 6 `charges_draft`).
 *
 * - charge_months: кой и кога е генерирал месеца (по-късно и издаването).
 * - upcoming_charges: предстоящи начисления (депозит, неустойка, друго),
 *   въведени през месеца за своя месец. Не се трият, а се отказват (с причина).
 * - charge_drafts: работните редове на месеца. Свободно се променят до
 *   издаването; ръчна промяна и махане пазят причината и кой ги е направил.
 *   Месецът е първото число. Видовете (type) са списък в кода (ChargeType).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charge_months', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->date('month');
            $table->timestamp('generated_at')->nullable();
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'month']);
            $table->foreign(['company_id', 'generated_by'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('upcoming_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('lease_contract_id');
            $table->unsignedBigInteger('property_id');
            $table->string('type', 20);
            $table->date('month');
            $table->decimal('amount', 12, 2);
            $table->text('note')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'month']);
            $table->foreign(['company_id', 'lease_contract_id'])->references(['company_id', 'id'])->on('lease_contracts')->restrictOnDelete();
            $table->foreign(['company_id', 'property_id'])->references(['company_id', 'id'])->on('properties')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE upcoming_charges ADD CONSTRAINT upcoming_charges_type_check CHECK (type IN ('deposit', 'penalty', 'other'))");
        DB::statement('ALTER TABLE upcoming_charges ADD CONSTRAINT upcoming_charges_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE upcoming_charges ADD CONSTRAINT upcoming_charges_month_check CHECK (DAYOFMONTH(month) = 1)');

        Schema::create('charge_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->date('month');
            $table->string('type', 20);
            $table->string('source', 20);                          // пресметнат, предстоящ, ръчен
            $table->unsignedBigInteger('lease_contract_id')->nullable();
            $table->unsignedBigInteger('owner_contract_id')->nullable();
            $table->unsignedBigInteger('property_id');
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->unsignedBigInteger('upcoming_charge_id')->nullable();
            $table->decimal('amount', 12, 2);                      // това, което ще се начисли
            $table->decimal('proposed_amount', 12, 2)->nullable(); // какво предлага сметката сега (празно = вече нищо)
            $table->decimal('computed_amount', 12, 2)->nullable(); // пресметнатият наем, ако е ползван договореният за първия период
            $table->unsignedTinyInteger('days')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->unsignedBigInteger('edited_by')->nullable();
            $table->string('edit_reason', 500)->nullable();
            $table->decimal('edited_proposed_amount', 12, 2)->nullable(); // какво предлагаше сметката при ръчната промяна
            $table->timestamp('removed_at')->nullable();
            $table->unsignedBigInteger('removed_by')->nullable();
            $table->string('remove_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'month', 'type']);
            $table->foreign(['company_id', 'lease_contract_id'])->references(['company_id', 'id'])->on('lease_contracts')->restrictOnDelete();
            $table->foreign(['company_id', 'owner_contract_id'])->references(['company_id', 'id'])->on('owner_contracts')->restrictOnDelete();
            $table->foreign(['company_id', 'property_id'])->references(['company_id', 'id'])->on('properties')->restrictOnDelete();
            $table->foreign(['company_id', 'tenant_id'])->references(['company_id', 'id'])->on('tenants')->restrictOnDelete();
            $table->foreign(['company_id', 'owner_id'])->references(['company_id', 'id'])->on('owners')->restrictOnDelete();
            $table->foreign(['company_id', 'upcoming_charge_id'])->references(['company_id', 'id'])->on('upcoming_charges')->restrictOnDelete();
            $table->foreign(['company_id', 'edited_by'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['company_id', 'removed_by'])->references(['company_id', 'id'])->on('users')->restrictOnDelete();
        });

        // Към собственика: по договор за управление; всичко друго: по договор за наем.
        DB::statement("ALTER TABLE charge_drafts ADD CONSTRAINT charge_drafts_side_check
            CHECK ((type = 'owner' AND owner_contract_id IS NOT NULL AND lease_contract_id IS NULL)
                OR (type <> 'owner' AND lease_contract_id IS NOT NULL AND owner_contract_id IS NULL))");
        DB::statement("ALTER TABLE charge_drafts ADD CONSTRAINT charge_drafts_source_check CHECK (source IN ('computed', 'upcoming', 'manual'))");
        DB::statement('ALTER TABLE charge_drafts ADD CONSTRAINT charge_drafts_month_check CHECK (DAYOFMONTH(month) = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('charge_drafts');
        Schema::dropIfExists('upcoming_charges');
        Schema::dropIfExists('charge_months');
    }
};
