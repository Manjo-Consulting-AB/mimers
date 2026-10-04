<?php

namespace App\Actions\Attachment;

use App\Actions\Access\ResolveItemScope;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bilagorna i en container, en sida i taget — underlaget för dokumentfliken
 * (issue 178 · [[ADR-0050 Desktopdesignen]] § 12–15).
 *
 * **Urvalet är bilagor på LEVANDE items i containern användaren når.** Tre
 * villkor, och varje villkor står där av ett eget skäl:
 *
 * - `whereHas('item', …)` bär tre saker på en gång. Containern: itemet ska
 *   höra till DEN HÄR containern. Papperskorgen: relationen bär itemets
 *   SoftDeletes, så ett item i papperskorgen faller bort. Och containerns EGEN
 *   bild: den sitter på `container_id` med `item_id = NULL` och har inget item
 *   att matcha, så den kan aldrig passera ledet ([[ADR-0047 Containerns
 *   bild]]). Ett `item_id IS NOT NULL` står bredvid som samma sanning skriven
 *   rakt ut — villkoret är redundant mot ledet ovan, men det är den form
 *   issuens urval är formulerat i, och en läsare ska inte behöva sluta sig
 *   till det ur en relation.
 * - Bilagans eget SoftDeletes-scope gör detsamma för en bilaga i
 *   papperskorgen.
 * - Omfånget läggs ovanpå som `whereIn('item.id', …)`, och ett OMFATTANDE
 *   omfång slipper villkoret helt: `ItemScope::itemIds()` svarar `null` för
 *   "hela containern", och listan ska inte materialiseras till en `whereIn`
 *   med varje löpnummer (issue 73 § Beslut 1). Ett begränsat omfång med en
 *   tom lista kompilerar till `0 = 1` — "når ingenting", aldrig "når allt"
 *   (App\Support\Access\ItemScope).
 *
 * **ELLER inom en grupp, OCH mellan grupperna** (Beslut 2). `kind`, `item` och
 * `uploader` tar var sin LISTA: en bilaga har en typ, ett item och en
 * uppladdare, så flera värden i samma grupp måste vara en ELLER — ett OCH
 * inom gruppen hade aldrig kunnat träffa. Grupperna emellan är det ett OCH:
 * `kind[]=document&item[]=<motor>` ger motorns dokument och varken motorns
 * bild eller värmarens pdf. Varje grupp smalnar av frågan och ingen av dem
 * breddar den: en ULID som inte matchar något ger ett tomt resultat, aldrig
 * ett ogiltigt filter (samma linje som App\Actions\Item\ListItems § docblock).
 * `uploader` slås upp GLOBALT och inte inom omfånget — det är ofarligt, för
 * omfångsvillkoret står kvar och en uppladdare utanför omfånget ger därför
 * noll rader. Att i stället bygga en lista över "uppladdare inom omfånget" och
 * pröva ULID:n mot den hade varit en andra formulering av samma fråga.
 *
 * **Datumgränserna är användarens dygn, inte UTC:s** (Beslut 2). `from` och
 * `to` är `Y-m-d` i användarens tidszon (`User::preferredTimezone()`, issue
 * 135), och de vänds till ett UTC-spann här: en användare i Europe/Stockholm
 * är i det nya dygnet redan när servern i UTC ännu är i det gamla. Samma regel
 * som `I år` på kostnadsfliken och månaden i DashboardController.
 *
 * **Ordningen har en andrasortering i varje gren.** Två bilagor uppladdade i
 * samma sekund har samma tidsstämpel, och en lista vars innehåll byter plats
 * mellan två anrop är brus i varje svar Inertia skickar — samma par och samma
 * skäl som App\Actions\Attachment\ListRecentImages. `size` sorterar på
 * `stored_file.byte_size` och kräver därför en join; `select('attachment.*')`
 * står därför i den grenen så att löpnumren inte kolliderar med itemets
 * (samma grepp som App\Http\Controllers\Settings\StorageController).
 *
 * **Trettio rader per sida.** Antalet står här och inte i en query-parameter:
 * en sidstorlek som gick att skicka in vore ännu en fråga användaren ställer.
 * Sidnumret kommer ur `?page=` och räknas av ramverket.
 *
 * **Raden är en modell och inte en array**, till skillnad från
 * ListContainerCosts: formen är App\Http\Resources\AttachmentResource, som
 * redan är API:ets kontrakt för en bilaga, og den byggs i kontrollern.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen mot
 * containerns `view` — samma linje som ListRecentImages, ListRecentOpens,
 * ListItems och ListFavorites. Den här rutten har ingen item-ULID att pröva en
 * itemgrind mot, och en främling får 403 av kontrollern och inte en tom lista.
 */
