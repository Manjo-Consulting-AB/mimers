<?php

namespace App\Http\Controllers;

use App\Actions\Loan\CreateLoan;
use App\Actions\Loan\DeleteLoan;
use App\Actions\Loan\UpdateLoan;
use App\Exceptions\Api\ApiException;
use App\Http\Requests\Loan\StoreLoanRequest;
use App\Http\Requests\Loan\UpdateLoanRequest;
use App\Models\Container;
use App\Models\Item;
use App\Models\Loan;
use App\Support\Frontend\ApiErrorTranslator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Webbens utlåningsyta — låna ut, ändra och registrera återlämning, samt ta
 * bort en rad, se issue 67a § Beslut 1, 3, 6 och 7.
 *
 * **Listan har ingen rutt.** Den öppna utlåningen och historiken kommer med
 * detaljvyns props ur App\Http\Controllers\ItemController::show() (Beslut 1),
 * av samma skäl som bilagorna (issue 60 § Beslut 2), relationerna (issue 58
 * § Beslut 1) och schemana (issue 63a § Beslut 1) gjorde det: en andra väg
 * till samma lista är en andra sanning om sorteringen och om vad resursen
 * bär.
 *
 * **Grindarna är ITEMETS, en pinne per handling** (Beslut 6). `create` för att
 * lägga till en utlåning, `update` för att ändra den och registrera
 * återlämningen, `delete` för att ta bort raden. Det är samma grindar
 * App\Http\Controllers\Api\LoanController prövar sedan issue 71 (andra
 * halvan), och samma avbildning som ItemPolicy bär: `write` ändrar ett lån men
 * tar inte bort det, och den som bara har `read` ser sektionen men ingen
 * skrivyta och får 403 om hon postar ändå.
 *
 * **Ingen ny FormRequest.** `StoreLoanRequest` och `UpdateLoanRequest` delas
 * rakt av, inklusive `after_or_equal:lent_at` på både `due_at` och
 * `returned_at` — regeln är densamma i vyn som på `/api`, och vyn formulerar
 * ingen egen datumkontroll (Beslut 3). Fälten i formuläret är `<input
 * type="date">`, som skickar `Y-m-d`, exakt den form `date`-regeln tar emot.
 *
 * **INGEN notis, ingen påminnelse och inget mejl.** Utlåningspåminnelsen är en
 * notisgenerator i M5 och en plangräns (`loan_reminders`) — den här ytan
 * varken skapar eller visar en leverans, och `borrower_email` läses ALDRIG som
 * mottagaradress (Beslut 4, [[ADR-0017 Missbruksvektorer]] § 7). Ingen
 * `mailto:`-länk, ingen påminnelseknapp och ingen delning: adressen är en
 * kontaktuppgift utlånaren har framme när hon SJÄLV tar kontakt.
 *
 * **Att ta bort raden är inte att återlämna** (Beslut 7). `destroy()` tar bort
 * registreringen, inte lånet ur världen — prylen är fortfarande utlånad om
 * raden var den öppna. Bekräftelsen i vyn säger det, och återlämningen är den
 * andra knappen.
 *
 * **Domänregeln "högst en öppen utlåning per item" ligger i
 * App\Actions\Loan\AssertNoOpenLoan** sedan issue 110 (Beslut 6, [[Items och
 * organisation]] § loan). Fram till dess var den en medveten andra kopia av
 * `Api\LoanController`s, för `app/Actions/**` låg utanför den issunens
 * omfångsruta; nu anropar båda ytorna samma spärr, och de tre skrivningarna
 * (`CreateLoan`, `UpdateLoan`, `DeleteLoan`) bor i samma katalog. Utan
 * spärren kunde webben skapa två öppna lån på samma item.
 *
 * Spärren är en check-then-act och körs därför i EN transaktion med
 * `lockForUpdate()` på ITEM-raden, inuti Actionerna (granskningsfynd; samma
 * konvention som ContainerController::destroy och 22a/22b) — låset ligger
 * aldrig på loan-tabellen, en tom mängd rader är ett gap lock i MySQL
 * (22a § Beslut 7).
 *
 * **Ett domänfel blir ett formulärfel, aldrig en JSON-kropp.** `ApiException`
 * svarar `{"error":{"code":…}}` var den än kastas — också från en
 * Inertia-kontroller — så spärren fångas och formuleras av
 * App\Support\Frontend\ApiErrorTranslator, samma mönster som
 * ItemLinkController och AttachmentController.
 */
