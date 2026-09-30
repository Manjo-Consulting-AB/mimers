<?php

namespace App\Http\Controllers;

use App\Actions\Access\ResolveItemScope;
use App\Actions\Audit\ListAuditEvents;
use App\Actions\Audit\PresentAuditEvents;
use App\Http\Requests\Audit\ContainerHistoryFilterRequest;
use App\Http\Resources\ContainerResource;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Containerns historikflik — `GET /containers/{container}/history`, se issue
 * 116 och [[ADR-0043 Tre loggar]] § Händelseloggen.
 *
 * **Containerns flik är en egen sida på en egen rutt**, som de andra flikarna
 * i `containerTabs` (issue 101): översikten, items och inställningssidan har
 * var sin adress, och historiken hade blivit den enda fliken utan. Sidan
 * ligger i `ContainerLayout` och bär samma prop som sina grannar.
 *
 * **Läsregeln bor inte här.** Grinden svarar på om användaren når containern
 * alls — och den är `viewAuditLog`, samma grind som
 * App\Http\Controllers\Api\AuditLogController::index() prövar: en gäst med
 * containerns `read` eller `write` når ytan, och främlingen får 403 och inte
 * en tom lista. VILKA rader hon sedan läser svarar
 * App\Actions\Audit\ListAuditEvents på, i EN fråga för både ägaren och gästen
 * (issue 108). Kontrollern filtrerar ingenting själv: två formuleringar av
 * läsregeln glider isär, och den ena hade blivit en väg runt `ItemPolicy`.
 *
 * **Filtren bor i samma action** (issue 179 § Beslut 1). Kontrollern läser
 * querysträngen genom App\Http\Requests\Audit\ContainerHistoryFilterRequest —
 * formen prövas här, existensen där — och skickar de validerade värdena rakt
 * in i `ListAuditEvents::forContainer()`. Ett eget `where` här hade varit en
 * andra sanning om vad användaren får läsa; nu läggs filtren OVANPÅ de tre
 * leden i samma fråga, och ett filter kan därför aldrig vidga läsregeln.
 *
 * **Dagsgrupperingen görs på servern** (Beslut 3). Proppen är
 * `days: [{date, rows}]`, och `date` är dagen i `$user->preferredTimezone()`
 * — samma regel som `User::today()`, [[ADR-0044 Användarens dag]]. Klienten
 * räknar aldrig om en tidpunkt till en dag: en användare i Stockholm ser en
 * händelse 23:30 UTC på nästa dygn, och webbläsarens klocka är inte hennes
 * tidszon. Gruppen behöver ingen egen fråga — raderna är redan hämtade i
 * ordning, och grupperingen är en vikning av dem.
 *
 * **Diagrammens tal kommer ur samma action** (issue 180 § Beslut 1–2).
 * Proppen `stats` ligger bredvid `days` och byggs av
 * `ListAuditEvents::statsForContainer()` — samma läsregel och samma filter,
 * men utan gränsen på hundra rader. Kontrollern räknar ingenting själv: en
 * egen `GROUP BY` här hade varit en andra sanning om vilka händelser
 * användaren får se, och den hade inte kunnat ärva filtren.
 *
 * **Valen i filterfältet kommer ur läsregeln** (Beslut 2), genom
 * `ListAuditEvents::facets()`: användarna och typerna som förekommer i
 * containerns läsbara rader. Itemslistan är containerns items inom omfånget —
 * samma `ResolveItemScope` som varje annan listning, så en mottagare inte kan
 * filtrera på ett item hon inte ser.
 *
 * **Namnen slås upp när raden läses.** `audit_log` bär identifierare och inte
 * namn, så raderna kommer ur ListAuditEvents och formen ur
 * App\Actions\Audit\PresentAuditEvents — samma två steg som `/api` går, och
 * samma uppslag som gör att en gallrad item- eller användarrad får sin
 * neutrala ersättare i stället för ett tomt fält.
 *
 * **De hundra senaste, och ingen paginering.** Gränsen står i ListAuditEvents
 * och är API:ets egen (issue 40 § Beslut 8). Behövs mer är det ett eget
 * beslut, inte en parameter här.
 *
 * **`can.update` kom med issue 170** · [[ADR-0050 Desktopdesignen]] § 2–3.
 * Fliken bär samma hjälte som itemlistan — `compact` — och hjälten ritar
 * *Redigera container* ur samma flagga som översikten och inställningssidan
 * ritar sina ur. Den räknas med en policyfråga per sida och läggs BREDVID
 * `ContainerResource`, precis som i ContainerController::show() (issue 54
 * § Beslut 9): flaggan är presentation, och ruttens `update` prövas ändå.
 */
class ContainerHistoryController extends Controller
{
    public function __construct(
        private readonly ListAuditEvents $listAuditEvents,
        private readonly PresentAuditEvents $presentAuditEvents,
    ) {}