class ListContainerAttachments
{
    /** Antalet rader per sida — Beslut 2. */
    public const PER_PAGE = 30;

    /** Sorteringarna `sort` kan anta, och `newest` är förvalet (Beslut 2). */
    public const SORTS = ['newest', 'oldest', 'name', 'size'];

    /**
     * Typerna `kind` kan filtreras på, eller null för ett värde som inte är
     * en av dem (Beslut 2 · [[ADR-0050 Desktopdesignen]] § 12).
     *
     * Värdena är desamma som App\Actions\Attachment\StoreAttachment::
     * kindFromMime() härleder — bild, dokument och övrigt. Bildens *Manual*,
     * *Kvitto*, *Service* och *Försäkring* finns inte: de är ett senare beslut
     * (§ 12), och en väljare som erbjöd dem hade varit ett filter raderna inte
     * kan svara på.
     *
     * **Listan står i en metod och inte i en konstant**, och både kontrollern
     * och frågan går genom den: två avskrifter av samma tre värden glider
     * isär, och den ena hade erbjudit ett filter som den andra ignorerade.
     * Namnet är verbet och inte en `KINDS`-konstant — den senare är förbjuden
     * som identifierare i app/ sedan issue 84 ([[ADR-0036 Containerns art]]:
     * arten är fri och har ingen sluten lista), och ett prov letar efter ordet.
     */
    public static function kindFilter(mixed $kind): ?string
    {
        return is_string($kind) && in_array($kind, ['image', 'document', 'other'], true) ? $kind : null;
    }

    /**
     * Typfiltret ur querysträngen, normaliserat till en lista (Beslut 1).
     *
     * Både `kind=image` och `kind[]=image&kind[]=document` tas emot: ett
     * skalärt värde blir en lista med ett element, och varje värde prövas
     * genom `kindFilter()` så att ett okänt värde faller bort. Listan är
     * DEDUPLICERAD och står i ordningen `image, document, other` — den fasta
     * ordningen och inte den inskickade, så att två adresser som pekar på
     * samma filter ger samma `filter`-propp.
     *
     * En tom lista är inget filter: den som skickade `kind[]=video` får hela
     * listan och inte ett tomt svar, samma linje som `kindFilter()` drar för
     * ett enskilt värde.
     *
     * @return list<string>
     */
    public static function kindFilters(mixed $kind): array
    {
        if (is_string($kind)) {
            $kind = [$kind];
        }

        if (! is_array($kind)) {
            return [];
        }

        $giltiga = array_filter(array_map(
            static fn (mixed $varde): ?string => self::kindFilter($varde),
            $kind,
        ), static fn (?string $varde): bool => $varde !== null);

        return array_values(array_filter(
            ['image', 'document', 'other'],
            static fn (string $varde): bool => in_array($varde, $giltiga, true),
        ));
    }

    public function __construct(private readonly ResolveItemScope $resolveItemScope) {}

