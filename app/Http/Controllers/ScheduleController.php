<?php

namespace App\Http\Controllers;

use App\Actions\Schedule\OpenNextOccurrence;
use App\Http\Requests\Schedule\StoreScheduleRequest;
use App\Http\Requests\Schedule\UpdateScheduleRequest;
use App\Http\Resources\ContainerResource;
use App\Http\Resources\ScheduleResource;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webbens schemaytor — formulären, pausen och raderingen, se issue 63a
 * § Beslut 1, 6, 7 och 8.
 *
 * **Ingenting av `/api` görs om.** `StoreScheduleRequest` och
 * `UpdateScheduleRequest` delas rakt av — inklusive deras
 * `prohibited_if`/`required_if`-par, som ÄR skillnaden mellan de tre
 * återkommandetyperna (issue 21 § Beslut 5). Skrivningen är Actionens:
 * App\Actions\Schedule\OpenNextOccurrence öppnar schemats första förekomst,
 * i SAMMA transaktion som schemat (issue 22 § Beslut 3 och 9). Den här
 * kontrollern anropar den, den skriver inte om den — och den räknar aldrig
 * ett förfallodatum själv.
 *
 * **Listan har ingen rutt.** Schemana kommer med detaljvyns props ur
 * App\Http\Controllers\ItemController::show(), av samma skäl som bilagorna
 * (issue 60 § Beslut 2) och relationerna (issue 58 § Beslut 1): en andra väg
 * till samma läsning är en andra sanning om sorteringen och om vad resursen
 * bär. Formulären har däremot egna sidor (Beslut 1): ett schema har åtta fält
 * och två beroende par, och det ryms inte i en rad som expanderar.
 *
 * **Fyra grindar, alla på ITEMET** (Beslut 7): `view` för listan, `create`
 * för att lägga till, `update` för att ändra OCH pausa, `delete` för att
 * radera — samma pinnar som detaljvyn redan bär i `can`, och samma som
 * App\Http\Controllers\Api\ScheduleController prövar sedan issue 71b. Ingen
 * SchedulePolicy skrivs: schemat följer itemet ([[ADR-0028 Åtkomst på
 * itemnivå]] § Beslut). En `create`-mottagare lägger till ett schema men rör
 * inte ett befintligt; en `write`-mottagare ändrar och pausar men raderar
 * inte.
 *
 * **Pausen är en `PATCH` som bär bara `is_active`** (Beslut 6). Den går
 * genom `update()` nedan och inte genom en egen rutt: `UpdateScheduleRequest`
 * har `sometimes` på allt, och en återaktivering som saknar en öppen förekomst
 * öppnar en — samma regel som `/api` (issue 22 § Beslut 3).
 *
 * **Raderingen lovar ingen papperskorg** (Beslut 8). Schemat mjukraderas
 * (SoftDeletes), men papperskorgen listar fyra typer och `schedule` är inte en
 * av dem (issue 20a § Beslut 3), så raden går inte att återställa ur en vy.
 * Bekräftelsetexten i vyn säger det som är sant — att schemat och dess
 * kommande förekomster tas bort — och nämner varken 30 dagar eller
 * papperskorgen.
 */
class ScheduleController extends Controller
{
    /**
     * GET /containers/{container}/items/{item}/schedules/create — formuläret,
     * se issue 63a § Beslut 3, 4 och 5.
     *
     * Grinden är ITEMETS `create`: att lägga till ett schema är att lägga
     * till, och en `create`-mottagare får göra det på sitt item utan att för
     * den skull få ändra det som redan står där.
     *
     * Sidan bär bara pärmen och itemet. Inga valutor och inga listor: ett
     * schema har inga relationer att välja ur, och de tre återkommandetyperna
     * är `Schedule::RECURRENCE_TYPES` — ett domänvärde, inte en lista ur
     * databasen.
     */
    public function create(Request $request, Container $container, Item $item): Response
    {
        Gate::authorize('create', $item);

        $container->loadMissing('account');

        return Inertia::render('Containers/Items/Schedules/Create', [
            'container' => ContainerResource::make($container)->resolve($request),
            'item' => ['ulid' => $item->ulid, 'name' => $item->name],
        ]);
    }

    /**
     * POST /containers/{container}/items/{item}/schedules — 302 till itemets
     * detaljvy.
     *
     * Ordningen och transaktionen är `/api`:s (App\Http\Controllers\Api\
     * ScheduleController::store()), och det är inte en detalj: ett schema som
     * sparats UTAN sin öppna förekomst är ett tillstånd användaren varken kan
     * se eller laga, så `OpenNextOccurrence` körs i samma transaktion och
     * kastar den rullar schemat tillbaka.
     *
     * Formuläret skickar aldrig `is_active` (Beslut 6: pausen är en knapp och
     * inte ett kryss), så ett schema som skapas här är alltid aktivt och
     * öppnar alltid sin första förekomst. Modellens standardvärde gäller —
     * vyn hittar inget eget.
     *
     * `item_id` sätts explicit från rutten, aldrig via massildelning: fältet
     * är uteslutet ur Schedule#[Fillable] (§ Att se upp med).
     */
    public function store(StoreScheduleRequest $request, Container $container, Item $item, OpenNextOccurrence $openNextOccurrence): RedirectResponse
    {
        Gate::authorize('create', $item);

        $schedule = new Schedule($request->validated());
        $schedule->item_id = $item->id;

        DB::transaction(function () use ($schedule, $openNextOccurrence): void {
            $schedule->save();

            if ($schedule->is_active) {
                $openNextOccurrence->handle($schedule);
            }
        });

        return redirect()
            ->route('containers.items.show', [$container, $item])
            ->with('status', 'schedule-created');
    }

