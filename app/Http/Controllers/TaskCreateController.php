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
 * **En ULID som inte finns, eller som är mjukraderad, ger 404 och inte 403.**
 * Uppslaget är globalt — sidan ligger utanför containerfamiljen — och
 * ordningen är `ScheduleController::move()`s: objektet först, grinden sedan.
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
            $item = Item::query()
                ->with(['container:id,ulid,name,account_id', 'container.account'])
                ->where('ulid', $ulid)
                ->first();

            abort_if($item === null, 404);

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
     * Null betyder "använd standardadressen", och den är `/tasks` — samma svar
     * för en adress som saknas och en som nekats.
     */
    public static function safeReturn(mixed $return): ?string
    {
        if (! is_string($return) || $return === '' || ! str_starts_with($return, '/')) {
            return null;
        }

        if (str_starts_with($return, '//') || str_starts_with($return, '/\\')) {
            return null;
        }

        return $return;
    }
}
