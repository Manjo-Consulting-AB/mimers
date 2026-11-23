<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * M82 · issue 470 · Sökfrågor som gav noll träffar, [[ADR-0094 Hjälpcentret]]
 * § 8. Frågan är avidentifierad innan den når hit: e-postadresser och
 * sifferföljder borttagna, normaliserad och kapad på ordgräns
 * (App\Support\Help\SearchGapQuery). Det finns ett tak på 1000 nya frågor per
 * dag, och gallringen raderar på riktigt efter 90 dagar
 * (App\Console\PrunesHelpData).
 *
 * **Ingen koppling till person, konto eller IP**, och ingen `deleted_at`: raden
 * är ingen användarinnehållsrad utan en anonym summa, och den gallras bort för
 * gott.
 *
 * UNIQUE `(date, query)` — en fråga som redan räknats den dagen räknas upp i
 * stället för att läggas till igen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_search_gap_daily', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('query', 60);
            $table->unsignedInteger('count');
            $table->timestamps();

            $table->unique(['date', 'query']);
        });
    }

    /**
     * Migrationer rullas aldrig tillbaka i produktion; down() finns för testmiljön.
     */
    public function down(): void
    {
        Schema::dropIfExists('help_search_gap_daily');
    }
};