    /**
     * GET /containers/{container}/items/{item}/schedules/{schedule}/edit —
     * formuläret.
     *
     * Grinden är ITEMETS `update`: en `create`-mottagare lägger till, men rör
     * aldrig ett schema som redan står där.
     *
     * `{schedule}` binds av rutternas `scopeBindings()` genom
     * App\Models\Item::schedules(): ett schema på ett annat item, eller ett
     * item i en annan pärm, ger 404 (issue 21 § Beslut 1).
     */
    public function edit(Request $request, Container $container, Item $item, Schedule $schedule): Response
    {
        Gate::authorize('update', $item);

        $container->loadMissing('account');

        return Inertia::render('Containers/Items/Schedules/Edit', [
            'container' => ContainerResource::make($container)->resolve($request),
            'item' => ['ulid' => $item->ulid, 'name' => $item->name],
            'schedule' => (new ScheduleResource($schedule))->resolve($request),
        ]);
    }

    /**
     * PATCH /containers/{container}/items/{item}/schedules/{schedule} — 302
     * till itemets detaljvy.
     *
     * Kroppen är antingen formulärets sju fält — de åtta minus `is_active`,
     * som formuläret aldrig ritar — eller `{"is_active": false}` från
     * pausknappen. Båda går genom samma metod och samma delade FormRequest
     * (Beslut 6): `UpdateScheduleRequest::validationData()` lägger radens
     * nuvarande värden under klientens, så en paus tvingar inte fram de
     * andra fälten och `validated()` bär ändå hela raden.
     *
     * Logiken är `/api`:s (App\Http\Controllers\Api\ScheduleController::
     * update()), och den är kopierad med flit och inte delad — samma linje
     * som ItemController::replaceTags(): utbrytningen ligger utanför den här
     * issuen, och två formuleringar av samma skrivning glider isär.
     *
     * Ett PAUSAT schema som aktiveras och saknar en öppen förekomst öppnar en,
     * i samma transaktion (issue 22 § Beslut 3). Att pausa rör ALDRIG den
     * öppna förekomsten: raden ligger kvar, och en pausad förekomst blockerar
     * fortfarande de uppgifter som beror på den (Beslut 6, issue 63c).
     */
    public function update(
        UpdateScheduleRequest $request,
        Container $container,
        Item $item,
        Schedule $schedule,
        OpenNextOccurrence $openNextOccurrence,
    ): RedirectResponse {
        Gate::authorize('update', $item);

        $wasActive = $schedule->is_active;

        $schedule->fill($request->validated());

        if ($schedule->recurrence_type === 'none') {
            $schedule->interval_unit = null;
            $schedule->interval_count = null;
        }

        $reactivated = $schedule->is_active && ! $wasActive;

        DB::transaction(function () use ($schedule, $openNextOccurrence, $reactivated): void {
            $schedule->save();

            if ($reactivated && ! $schedule->openOccurrence()->exists()) {
                $openNextOccurrence->handle($schedule);
            }
        });

        // Pausen och återupptagningen är samma skrivning som en ändring av
        // titeln — bara `is_active` avgör vilken mening användaren möts av.
        // Formuläret ritar aldrig fältet, så en ändring som kommer därifrån
        // lämnar flaggan orörd och får `schedule-updated`.
        $status = match (true) {
            $reactivated => 'schedule-resumed',
            $wasActive && ! $schedule->is_active => 'schedule-paused',
            default => 'schedule-updated',
        };

        return redirect()
            ->route('containers.items.show', [$container, $item])
            ->with('status', $status);
    }

    /**
     * DELETE /containers/{container}/items/{item}/schedules/{schedule} — 302
     * till itemets detaljvy.
     *
     * Grinden är ITEMETS `delete`, en egen pinne (Beslut 7): en
     * `write`-mottagare ändrar och pausar ett schema men tar inte bort det.
     *
     * Raderingen är MJUK (SoftDeletes): `deleted_at` sätts och raden ligger
     * kvar. Schemat hamnar INTE i papperskorgen — den listar fyra typer och
     * behåller fyra (issue 20a § Beslut 3) — och därför lovar varken
     * bekräftelsen eller flashkoden en väg tillbaka (Beslut 8).
     */
    public function destroy(Container $container, Item $item, Schedule $schedule): RedirectResponse
    {
        Gate::authorize('delete', $item);

        $schedule->delete();

        return redirect()
            ->route('containers.items.show', [$container, $item])
            ->with('status', 'schedule-deleted');
    }
}
