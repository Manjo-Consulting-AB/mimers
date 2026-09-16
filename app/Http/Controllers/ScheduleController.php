<?php

namespace App\Http\Controllers;

use App\Actions\Schedule\OpenNextOccurrence;
use App\Http\Requests\Schedule\StoreScheduleRequest;
use App\Http\Requests\Schedule\UpdateScheduleRequest;
use App\Http\Resources\ContainerResource;
use App\Http\Resources\OccurrenceDependencyResource;
use App\Http\Resources\ScheduleDependencyResource;
use App\Http\Resources\ScheduleResource;
use App\Models\Container;
use App\Models\Item;
use App\Models\OccurrenceDependency;
use App\Models\Schedule;
use App\Models\ScheduleDependency;
use App\Models\ScheduleOccurrence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webbens schemaytor — formulären, pausen, raderingen och, sedan issue 63c,
 * schemats sida med beroendena på båda nivåerna, se issue 63a § Beslut 1, 6, 7
 * och 8 och issue 63c § Beslut 1–9.
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
 *
 * **Beroendena ritas på schemats sida och byggs här** (issue 63c § Beslut 1
 * och 2). `show()` nedan anropar 63b:s `ScheduleOccurrenceController::show()`
 * oförändrad och fogar till 63c:s fyra props — se docblocket för `show()` för
 * varför rutten pekar hit.
 */
class ScheduleController extends Controller
{
    /**
     * GET /containers/{container}/items/{item}/schedules/{schedule} — schemats
     * sida: regeln, den öppna förekomsten, historiken och BEROENDENA på båda
     * nivåerna (issue 63c § Beslut 1–5).
     *
     * **Basen är 63b:s och byggs fortfarande av den.** Regeln, förekomsterna
     * och historiken kommer ur App\Http\Controllers\ScheduleOccurrenceController
     * ::show(), som anropas OFÖRÄNDRAD — 63c:s omfångsruta namnger den här
     * filen och rör inte den. En andra kopia av samma props vore en andra
     * sanning om sorteringen, om `overdue` och om vilken förekomst som är
     * öppen, och en utbrytning ur 63b:s sida ligger utanför den här issuen.
     * Rutten pekar därför hit, och det här svaret är basen plus 63c:s fyra
     * props: en sida, ett svar.
     *
     * **Grinden är ITEMETS `view`, och den prövas i basen** — innan en enda
     * beroenderad läses. En `read`-mottagare ser beroendena men ingen
     * skrivyta (Beslut 8): `can.update` är samma pinne som 63a och 63b bar.
     *
     * **Beroendena kommer med sidan och har ingen egen rutt** (Beslut 1), av
     * samma skäl som bilagorna (issue 60 § Beslut 2) och relationerna (issue
     * 58 § Beslut 1): en sida, ett svar, och ingen andra väg till samma
     * läsning. Formen är `/api`:s — samma två Resource-klasser som
     * Api\ScheduleDependencyController::index() och
     * Api\OccurrenceDependencyController::index() bygger.
     */
    public function show(
        Request $request,
        Container $container,
        Item $item,
        Schedule $schedule,
        ScheduleOccurrenceController $base,
    ): Response {
        $page = $base->show($request, $container, $item, $schedule);

        $scheduleDependencies = $this->scheduleDependencies($schedule, $request);

        // Undantagen hör till den ÖPPNA förekomsten — den enda som går att
        // lägga ett beroende på, och den enda frågan "vad väntar jag på just
        // nu" har ett svar för (Beslut 2). Samma definition av öppen som
        // basens lista räknar med: App\Models\Schedule::openOccurrence().
        $open = $schedule->openOccurrence()->first();

        $occurrenceDependencies = $this->occurrenceDependencies($open, $request);

        // Motparterna användaren får ändra, i samma pärm (Beslut 3) — en per
        // nivå, för ett schema utan öppen förekomst kan inte väljas på
        // förekomstnivån.
        return $page->with([
            'scheduleDependencies' => $scheduleDependencies,
            'occurrenceDependencies' => $occurrenceDependencies,
            'hasOpenOccurrence' => $open !== null,
            'counterparts' => $this->counterparts($request, $container, $schedule, $scheduleDependencies, $occurrenceDependencies),
        ]);
    }

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

