<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 411 · Tabellen `item_version`, se [[ADR-0088 Versionshistorik för
 * items]] och [[Items och organisation]] § item_version.
 *
 * En rad är en ögonblicksbild av itemets egna fält FÖRE en ändring. Filer
 * versioneras inte — omslagsbilden och taggarna bärs som id (Riktning 2).
 *
 * `field_values` ersätter de sex kolumnerna `manufacturer`, `model`,
 * `serial_number`, `position_note`, `purchased_at` och `warranty_until` när
 * de blir egna fält ([[ADR-0089 Egna fält på items]] § Riktning 9, ändrat
 * 2026-11-19). Nycklarna är fältens id.
 *
 * `billed_account_id` är containerns ägare när versionen skapades — ett
 * undantag från "den som skapar raden betalar", av samma skäl som
 * containerraden (Riktning 3).
 *
 * `replaced_by_agent_id` är en identifierare UTAN främmande nyckel, som
 * `audit_log.agent_id` (ADR-0087): en version får inte hindra att agenten
 * raderas.
 *
 * `updated_at` finns utöver dokumentets `created_at` därför att
 * App\Support\Usage\CountedRows::move() skriver `updated_at` när en betalare
 * flyttas, och AGENTS.md § Databaskonventioner begär båda stämplarna på
 * allt. `replaced_at` är kvar som sin egen kolumn: den säger när skrivningen
 * som ersatte versionen hände, inte när raden rördes.
 *
 * Ingen SoftDeletes: en version tas bort för gott och går inte via
 * papperskorgen (Riktning 6).
 *
 * **Filens tidsstämpel ligger före `2026_11_15_030000` med flit**, trots att
 * issuen är senare: den migrationen fyller `usage_counter.metadata_bytes`
 * genom App\Support\Usage\CountedRows, vars tabellista kommer ur
 * App\Support\Usage\MetadataSize::COLUMNS — och den listan bär nu
 * `item_version`. På en färsk databas hade ifyllningen annars frågat efter en
 * tabell som ännu inte fanns. Tabellen är tom när ifyllningen körs, så den
 * bidrar med noll rader där.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_version', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('item_id')->constrained('item')->cascadeOnDelete();
            $table->foreignId('billed_account_id')->constrained('account')->onDelete('restrict');
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->json('field_values');
            $table->foreignId('category_id')->nullable()->constrained('category')->nullOnDelete();
            $table->foreignId('cover_attachment_id')->nullable()->constrained('attachment')->nullOnDelete();
            $table->string('icon', 64)->nullable();
            $table->string('icon_color', 16)->nullable();
            $table->json('tag_ids');
            $table->foreignId('replaced_by_user_id')->nullable()->constrained('user')->nullOnDelete();
            $table->unsignedBigInteger('replaced_by_agent_id')->nullable();
            $table->timestamp('replaced_at');
            $table->timestamps();

            $table->index(['item_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_version');
    }
};
