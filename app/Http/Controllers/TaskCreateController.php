<?php

namespace App\Http\Controllers;

use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Formuläret för en ny uppgift — `GET /tasks/create`, M27 · issue 246, se
 * [[ADR-0054 Inboxen]] § 5 och § 8.
 *
 * **Sidan är den tredje vägen in i ett schema**, vid sidan av itemets egen
 * `GET /containers/{container}/items/{item}/schedules/create` (issue 63a) och
 * fångsten på `/inbox` (issue 245). Utan en plats hamnar uppgiften i
 * användarens inbox; med en plats hamnar den på itemet, med *Next* som förval.
 *
 * **`?item=<ulid>` är frivilligt och är hela skillnaden.** Itemets flik länkar
 * hit med sitt eget item, så knappen där förväljer platsen. Containerns flik
 * och `/tasks` länkar hit utan: en container är inte ett item, och på `/tasks`
 * finns ingen plats alls — båda börjar i inboxen och byter med *Change…*
 * (resources/js/components/ItemTargetPicker.vue, issue 242).
 *
 * **Grinden är ITEMETS `create`, och den finns bara när ett item är valt.**
 * Att välja ett item man inte får skapa i är en död plats, och svaret är 403 —
 * samma pinne som `ScheduleController::create()` prövar och samma som
 * målväljaren filtrerar sin lista med (App\Actions\Item\ListCreatableItems).
 * Utan `item` finns ingenting att neka: inboxen är användarens egen, och
 * `POST /inbox/tasks` prövar samma sak igen när hon sparar.
 *
 * **En ULID som inte finns, vars container är mjukraderad, eller som tillhör
 * en inbox ger 404 och inte 403.** Uppslaget är globalt — sidan ligger utanför
 * containerfamiljen — och ordningen är `ScheduleController::move()`s: objektet
 * först, grinden sedan. Inboxen är ingen plats: den nås bara genom sina egna
 * ytor (ADR-0054 § 2), och målväljaren listar den aldrig.
 *
 * **Sidan skickar itemet OCH containern** (Beslut 1): vyn visar båda i
 * platsfältet, och formulärets POST-adress byggs ur dem — itemets egen rutt.
 * Formen är `ScheduleController::create()`s, som av samma skäl skickar
 * `{ulid, name}` och ingenting ur ItemResource (en sida, ett svar, och ingen
 * andra sanning om vad en item-rad bär).
 *
 * **`returnUrl` är den enda adressen en användare får skicka med sig själv.**
 * Knapparna som leder hit bär `?return=` med ytan hon kom från, och värdet
 * följer med formuläret tillbaka till `POST`-en. `safeReturn()` nedan är
 * kontrollen, och den ligger här för att de tre skrivrutterna delar den —
 * `ScheduleController::store()` för itemet, `InboxController::storeTask()`
 * för inboxen — så en öppen omdirigering stängs på ett ställe och inte tre.
 */
class TaskCreateController extends Controller
{
    /**
     * GET /tasks/create — 200. Formuläret, med platsen förvald ur `?item=`.
     */
    public function create(Request $request): Response
    {
        $item = null;
        $ulid = $request->query('item');

        if (is_string($ulid) && $ulid !== '') {
            // Globalt uppslag med flit: sidan ligger utanför containerfamiljen,
            // och SoftDeletes' globala scope gör en mjukraderad ULID till 404 —
            // samma svar som en som aldrig funnits.
            //
            // `container.account` läses i förväg: ItemPolicy frågar efter
            // ägarkontots status (regel 4), och utan raden hade grinden gjort
            // ett uppslag till — samma värmning som ListCreatableItems gör.
            // `container.inbox_user_id` tas med av samma skäl: `isInbox()`
            // nedan läser kolumnen, och utan den i urvalet hade den varit null.
            $item = Item::query()
                ->with(['container:id,ulid,name,account_id,inbox_user_id', 'container.account'])
                ->where('ulid', $ulid)
                ->first();

            // Objektet först, grinden sedan (samma ordning som
            // ScheduleController::move()) — och två 404:or innan grinden.
            // En ULID utan träff, och ett item vars container är mjukraderad:
            // den eager-laddade raden blir null, och ett senare
            // `$item->container->ulid` hade kastat och gett 500.
            abort_if($item === null || $item->container === null, 404);

            // Inboxen nås bara genom sina egna ytor (ADR-0054 § 2), och
            // App\Actions\Item\ListCreatableItems listar den aldrig. Ägaren FÅR
            // skapa på sitt inbox-item — det är därför grinden inte räcker —
            // så platsen nekas som 404, samma svar som en ULID utan träff.
            abort_if($item->container->isInbox(), 404);

            Gate::authorize('create', $item);
        }

        return Inertia::render('Tasks/Create', [
            'item' => $item === null ? null : [
                'ulid' => $item->ulid,
                'name' => $item->name,
                'container' => ['ulid' => $item->container->ulid, 'name' => $item->container->name],
            ],
            'returnUrl' => self::safeReturn($request->query('return')),
        ]);
    }

    /**
     * Den returnerade adressen, eller null när den inte får användas.
     *
     * **Bara en relativ adress duger** (Beslut 3): värdet ska börja med ett
     * enda `/`. `//evil.example` och `/\evil.example` är protokollrelativa och
     * läses av webbläsaren som en annan värd, så de nekas med — en
     * omdirigering till en främmande sajt är annars ett öppet mål för vem som
     * helst som kan lura in en länk i användarens flöde.
     *
     * **Kontrolltecken och backslash nekas var de än står.** WHATWG-parsern tar
     * bort tab, LF och CR var som helst i adressen innan den läses, så
     * `/%09/evil.example` — avkodat till `/<TAB>/evil.example` — blir
     * `//evil.example` i webbläsaren. Ett backstreck efter första tecknet har
     * samma verkan. Mönstret är därför hela regeln: ett inledande `/`, ingen
     * andra snedstrecksvariant, och sedan bara tecken utanför kontroll- och
     * backslashmängden. `\z` och inte `$`: `$` hade släppt igenom ett avslutande
     * radbrytningstecken.
     *
     * Null betyder "använd standardadressen", och den är `/tasks` — samma svar
     * för en adress som saknas och en som nekats.
     */
    public static function safeReturn(mixed $return): ?string
    {
        if (! is_string($return)) {
            return null;
        }

        // Avgränsaren är `~` och inte `/`: mönstret bär `/` i en teckenklass,
        // och PCRE stänger vid första oeskyddade avgränsaren — även inuti en
        // klass.
        if (preg_match('~\A/(?![/\\\\])[^\x00-\x1F\x7F\\\\]*\z~', $return) !== 1) {
            return null;
        }

        return $return;
    }
}