    /**
     * Schemats beroenden — REGELN som ärvs av varje ny förekomst (Beslut 2).
     *
     * Samma uppslag och samma form som
     * App\Http\Controllers\Api\ScheduleDependencyController::index(): raderna,
     * motparternas scheman och motparternas items i tre frågor, aldrig en per
     * rad, och motparter vars schema eller item är mjukraderat filtreras bort
     * medan raden ligger kvar i tabellen. Sorterat på motpartens titel.
     *
     * Logiken är `/api`:s och den är kopierad med flit och inte delad — samma
     * linje som ScheduleController::update(): `/api`-kontrollern svarar med en
     * JsonResponse och en utbrytning till en delad Action ligger utanför den
     * här issuen. Ändras den ena ska den andra ändras.
     *
     * @return list<array<string, mixed>>
     */
    private function scheduleDependencies(Schedule $schedule, Request $request): array
    {
        $rows = ScheduleDependency::query()
            ->where('schedule_id', $schedule->id)
            ->get();

        $schedulesById = Schedule::query()
            ->whereIn('id', $rows->pluck('depends_on_schedule_id')->unique()->values()->all())
            ->get(['id', 'ulid', 'title', 'item_id'])
            ->keyBy('id');

        $itemsById = Item::query()
            ->whereIn('id', $schedulesById->pluck('item_id')->unique()->values()->all())
            ->get(['id', 'ulid', 'name'])
            ->keyBy('id');

        $visible = $rows->filter(function (ScheduleDependency $row) use ($schedulesById, $itemsById): bool {
            $counterpart = $schedulesById->get($row->depends_on_schedule_id);

            return $counterpart !== null && $itemsById->has($counterpart->item_id);
        });

        $visible->each(function (ScheduleDependency $row) use ($schedulesById, $itemsById): void {
            $counterpart = $schedulesById->get($row->depends_on_schedule_id);
            $counterpartItem = $itemsById->get($counterpart->item_id);

            $row->setAttribute('counterpart_ulid', $counterpart->ulid);
            $row->setAttribute('counterpart_title', $counterpart->title);
            $row->setAttribute('counterpart_item_ulid', $counterpartItem->ulid);
            $row->setAttribute('counterpart_item_name', $counterpartItem->name);
        });

        return ScheduleDependencyResource::collection($visible->sortBy('counterpart_title')->values())->resolve($request);
    }

    /**
     * Den öppna förekomstens beroenden — UNDANTAGET som bara gäller den här
     * gången (Beslut 2).
     *
     * Samma uppslag och samma form som
     * App\Http\Controllers\Api\OccurrenceDependencyController::index(): `satisfied`
     * är härlett ur motpartens status, sorteringen är ouppfyllda först och
     * därefter `due_at` stigande, och en motpart under ett mjukraderat schema
     * eller item filtreras bort.
     *
     * **`schedule_ulid` läggs bredvid resursens fält.** Vyn länkar varje
     * motpart vidare till sitt eget schema (Beslut 5), och motparten här är en
     * FÖREKOMST: `depends_on.ulid` pekar på en förekomst och går inte att
     * navigera med. Resursen är `/api`:s form (issue 23b § Beslut 8) och rörs
     * inte, så den här kontrollern fogar till det enda fält vyn behöver utöver
     * den — raden är ändå självständig för sin läsare.
     *
     * @return list<array<string, mixed>>
     */
    private function occurrenceDependencies(?ScheduleOccurrence $open, Request $request): array
    {
        if ($open === null) {
            return [];
        }

        $rows = OccurrenceDependency::query()
            ->where('occurrence_id', $open->id)
            ->get();

        $counterpartsById = ScheduleOccurrence::query()
            ->whereIn('id', $rows->pluck('depends_on_occurrence_id')->unique()->values()->all())
            ->with(['schedule:id,title,item_id,ulid', 'schedule.item:id,ulid,name'])
            ->get()
            ->keyBy('id');

        $visible = $rows->filter(function (OccurrenceDependency $row) use ($counterpartsById): bool {
            $counterpart = $counterpartsById->get($row->depends_on_occurrence_id);

            return $counterpart !== null
                && $counterpart->schedule !== null
                && $counterpart->schedule->item !== null;
        });

        $visible->each(function (OccurrenceDependency $row) use ($counterpartsById): void {
            $counterpart = $counterpartsById->get($row->depends_on_occurrence_id);
            $counterpartSchedule = $counterpart->schedule;
            $counterpartItem = $counterpartSchedule->item;

            $row->setAttribute('counterpart_ulid', $counterpart->ulid);
            $row->setAttribute('counterpart_title', $counterpartSchedule->title);
            $row->setAttribute('counterpart_due_at', $counterpart->due_at->toDateString());
            $row->setAttribute('counterpart_status', $counterpart->status);
            $row->setAttribute('counterpart_item_ulid', $counterpartItem->ulid);
            $row->setAttribute('counterpart_item_name', $counterpartItem->name);
            $row->setAttribute('counterpart_schedule_ulid', $counterpartSchedule->ulid);
            $row->setAttribute('satisfied', $counterpart->status !== ScheduleOccurrence::STATUS_OPEN);
        });

        $ordered = $visible->sortBy(fn (OccurrenceDependency $row): array => [
            $row->getAttribute('satisfied') ? 1 : 0,
            $row->getAttribute('counterpart_due_at'),
        ])->values();

        return array_map(
            fn (array $row, OccurrenceDependency $model): array => $row + [
                'schedule_ulid' => $model->getAttribute('counterpart_schedule_ulid'),
            ],
            OccurrenceDependencyResource::collection($ordered)->resolve($request),
            $ordered->all(),
        );
    }