    /**
     * Bilagorna i containern användaren når, filtrerade och sorterade.
     *
     * `item` och `storedFile.derivatives` är eager-laddade: raden visar
     * filnamn, storlek, typ och sitt item, och ritar en miniatyr bara när
     * `thumb`-varianten finns — den regeln är `variants` i
     * App\Http\Controllers\ItemController::show och kräver derivaten utan en
     * fråga per bild (issue 61b § Beslut 1). Frågekostnaden är därför konstant
     * och växer inte med antalet rader.
     *
     * @param  array{q?: string|null, kind?: list<string>, item?: list<string>, uploader?: list<string>, from?: string|null, to?: string|null, sort?: string|null}  $filters
     */
    public function handle(User $user, Container $container, array $filters = []): LengthAwarePaginator
    {
        $itemIds = $this->resolveItemScope->handle($user, $container)->itemIds();

        $query = $this->visible($container, $itemIds)
            ->select('attachment.*')
            // `billedAccount` bär AttachmentResource (`billed_account`), och
            // `storedFile.derivatives` bär `variants` i kontrollern: utan dem
            // hade båda blivit en fråga per rad.
            ->with(['item', 'storedFile.derivatives', 'billedAccount']);

        $this->applyItemFilter($query, $filters['item'] ?? []);
        $this->applyScalarFilters($query, $user, $filters);
        $this->applySort($query, $filters['sort'] ?? null);

        return $query->paginate(self::PER_PAGE);
    }

    /**
     * Urvalet som `handle()`, `uploaders()` och `counts()` delar: bilagor på
     * LEVANDE items i containern användaren når.
     *
     * `whereHas('item', …)` bär containern, papperskorgen (itemets SoftDeletes)
     * och containerns EGEN bild (`item_id = NULL` matchar aldrig) — se
     * klassdocblocken. `whereNotNull('attachment.item_id')` står bredvid som
     * samma sanning skriven rakt ut. Omfånget läggs ovanpå som
     * `whereIn('item.id', …)`, och ett OMFATTANDE omfång slipper villkoret
     * helt: `null` är "hela containern" och en tom lista är "når ingenting".
     *
     * Extraherad så att de tre läsarna frågar samma sak: två avskrifter av
     * urvalet glider isär, och den ena hade räknat in en bilaga den andra inte
     * visade.
     *
     * @param  list<int>|null  $itemIds
     * @return Builder<Attachment>
     */
    private function visible(Container $container, ?array $itemIds): Builder
    {
        return Attachment::query()
            ->whereNotNull('attachment.item_id')
            ->whereHas('item', function (Builder $query) use ($container, $itemIds) {
                /** @var Builder<Item> $query */
                $query->where('item.container_id', $container->getKey());

                // null är "hela containern" och lägger inget villkor; en tom
                // lista är "når ingenting" och kompilerar till `0 = 1` — se
                // App\Support\Access\ItemScope.
                if ($itemIds !== null) {
                    $query->whereIn('item.id', $itemIds);
                }
            });
    }

