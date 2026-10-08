<?php

namespace App\Actions\Attachment;

use App\Actions\Access\ResolveItemScope;
use App\Actions\Inbox\ResolveInbox;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Alla filer användaren når, i alla containrar och i hennes egen inbox —
 * underlaget för `GET /documents` (M28, testarnas fynd 2026-10-07 · issue
 * 255:s serversida, se App\Http\Controllers\DocumentController).
 *
 * **Urvalet är bilagor på LEVANDE items**, i varje container användaren når
 * (`Container::scopeAccessibleBy()` + `Container::scopeListable()`) PLUS
 * bilagorna på hennes EGEN inbox. Den egna inboxen listas inte av
 * `listable()` — den nås genom sina egna ytor ([[ADR-0054 Inboxen]] § 2) —
 * och läggs därför tillbaka här, uttryckligen. Inboxen SLÅS UPP och skapas
 * aldrig: en sida som läser ska inte skriva en container (ADR-0054 § 1,
 * `ResolveInbox::existing()`).
 *
 * **Containerns EGEN bild är inte en fil här** ([[ADR-0047 Containerns
 * bild]]). Den sitter på `container_id` med `item_id = NULL`, och
 * `whereNotNull('attachment.item_id')` står som samma sanning skrivet rakt ut
 * — samma led och samma skäl som App\Actions\Attachment\ListContainerAttachments.
 * Bilagans och itemets SoftDeletes faller bort genom `whereHas('item', …)` och
 * bilagans eget scope.
 *
 * **Omfånget per container är detsamma som på containerns dokumentflik**
 * (App\Actions\Access\ResolveItemScope): en omfångsbegränsad medlem ser här
 * exakt det hon ser på fliken. Upplösningen görs för ALLA containrar i ETT
 * anrop (`forContainers()`) — den är byggd för toppnivåytorna och kostar tre
 * frågor, fyra när någon container har en itemgrant, oavsett hur många
 * containrar och items som avses. `itemIds()` svarar `null` för ett
 * OMFATTANDE omfång ("hela containern") och en lista för ett begränsat; en
 * tom lista är "når ingenting" och aldrig "når allt" (App\Support\Access\
 * ItemScope). De två formerna hålls isär hela vägen ned till SQL: ett
 * obegränsat omfång blir `item.container_id IN (…)` och ett begränsat
 * `item.id IN (…)`, och den som når ingenting får `0 = 1` och inte hela
 * tabellen.
 *
 * **Filtren står i querysträngen** (Beslut 2): `container[]` (ULID:er plus
 * värdet `inbox`), `kind[]` och `sort`. `kind` och `sort` är containerns fliks
 * värden — listan och sorteringen ÄR desamma, och `kindFilters()` och `SORTS`
 * läses därför ur ListContainerAttachments i stället för att skrivas av en
 * gång till. Inget filter per item och ingen uppladdare: den här ytan listar
 * hela kontot, och de två grupperna hör till flikens fråga.
 *
 * **En ULID användaren inte når ger ingenting, inte ett fel** — samma linje
 * som ListContainerAttachments § docblock. Filtret smalnar av mot det
 * användaren redan ser; det kan varken bredda urvalet eller svara med ett
 * formulärfel på en handredigerad adress.
 *
 * **Antalet frågor är konstant** (Beslut 5): kontona, containrarna,
 * omfångsupplösningen, inboxuppslaget, sidan och de två antalsfrågorna är alla
 * ett fast antal oberoende av antal containrar, filer och rader. Per-container-
 * antalet kräver en join mot `item` — bytena bor på `stored_file`, men
 * containeridentiteten bor på itemet — och den joinen ÄR samma urval som
 * `visible()` skriver, formulerat en gång i `reachableItems()`.
 *
 * **Femtio rader per sida** (Beslut 1). Sidnumret kommer ur `?page=` och
 * räknas av ramverket.
 *
 * **Ingen `Gate::authorize()`.** Rutten ligger bakom `auth`, och listan är
 * byggd ur det användaren når — det finns ingen enskild container eller item
 * att pröva en grind mot, och en tom lista är rätt svar för en användare utan
 * containrar.
 */
class ListAccessibleAttachments
{
    /** Antalet rader per sida — Beslut 1. */
    public const PER_PAGE = 50;

