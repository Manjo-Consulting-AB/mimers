<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 177 · Senast öppnade filer. Se [[ADR-0051 Senast öppnade filer]] och
 * [[M24 Desktopdesignen]] § 177.
 *
 * **Öppningarna lagras per person, som `recent_visit`.** Dokumentfliken i
 * `docs/Design/dokument.png` har en rad *Senast öppnade*, och ingenting i
 * datamodellen svarade på frågan: händelseloggen ([[ADR-0043 Tre loggar]])
 * loggar ändringar och inte visningar. En kolumn `last_opened_at` på
 * `attachment` var det första förslaget, men då delar alla i containern samma
 * värde — en gäst hade sett när ägaren senast öppnade ett kvitto, och bara
 * den senaste öppningen hade funnits kvar.
 *
 * **Ett par per person och bilaga.** Det unika indexet
 * `(user_id, attachment_id)` är garanten för att en ny öppning UPPDATERAR
 * raden i stället för att skriva en andra: skrivningen är en upsert på paret,
 * och utan indexet hade varje omladdning av en förhandsvisning lagt en rad
 * till.
 *
 * **Indexet `(user_id, opened_at)` bär både läsningen och taket.** Läsningen
 * tar de senaste för en person och en container, och städningen i samma
 * förfrågan raderar det som ligger utanför de femtio — båda sorterar på
 * `opened_at` inom en `user_id`.
 *
 * **Ingen `ulid` och ingen `deleted_at`**, av samma skäl som för
 * `recent_visit`: raden syns aldrig utåt — listan visar bilagans ULID och
 * namn, och ingen rutt, resurs eller vy identifierar en enskild öppningsrad —
 * och den är inget innehåll att återställa: papperskorgen listar fyra typer,
 * och en öppningsrad hör inte bland dem.
 *
 * **`ON DELETE RESTRICT`, som husets konvention.** Raderna städas av
 * raderingarna själva och inte av en kaskad, och det är ett medvetet val: en
 * kaskad hade varit en radering ingen action har bett om (samma linje som
 * [[ADR-0045 Radering av konto och person]] § Beslut 2). `DeleteUser` tar
 * personens rader, och `PurgeAttachment` tar en gallrad bilagas — den vägen
 * bär också item- och containergallringen, eftersom båda går genom
 * `PurgeContent::attachment()` och `PurgeContainer` (se deras docblock).
 * `attachment_id` är ON DELETE RESTRICT och inte CASCADE av samma skäl: en
 * bilaga får inte försvinna tystare än genom `PurgeAttachment`, som är den
 * enda vägen UT ur papperskorgen och den som minskar `reference_count`.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('attachment_open', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user')->onDelete('restrict');
            $table->foreignId('attachment_id')->constrained('attachment')->onDelete('restrict');
            $table->timestamp('opened_at');
            $table->timestamps();

            $table->unique(['user_id', 'attachment_id']);
            $table->index(['user_id', 'opened_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attachment_open');
    }
};
