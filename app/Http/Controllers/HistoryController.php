<?php

namespace App\Http\Controllers;

use App\Actions\Access\ResolveItemScope;
use App\Actions\Audit\ListAuditEvents;
use App\Actions\Audit\PresentAuditEvents;
use App\Http\Requests\Audit\HistoryFilterRequest;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Den globala historiken — `GET /history`, se M30 (testarnas fynd 2026-10-09)
 * · issue 271 och [[ADR-0043 Tre loggar]] § Händelseloggen.
 *
 * **Sidan är containerns historikflik över hela kontot.** Fliken (issue 116)
 * visar en containers händelser; här ser användaren samma lista, samma filter
 * och samma tre diagram över allt hon får läsa — varje container hon når OCH
 * kontohändelserna utan container (`account.deleted`), under platsen
 * *Account*. Serverns halva är den här rutten; sidans design kommer i issue
 * 272.
 *
 * **Ingen grind, och det är Beslut 1.** Varje inloggad användare har en
 * historik, och det finns ingen container att pröva en grind mot — adressen
 * bär ingen. Vad listan SEDAN innehåller avgör läsregeln, och den bor i
 * App\Actions\Audit\ListAuditEvents: kontrollern filtrerar ingenting själv
 * ([[ADR-0024 Tunna controllers och actions]]). En främling har ingen historik
 * att få, och en tom lista är rätt svar för en användare utan containrar —
 * samma linje som DocumentController.
 *
 * **Filtren står i querysträngen** (Beslut 2) och läses genom App\Http\
 * Requests\Audit\HistoryFilterRequest — formen prövas där, existensen i
 * läsregeln. De går rakt in i `forUser()` och `statsForUser()`, så listan och
 * talen följer samma urval; `filter`-proppen är samma värden så som servern
 * tillämpade dem, och vyn ritar sina fält ur den och läser aldrig adressen
 * själv.
 *
 * **Dagarna viks i PresentAuditEvents** (Beslut 5), på samma sätt som på
 * fliken: `date` är dagen i `$user->preferredTimezone()`
 * ([[ADR-0044 Användarens dag]]), och klienten räknar aldrig om en tidpunkt
 * till en dag.
 *
 * **Diagrammens tal kommer ur samma action** (Beslut 4): `statsForUser()` är
 * `statsForContainer()` utan containervillkor, och kontrollern räknar
 * ingenting själv. `topItems` bär `container_ulid` — raderna kommer från flera
 * containrar här, och sidan ska kunna länka rätt.
 *
 * **Containervalets lista är alla containrar användaren når** (Beslut 6),
 * sorterade på namn, med `inbox` som flagga. Den egna inboxen är åtkomlig men
 * inte listbar ([[ADR-0054 Inboxen]] § 2) — väljaren bär den därför med
 * flaggan satt, så sidan kan skriva *Inbox* i stället för dess namn; se
 * `Frågor och antaganden` i PR:en. Alternativet *Account activity* ritar sidan
 * själv och står inte i listan.
 *
 * **Itemvalen erbjuds per container och aldrig som en lista över allt**
 * (Beslut 6): `options.items` är tom utan en vald container, och fylld med
 * containerns items inom `ResolveItemScope` — samma omfång som varje annan
 * listning — när `filter.container` är ett ULID för en container i listan.
 * `item` i adressen verkar ändå genom läsregeln, som på fliken.
 *
 * **De hundra senaste, och ingen paginering.** Gränsen står i ListAuditEvents
 * och är API:ets egen (issue 40 § Beslut 8). Behövs mer är det ett eget
 * beslut, inte en parameter här.
 */
class HistoryController extends Controller
{
    public function __construct(
        private readonly ListAuditEvents $listAuditEvents,
        private readonly PresentAuditEvents $presentAuditEvents,
    ) {}

    /**
     * GET /history — 200.
     */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $filters = $this->filters();

        $logs = $this->listAuditEvents->forUser($user, filters: $filters);
        $facets = $this->listAuditEvents->facetsForUser($user);

        $containers = $this->containers($user);

