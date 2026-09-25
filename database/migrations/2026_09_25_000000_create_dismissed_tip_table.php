<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 128 · Informationsytan. Se [[M19 Dashboarden]] § 128 och
 * [[ADR-0039 Containerns översikt]] § Konsekvenser.
 *
 * **Det dolda tillståndet hör till personen, inte till webbläsaren.** Ett
 * tips som kryssats bort ska vara borta i nästa webbläsare också, så raden
 * ligger på `user_id` och ingenting i `localStorage` rör den. Det är samma
 * skäl som gör `favorite` till en tabell i stället för en flagga (issue 105):
 * tillståndet är personens och följer henne.
 *
 * **Tabellen har ingen `ulid`.** Ingen rutt, ingen resurs och ingen vy
 * identifierar en enskild rad — API:et ser den aldrig, och ytan får sina
 * nycklar ur App\Support\Tips. Samma avvägning som `item_link` (issue 14
 * § Beslut 1) och `favorite`.
 *
 * **Tabellen har ingen `deleted_at`.** Ett dolt tips är inget användarskapat
 * innehåll: det finns ingenting att återställa, ingen papperskorg listar
 * tipstypen (issue 76 § Beslut 3), och ett `deleted_at` hade stått i vägen
 * för det unika paret nedan — en mjukraderad rad ligger kvar i indexet och
 * hade blockerat en ny rad för samma nyckel.
 *
 * **Tillståndet är per NYCKEL, inte per yta.** Det finns ingen rad som säger
 * "användaren har stängt panelen" — bara en rad per tips hon dolt. Ett tips
 * som läggs till senare har därför ingen rad och visas för den som dolt allt
 * som fanns förut (issuens krav 2). Det unika paret `(user_id, tip_key)` är
 * garanten för "en rad per person och tips", och det är indexet och inte en
 * läsning-i-förväg som bär den: DismissedTipController skriver rakt in och
 * fångar `UniqueConstraintViolationException` — samma linje som
 * `favorite` (issue 105), och av samma skäl: en check-then-act är en
 * kapplöpning mot ett dubbelklick.
 *
 * **`ON DELETE RESTRICT`, och det är konventionen och inte ett förbiseende.**
 * `favorite` kaskar med flit åt båda håll; här är svaret det motsatta.
 * Tabellen hör till PERSONEN, och personraderingen finns inte än —
 * `DeleteAccount` raderar kontot men aldrig användarraden. En kaskad hade
 * alltså inte rört något i dag, men den hade dolt att raden är personens:
 * den dag personraderingen byggs ska den här tabellen stå med i
 * [[Registerförteckning]] och städas av den, inte falla tyst för att en
 * förälder försvann. Restrict är det som gör den till en post att ta
 * ställning till.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('dismissed_tip', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user')->onDelete('restrict');
            $table->string('tip_key', 60);
            $table->timestamps();

            $table->unique(['user_id', 'tip_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dismissed_tip');
    }
};