class LoanController extends Controller
{
    /**
     * POST /containers/{container}/items/{item}/loans — 302 till itemets
     * detaljvy.
     *
     * `{loan}` finns inte i rutten: raden skapas här. `item_id` sätts
     * explicit av App\Actions\Loan\CreateLoan och aldrig via massildelning —
     * `item_id` är UTESLUTEN ur Loan#[Fillable], samma regel som på `/api`.
     * Sedan issue 110 skriver actionen också loggraden, så att webben och
     * `/api` skriver exakt samma rad.
     */
    public function store(
        StoreLoanRequest $request,
        Container $container,
        Item $item,
        ApiErrorTranslator $translator,
        CreateLoan $createLoan,
    ): RedirectResponse {
        Gate::authorize('create', $item);

        try {
            $createLoan->handle($item, $request->user(), new Loan($request->validated()));
        } catch (ApiException $e) {
            throw ValidationException::withMessages([
                // Felet gäller formuläret i sin helhet och inte ett enskilt
                // fält, men felpåsen bär en nyckel per mening. Den ligger på
                // det första obligatoriska fältet, så fokus landar vid
                // formulärets början (useErrorFocus) — meningen säger själv
                // vad som blockerar.
                'borrower_name' => $translator->message($e),
            ]);
        }

        return redirect()
            ->route('containers.items.show', [$container, $item])
            ->with('status', 'loan-created');
    }

    /**
     * PATCH /containers/{container}/items/{item}/loans/{loan} — 302 till
     * itemets detaljvy.
     *
     * **Bara de fält klienten faktiskt skickade skrivs tillbaka.**
     * `UpdateLoanRequest::validationData()` bär HELA det sammanslagna
     * tillståndet — radens tidigare värden plus klientens — för att
     * tvärfältsvalideringen ska se resultatet av ändringen. Att fylla alltihop
     * skulle skriva de inaktuella, pre-lock-värdena över den nyss låsta raden
     * (granskningsfynd på `/api`). Det sammanslagna tillståndet behövs bara
     * för valideringen.
     *
     * Lånet läses om under item-låset, en current read (22b § Beslut 9): en
     * PATCH byggd på en inaktuell rad ska inte omedvetet återöppna ett lån en
     * samtidig begäran just stängde.
     *
     * **Vyn skickar alltid ett datum** (Beslut 3): "Tillbaka idag" sätter
     * dagens datum och formuläret ett eget. Att nolla `returned_at` — och
     * därmed återöppna lånet — är en API-operation utan yta här.
     *
     * Skrivningen är App\Actions\Loan\UpdateLoan sedan issue 110 — delad med
     * `/api`, så att de två ytorna skriver exakt samma rad i händelseloggen.
     * Kontrollern behåller formen: den skickar bara de fält klienten faktiskt
     * skickade.
     */
    public function update(
        UpdateLoanRequest $request,
        Container $container,
        Item $item,
        Loan $loan,
        ApiErrorTranslator $translator,
        UpdateLoan $updateLoan,
    ): RedirectResponse {
        Gate::authorize('update', $item);

        try {
            $updateLoan->handle(
                $item,
                $loan,
                array_intersect_key($request->validated(), $request->all()),
                $request->user(),
            );
        } catch (ApiException $e) {
            throw ValidationException::withMessages([
                'returned_at' => $translator->message($e),
            ]);
        }

        return redirect()
            ->route('containers.items.show', [$container, $item])
            ->with('status', 'loan-updated');
    }

    /**
     * DELETE /containers/{container}/items/{item}/loans/{loan} — 302 till
     * itemets detaljvy.
     *
     * Grinden är ITEMETS `delete` (Beslut 6): `write` ändrar ett lån men tar
     * inte bort det. Raderingen är MJUK (SoftDeletes) — `deleted_at` sätts och
     * raden ligger kvar — och ett raderat lån hamnar INTE i papperskorgen:
     * den listar fyra typer och behåller fyra (issue 76 § Beslut 3). Därför
     * säger bekräftelsen i vyn att raden försvinner, aldrig att den går att
     * återställa.
     *
     * **Det här är inte en återlämning** (Beslut 7). Raden tas bort för att
     * den registrerades fel; att prylen kommit tillbaka registreras med
     * `update()` och en egen knapp. Raderingen och loggraden är
     * App\Actions\Loan\DeleteLoan sedan issue 110 — delad med `/api`.
     */
    public function destroy(Request $request, Container $container, Item $item, Loan $loan, DeleteLoan $deleteLoan): RedirectResponse
    {
        Gate::authorize('delete', $item);

        $deleteLoan->handle($loan, $request->user());

        return redirect()
            ->route('containers.items.show', [$container, $item])
            ->with('status', 'loan-deleted');
    }
}