        return Inertia::render('History/Index', [
            // Händelserna grupperade per dag i användarens tidszon, nyast
            // först — ordningen ListAuditEvents gav dem.
            'days' => $this->presentAuditEvents->byDay($logs, $user->preferredTimezone()),
            // Diagrammens tre tal (Beslut 4): samma läsregel och samma filter
            // som `days`, men utan gränsen på hundra — se
            // ListAuditEvents::statsForUser(). Utan datumfilter spänner
            // `perDay` de senaste trettio dagarna medan `perType` och
            // `topItems` räknar hela den läsbara mängden; de två talen är
            // därför inte summan av varandra.
            'stats' => $this->listAuditEvents->statsForUser($user, $filters),
            // Filtret så som servern tillämpade det. `null` för ett fält
            // användaren inte satte, och vyn ritar fälten ur det: ett tomt
            // fält är ett filter som inte gäller.
            'filter' => [
                'container' => $filters['container'] ?? null,
                'type' => $filters['type'] ?? null,
                'user' => $filters['user'] ?? null,
                'item' => $filters['item'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'options' => [
                'containers' => $containers
                    ->map(static fn (Container $container): array => [
                        'ulid' => (string) $container->ulid,
                        'name' => (string) $container->name,
                        'inbox' => $container->isInbox(),
                    ])
                    ->values()
                    ->all(),
                'types' => $facets['types'],
                'users' => $facets['users']
                    ->map(static fn (User $actor): array => ['ulid' => (string) $actor->ulid, 'name' => (string) $actor->name])
                    ->values()
                    ->all(),
                'items' => $this->itemOptions($user, $filters, $containers),
            ],
        ]);
    }

    /**
     * Querysträngen genom App\Http\Requests\Audit\HistoryFilterRequest — och
     * bara de nycklar som bär ett värde.
     *
     * En tom sträng och en utelämnad parameter betyder samma sak, och efter
     * `ConvertEmptyStringsToNull` är `?from=` en `null`. Båda faller bort här
     * i stället för att gå vidare som ett filter utan värde: ett `null` i
     * `$filters` är samma sak som en utelämnad parameter i `filtered()`, men
     * en explicit `null` i `filter`-proppen hade varit brus.
     *
     * Requesten löses upp med `app()` och inte som ett metodargument: ett
     * argument hade validerats av routern innan kontrollern hann rita sidan,
     * och ett formulärfel mitt i en läsning är ett svar användaren inte kan
     * göra något med. Samma grepp och samma skäl som ContainerHistoryController.
     *
     * @return array<string, string>
     */
    private function filters(): array
    {
        /** @var array<string, string|null> $validated */
        $validated = app(HistoryFilterRequest::class)->validated();

        return array_filter(
            $validated,
            static fn (?string $value): bool => $value !== null && $value !== '',
        );
    }

    /**
     * Containrarna användaren når, sorterade på namn — containervalets lista
     * (Beslut 6).
     *
     * `accessibleBy()` och inte `listable()`: väljaren bär också den egna
     * inboxen, märkt med `inbox`, så en användare kan filtrera sin inkorgs
     * historia. Se `Frågor och antaganden` i PR:en om förhållandet till
     * [[ADR-0054 Inboxen]] § 2.
     *
     * @return Collection<int, Container>
     */
    private function containers(User $user): Collection
    {
        $accountIds = $user->accounts->pluck('id')->values()->all();

        return Container::query()
            ->accessibleBy($user, $accountIds)
            ->orderBy('name')
            ->get();
    }

    /**
     * Itemvalen för itemfiltrets väljare, och bara när en container är vald
     * (Beslut 6).
     *
     * En itemlista över ALLA containrar hade varit en meny utan sammanhang —
     * namnen säger inte vilken container de hör till — och den hade vuxit med
     * varje item användaren når. Filtret erbjuds därför per container, och
     * `account` ger inga: en kontorad handlar inte om ett item.
     *
     * Omfånget är containerns, genom samma `ResolveItemScope` som
     * ListContainerCosts och ItemController::index() använder, så en mottagare
     * bara kan filtrera på det hon ser — samma form som
     * ContainerHistoryController::itemOptions().
     *
     * @param  array<string, string>  $filters
     * @param  Collection<int, Container>  $containers
     * @return list<array{ulid: string, name: string}>
     */
    private function itemOptions(User $user, array $filters, Collection $containers): array
    {
        $ulid = $filters['container'] ?? null;

        if ($ulid === null || $ulid === 'account') {
            return [];
        }

        $container = $containers->firstWhere('ulid', $ulid);

        if ($container === null) {
            return [];
        }

        $scope = app()->build(ResolveItemScope::class)->handle($user, $container);

        return $container->items()
            ->inScope($scope)
            ->orderBy('name')
            ->get(['ulid', 'name'])
            ->map(static fn (Item $item): array => ['ulid' => (string) $item->ulid, 'name' => (string) $item->name])
            ->values()
            ->all();
    }
}
