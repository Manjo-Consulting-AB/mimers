<?php

namespace App\Actions\Attachment;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Skriver en öppningsrad för en bilaga — "senast öppnade filer", se
 * [[ADR-0051 Senast öppnade filer]] och [[M24 Desktopdesignen]] § 177.
 *
 * **Anroparen är App\Http\Controllers\AttachmentDownloadController, och
 * anropet ligger EFTER grinden.** Det är hela skillnaden mellan en öppning
 * och ett försök: en nekad förfrågan kastar `AuthorizationException` innan
 * raden skrivs, så en bilaga utanför omfånget lämnar varken en rad eller ett
 * spår. Ingen middleware gör det här, av samma skäl som för `recent_visit`:
 * bara `files.download` räknas, och `files.deliver` på filoriginet hade
 * fångats också — den rutt som bär en signerad URL och inte vet vem som
 * frågar ([[ADR-0019 Filleverans]]).
 *
 * **VILKA förfrågningar som räknas avgörs av anroparen, inte här.** Actionen
 * är verktyget: den tar en person och en bilaga och skriver raden. Att
 * miniatyrer (`?variant=thumb`) inte är öppningar, och att containerns egen
 * bild ([[ADR-0047 Containerns bild]]) aldrig räknas, är villkor som hör till
 * requesten och till bilagans slag — de bor i kontrollern, på samma plats som
 * grinden, så att reglerna om vad en öppning ÄR står på ett ställe.
 *
 * **En ny öppning uppdaterar raden, den skriver ingen andra.** Upserten går
 * på det unika paret `(user_id, attachment_id)`, och `opened_at` flyttas
 * fram. Att läsa-i-förväg och välja INSERT eller UPDATE hade varit en
 * kapplöpning mot en dubbelklickad länk; indexet bär garanten, som för
 * `recent_visit` och `favorite`.
 *
 * **Taket är femtio rader per person, och det upprätthålls här.** En 51:a rad
 * raderar de äldsta utöver femtio i SAMMA förfrågan — samma transaktion som
 * skrivningen, så en person kan aldrig ses med fler. Det behövs därför inget
 * schemalagt jobb ([[ADR-0051 Senast öppnade filer]] § Beslut). Taket är
 * högre än de tjugo i `recent_visit` eftersom en fil öppnas oftare än ett
 * item besöks, och raden ska räcka till fem kort även efter att filtret tagit
 * bort några. Ordningen är `opened_at` fallande med `id` fallande som
 * skiljedomare, så två öppningar inom samma sekund ändå får en stabil ordning
 * och den senast skrivna raden vinner.
 *
 * **Låset ligger på personens rad, och det tas före upserten.** Två nästan
 * samtidiga öppningar av samma person — två flikar — är två transaktioner som
 * var för sig bara ser sina egna ocommittade rader; utan låset kunde båda
 * räkna fram "femtio att behålla" och lämna en femtioförsta kvar. Den
 * enskilda personraden är en punkt att serialisera på: den finns alltid, och
 * den har inga gap som två insertioner kan krocka i, till skillnad från en
 * låsning över öppningsraderna.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten är anroparens ansvar — samma
 * linje som PurgeAttachment och RecordRecentVisit: actionen är verktyget,
 * inte grinden, och en grind här hade varit en andra prövning av samma policy
 * som kontrollern redan gjort.
 */
class RecordAttachmentOpen
{
    /**
     * Antalet rader en person behåller — [[ADR-0051 Senast öppnade filer]]
     * § Beslut. Listan visar fem (App\Actions\Attachment\ListRecentOpens);
     * taket är tio gånger så högt med flit, så att en lista som filtrerats av
     * omfånget fortfarande har något att fylla på ur.
     */
    public const LIMIT = 50;

    public function handle(User $user, Attachment $attachment): void
    {
        DB::transaction(function () use ($user, $attachment): void {
            $userId = $user->getKey();
            $now = now();

            // Serialiserar personens egna öppningar, så att taket nedan
            // räknas mot ett tillstånd som inte samtidigt ändras av en annan
            // flik.
            DB::table($user->getTable())->where('id', $userId)->lockForUpdate()->first();

            // Upserten är en INSERT ... ON DUPLICATE KEY UPDATE mot det unika
            // paret. `created_at` står inte med bland de uppdaterade
            // kolumnerna: raden skapades vid den FÖRSTA öppningen, och en
            // uppdatering av `opened_at` ska inte skriva om historiken.
            DB::table('attachment_open')->upsert(
                [[
                    'user_id' => $userId,
                    'attachment_id' => $attachment->getKey(),
                    'opened_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                ['user_id', 'attachment_id'],
                ['opened_at', 'updated_at'],
            );

            // De femtio senaste behålls, och resten raderas. Id:na läses
            // först och raderingen sker mot en lista i minnet: MySQL tillåter
            // inte att en DELETE läser sin egen tabell i en delfråga.
            $behall = DB::table('attachment_open')
                ->where('user_id', $userId)
                ->orderByDesc('opened_at')
                ->orderByDesc('id')
                ->limit(self::LIMIT)
                ->pluck('id')
                ->all();

            DB::table('attachment_open')
                ->where('user_id', $userId)
                ->whereNotIn('id', $behall)
                ->delete();
        });
    }
}