    /**
     * Värdet i `container[]` som pekar ut den egna inboxen (Beslut 2).
     * Inboxens container har ingen ULID som får lämna servern — den är dold
     * (ADR-0054 § 1) — så värdet är ett ord och inte ett löpnummer.
     */
    public const INBOX = 'inbox';

    /**
     * Användarens nådda mängd, upplöst EN gång per request: containrarna, det
     * egna inbox-itemet och omfånget delat i obegränsade containrar och
     * begränsade item.
     *
     * @var array<int, array{
     *     containers: Collection<int, Container>,
     *     inbox: Item|null,
     *     unrestricted: array<int, true>,
     *     items: array<int, list<int>>,
     * }>
     */
    private array $reach = [];

    public function __construct(
        private readonly ResolveItemScope $resolveItemScope,
        private readonly ResolveInbox $resolveInbox,
    ) {}

    /**
     * Sidan av listan — filtrerad, sorterad och paginerad (Beslut 1, 2 och 5).
     *
     * `item.container` är eager-laddad: raden bär BÅDE itemet och containern
     * (Beslut 3), och utan raden hade varje rad kostat två uppslag. `storedFile`
     * och `billedAccount` bär AttachmentResource — samma tre relationer som
     * ListContainerAttachments eager-laddar, av samma skäl.
     *
     * @param  array{container?: list<string>, kind?: list<string>, sort?: string|null}  $filters
     */
    public function handle(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = $this->visible($user, $filters['container'] ?? [])
            ->select('attachment.*')
            ->with(['item.container', 'storedFile', 'billedAccount']);

        $kinds = $filters['kind'] ?? [];

        if ($kinds !== []) {
            $query->whereIn('attachment.kind', $kinds);
        }

        $this->applySort($query, $filters['sort'] ?? null);

        return $query->paginate(self::PER_PAGE);
    }

    /**
     * Containerväljaren: varje container användaren når, med antalet filer hon
     * ser i den — inboxen som `{ulid: 'inbox', count}` och FÖRST när den har
     * filer (Beslut 4).
     *
     * **Antalen följer INTE det valda filtret** (Beslut 4, samma regel som
     * `filterOptions` i ContainerDocumentController): siffran bredvid en
     * container är alltid antalet filer användaren når där, och ett `kind` eller
     * ett `container` i adressen rör den inte. Ett filter som räknade om sig
     * självt hade visat noll för varje val utom det valda.
     *
     * **Inboxen står först och bara när den har filer.** Den finns inte för
     * varje användare — den skapas av den första fångsten — och ett val utan
     * filer bakom sig är ett filter som bara kan ge en tom lista. Övriga
     * containrar bär sitt antal även när det är noll: de är platser användaren
     * kan välja, och raden säger hur mycket som ligger där.
     *
     * @return list<array{ulid: string, name?: string, count: int}>
     */
    public function containers(User $user): array
    {
        $reach = $this->reach($user);
        $antal = $this->countsPerContainer($user);

        $rader = [];

        $inboxId = $reach['inbox'] === null ? null : (int) $reach['inbox']->container_id;

        if ($inboxId !== null && ($antal[$inboxId] ?? 0) > 0) {
            $rader[] = ['ulid' => self::INBOX, 'count' => $antal[$inboxId]];
        }

        foreach ($reach['containers'] as $container) {
            $rader[] = [
                'ulid' => (string) $container->ulid,
                'name' => (string) $container->name,
                'count' => $antal[(int) $container->getKey()] ?? 0,
            ];
        }

        return $rader;
    }

    /**
     * Antalet filer per typ, i hela den nådda mängden (Beslut 4) — underlaget
     * för typfiltrets siffror.
     *
     * **Samma urval som `containers()`** och samma skäl: antalen följer inte
     * det valda filtret. Alla tre nycklarna står med även när en typ saknas,
     * så vyn slipper hantera en utelämnad nyckel — samma form som `kinds` i
     * ListContainerAttachments::counts().
     *
     * @return array<string, int>
     */
    public function kinds(User $user): array
    {
        $kinds = ['image' => 0, 'document' => 0, 'other' => 0];

        $rader = $this->visible($user, [])
            ->toBase()
            ->select('attachment.kind as grupp', DB::raw('COUNT(*) as antal'))
            ->groupBy('attachment.kind')
            ->get();

        foreach ($rader as $rad) {
            $kind = (string) $rad->grupp;

            if (array_key_exists($kind, $kinds)) {
                $kinds[$kind] = (int) $rad->antal;
            }
        }

        return $kinds;
    }