    /**
     * Uppladdarna bakom bilagorna användaren ser i containern, sorterade på
     * namn — valen i uppladdarfiltret (Beslut 2: "valen är bara uppladdarna i
     * det användaren redan ser").
     *
     * **Ett DISTINCT över samma urval som handle()**, inte en lista över
     * containerns medlemmar: en medlem som aldrig laddat upp något i containern
     * är inget val, och en uppladdare utanför omfånget är det inte heller. Ett
     * filter utan alternativ ritas inte (samma regel som filterfältet i
     * App\Http\Controllers\ContainerCostController), och en tom lista här
     * betyder just det.
     *
     * Frågan ställs mot `attachment` och inte mot `user` med ett `whereHas`:
     * mängden som ska skiljas ut är uppladdarnas ID:N, och namnen slås upp i en
     * andra fråga för de ID:N som blev kvar. Det är två frågor, konstanta över
     * antalet rader.
     *
     * @return list<array{ulid: string, name: string}>
     */
    public function uploaders(User $user, Container $container): array
    {
        $itemIds = $this->resolveItemScope->handle($user, $container)->itemIds();

        $ids = $this->visible($container, $itemIds)
            ->distinct()
            ->pluck('attachment.uploaded_by_user_id')
            ->all();

        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['ulid', 'name'])
            ->map(static fn (User $user): array => [
                'ulid' => (string) $user->ulid,
                'name' => (string) $user->name,
            ])
            ->values()
            ->all();
    }

    /**
     * Antalet bilagor per typ, item och uppladdare, inom det användaren ser
     * (Beslut 5) — underlaget för siffrorna i filterkolumnen.
     *
     * **Samma urval som `uploaders()`** — containern, omfånget, inga
     * borttagna — och därför samma `visible()`. Antalen följer INTE det valda
     * filtret: siffran bredvid *Image* är alltid antalet bilder användaren
     * når, och ett `q` eller en `kind` i adressen rör den inte. Ett filter
     * som räknade om sig självt hade visat noll för varje val utom det valda.
     *
     * **Konstant antal frågor**: en `GROUP BY` per grupp, plus ett ULID-uppslag
     * för de löpnummer som blev kvar i item- och uppladdargruppen. Totalen är
     * summan av typgrupperna — varje bilaga har en typ, så en fjärde fråga
     * hade svarat samma sak som den första. `kind` bär alla tre nycklarna även
     * när en typ saknas, så vyn slipper hantera en utelämnad nyckel.
     *
     * @return array{total: int, kind: array<string, int>, item: array<string, int>, uploader: array<string, int>}
     */
    public function counts(User $user, Container $container): array
    {
        $itemIds = $this->resolveItemScope->handle($user, $container)->itemIds();

        $kinds = ['image' => 0, 'document' => 0, 'other' => 0];
        $total = 0;

        $rader = $this->visible($container, $itemIds)
            ->toBase()
            ->select('attachment.kind as grupp', DB::raw('COUNT(*) as antal'))
            ->groupBy('attachment.kind')
            ->get();

        foreach ($rader as $rad) {
            $total += (int) $rad->antal;

            $kind = (string) $rad->grupp;

            if (array_key_exists($kind, $kinds)) {
                $kinds[$kind] = (int) $rad->antal;
            }
        }

        $perItem = $this->visible($container, $itemIds)
            ->toBase()
            ->select('attachment.item_id as grupp', DB::raw('COUNT(*) as antal'))
            ->groupBy('attachment.item_id')
            ->pluck('antal', 'grupp')
            ->all();

        $perUppladdare = $this->visible($container, $itemIds)
            ->toBase()
            ->select('attachment.uploaded_by_user_id as grupp', DB::raw('COUNT(*) as antal'))
            ->groupBy('attachment.uploaded_by_user_id')
            ->pluck('antal', 'grupp')
            ->all();

        return [
            'total' => $total,
            'kind' => $kinds,
            'item' => $this->ulidCounts($perItem, Item::class),
            'uploader' => $this->ulidCounts($perUppladdare, User::class),
        ];
    }

    /**
     * Löpnummer → antal, översatt till ULID → antal (Beslut 5).
     *
     * Ett uppslag per grupp och ingenting mer: mängden som ska namnges är
     * löpnumren ur `GROUP BY`, och ett `id` utan rad i tabellen — kan inte
     * hända med en deklarerad främmande nyckel — hoppas över i stället för att
     * bli en nyckel utan namn.
     *
     * @param  array<int|string, int|string>  $antalPerId
     * @param  class-string<Item>|class-string<User>  $modell
     * @return array<string, int>
     */
    private function ulidCounts(array $antalPerId, string $modell): array
    {
        if ($antalPerId === []) {
            return [];
        }

        $ulids = $modell::query()->whereIn('id', array_keys($antalPerId))->pluck('ulid', 'id')->all();

        $antalPerUlid = [];

        foreach ($antalPerId as $id => $antal) {
            if (isset($ulids[$id])) {
                $antalPerUlid[(string) $ulids[$id]] = (int) $antal;
            }
        }

        return $antalPerUlid;
    }

    /**
     * `item`-filtret — itemets ULID, som en ELLER-lista (Beslut 1 och 2).
     *
     * Villkoret ligger i item-ledet och inte som `where('attachment.item_id',
     * …)`: en ULID som inte finns i containern, eller som ligger utanför
     * omfånget, ska ge noll rader, och den sökta raden är ändå alltid den
     * aktuella item-raden i underfrågan. Flera värden är en ELLER inom
     * gruppen — en bilaga har ETT item, så ett OCH hade aldrig kunnat träffa
     * — medan gruppen som helhet kombineras med de andra Gruppen med OCH.
     *
     * @param  Builder<Attachment>  $query
     * @param  list<string>  $ulids
     */
    private function applyItemFilter(Builder $query, array $ulids): void
    {
        if ($ulids === []) {
            return;
        }

        $query->whereHas('item', static function (Builder $item) use ($ulids) {
            /** @var Builder<Item> $item */
            $item->whereIn('item.ulid', $ulids);
        });
    }

    /**
     * Sökningen, typen, uppladdaren och datumet — de filter som står direkt på
     * `attachment`.
     *
     * **`kind` och `uploader` är ELLER inom sin grupp** (Beslut 2): en bilaga
     * har en typ och en uppladdare, så `whereIn` är den enda formen som kan
     * träffa mer än ett värde. En tom lista är inget filter.
     *
     * **`q` söker i filnamnet** (Beslut 3). `%`, `_` och escapetecknet självt
     * escapas med `!` och inte med backslash: `'\\'` är ETT tecken i MariaDB
     * men TVÅ i sqlite, och sviten kör sqlite — då hade ett filnamn med
     * backslash betett sig olika i test och drift. `ESCAPE '!'` står därför
     * utskrivet och `!`, `%` och `_` blir bokstavliga.
     *
     * `from` och `to` är dygnsgränser i användarens tidszon och vänds till ett
     * UTC-spann: `startOfDay()` respektive `endOfDay()` i hennes zon, lästa mot
     * de UTC-tidsstämplar kolumnen bär. Ett `to` mitt på dygnet hade tappat
     * allt uppladdat efter midnatt — samma fälla som `whereDate()` finns för
     * att undvika.
     *
     * @param  Builder<Attachment>  $query
     * @param  array{q?: string|null, kind?: list<string>, uploader?: list<string>, from?: string|null, to?: string|null}  $filters
     */
    private function applyScalarFilters(Builder $query, User $user, array $filters): void
    {
        $q = $filters['q'] ?? null;

        if ($q !== null && $q !== '') {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q);

            $query->whereRaw("attachment.filename LIKE ? ESCAPE '!'", ['%'.$escaped.'%']);
        }

        $kinds = $filters['kind'] ?? [];

        if ($kinds !== []) {
            $query->whereIn('attachment.kind', $kinds);
        }

        $uploaders = $filters['uploader'] ?? [];

        if ($uploaders !== []) {
            // En ULID utan användare bakom sig ger `whereIn(…, [])` och
            // därmed `0 = 1` — "matchar ingenting" och aldrig "matchar allt".
            // Ett `whereNull` hade varit det tysta svaret: kolumnen är
            // NOT NULL, så det hade råkat bli rätt av fel skäl.
            $query->whereIn(
                'attachment.uploaded_by_user_id',
                User::query()->whereIn('ulid', $uploaders)->pluck('id')->all(),
            );
        }

        $timezone = $user->preferredTimezone();

        $from = $filters['from'] ?? null;

        if ($from !== null && $from !== '') {
            $query->where('attachment.created_at', '>=', Carbon::parse($from, $timezone)->startOfDay()->utc());
        }

        $to = $filters['to'] ?? null;

        if ($to !== null && $to !== '') {
            $query->where('attachment.created_at', '<=', Carbon::parse($to, $timezone)->endOfDay()->utc());
        }
    }

    /**
     * Ordningen, med andrasortering i varje gren.
     *
     * `name` sorterar på `filename` och inte på itemets namn: raden ÄR filen,
     * och listan sorteras på det användaren ser. `size` är störst först — den
     * fråga en lagringsyta ställer — och kräver joinen mot `stored_file`,
     * eftersom bytena bor där (`mime_type` och `byte_size` likaså, se
     * App\Http\Resources\AttachmentResource).
     *
     * Ett okänt värde faller tillbaka på förvalet `newest` i stället för att
     * kasta: sorteringen är en fråga i querysträngen, och en handredigerad
     * adress ska ge listan och inte ett fel.
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
