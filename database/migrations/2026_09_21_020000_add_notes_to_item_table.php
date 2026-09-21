<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 96 · Itemets anteckningsfält. Se [[ADR-0041 Itemets vy]] § Beslut och
 * [[M16 Itemets vy]] § 96.
 *
 * `item.notes` är en NULLBAR TEXTKOLUMN bredvid `description`, och de två betyder
 * olika saker: `description` säger vad itemet ÄR — meningen en annan människa
 * behöver för att veta vad hon tittar på — medan anteckningen säger vad
 * användaren VET om det, ett fritt fält som växer med tiden.
 *
 * **Ingenting flyttar text mellan dem.** Ingen `UPDATE` som kopierar
 * `description` till `notes`, ingen fallback i någon vy, ingen sammanslagning i
 * något svar. Ett fält som bär två syften får förr eller senare två format, och
 * en migrering som fyller det nya fältet ur det gamla är precis den
 * sammanslagningen — den går inte att ångra, för efteråt går de två texterna
 * inte att skilja åt igen.
 *
 * **Ett fält, inte en tabell.** Ingen `item_note`, ingen tidsstämpel per stycke,
 * ingen rad per anteckning. Vill produkten senare ha en ström av daterade
 * anteckningar är det en tabell och ett nytt beslut; den här migreringen bygger
 * inte halva den.
 *
 * **Indexet byggs om i SAMMA migrering som kolumnen.** `item`-migreringen
 * (issue 13a § Beslut 4) skapade FULLTEXT-indexet i förväg med motiveringen att
 * det är dyrare att lägga till på en full tabell, och samma räkning gäller här:
 * kolumnen och indexet hör ihop. Kolumnlistan är dokumentets sex kolumner och
 * speglar App\Models\Item::toSearchableArray() — de två är dokumenterade
 * speglar och prövas mot varandra i tests/Feature/Item/AnteckningsfaltTest.
 *
 * FULLTEXT är en MySQL-företeelse och ligger kvar bakom samma
 * drivrutinskontroll som i dag: sqlite, som testsviten kör, har ingen
 * motsvarighet. `#[SearchUsingFullText]` är fortfarande INTE satt på
 * App\Models\Item (issue 15b § Beslut 3) — attributet får Scout att sända
 * `whereFullText(...)`, som sqlite faller på, och hela sviten skulle falla på
 * varje sökning. Indexet står redo och används när CI kör mot MariaDB.
 *
 * **Indexet får ett EGET namn, och det är inte kosmetiskt.** Laravel 13 härleder
 * namnet ur tabellen och kolumnlistan utan att korta det: den sexkolumnslista
 * den här migreringen vill ha hade gett
 * `item_name_description_manufacturer_model_serial_number_notes_fulltext`, 69
 * tecken, och MySQLs gräns är 64. Namnet går rakt igenom sqlite och faller
 * först i CI-jobbet `Migreringar` mot MySQL 8 — exakt den fällan issue 23b
 * (M3) betalade för, där två härledda indexnamn på 67 respektive 66 tecken
 * gjorde staging nere efter merge. `item_fulltext` är tolv tecken och står
 * still även om kolumnlistan ändras igen.
 *
 * Det gamla indexet släpps med sin kolumnlista och inte med sitt namn: Laravel
 * räknar fram samma 63 tecken ur listan, och då behöver namnet inte skrivas av
 * för hand och kunna bli fel.
 *
 * Kolumnen läggs efter `description`: de två läses bredvid varandra, och
 * ordningen i schemat ska visa att de hör ihop utan att vara samma sak.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('item', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('description');

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->dropFullText(['name', 'description', 'manufacturer', 'model', 'serial_number']);

                // Dokumentets sex kolumner — spegeln av
                // App\Models\Item::toSearchableArray().
                $table->fullText(
                    ['name', 'description', 'notes', 'manufacturer', 'model', 'serial_number'],
                    'item_fulltext'
                );
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('item', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->dropFullText('item_fulltext');
                $table->fullText(['name', 'description', 'manufacturer', 'model', 'serial_number']);
            }

            $table->dropColumn('notes');
        });
    }
};
