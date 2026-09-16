<?php

namespace App\Http\Controllers\Api;

use App\Actions\Access\ResolveItemScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cost\StoreCostEntryRequest;
use App\Http\Requests\Cost\UpdateCostEntryRequest;
use App\Http\Resources\CostEntryResource;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\User;
use App\Support\Cost\MinorUnits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * CRUD-ytan för kostnadsrader, se issue 45a. INGEN behörighetslogik bor här —
 * de fyra itemnästlade metoderna anropar bara `Gate::authorize()` mot ITEMETS
 * egna grindar på App\Policies\ItemPolicy sedan issue 71 (andra halvan):
 * `view` (listning), `create` (POST), `update` (PATCH) och `delete` (DELETE).
 * Laddern avgör, se [[ADR-0028 Åtkomst på itemnivå]] § Beslut och issue 71
 * § Beslut 1 och 5. Ingen ny policymetod, ingen CostEntryPolicy — kostnaden
 * följer itemet. Registrering är fri på alla plannivåer — grinden sitter på
 * RAPPORTEN och byggs i issue 46.
 *
 * `suppliers()` ligger på CONTAINERN och behåller därför containerns `view`,
 * oförändrad (issue 45b § Beslut 1, issue 74 § Beslut 6) — den rutten har
 * ingen item-ULID att pröva en itemgrind mot, och urvalet filtreras per
 * omfång i frågan i stället.
 *
 * Fram till dess var grinden containerns `view`/`update` i alla fyra: en
 * omfångsbegränsad mottagare kunde läsa kostnaderna på vilket item som helst
 * i pärmen men inte bokföra en på sitt eget, och en `write`-mottagare kunde
 * radera en kostnadsrad. `Container $container` står kvar i signaturerna för
 * att ImplicitRouteBinding löser barnbindningen mot den redan lösta
 * föräldern; `store()` behöver den dessutom för attributedAccountId().
 *
 * routes/api.php nästlar `{item}` under `{container}` och `{cost}` under
 * `{item}` med gruppens `->scopeBindings()` — `{item}` löses genom
 * App\Models\Container::items() och `{cost}` genom
 * App\Models\Item::costs(). Det är HELA skyddet mot en kostnad på ett annat
 * item, eller ett item i en annan container: båda ger 404 (§ Beslut 7). En
 * kostnad nås bara genom den nästlade rutten, så ett mjukraderat item är inte
 * längre bindbart — papperskorgen kräver ingen kod här (§ Beslut 9).
 *
 * Ingen show(): listan hämtar hela uppsättningen, samma val som för lån
 * (§ Beslut 7). Listan sorteras `incurred_on` fallande med `id` fallande som
 * andrasortering.
 *
 * `amount` sätts ALDRIG via massilldelning: det som kommer in är en sträng i
 * huvudenhet ("1200,50") och det som lagras är heltalet i minsta enhet
 * (120050) från App\Support\Cost\MinorUnits::parse() (§ Beslut 3–4).
 * `container_id` denormaliseras från itemet — aldrig ur kroppen (§ Beslut 2).
 * `created_by_user_id` sätts från token, och `created_by_account_id` från
 * attributedAccountId(): kontot härleds ur HUR användaren når containern
 * (ägarkontots medlem, managed-mottagare eller eget konto), aldrig ur
 * kroppen (§ Beslut 2; granskningens fynd 1).
 */
class CostEntryController extends Controller
{
    /**
     * Högst 50 förslag. Uppslaget matar en autocomplete, inte en rapport, och
     * klienten hämtar listan en gång och filtrerar medan användaren skriver —
     * fler rader hade bara gjort hämtningen långsammare (issue 45b § Beslut
     * 3–4). Inget konfigvärde, ingen query-parameter.
     */
    private const SUPPLIER_SUGGESTION_LIMIT = 50;

    /**
     * GET /api/containers/{container}/items/{item}/costs — 200. Sorterad
     * `incurred_on` fallande med `id` fallande som andrasortering, så den
     * senaste kostnaden står först och två kostnader samma dag ändå får en
     * stabil ordning (§ Beslut 7). Ingen paginering. Ett konstant antal
     * frågor oavsett antal rader: `createdByAccount` laddas i förväg
     * (§ Beslut 14).
     */
    public function index(Container $container, Item $item): JsonResponse
    {
        Gate::authorize('view', $item);

        $costs = $item->costs()
            ->with('createdByAccount')
            ->orderByDesc('incurred_on')
            ->orderByDesc('id')
            ->get();

        return CostEntryResource::collection($costs)->response();
    }

