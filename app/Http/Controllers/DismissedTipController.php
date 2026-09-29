<?php

namespace App\Http\Controllers;

use App\Models\DismissedTip;
use App\Support\Tips;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Krysset i informationsytan — stäng hela ytan, se [[M19 Dashboarden]] § 128
 * och [[ADR-0039 Containerns översikt]] § Konsekvenser.
 *
 * **Två rutter och ingen sida.** Ytan står på dashboarden och på containerns
 * översikt (resources/js/components/InfoPanel.vue): `store()` nedan döljer ETT
 * tips och `dismissAll()` döljer hela listan, och det är krysset som postar
 * till den senare. Någon egen sida för ett tips hade varit en andra väg till
 * samma skrivning.
 *
 * **Krysset stänger ytan och är inte ett "Next tip".** Postade det till
 * `store()` med tipset som stod framme hade servern svarat med listan utan
 * just det tipset, och nästa tips hade stått där i stället — krysset hade
 * gjort samma sak som bläddringsknappen. `dismissAll()` svarar i stället med
 * en tom lista, och då ritas ingen yta alls (issuens *Klart när*).
 *
 * **Nyckeln prövas mot listan, och en okänd ger 404.** `App\Support\Tips` är
 * den enda som vet vilka tipsen är (issuens krav 3), så grinden är
 * `Tips::knows()` och ingenting annat — ingen FormRequest, ingen validering
 * mot en tabell. En nyckel utanför listan ska inte bli en rad i
 * `dismissed_tip`: en sådan rad hade varit ett svar på ett tips som inte
 * finns, och den hade dessutom gjort en framtida nyckel med samma namn dold
 * i förväg. `dismissAll()` behöver ingen grind: den rör bara listan
 * `Tips::keys()`, som per konstruktion är kända nycklar.
 *
 * **Skrivningen är idempotent.** Att dölja samma tips två gånger ger ingen
 * andra rad — det unika paret `(user_id, tip_key)` är garanten — och det är
 * inte ett fel. Ett dubbelklick möter indexet i stället för att svara 500,
 * och svaret blir detsamma som för den första. Samma form och samma skäl som
 * App\Http\Controllers\FavoriteController.
 *
 * **Användaren är den inloggade och aldrig kroppen.** `user_id` kommer ur
 * sessionen och `tip_key` ur ruttens nyckel eller ur `Tips` — båda sätts
 * explicit, aldrig via massildelning (`DismissedTip` har dem utanför
 * `#[Fillable]`).
 *
 * **Ingen flash-kod.** Ytan är sitt eget kvitto: tipset försvinner, eller
 * hela ytan med den, och en ruta som sade *tipset är dolt* hade varit en
 * andra bekräftelse på samma sak. Rutterna bakom `auth`, som resten av
 * webben — en utloggad besökare når dem aldrig.
 */
class DismissedTipController extends Controller
{
    /**
     * POST /tips/{key}/dismiss — dölj ett enskilt tips, 302 tillbaka till
     * sidan ytan står på.
     *
     * Krysset postar inte längre hit (det stänger hela ytan genom
     * `dismissAll()` nedan), men rutten behålls oförändrad: skrivningen per
     * nyckel är kontraktet `Tips::knows()` vaktar, och den är den enda vägen
     * till en enskild rad.
     *
     * `back()` och inte en omdirigering till en fast rutt: ytan finns på två
     * adresser (dashboarden och containerns översikt), och en omdirigering
     * till den ena hade skickat den som kryssade på den andra dit.
     */
    public function store(Request $request, string $key): RedirectResponse
    {
        abort_unless(app(Tips::class)->knows($key), 404);

        $tip = new DismissedTip;
        $tip->tip_key = $key;

        try {
            $request->user()->dismissedTips()->save($tip);
        } catch (UniqueConstraintViolationException) {
            // Redan dold — ingen andra rad, och inget fel att visa.
        }

        return back();
    }

    /**
     * POST /tips/dismiss — dölj allt `Tips::keys()` rymmer, 302 tillbaka
     * till sidan ytan står på. Kryssets rutt.
     *
     * **Alla tips, beständigt.** Beslutet står i issuen: krysset döljer hela
     * listan och inte bara det tips som stod framme, och raderna ligger kvar
     * i `dismissed_tip` — ytan är stängd i nästa webbläsare också. Ett tips
     * som läggs till i en senare release har ingen rad och visas därför för
     * den som stängt ytan (issuens krav 2): det finns ingen rad som säger
     * "användaren har stängt panelen", bara en rad per nyckel.
     *
     * **EN skrivning och inte en per tips.** Hela listan går in i en
     * `insertOrIgnore`, vilket är samma "en fråga per anrop" som
     * `Tips::visibleFor()` håller på läs-sidan. Raderna får samma tidsstämpel
     * och skrivs i `Tips::keys()` ordning.
     *
     * **`insertOrIgnore` och inte `store()`:s fångade undantag.** Att dölja
     * allt två gånger är ett dubbelklick, inte ett fel — men en `save()` per
     * nyckel hade kastat och fångat tre gånger för den som redan stängt
     * ytan, och `upsert()` hade skrivit om `updated_at` på rader som redan
     * fanns. `insertOrIgnore` lämnar de befintliga raderna orörda och är
     * därför det som gör "rör inte redan dolda tips" sant: en rad som redan
     * finns behåller sin tidsstämpel.
     */
    public function dismissAll(Request $request): RedirectResponse
    {
        $now = now();

        $rows = array_map(fn (string $key): array => [
            'user_id' => $request->user()->id,
            'tip_key' => $key,
            'created_at' => $now,
            'updated_at' => $now,
        ], app(Tips::class)->keys());

        DismissedTip::insertOrIgnore($rows);

        return back();
    }
}
