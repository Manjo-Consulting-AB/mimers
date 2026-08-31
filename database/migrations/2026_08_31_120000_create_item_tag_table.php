<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 13b · Item och taggar. Se [[Items och organisation]] § item_tag och
 * [[ADR-0004 Fria taggar och kategorier]].
 *
 * Kopplingstabellen mellan item och tagg, förlagan är den enda andra pivoten
 * i repot (`account_user`, 2026_08_24_115144_create_account_user_table.php):
 * singular tabellnamn, ingen `ulid`, timestamps, UNIQUE över paret.
 *
 * `ulid` och `deleted_at` saknas medvetet (issue 13b § Beslut 3): kopplingen
 * exponeras aldrig som egen resurs — den nås genom itemet, precis som
 * `account_user` nås genom kontot eller användaren — och den är inte innehåll
 * utan en relation mellan två saker som var för sig mjukraderas. Att ta bort
 * en tagg från ett item raderar pivotraden på riktigt; återupplivas taggen
 * kommer dess kopplingar tillbaka med den.
 *
 * Båda indexen behövs och är inte samma sak (§ Beslut 1): UNIQUE
 * `(item_id, tag_id)` betjänar "vilka taggar har det här itemet", medan
 * `(tag_id, item_id)` betjänar "vilka items har den här taggen" — den
 * vanligaste frågan i hela systemet när strukturen är helt fri, se
 * [[Items och organisation]] § item_tag.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('item_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('item')->onDelete('restrict');
            $table->foreignId('tag_id')->constrained('tag')->onDelete('restrict');
            $table->timestamps();

            $table->unique(['item_id', 'tag_id']);
            $table->index(['tag_id', 'item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_tag');
    }
};
