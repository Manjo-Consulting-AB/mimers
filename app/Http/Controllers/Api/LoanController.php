<?php

namespace App\Http\Controllers\Api;

use App\Actions\Loan\CreateLoan;
use App\Actions\Loan\DeleteLoan;
use App\Actions\Loan\UpdateLoan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loan\StoreLoanRequest;
use App\Http\Requests\Loan\UpdateLoanRequest;
use App\Http\Resources\LoanResource;
use App\Models\Container;
use App\Models\Item;
use App\Models\Loan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * CRUD-ytan för utlåning, se issue 76. INGEN behörighetslogik bor här — varje
 * metod anropar bara `Gate::authorize()` mot ITEMETS egna grindar på
 * App\Policies\ItemPolicy sedan issue 71 (andra halvan): `view` (listning),
 * `create` (POST), `update` (PATCH) och `delete` (DELETE). Laddern avgör, se
 * [[ADR-0028 Åtkomst på itemnivå]] § Beslut och issue 71 § Beslut 1 och 5.
 * Ingen ny policymetod, ingen `LoanPolicy` — lånet följer itemet.
 *
 * Fram till dess var grinden containerns `view`/`update`: en
 * omfångsbegränsad mottagare kunde läsa låntagarens namn på vilket item som
 * helst i containern men inte registrera en utlåning på sitt eget, och en
 * `write`-mottagare kunde radera ett lån. Återlämningen — `PATCH` med
 * `returned_at` — är `update`, inte `create`: lånet finns redan.
 * `Container $container` står kvar i signaturerna för att ImplicitRouteBinding
 * löser barnbindningen mot den redan lösta föräldern.
 *
 * routes/api.php nästlar `{item}` under `{container}` och `{loan}` under
 * `{item}` med gruppens `->scopeBindings()` — `{item}` löses genom
 * App\Models\Container::items() och `{loan}` genom
 * App\Models\Item::loans(). Det är HELA skyddet mot ett lån på ett annat
 * item, eller ett item i en annan container: båda ger 404 (§ Beslut 5).
 *
 * Ingen show(): listan hämtar hela uppsättningen, som är kort per definition
 * (§ Beslut 5).
 *
 * Domänregeln "högst en öppen utlåning per item" bor i
 * App\Actions\Loan\AssertNoOpenLoan sedan issue 110, inte här och inte i en
 * Request — den behöver itemet från rutten och är en domänregel, inte en
 * formregel (§ Beslut 4). Fram till dess låg den i kontrollern: ingen fil i
 * app/Actions fick röras i den issuen. Spärren är en check-then-act och körs
 * därför i EN transaktion med `lockForUpdate()` på ITEM-raden, inuti
 * Actionerna (granskningsfynd; samma konvention som ContainerController::
 * destroy och 22a/22b) — låset ligger aldrig på loan-tabellen, en tom mängd
 * rader är ett gap lock i MySQL (22a § Beslut 7).
 *
 * `item_id` sätts explicit från rutten, aldrig via massildelning — `item_id`
 * är UTESLUTEN ur Loan#[Fillable] (§ Beslut 9). Påminnelsen mot `due_at` —
 * generatorn, mallen och Pro-grinden — är issue 76b och rör ingen fil här.
 */
class LoanController extends Controller
{
    /**
     * GET /api/containers/{container}/items/{item}/loans — 200. Sorterad
     * `lent_at` fallande med `id` fallande som andrasortering, så det som
     * lånades ut senast står först och två lån med samma datum ändå får en
     * stabil ordning. Ingen paginering — listan är kort per definition
     * (§ Beslut 5). Ett konstant antal frågor oavsett antal lån:
     * LoanResource läser bara kolumner på raden själv, inga relationer att
     * ladda i förväg.
     */
    public function index(Container $container, Item $item): JsonResponse
    {
        Gate::authorize('view', $item);

        $loans = $item->loans()
            ->orderByDesc('lent_at')
            ->orderByDesc('id')
            ->get();

        return LoanResource::collection($loans)->response();
    }

