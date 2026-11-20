<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 425 · Det destruktiva steget i expand/contract för itemets sex fasta
 * fält. Se [[ADR-0089 Egna fält på items]] § Riktning 5 och
 * [[Items och organisation]] § item.
 *
 * Issue 420 kopierade värdena till `item_field_value` och koden slutade läsa
 * och skriva kolumnerna. Releasen med 420 och 423 är utrullad innan den här
 * går ut — annars finns kod kvar som läser kolumner som försvinner
 * (AGENTS.md § Databaskonventioner). Kvar att göra här är att ta bort
 * `manufacturer`, `model`, `serial_number`, `purchased_at`, `warranty_until`
 * och `position_note`.
 *
 * **FULLTEXT-indexet släpps före kolumnerna och byggs efter dem.** Indexet
 * heter `item_fulltext` — namnet sattes i issue 15b just för att det ska stå
 * still när kolumnlistan ändras, och Laravel:s härledda namn på den listan
 * passerade 64-teckengränsen. En kolumn kan inte tas bort under ett index som
 * bär den, och släppet kommer därför först.
 *
 * **Den nya listan är de sökbara kolumnerna i
 * App\Models\Item::toSearchableArray():** `name`, `description` och `notes`.
 * `notes` kom in i indexet i issue 96 och stannar när de sex fasta fälten
 * lämnar det; issue 425:s text säger `(name, description)`, men den listan
 * skrevs innan `notes` fanns och indexet ska spegla sökmängden —
 * tests/Feature/Item/AnteckningsfaltTest prövar spegeln efter 425.
 *
 * **Drivrutinsvakten.** sqlite i testsviten har ingen FULLTEXT-motsvarighet,
 * så indexraderna ligger bakom samma `mysql`-kontroll som i
 * create_item_table och add_notes_to_item_table. Kolumnborttagningen körs
 * överallt.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const COLUMNS = [
        'manufacturer',
        'model',
        'serial_number',
        'purchased_at',
        'warranty_until',
        'position_note',
    ];

    public function up(): void
    {
        $mysql = Schema::getConnection()->getDriverName() === 'mysql';

        if ($mysql) {
            Schema::table('item', function (Blueprint $table): void {
                $table->dropFullText('item_fulltext');
            });
        }

        Schema::table('item', function (Blueprint $table): void {
            $table->dropColumn(self::COLUMNS);
        });

        if ($mysql) {
            Schema::table('item', function (Blueprint $table): void {
                $table->fullText(['name', 'description', 'notes'], 'item_fulltext');
            });
        }
    }

    /**
     * Gör ingenting: migrationer rullas aldrig tillbaka i produktion, fel
     * åtgärdas framåt (AGENTS.md § Databaskonventioner). Att lägga tillbaka
     * kolumnerna hade dessutom gett tomma fält — värdena bor i
     * `item_field_value` sedan issue 420.
     */
    public function down(): void
    {
        //
    }
};
