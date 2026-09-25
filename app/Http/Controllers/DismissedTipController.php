<?php

namespace App\Http\Controllers;

use App\Models\DismissedTip;
use App\Support\Tips;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Krysset i informationsytan — dölj ett tips, se [[M19 Dashboarden]] § 128
 * och [[ADR-0039 Containerns översikt]] § Konsekvenser.
 *
 * **En rutt och ingen sida.** Ytan står på dashboarden och på containerns
 * översikt (resources/js/components/InfoPanel.vue), och krysset postar hit.
 * Någon egen sida för ett tips hade varit en andra väg till samma skrivning.
 *
 * **Nyckeln prövas mot listan, och en okänd ger 404.** `App\Support\Tips` är
 * den enda som vet vilka tipsen är (issuens krav 3), så grinden är
 * `Tips::knows()` och ingenting annat — ingen FormRequest, ingen validering
 * mot en tabell. En nyckel utanför listan ska inte bli en rad i
 * `dismissed_tip`: en sådan rad hade varit ett svar på ett tips som inte
 * finns, och den hade dessutom gjort en framtida nyckel med samma namn dold
 * i förväg.
 *
 * **Skrivningen är idempotent.** Att dölja samma tips två gånger ger ingen
 * andra rad — det unika paret `(user_id, tip_key)` är garanten — och det är
 * inte ett fel. Ett dubbelklick möter indexet i stället för att svara 500,
 * och svaret blir detsamma som för den första. Samma form och samma skäl som
 * App\Http\Controllers\FavoriteController.
 *
 * **Användaren är den inloggade och aldrig kroppen.** `user_id` kommer ur
 * sessionen och `tip_key` ur ruttens nyckel — båda sätts explicit, aldrig via
 * massildelning (`DismissedTip` har dem utanför `#[Fillable]`).
 *
 * **Ingen flash-kod.** Ytan är sitt eget kvitto: tipset försvinner och nästa
 * står där i stället, och en ruta som sade *tipset är dolt* hade varit en
 * andra bekräftelse på samma sak. Rutten bakom `auth`, som resten av
 * webben — en utloggad besökare når den aldrig.
 */
class DismissedTipController extends Controller
{
    /**
     * POST /tips/{key}/dismiss — 302 tillbaka till sidan krysset står på.
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
}
