<?php

namespace App\Actions\Item;

use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Skriver en besöksrad för ett item — "nyligen besökta", se
 * [[ADR-0049 Nyligen besökta]] och [[M23 Mobilen och kartan]] § 160.
 *
 * **Anroparen är App\Http\Controllers\ItemController::show(), och anropet
 * ligger EFTER grinden.** Det är hela skillnaden mellan en visning och ett
 * försök: en nekad visning kastar `AuthorizationException` innan raden skrivs,
 * så ett item utanför omfånget lämnar varken en rad eller ett spår. Ingen
 * middleware gör det här, av samma skäl: bara itemsidan räknas —
 * containerns sidor, sökträffar och kartan är andra ytor, och en middleware
 * hade fångat dem också.
 *
 * **Ett nytt besök uppdaterar raden, det skriver ingen andra.** Upserten går
 * på det unika paret `(user_id, item_id)`, och `visited_at` flyttas fram. Att
 * läsa-i-förväg och välja INSERT eller UPDATE hade varit en kapplöpning mot en
 * dubbelklickad länk; indexet bär garanten, som för `favorite`
 * (issue 105).
 *
 * **Taket är tjugo rader per person, och det upprätthålls här.** En 21:a rad
 * raderar de äldsta utöver tjugo i SAMMA förfrågan — samma transaktion som
 * skrivningen, så en person kan aldrig ses med fler. Det behövs därför inget
 * schemalagt jobb, och tabellen är begränsad per person utan gallring
 * ([[ADR-0049 Nyligen besökta]] § Motivering). Ordningen är `visited_at`
 * fallande med `id` fallande som skiljedomare, så två besök inom samma sekund
 * ändå får en stabil ordning och den senast skrivna raden vinner.
 *
 * **Låset ligger på personens rad, och det tas före upserten.** Två nästan
 * samtidiga besök av samma person — två flikar — är två transaktioner som
 * var för sig bara ser sina egna ocommittade rader; utan låset kunde båda
 * räkna fram "tjugo att behålla" och lämna en tjugoförsta kvar. Den enskilda
 * personraden är en punkt att serialisera på: den finns alltid, och den har
 * inga gap som två insertioner kan krocka i, till skillnad från en låsning
 * över besöksraderna. Låset är en current read, så den som kommer sist ser
 * den förstas rader och gallrar mot dem.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten är anroparens ansvar — samma
 * linje som ListFavorites och ListItems: actionen är verktyget, inte grinden,
 * och en grind här hade varit en andra prövning av samma policy som
 * kontrollern redan gjort.
 */
class RecordRecentVisit
{
    /**
     * Antalet rader en person behåller — [[ADR-0049 Nyligen besökta]]
     * § Beslut. Listan visar högst tio (App\Actions\Item\ListRecentVisits);
     * taket är dubbelt så högt med flit, så att en lista som filtrerats av
     * omfånget fortfarande har något att fylla på ur.
     */
    public const LIMIT = 20;

    public function handle(User $user, Item $item): void
    {
        DB::transaction(function () use ($user, $item): void {
            $userId = $user->getKey();
            $now = now();

            // Serialiserar personens egna besök, så att taket nedan räknas
            // mot ett tillstånd som inte samtidigt ändras av en annan flik.
            DB::table($user->getTable())->where('id', $userId)->lockForUpdate()->first();

            // Upserten är en INSERT ... ON DUPLICATE KEY UPDATE mot det unika
            // paret. `created_at` står inte med bland de uppdaterade
            // kolumnerna: raden skapades vid det FÖRSTA besöket, och en
            // uppdatering av `visited_at` ska inte skriva om historiken.
            DB::table('recent_visit')->upsert(
                [[
                    'user_id' => $userId,
                    'item_id' => $item->getKey(),
                    'visited_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                ['user_id', 'item_id'],
                ['visited_at', 'updated_at'],
            );

            // De tjugo senaste behålls, och resten raderas. Id:na läses först
            // och raderingen sker mot en lista i minnet: MySQL tillåter inte
            // att en DELETE läser sin egen tabell i en delfråga.
            $behall = DB::table('recent_visit')
                ->where('user_id', $userId)
                ->orderByDesc('visited_at')
                ->orderByDesc('id')
                ->limit(self::LIMIT)
                ->pluck('id')
                ->all();

            DB::table('recent_visit')
                ->where('user_id', $userId)
                ->whereNotIn('id', $behall)
                ->delete();
        });
    }
}
