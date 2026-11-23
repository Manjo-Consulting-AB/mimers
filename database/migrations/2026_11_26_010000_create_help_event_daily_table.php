<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * M82 · issue 470 · Hjälpens mätning, [[ADR-0094 Hjälpcentret]] § 8 och
 * [[ADR-0079 Övervakning]] § 4. Anonyma summor per dag över vad som görs i
 * hjälpen: artikelvisningar, sökningar, klick på sökträffar och öppnade eller
 * okända sammanhang.
 *
 * **Ingen användare, inget konto, ingen IP och ingen session.** Inget
 * `user_id`, `account_id`, `ip` eller `session_id` — en rad som kunde knytas
 * till en person vore en personuppgift, och hela poängen är att mätningen inte
 * kräver samtycke. Ingen `ulid` och ingen `deleted_at` heller: raden är en
 * räknare utan användarinnehåll.
 *
 * **Tom sträng i stället för NULL med flit.** MySQL släpper igenom dubbletter
 * av NULL i ett unikt index, så en nullbar `article_id` eller `context` hade
 * gjort varje uppräkning till en ny rad. Det unika indexet över alla fyra
 * kolumnerna gör att uppräkningen är en enda upsert.
 *
 * `event` är en uppräkning som VARCHAR med CHECK-villkor, aldrig MySQL ENUM
 * (AGENTS.md § Databaskonventioner). CHECK-villkoret läggs bara på mysql —
 * sqlite saknar ALTER ... ADD CONSTRAINT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_event_daily', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('event', 30);
            $table->string('article_id', 80)->default('');
            $table->string('context', 100)->default('');
            $table->unsignedInteger('count');
            $table->timestamps();

            $table->unique(['date', 'event', 'article_id', 'context']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE help_event_daily ADD CONSTRAINT help_event_daily_event_check CHECK (event IN ('article_view', 'search', 'search_click', 'context_open', 'context_unknown'))");
        }
    }

    /**
     * Migrationer rullas aldrig tillbaka i produktion; down() finns för testmiljön.
     */
    public function down(): void
    {
        Schema::dropIfExists('help_event_daily');
    }
};
