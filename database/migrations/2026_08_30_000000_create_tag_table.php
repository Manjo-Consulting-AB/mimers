<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 12 · Taggar: platt lista per container. Se
 * [[Items och organisation]] § tag och [[ADR-0004 Fria taggar och
 * kategorier]] — kategorin är var saken hör hemma, taggarna är allt annat
 * man vill kunna filtrera på. Ingen förälder, inget djup, se issue 12 §
 * Omfång "Undertaggar, taggkategorier, taggrupper".
 *
 * `name` VARCHAR(100), inte 255 — dokumentet är uttryckligt, se issue 12 §
 * Att se upp med. `color` är CHAR(7) NULL, exakt `#rrggbb` i gemener,
 * ingenting mer — presentation är issue 56.
 *
 * UNIQUE `(container_id, name)` enligt dokumentet ser mjukraderade rader,
 * vilket är avsiktligt: det är grunden för återupplivningen i issue 12 §
 * Beslut 4 (POST med ett mjukraderat namn återställer raden i stället för
 * att kollidera med indexet). `(container_id, deleted_at)` läggs till
 * utöver dokumentets index — AGENTS.md § Databaskonventioner kräver att
 * varje listningsindex inkluderar `deleted_at`, och det unika indexet gör
 * inte det jobbet.
 *
 * Issue 12 § Beslut 5: "vinter" och "Vinter" är samma namn — jämförs som
 * databasen jämför det, ingen PHP-sidig strtolower ovanpå. mysql (prod)
 * ger det gratis via anslutningens `utf8mb4_unicode_ci`
 * (config/database.php), så kolumnen lämnas utan egen collation där.
 * sqlite (test) har ingen motsvarighet till den collationen — känt gap,
 * se [[Tankar]] — och är annars skiftlägeskänslig för `=`; `collate
 * nocase` läggs bara på för sqlite så att samma jämförelse ger samma
 * resultat i båda miljöerna, se förlagans mysql-only CHECK-villkor
 * (2026_08_25_010000_create_container_table.php) för samma sorts
 * drivrutinsgrening.
 *
 * Ingen `Tag::items()`-relation och ingen `item_tag`-tabell här — den hör
 * till issue 13b, se issue 12 § Omfång.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $isSqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::create('tag', function (Blueprint $table) use ($isSqlite) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('container_id')->constrained('container')->onDelete('restrict');
            $table->string('name', 100)->collation($isSqlite ? 'nocase' : null);
            $table->char('color', 7)->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['container_id', 'name']);
            $table->index(['container_id', 'deleted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tag');
    }
};