    /**
     * Urvalet som `handle()`, `containers()` och `kinds()` delar: bilagor på
     * levande items i den nådda mängden, avgränsad till `$selected`.
     *
     * `$selected` är `container[]` ur adressen — tom betyder "allt användaren
     * når", och en ULID utanför den nådda mängden faller bort utan att bli ett
     * fel. Extraherad så att de tre läsarna frågar samma sak: två avskrifter av
     * urvalet glider isär, och den ena hade visat en fil den andra dolde.
     *
     * @param  list<string>  $selected
     * @return Builder<Attachment>
     */
    private function visible(User $user, array $selected): Builder
    {
        [$unrestricted, $itemIds] = $this->narrow($user, $selected);

        return Attachment::query()
            ->whereNotNull('attachment.item_id')
            ->whereHas('item', function (Builder $item) use ($unrestricted, $itemIds): void {
                $this->reachableItems($item, $unrestricted, $itemIds);
            });
    }

    /**
     * Det nådda itemvillkoret, formulerat EN gång och använt från två
     * byggare: `whereHas('item', …)` i `visible()` och den joinade
     * antalsfrågan i `countsPerContainer()`.
     *
     * Kolumnerna skrivs kvalificerade (`item.container_id`, `item.id`), vilket
     * är samma form i båda fallen — i `whereHas` är `item` underfrågans tabell,
     * i joinen den yttre frågans.
     *
     * **Obegränsat omfång blir `container_id IN (…)` och begränsat blir
     * `item.id IN (…)`.** Den som når ingenting — inga containrar alls, eller
     * ett filter på ULID:er utanför den nådda mängden — får `0 = 1`: en tom
     * grupp hade matchat varje item, och "når ingenting" får aldrig kollapsa
     * till "når allt" (App\Support\Access\ItemScope).
     *
     * @param  Builder<covariant EloquentModel>  $query
     * @param  list<int>  $unrestricted
     * @param  list<int>  $itemIds
     */
    private function reachableItems(Builder $query, array $unrestricted, array $itemIds): void
    {
        $query->where(function (Builder $inner) use ($unrestricted, $itemIds): void {
            if ($unrestricted !== []) {
                $inner->whereIn('item.container_id', $unrestricted);
            }

            if ($itemIds !== []) {
                $inner->orWhereIn('item.id', $itemIds);
            }

            if ($unrestricted === [] && $itemIds === []) {
                $inner->whereRaw('0 = 1');
            }
        });
    }

    /**
     * Antalet filer per container, i hela den nådda mängden (Beslut 4).
     *
     * **En fråga, en `GROUP BY`** — antalet växer inte med antalet containrar.
     * Urvalet är `reachableItems()`, samma villkor som `visible()` ställer;
     * `item.deleted_at IS NULL` står utskrivet för att joinen går förbi
     * relationens SoftDeletes-scope. `attachment.item_id` behöver inget eget
     * `NOT NULL`: en containerbild har inget item att joina mot och faller i
     * inner joinen.
     *
     * @return array<int, int> container_id → antal
     */
    private function countsPerContainer(User $user): array
    {
        [$unrestricted, $itemIds] = $this->narrow($user, []);

        $query = Attachment::query()
            ->join('item', 'item.id', '=', 'attachment.item_id')
            ->whereNull('item.deleted_at');

        $this->reachableItems($query, $unrestricted, $itemIds);

        $antal = [];

        $rader = $query
            ->toBase()
            ->select('item.container_id as grupp', DB::raw('COUNT(*) as antal'))
            ->groupBy('item.container_id')
            ->get();

        foreach ($rader as $rad) {
            $antal[(int) $rad->grupp] = (int) $rad->antal;
        }

        return $antal;
    }

