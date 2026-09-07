<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 76 · Utlåning. Se [[Items och organisation]] § loan och
 * [[ADR-0017 Missbruksvektorer]] § 7.
 *
 * Kolumnerna är dokumentets, i den ordningen — inget mer: ingen
 * `container_id` (containern härleds ur itemet, som aldrig byter container),
 * ingen `created_by_*` (utlåningen är en händelse på ett item, inte en
 * innehållsskapelse).
 *
 * Datumen är DATE, inte TIMESTAMP — en utlåning är en dag, inte ett klockslag
 * (issue 76 § Beslut 1). `borrower_email` är en kontaktuppgift i vyn, ALDRIG
 * en mottagaradress — se § Beslut 8 och ADR-0017 § 7.
 *
 * Indexet är dokumentets `(item_id, returned_at)` UTÖKAT med `deleted_at` —
 * AGENTS.md § Databaskonventioner: varje index som används för listning
 * måste inkludera `deleted_at`. Listningen och den öppna utlåningen filtrerar
 * på alla tre (issue 76 § Beslut 2).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('loan', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('item_id')->constrained('item')->onDelete('restrict');
            $table->string('borrower_name', 255);
            $table->string('borrower_email', 255)->nullable();
            $table->date('lent_at');
            $table->date('due_at')->nullable();
            $table->date('returned_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['item_id', 'returned_at', 'deleted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan');
    }
};
