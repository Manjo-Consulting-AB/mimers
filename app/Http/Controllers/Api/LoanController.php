<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loan\StoreLoanRequest;
use App\Http\Requests\Loan\UpdateLoanRequest;
use App\Http\Resources\LoanResource;
use App\Models\Container;
use App\Models\Item;
use App\Models\Loan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * CRUD-ytan för utlåning, se issue 76. INGEN behörighetslogik bor här — varje
 * metod anropar bara `Gate::authorize()` mot de BEFINTLIGA grindarna `view`
 * (listning) och `update` (skapa/ändra/radera) på App\Policies\ContainerPolicy,
 * se issue 76 § Beslut 6. Ingen ny policymetod, ingen `LoanPolicy`.
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
 * Domänregeln "högst en öppen utlåning per item" bor här i kontrollern, inte
 * i en Request — den behöver itemet från rutten och är en domänregel, inte en
 * formregel (§ Beslut 4). Den är för liten för en egen Action ([[ADR-0024
 * Tunna controllers och actions]]: bara skrivningar med en regel värd ett
 * eget test får en) och ingen fil i app/Actions får röras i den här issuen.
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
        Gate::authorize('view', $container);

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
     */
    public function store(StoreLoanRequest $request, Container $container, Item $item): JsonResponse
    {
        Gate::authorize('update', $container);

        $this->assertNoOpenLoan($item);

        $loan = new Loan($request->validated());
        $loan->item_id = $item->id;
        $loan->save();

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
     */
    public function update(UpdateLoanRequest $request, Container $container, Item $item, Loan $loan): LoanResource
    {
        Gate::authorize('update', $container);

        $loan->fill($request->validated());

        if ($loan->returned_at === null) {
            $this->assertNoOpenLoan($item, $loan);
        }

        $loan->save();

        return new LoanResource($loan);
    }

    /**
     * DELETE /api/containers/{container}/items/{item}/loans/{loan} — 204,
     * ingen kropp. Mjuk radering (SoftDeletes): `deleted_at` sätts och raden
     * ligger kvar. Ett raderat lån hamnar INTE i papperskorgen — den listar
     * fyra typer och behåller fyra, se issue 76 § Beslut 3. Ett raderat lån
     * går alltså inte att ta tillbaka via API:et i MVP; ett medvetet glapp,
     * inte ett förbiseende.
     */
    public function destroy(Container $container, Item $item, Loan $loan): Response
    {
        Gate::authorize('update', $container);

        $loan->delete();

        return response()->noContent();
    }

    /**
     * Spärren "högst en öppen utlåning per item" (§ Beslut 4): hittar
     * kontrollern en öppen utlåning (annan än $except, när ett lån håller på
     * att återöppnas) avvisas skrivningen med `loan.already_open` och den
     * befintliga utlåningens ULID i `data.loan` — så klienten kan peka ut
     * raden som blockerar.
     */
    private function assertNoOpenLoan(Item $item, ?Loan $except = null): void
    {
        $query = $item->loans()->whereNull('returned_at');

        if ($except !== null) {
            $query->whereKeyNot($except->getKey());
        }

        $open = $query->first();

        if ($open !== null) {
            throw ApiException::make('loan.already_open', ['loan' => $open->ulid], 422);
        }
    }
}