    /**
     * POST /api/containers/{container}/items/{item}/costs — 201.
     * StoreCostEntryRequest har bevisat att fälten finns och att `amount` är
     * en sträng; själva beloppstolkningen ligger HÄR, i kontrollern, genom
     * MinorUnits::parse() — den kastar `cost.amount_invalid`/
     * `cost.amount_decimals` innan en rad skapas (§ Beslut 4–5).
     *
     * Inga domänregler utöver det: registrering är fri på alla plannivåer,
     * kostnadsrader är metadata (räknas inte mot kvoten) och bär ingen
     * revisionslogg (issue 45a Omfång). Därför ingen transaktion och ingen
     * Action — det finns ingen regel värd ett eget test att skydda
     * ([[ADR-0024 Tunna controllers och actions]]).
     *
     * Grinden är itemets `create` (issue 71 § Beslut 1 och 5): en kostnadsrad
     * är ny information som läggs till itemet, inte en ändring av det.
     */
    public function store(StoreCostEntryRequest $request, Container $container, Item $item): JsonResponse
    {
        Gate::authorize('create', $item);

        $data = $request->validated();
        $amount = MinorUnits::parse($data['amount'], $data['currency']);
        unset($data['amount']);

        $cost = new CostEntry($data);
        $cost->amount = $amount;
        $cost->item_id = $item->id;
        $cost->container_id = $item->container_id;
        $cost->created_by_user_id = $request->user()->id;
        $cost->created_by_account_id = $this->attributedAccountId($request->user(), $container);
        $cost->save();

        return (new CostEntryResource($cost->load('createdByAccount')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PATCH /api/containers/{container}/items/{item}/costs/{cost} — 200.
     * Bara dokumenterade fält, alla valfria. `amount` och `currency` ändras
     * ALLTID tillsammans — UpdateCostEntryRequest kräver paret med
     * `required_with` åt båda hållen (§ Beslut 12) — så här räcker det att
     * fråga efter `amount`: finns det finns också `currency` i `validated()`
     * och beloppet kan tolkas mot den nya valutan.
     *
     * Inga tvärfältsregler mot radens befintliga tillstånd, så ingen
     * validationData()-sammanslagning som UpdateLoanRequest behövde
     * (§ Beslut 12).
     *
     * Grinden är itemets `update` (issue 71 § Beslut 1 och 5).
     */
    public function update(UpdateCostEntryRequest $request, Container $container, Item $item, CostEntry $cost): CostEntryResource
    {
        Gate::authorize('update', $item);

        $data = $request->validated();

        if (array_key_exists('amount', $data)) {
            $amount = MinorUnits::parse($data['amount'], $data['currency']);
            unset($data['amount']);
        }

        $cost->fill($data);

        if (isset($amount)) {
            $cost->amount = $amount;
        }

        $cost->save();

        return new CostEntryResource($cost->load('createdByAccount'));
    }

    /**
     * DELETE /api/containers/{container}/items/{item}/costs/{cost} — 204,
     * ingen kropp. Mjuk radering (SoftDeletes): `deleted_at` sätts och raden
     * ligger kvar. Kostnadsraderna hamnar INTE i papperskorgen — den listar
     * fyra typer och behåller fyra, se issue 45a § Beslut 9 och issue 76 §
     * Beslut 3. Raderas itemet följer raderna med till papperskorgen genom
     * SoftDeletes på itemet och återställs med det — utan att den här metoden
     * eller RestoreContent behöver veta att tabellen finns.
     *
     * Grinden är itemets `delete` (issue 71 § Beslut 1 och 5): `write` ändrar
     * en kostnadsrad men tar inte bort den.
     */
    public function destroy(Container $container, Item $item, CostEntry $cost): Response
    {
        Gate::authorize('delete', $item);

        $cost->delete();

        return response()->noContent();
    }

    /**
     * GET /api/containers/{container}/costs/suppliers — 200. Distinkta
     * leverantörer i containern, sorterade på användningsfrekvens fallande
     * med leverantörsnamn stigande som andrasortering — uppslagsytan för
     * autocomplete, issue 45b.
     *
     * Ytan ligger på CONTAINERN, inte på itemet (issue 45b § Beslut 1): den
     * som registrerar en kostnad på ett nytt item ska få containerns hela
     * leverantörshistorik, och `container_id` är denormaliserad på raden just
     * för att frågan inte ska behöva joina `item`. En enda `GROUP BY` mot
     * `cost_entry` — ingen relation, ingen Eloquent-modell — som indexet
     * `(container_id, deleted_at, supplier)` från 45a gör billig i stället
     * för en full scan.
     *
     * `supplier` normaliseras aldrig (ADR-0016): en `strtolower()` i
     * grupperingen hade gett en lista med värden som inte finns i någon rad
     * (issue 45b § Beslut 5). Mjukraderade rader räknas inte och `NULL`
     * filtreras bort — en kostnad i papperskorgen ska inte hålla liv i en
     * leverantör, och en kostnad utan leverantör är inte en leverantör som
     * heter ingenting (§ Beslut 3). Inga värden som förekommer på ett raderat
     * ITEM göms: kostnadsraden är inte raderad, och uppslaget är ett
     * inmatningsstöd, inte en summering (§ Klart när, sista punkten).
     *
     * Issue 74 § Beslut 6: ytan står kvar på containergrinden, men den
     * LÄCKER — en lista med "Advokatbyrån Ek & Partners" säger något om
     * pärmen som mottagaren av motorn inte ska veta. För en OMFÅNGSBEGRÄNSAD
     * mottagare joinas därför `item` in och omfånget styr raderna. För ett
     * OMFATTANDE omfång läggs ingen join till: den befintliga frågan mot
     * bara `cost_entry` är billigare (indexet från 45a), och de två fallen
     * är två grenar med flit — en join som alltid görs hade kostat ägaren
     * en join i onödan. Raderna är desamma som förut; bara urvalet skiljer.
     *
     * Mjukraderade items filtreras INTE bort i den begränsade grenen: samma
     * regel som ägaren har, att ett värde som förekommer på ett raderat item
     * inte göms. Omfånget är grant-baserat och ett mjukraderat item behåller
     * sin plats i grafen (issue 74 § Beslut 3).
     */
    public function suppliers(Request $request, Container $container, ResolveItemScope $resolveItemScope): JsonResponse
    {
        Gate::authorize('view', $container);

        $query = DB::table('cost_entry')
            ->where('cost_entry.container_id', $container->id)
            ->whereNull('cost_entry.deleted_at')
            ->whereNotNull('cost_entry.supplier');

        $scope = $resolveItemScope->handle($request->user(), $container);
        $itemIds = $scope->itemIds();

        if ($itemIds !== null) {
            $query->join('item', 'item.id', '=', 'cost_entry.item_id')
                ->whereIn('item.id', $itemIds);
        }

        $rader = $query
            ->selectRaw('cost_entry.supplier AS supplier, COUNT(*) AS antal')
            ->groupBy('cost_entry.supplier')
            ->orderByDesc('antal')
            ->orderBy('cost_entry.supplier')
            ->limit(self::SUPPLIER_SUGGESTION_LIMIT)
            ->get();

        return response()->json([
            'data' => array_map(
                static fn (object $rad): array => [
                    'supplier' => $rad->supplier,
                    'count' => (int) $rad->antal,
                ],
                $rader->all()
            ),
        ]);
    }

    /**
     * Kontot en kostnadsrad tillskrivs när en användare skapar den. Ska vara
     * "varvet, inte den anställde" — men varvet är det konto vars medlem
     * handlar, inte nödvändigtvis containerns ägarkonto (granskningens fynd
     * 1). Den axel kolumnen skiljer på är konto kontra person, inte ägare
     * kontra gäst: en post som skapas av någon som kommer utifrån ska
     * tillskrivas det konto som gav hen åtkomst, så relationen överlever
     * personalomsättningen ([[ADR-0003 Åtkomstmodell]]: "poster tillskrivs
     * organisationen"). Regel 1 motsvarar [[Konton och åtkomst]] §
     * Behörighetsregler regel 1; regel 2 är `managed`-fallet; regel 3 är den
     * personliga åtkomsten.
     *
     * Tre regler, första träffen vinner:
     *
     * 1. Ägarkontots medlemmar handlar som ägaren → `$container->account_id`.
     * 2. Annars: en giltig `container_access`-rad på containern med
     *    `grantee_type = 'account'` vars `grantee_id` är ett konto användaren
     *    är medlem i → den radens `grantee_id`. "Giltig" är samma villkor som
     *    grinden använder (`scopeValid`: `revoked_at` NULL, `expires_at` inte
     *    passerat). Flera sådana rader är en patologi; lägst `id` vinner,
     *    deterministiskt.
     * 3. Annars är åtkomsten personlig (`grantee_type = 'user'`) och
     *    användarens EGET konto gäller: `type = 'personal'` bland hens
     *    medlemskap, och saknas ett sådant, medlemskapet med lägst
     *    `account_id`. En privatperson som bjudits in tillskrivs sig själv,
     *    inte pärmens ägare.
     *
     * Att fältet aldrig tas ur kroppen står fast (§ Beslut 2–3) — en
     * `managed`-skribent kan inte välja vilket av sina konton posten hamnar
     * på: kontot härleds ur hur användaren når containern, inte ur vad
     * klienten påstår.
     */
    private function attributedAccountId(User $user, Container $container): int
    {
        if ($container->account->users()->whereKey($user->id)->exists()) {
            return $container->account_id;
        }

        $accountIds = $user->accounts()->pluck('account.id')->all();

        $managed = ContainerAccess::query()
            ->where('container_id', $container->id)
            ->where('grantee_type', 'account')
            ->whereIn('grantee_id', $accountIds)
            ->valid()
            ->orderBy('id')
            ->first();

        if ($managed !== null) {
            return $managed->grantee_id;
        }

        $personligt = $user->accounts()
            ->where('account.type', 'personal')
            ->orderBy('account.id')
            ->first();

        if ($personligt !== null) {
            return $personligt->id;
        }

        return $user->accounts()->orderBy('account.id')->first()->id;
    }
}
