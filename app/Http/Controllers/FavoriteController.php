<?php

namespace App\Http\Controllers;

use App\Models\Container;
use App\Models\Favorite;
use App\Models\Item;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Webbens stjärna — märk ett item som favorit och ta bort markeringen, se
 * [[ADR-0042 Designsystemet]] § Konsekvenser och [[M17 Designsystemet]]
 * § 105.
 *
 * **Två rutter och ingen sida.** Stjärnan sitter i itemets huvud på
 * detaljvyn (resources/js/pages/Containers/Items/Show.vue) och är den enda
 * ytan; en egen sida för en markering hade varit en andra väg till samma
 * skrivning. Listan över favoriter är issue 106 och ligger i skalet, inte
 * här — `resources/js/layouts/**` rörs inte.
 *
 * **Grinden är `view`, och det är hela åtkomstprövningen.** Markeringen
 * speglar åtkomsten, den ger den inte: den som får LÄSA itemet får märka
 * det, och den som inte når det får 403 — samma svar som detaljvyn ger
 * (issue 73 § Beslut 3). Ingen ny regel och ingen egen kontroll här, alltså:
 * App\Policies\ItemPolicy::view() prövas, och
 * App\Actions\Access\ResolveItemScope rörs inte.
 *
 * Att `view` och inte `update` är rätt pinne följer av vad en favorit ÄR: en
 * rad om förhållandet mellan en användare och ett item, aldrig en ändring av
 * itemet. `update` hade dessutom stängt ute en `read`-mottagare från sin
 * egen bokmärkeslista, och `create` hade varit pinnen för att lägga något PÅ
 * itemet — det här lägger ingenting där. Följden av `view` är att regel 4
 * (det frysta ägarkontot) inte gäller: spärren sitter på skrivande mot
 * kontots innehåll, och en markering är personens egen.
 *
 * **Ingen `ItemResource` och ingen ny åtkomstregel.** Favoriten skrivs till
 * `favorite` och till ingenting annat; `app/Http/Resources/**` och
 * `app/Http/Controllers/Api/**` rörs inte, och `/api` får ingen yta för
 * markeringen i den här issuen.
 *
 * **Skrivningarna är idempotenta.** Att märka ett item som redan är märkt
 * ger ingen andra rad — det unika paret `(user_id, item_id)` är garanten —
 * och att ta bort en markering som inte finns är inte ett fel. En växling
 * som svarar olika på samma tillstånd blir en knapp som ger felkod vid ett
 * dubbelklick.
 *
 * Båda svaren är `back()` med en flash-kod — mönstret från issue 51
 * § Beslut 5, `status` och ingenting annat. `back()` och inte en
 * omdirigering till itemets rutt: stjärnan står på en sida som bär `?tab=`
 * och `?path=`, och en omdirigering till den rena sökvägen hade tappat
 * båda.
 */
class FavoriteController extends Controller
{
    /**
     * POST /containers/{container}/items/{item}/favorite — 302 tillbaka till
     * sidan stjärnan står på.
     *
     * Markeringen är användarens EGEN: `user_id` kommer ur den inloggade
     * sessionen och aldrig ur kroppen, och `item_id` ur ruttens item — båda
     * sätts explicit, aldrig via massildelning (`Favorite` har dem utanför
     * `#[Fillable]`, samma regel som App\Models\ItemLink).
     *
     * `{container}` används inte i kroppen: `scopeBindings()` har redan löst
     * `{item}` genom containerns `items()`-relation, så en item-ULID från en
     * annan container är 404 innan den här metoden körs — samma skydd som
     * varje annan nästlad itemrutt (issue 9b § Beslut 1).
     *
     * Det samtidiga dubbelklicket fångas i stället för att svara 500: den
     * första skrivningen hinner igenom, den andra möter det unika indexet.
     * Ingen andra rad skrivs, och svaret blir detsamma som för den första.
     */
    public function store(Request $request, Container $container, Item $item): RedirectResponse
    {
        Gate::authorize('view', $item);

        $user = $request->user();

        $favorite = new Favorite;
        $favorite->item_id = $item->id;

        try {
            $user->favorites()->save($favorite);
        } catch (UniqueConstraintViolationException) {
            // Redan märkt — ingen andra rad, och inget fel att visa.
        }

        return back()->with('status', 'favorite-added');
    }

    /**
     * DELETE /containers/{container}/items/{item}/favorite — 302 tillbaka
     * till sidan stjärnan står på.
     *
     * Raderingen är HÅRD och gäller bara markeringen: itemet rörs inte, och
     * `favorite` är ingen av papperskorgens fyra typer (issue 76 § Beslut 3)
     * — en bokmärkesrad är inget innehåll att återställa. Grinden är den
     * samma som för att märka, `view`: den som fick märka får ångra sig.
     *
     * Finns ingen markering är svaret detsamma som när en togs bort — se
     * klassens docblock om varför växlingen är idempotent i båda riktningar.
     */
    public function destroy(Request $request, Container $container, Item $item): RedirectResponse
    {
        Gate::authorize('view', $item);

        $request->user()->favorites()->where('item_id', $item->id)->delete();

        return back()->with('status', 'favorite-removed');
    }
}