    /**
     * Den nådda mängden, upplöst en gång per användare och request.
     *
     * Kontona, containrarna, inboxen och omfånget läses EN gång och används av
     * alla tre läsarna. Utan memon hade `handle()`, `containers()` och
     * `kinds()` frågat samma sak tre gånger — och `ResolveInbox::existing()`
     * är inte memoiserad, så varje anrop hade kostat två frågor till.
     *
     * Omfånget löses för containrarna och inboxen i ETT anrop
     * (`forContainers()`), vilket är precis vad den är byggd för: annars hade
     * varje container kostat tre frågor (issue 70 § Beslut 2).
     *
     * @return array{
     *     containers: Collection<int, Container>,
     *     inbox: Item|null,
     *     unrestricted: array<int, true>,
     *     items: array<int, list<int>>,
     * }
     */
    private function reach(User $user): array
    {
        $nyckel = (int) $user->id;

        if (isset($this->reach[$nyckel])) {
            return $this->reach[$nyckel];
        }

        $accountIds = $user->accounts->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $containers = Container::query()
            ->accessibleBy($user, $accountIds)
            ->listable()
            // Två containrar kan heta samma sak, och en lista vars innehåll
            // byter plats mellan två anrop är brus i varje svar Inertia
            // skickar — samma andrasortering som ordningen i listan har.
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'ulid', 'name']);

        $inbox = $this->resolveInbox->existing($user);

        $containerIds = $containers->map(static fn (Container $container): int => (int) $container->getKey())->all();

        if ($inbox !== null) {
            $containerIds[] = (int) $inbox->container_id;
        }

        $unrestricted = [];
        $items = [];

        if ($containerIds !== []) {
            foreach ($this->resolveItemScope->forContainers($user, $containerIds) as $containerId => $scope) {
                $itemIds = $scope->itemIds();

                if ($itemIds === null) {
                    $unrestricted[(int) $containerId] = true;
                } else {
                    $items[(int) $containerId] = $itemIds;
                }
            }
        }

        return $this->reach[$nyckel] = [
            'containers' => $containers,
            'inbox' => $inbox,
            'unrestricted' => $unrestricted,
            'items' => $items,
        ];
    }

    /**
     * Delar upp den nådda mängden i de två formerna SQL kan svara på:
     * container-ID:n med obegränsat omfång och item-ID:n ur begränsade omfång.
     *
     * `$selected` är `container[]` färdignormaliserad av kontrollern: ULID:er
     * och ordet `inbox`. En tom lista är inget filter, alltså hela den nådda
     * mängden. En ULID utanför mängden — eller `inbox` för en användare som
     * ännu inte har någon inbox — faller bort, och då blir båda listorna tomma
     * och urvalet `0 = 1`.
     *
     * @param  list<string>  $selected
     * @return array{0: list<int>, 1: list<int>}
     */
    private function narrow(User $user, array $selected): array
    {
        $reach = $this->reach($user);

        if ($selected === []) {
            // Nycklarna i de två tabellerna är tillsammans varje nådd container.
            $keep = $reach['unrestricted'] + $reach['items'];
        } else {
            $keep = [];
            $ulids = array_flip($selected);

            foreach ($reach['containers'] as $container) {
                if (isset($ulids[(string) $container->ulid])) {
                    $keep[(int) $container->getKey()] = true;
                }
            }

            if (isset($ulids[self::INBOX]) && $reach['inbox'] !== null) {
                $keep[(int) $reach['inbox']->container_id] = true;
            }
        }

        $unrestricted = array_map('intval', array_keys(array_intersect_key($reach['unrestricted'], $keep)));

        $itemIds = [];

        foreach (array_intersect_key($reach['items'], $keep) as $ids) {
            $itemIds = [...$itemIds, ...$ids];
        }

        return [$unrestricted, $itemIds];
    }

    /**
     * Ordningen, med andrasortering i varje gren — samma fyra lägen och samma
     * skäl som ListContainerAttachments::applySort(), vars privata metod inte
     * kan återanvändas härifrån (issuen: den actionen rörs inte).
     *
     * `size` sorterar på `stored_file.byte_size` och kräver därför en join;
     * `select('attachment.*')` står i den grenen så att löpnumren inte
     * kolliderar med itemets. Ett okänt värde faller tillbaka på förvalet
     * `newest` i stället för att kasta: en handredigerad adress ska ge listan
     * och inte ett fel.
     *
     * @param  Builder<Attachment>  $query
     */
    private function applySort(Builder $query, ?string $sort): void
    {
        match ($sort) {
            'oldest' => $query->orderBy('attachment.created_at')->orderBy('attachment.id'),
            'name' => $query->orderBy('attachment.filename')->orderBy('attachment.id'),
            'size' => $query
                ->join('stored_file', 'stored_file.id', '=', 'attachment.stored_file_id')
                ->orderByDesc('stored_file.byte_size')
                ->orderByDesc('attachment.id'),
            default => $query->orderByDesc('attachment.created_at')->orderByDesc('attachment.id'),
        };
    }
}
