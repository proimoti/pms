<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Удължаване с анекс (PROJECT.md, т. 2 „Удължаване с анекс“): всеки ред е
 * едно удължаване на срока (дата край) по един анекс: стар и нов срок.
 * Сегашният срок е в договора (end_on). Срокът не влияе на сумите.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['owner' => 'owner_contract', 'lease' => 'lease_contract'] as $prefix => $contract) {
            $table = "{$contract}_extensions";
            $key = "{$contract}_id";

            Schema::create($table, function (Blueprint $t) use ($table, $contract, $key) {
                $t->id();
                $t->foreignId('company_id')->constrained()->restrictOnDelete();
                $t->unsignedBigInteger($key);
                $t->unsignedBigInteger('annex_id');
                $t->date('previous_end_on');   // срокът преди анекса
                $t->date('end_on');            // новият срок
                $t->timestamps();

                // Един анекс удължава най-много веднъж.
                $t->unique(['company_id', $key, 'annex_id'], "{$table}_annex_unique");

                $t->foreign(['company_id', $key], "{$table}_contract_foreign")
                    ->references(['company_id', 'id'])->on("{$contract}s")->restrictOnDelete();
                // Анексът трябва да е от същия договор.
                $t->foreign(['company_id', $key, 'annex_id'], "{$table}_annex_foreign")
                    ->references(['company_id', $key, 'id'])->on("{$contract}_annexes")->restrictOnDelete();
            });

            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_term_check CHECK (end_on > previous_end_on)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_contract_extensions');
        Schema::dropIfExists('owner_contract_extensions');
    }
};