    /**
     * GET /containers/{container}/history — 200.
     */
    public function index(Request $request, Container $container): Response
    {
        Gate::authorize('viewAuditLog', $container);

        $container->loadMissing(['account', 'coverAttachment.storedFile.derivatives']);

        $user = $request->user();

        $filters = $this->filters();

        $logs = $this->listAuditEvents->forContainer($user, $container, filters: $filters);
        $facets = $this->listAuditEvents->facets($user, $container);

        return Inertia::render('Containers/History', [
            'container' => ContainerResource::make($container)->resolve($request),
            // Flaggan ritar hjältens *Redigera container* (issue 170); rutten
            // prövar `ContainerPolicy::update` på nytt.
            'can' => [
                'update' => Gate::forUser($user)->allows('update', $container),
            ],
            // Händelserna grupperade per dag i användarens tidszon, nyast
            // först — ordningen ListAuditEvents gav dem.
            'days' => $this->days($logs, $user->preferredTimezone()),
            // Diagrammens tre tal (issue 180 § Beslut 2): samma läsregel och
            // samma filter som `days`, men utan gränsen på hundra — se
            // ListAuditEvents::statsForContainer().
            'stats' => $this->listAuditEvents->statsForContainer($user, $container, $filters),
            // Filtret så som servern tillämpade det. `null` för ett fält
            // användaren inte satte, och vyn ritar fälten ur det: ett tomt
            // fält är ett filter som inte gäller.
            'filter' => [
                'type' => $filters['type'] ?? null,
                'user' => $filters['user'] ?? null,
                'item' => $filters['item'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'options' => [
                'types' => $facets['types'],
                'users' => $facets['users']
                    ->map(static fn (User $actor): array => ['ulid' => (string) $actor->ulid, 'name' => (string) $actor->name])
                    ->values()
                    ->all(),
                'items' => $this->itemOptions($user, $container),
            ],
        ]);
    }

    /**
     * Querysträngen genom App\Http\Requests\Audit\ContainerHistoryFilterRequest
     * — och bara de nycklar som bär ett värde.
     *
     * En tom sträng och en utelämnad parameter betyder samma sak, och efter
     * `ConvertEmptyStringsToNull` är `?from=` en `null`. Båda faller bort här
     * i stället för att gå vidare som ett filter utan värde: ett `null` i
     * `$filters` är samma sak som en utelämnad parameter i `filtered()`, men
     * en explicit `null` i `filter`-proppen hade varit brus.
     *
     * Requesten löses upp med `app()` och inte som ett metodargument: ett
     * argument hade validerats av routern innan kontrollern hunnit pröva
     * grinden, och en främling hade mötts av ett formulärfel i stället för
     * 403. Samma grepp och samma skäl som ContainerCostController.
     *
     * @return array<string, string>
     */
    private function filters(): array
    {
        /** @var array<string, string|null> $validated */
        $validated = app(ContainerHistoryFilterRequest::class)->validated();

        return array_filter(
            $validated,
            static fn (?string $value): bool => $value !== null && $value !== '',
        );
    }

    /**
     * Containerns items inom omfånget, för itemfiltrets väljare — samma
     * `ResolveItemScope` som ListContainerCosts och ItemController::index()
     * använder, så en mottagare bara kan filtrera på det hon ser (samma regel
     * som filterraden i issue 59a § Beslut 5).
     *
     * @return list<array{ulid: string, name: string}>
     */
    private function itemOptions(User $user, Container $container): array
    {
        $scope = app()->build(ResolveItemScope::class)->handle($user, $container);

        return $container->items()
            ->inScope($scope)
            ->orderBy('name')
            ->get(['ulid', 'name'])
            ->map(static fn (Item $item): array => ['ulid' => (string) $item->ulid, 'name' => (string) $item->name])
            ->values()
            ->all();
    }

    /**
     * Raderna vikta per dag i användarens tidszon — `days: [{date, rows}]`.
     *
     * `created_at` är en tidsstämpel i UTC, och dagen är den användaren ser:
     * `setTimezone()` på en KOPIA, så radens egen tidsstämpel står orörd och
     * `PresentAuditEvents` har redan skrivit den som ISO 8601. En händelse
     * 23:30 UTC hör till nästa dygn i Stockholm, och den hamnar därför i
     * morgondagens grupp — det är hela poängen med att låta servern räkna
     * dagen ([[ADR-0044 Användarens dag]] § Beslut 4).
     *
     * Raderna kommer i `created_at` fallande ordning, så dagarna hamnar i
     * samma ordning utan en egen sortering: en vikning bevarar insättnings-
     * ordningen, och `array_values()` gör listan till en lista.
     *
     * @param  Collection<int, AuditLog>  $logs
     * @return list<array{date: string, rows: list<array<string, mixed>>}>
     */
    private function days(Collection $logs, string $timezone): array
    {
        $rows = $this->presentAuditEvents->handle($logs);

        $days = [];

        foreach ($logs->values() as $index => $log) {
            $date = $log->created_at->copy()->setTimezone($timezone)->toDateString();

            $days[$date] ??= ['date' => $date, 'rows' => []];
            $days[$date]['rows'][] = $rows[$index];
        }

        return array_values($days);
    }
}