    /**
     * Motparterna användaren får välja, en lista per nivå (Beslut 3).
     *
     * Samma regel som issue 58 § Beslut 3 och samma bygge som
     * App\Http\Controllers\ItemController::counterparts(): pärmens scheman,
     * filtrerade med ITEMETS `update` — en motpart utanför omfånget visas inte
     * alls, inte som ett namnlöst spöke — och sorterade på itemets namn och
     * därefter schemats titel. Ett schema heter "Byt impeller" och betyder
     * ingenting utan sitt item.
     *
     * **Filtret är artighet och inte skydd.** Grinden i
     * App\Http\Controllers\ScheduleDependencyController::store() är den som
     * gäller, och den prövar samma sak igen — en kandidat som slinker igenom
     * här nekas där.
     *
     * **Fyra frågor och inga fler:** kandidaterna med sina items, schemats
     * egna beroenden (som redan är hämtade) och de öppna förekomsterna.
     * App\Actions\Access\ResolveItemScope är memoiserad per `{user}:{container}`,
     * så `update`-prövningen per kandidat kostar inga grant-frågor.
     *
     * @param  list<array<string, mixed>>  $scheduleDependencies
     * @param  list<array<string, mixed>>  $occurrenceDependencies
     * @return array{schedule: list<array<string, mixed>>, occurrence: list<array<string, mixed>>}
     */
    private function counterparts(
        Request $request,
        Container $container,
        Schedule $schedule,
        array $scheduleDependencies,
        array $occurrenceDependencies,
    ): array {
        // $user är nollbar därför att Request::user() är det; rutten ligger
        // bakom `auth`, så i drift är svaret aldrig tomt av den anledningen.
        $user = $request->user();

        if ($user === null) {
            return ['schedule' => [], 'occurrence' => []];
        }

        // Scheman som redan står i listan erbjuds inte igen — dubbletten är ett
        // valideringsfel, inte en tyst no-op (issue 23 § Beslut 9). De synliga
        // raderna räcker som underlag: en motpart vyn inte visar är heller inte
        // en kandidat här.
        $dependedOn = array_column(array_column($scheduleDependencies, 'depends_on'), 'ulid');
        $dependedOnOccurrences = array_column(array_column($occurrenceDependencies, 'depends_on'), 'ulid');

        $writable = Schedule::query()
            ->whereKeyNot($schedule->id)
            ->whereHas('item', fn ($query) => $query->where('container_id', $container->id))
            ->with('item:id,ulid,name,container_id')
            ->get()
            ->each(fn (Schedule $candidate) => $candidate->item->setRelation('container', $container))
            ->filter(fn (Schedule $candidate): bool => Gate::forUser($user)->allows('update', $candidate->item))
            ->sortBy(fn (Schedule $candidate): array => [$candidate->item->name, $candidate->title])
            ->values();

        $openByScheduleId = ScheduleOccurrence::query()
            ->whereIn('schedule_id', $writable->pluck('id')->all())
            ->where('status', ScheduleOccurrence::STATUS_OPEN)
            ->get(['id', 'ulid', 'schedule_id', 'due_at'])
            ->keyBy('schedule_id');

        $labels = fn (Schedule $candidate): array => [
            'title' => $candidate->title,
            'item' => ['ulid' => $candidate->item->ulid, 'name' => $candidate->item->name],
        ];

        return [
            'schedule' => $writable
                ->reject(fn (Schedule $candidate): bool => in_array($candidate->ulid, $dependedOn, true))
                ->map(fn (Schedule $candidate): array => ['ulid' => $candidate->ulid] + $labels($candidate))
                ->values()
                ->all(),

            // Bara scheman med en ÖPPEN förekomst kan väljas här: `depends_on`
            // är en förekomst-ULID, och en stängd motpart är inget undantag
            // någon väljer i förväg.
            'occurrence' => $writable
                ->filter(fn (Schedule $candidate): bool => $openByScheduleId->has($candidate->id))
                ->reject(fn (Schedule $candidate): bool => in_array($openByScheduleId->get($candidate->id)->ulid, $dependedOnOccurrences, true))
                ->map(fn (Schedule $candidate): array => [
                    'ulid' => $openByScheduleId->get($candidate->id)->ulid,
                    'due_at' => $openByScheduleId->get($candidate->id)->due_at->toDateString(),
                ] + $labels($candidate))
                ->values()
                ->all(),
        ];
    }
}
