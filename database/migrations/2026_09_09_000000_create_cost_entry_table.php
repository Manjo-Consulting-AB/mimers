<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 45a · Kostnadsregistrering. Se [[Items och organisation]] § cost_entry
 * och [[ADR-0016 Kostnadsregistrering]].
 *
 * Kolumnerna är dokumentets, i den ordningen — inget mer: ingen
 * `schedule_occurrence`-koppling, ingen `vat_amount`, inget kvittofält, ingen
 * leverantörstabell (ADR-0016 § Vad som medvetet utelämnas).
 *
 * `container_id` är DENORMALISERAD från itemet (dokumentets index och 45a §
 * Beslut 2): varje rapportfråga scopas till en container och ska inte behöva
 * joina item. Säkert eftersom ett item aldrig byter container (issue 21 §
 * Beslut 3). `amount` är SIGNED BIGINT — negativa belopp är tillåtna och är
 * poängen med kolumnen (kreditfaktura, returnerad del, garantiersättning), till
 * skillnad från byten.
 *
 * Indexen är dokumentets tre, alla med `deleted_at` — AGENTS.md §
 * Databaskonventioner: varje index som används för listning måste inkludera
 * `deleted_at`. Listningen sorterar på `incurred_on`, och 45b:s
 * leverantörsuppslag och 46:s gruppering läser de två sista.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cost_entry', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('container_id')->constrained('container')->onDelete('restrict');
            $table->foreignId('item_id')->constrained('item')->onDelete('restrict');
            $table->date('incurred_on');
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->string('description', 255);
            $table->string('supplier', 255)->nullable();
            $table->foreignId('created_by_user_id')->constrained('user')->onDelete('restrict');
            $table->foreignId('created_by_account_id')->constrained('account')->onDelete('restrict');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['item_id', 'deleted_at', 'incurred_on']);
            $table->index(['container_id', 'deleted_at', 'incurred_on']);
            $table->index(['container_id', 'deleted_at', 'supplier']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cost_entry');
    }
};
