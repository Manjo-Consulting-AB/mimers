<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 112 · Den rättsliga spärren. Se [[ADR-0043 Tre loggar]] § Den
 * rättsliga spärren och [[Registerförteckning]].
 *
 * Tabellen är systemets svar på en enda fråga: får det här kontots innehåll
 * gallras? **Ett konto är spärrat när det har en rad utan `lifted_at`** —
 * frågan ställs av App\Models\LegalHold::covers(), och svaret bor i tabellens
 * namn och inte i en regel varje gallringsjobb måste komma ihåg.
 *
 * **`created_at` är när spärren sattes.** Ingen egen `held_at`: raden skapas
 * i samma andetag som spärren sätts, och en andra kolumn hade varit samma
 * tidpunkt skriven två gånger. `updated_at` rör sig när spärren hävs, och
 * `lifted_at` är den tidpunkten i klartext.
 *
 * **Ingen `ulid`** — ingen rutt, ingen resurs och ingen vy identifierar en
 * spärrrad (AGENTS.md § Databaskonventioner: kravet gäller tabeller som syns
 * i API:et, och den här har ingen yta alls).
 *
 * **Ingen `deleted_at`.** En rad tas aldrig bort: en hävd spärr lämnar sin
 * rad kvar, för att en spärr en gång funnits är i sig en uppgift värd att
 * bevara. Att häva är att sätta `lifted_at`, inte att städa.
 *
 * **`account_id` är en identifierare utan främmande nyckel**, och det är
 * samma avvägning som issue 107 gjorde för `audit_log`: en nyckel säger att
 * raden inte får finnas utan det den pekar på, och den här radens poäng är
 * motsatsen. Två konkreta skäl:
 *
 * - Raden överlever kontot. Ett konto vars spärr hävts är fortfarande
 *   vilande, och `delete-dormant-accounts` ska kunna radera det — med
 *   ON DELETE RESTRICT hade varje konto som någonsin varit spärrat fällt
 *   account-raderingen varje natt, för alltid.
 * - Raden får aldrig kaskadraderas. Att spärren försvinner tillsammans med
 *   kontot vore att radera just det bevis spärren finns för att bevara.
 *
 * App\Actions\LegalHold\PlaceLegalHold tar en Account-modell och inte ett
 * id, så kolumnen kan inte peka på ett konto som aldrig funnits när raden
 * skrivs. Efter en kontoradering pekar den på ingen — samma sak som
 * `audit_log.account_id` gör, och avsiktligt.
 *
 * Indexet `(account_id, lifted_at)` är covers() läsväg; den främmande
 * nyckelns index hade bara täckt `account_id`.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('legal_hold', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->string('case_number', 120);
            $table->text('reason');
            $table->timestamp('lifted_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'lifted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('legal_hold');
    }
};
