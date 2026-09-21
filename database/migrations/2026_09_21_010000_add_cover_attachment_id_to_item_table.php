<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 93 · Itemets omslagsbild. Se [[ADR-0041 Itemets vy]] § Beslut och
 * § Konsekvenser, och [[M16 Itemets vy]] § 93.
 *
 * `item.cover_attachment_id` är en NULLBAR pekare till en av itemets egna
 * bilagor. Vilken bild som är itemets är ett faktum om ITEMET och bor därför
 * här — ingen `is_cover`, ingen sorteringsnyckel och ingen flagga på
 * `attachment`, som bär samma kolumner som förut.
 *
 * Pekaren är en PREFERENS och inte data: den som inte väljer någon bild får
 * ändå en, genom upplösningen i App\Actions\Item\ResolveItemCover — den valda
 * bilagan om den finns kvar och är en bild, annars itemets äldsta bild, annars
 * ingen.
 *
 * **ON DELETE SET NULL, till skillnad från husets RESTRICT** (AGENTS.md
 * § Databaskonventioner). Avvikelsen är medveten och står i [[ADR-0041
 * Itemets vy]] § Konsekvenser: en preferens får aldrig hindra papperskorgens
 * gallring. Bilagor raderas HÅRT på två ställen — App\Actions\Trash\
 * PurgeContent när ett item gallras ur papperskorgen, och
 * App\Actions\Attachment\PurgeAttachment när en bilaga gör det — och en
 * RESTRICT här hade gjort båda omöjliga: gallringen hade fallit på ett
 * främmandenyckelfel, och felet hade synts först i drift, i en nattlig
 * körning, i stället för i en testsvit. Pekaren nollställs alltså när bilagan
 * försvinner, och upplösningen faller tillbaka på regeln.
 *
 * Mjukradering rör inte pekaren: raden finns kvar, och en mjukraderad bilaga
 * faller bort genom SoftDeletes' globala scope i upplösningen — steg 1
 * misslyckas och steg 2 gäller ([[ADR-0008 Soft delete och papperskorg]]).
 *
 * Kolumnen läggs efter `position_note`, sist av itemets egna fält och före
 * `created_by_*`: den är itemets egen uppgift, inte historik om raden.
 *
 * Inget eget index: kolumnen listas aldrig, och den främmande nyckeln får
 * sitt index av databasen.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('item', function (Blueprint $table) {
            $table->foreignId('cover_attachment_id')
                ->nullable()
                ->after('position_note')
                ->constrained('attachment')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('item', function (Blueprint $table) {
            $table->dropForeign(['cover_attachment_id']);
            $table->dropColumn('cover_attachment_id');
        });
    }
};
