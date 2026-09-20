<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 85 · Valutan ärvs nedåt. Se [[ADR-0037 Valutans arv]].
 *
 * Kontot bär valutan — den är botten i arvet och den enhet en global summa
 * uttrycks i. Kolumnen är obligatorisk, som `locale`, `timezone` och
 * `unit_system` redan är: ett `null` här vore en inställning utan svar, och
 * containerns arv (issue 85) har ingenting att falla tillbaka på.
 *
 * **Defaulten är förvalet vid registreringen.** `App\Actions\Auth\
 * CreatesUserWithPersonalAccount` skapar kontot utan att fråga efter en
 * valuta — registreringsformuläret tar namn, e-post och lösenord — och
 * kolumnens default är därför det värde ett nytt konto får. Den som vill
 * byta gör det i inställningarna efteråt (aldrig en blockerande fråga,
 * [[ADR-0037 Valutans arv]] § Konsekvenser).
 *
 * `SEK` och inte en härledning ur `locale`: språk är inte marknad. Kontots
 * övriga förval är svenska (sv_SE, Europe/Stockholm), och produkten är
 * svensk. Se PR:ens `## Frågor och antaganden` — [[ADR-0037 Valutans arv]]
 * säger "ett vettigt förval" utan att namnge det.
 *
 * Ingen CHECK på formatet, av samma skäl som `cost_entry.currency` saknar
 * det: en valuta är ingen uppräkning, och en stängd lista i schemat vore
 * domänen inbyggd i koden ([[ADR-0033 Produktens omfång]]). Formen prövas i
 * inmatningen (`alpha`, `size:3`).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('account', function (Blueprint $table) {
            $table->char('currency', 3)->default('SEK')->after('unit_system');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