    /**
     * POST /api/containers/{container}/items/{item}/loans — 201.
     * StoreLoanRequest har redan bevisat att kroppen är sammanhängande:
     * `lent_at` finns och `due_at`/`returned_at` ligger inte före den
     * (§ Beslut 9). Den öppna-lån-spärren ligger här, efter valideringen:
     * ett item med en redan öppen utlåning (`returned_at IS NULL`) får inte
     * en andra (§ Beslut 4).
     *
     * Spärrens check-then-act och skrivningen delar EN transaktion under
     * `lockForUpdate()` på ITEM-raden (granskningsfynd): två samtidiga POST
     * på samma item serialiseras och den andra ser den förstas öppna lån.
     * Låset ligger aldrig på loan-tabellen — en tom mängd rader är ett gap
     * lock i MySQL (22a § Beslut 7).
     *
     * Grinden är itemets `create` (issue 71 § Beslut 1 och 5): en utlåning är
     * ny information som läggs till itemet, inte en ändring av det.
     */
    public function store(StoreLoanRequest $request, Container $container, Item $item, CreateLoan $createLoan): JsonResponse
    {
        Gate::authorize('create', $item);

        $loan = $createLoan->handle($item, $request->user(), new Loan($request->validated()));

        return (new LoanResource($loan))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PATCH /api/containers/{container}/items/{item}/loans/{loan} — 200.
     * Bara dokumenterade fält, alla valfria. Reglerna i UpdateLoanRequest
     * gäller det sammanslagna tillståndet efter ändringen, se klassens
     * validationData().
     *
     * Den öppna-lån-spärren gäller också här, men bara när PATCH:en gör att
     * lånet FÖRBLIR eller ÅTERBLIR öppet — `returned_at` är null efter
     * ändringen. Ett stängt lån som nollställer `returned_at` medan ett
     * annat lån är öppet avvisas (§ Beslut 4); att stänga (sätta
     * `returned_at`) eller röra andra fält på ett stängt lån rör ingen spärr.
     *
     * Grinden är itemets `update` (issue 71 § Beslut 1 och 5) — att registrera
     * en återlämning ändrar ett lån som redan finns, och en `create`-mottagare
     * nekas den därför.
     *
     * Spärrens check-then-act och skrivningen delar EN transaktion under
     * `lockForUpdate()` på ITEM-raden (granskningsfynd) — två samtidiga
     * PATCH som båda återöppnar ett stängt lån på samma item serialiseras,
     * och den andra ser den förstas öppna lån. Lånet läses sedan om under
     * item-låset, en current read (22b § Beslut 9): en PATCH byggd på en
     * inaktuell rad ska inte omedvetet återöppna ett lån en samtidig begäran
     * just stängde.
     */
    public function update(UpdateLoanRequest $request, Container $container, Item $item, Loan $loan, UpdateLoan $updateLoan): LoanResource
    {
        Gate::authorize('update', $item);

        // Bara de fält klienten faktiskt skickade skrivs tillbaka.
        // `validated()` bär HELA det sammanslagna tillståndet — radens tidigare
        // värden (lästa vid request-resolution, före låset) plus klientens, se
        // UpdateLoanRequest::validationData(). Att fylla alltihop skulle skriva
        // de inaktuella, pre-lock-värdena över den nyss låsta raden och t.ex.
        // tyst återöppna ett lån en samtidig PATCH just stängde
        // (granskningsfynd). Det sammanslagna tillståndet behövs bara för
        // tvärfältsvalideringen.
        $loan = $updateLoan->handle(
            $item,
            $loan,
            array_intersect_key($request->validated(), $request->all()),
            $request->user(),
        );

        return new LoanResource($loan);
    }

    /**
     * DELETE /api/containers/{container}/items/{item}/loans/{loan} — 204,
     * ingen kropp. Mjuk radering (SoftDeletes): `deleted_at` sätts och raden
     * ligger kvar. Ett raderat lån hamnar INTE i papperskorgen — den listar
     * fyra typer och behåller fyra, se issue 76 § Beslut 3. Ett raderat lån
     * går alltså inte att ta tillbaka via API:et i MVP; ett medvetet glapp,
     * inte ett förbiseende.
     *
     * Grinden är itemets `delete` (issue 71 § Beslut 1 och 5): `write` ändrar
     * ett lån men tar inte bort det.
     */
    public function destroy(Request $request, Container $container, Item $item, Loan $loan, DeleteLoan $deleteLoan): Response
    {
        Gate::authorize('delete', $item);

        $deleteLoan->handle($loan, $request->user());

        return response()->noContent();
    }
}
