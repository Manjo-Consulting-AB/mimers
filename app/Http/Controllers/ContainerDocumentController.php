<?php

namespace App\Http\Controllers;

use App\Actions\Access\ResolveItemScope;
use App\Actions\Attachment\ListContainerAttachments;
use App\Actions\Attachment\ListRecentOpens;
use App\Http\Resources\AttachmentResource;
use App\Http\Resources\ContainerResource;
use App\Models\Attachment;
use App\Models\AttachmentOpen;
use App\Models\Container;
use App\Models\Item;
use App\Models\UsageCounter;
use App\Models\User;
use App\Support\Access\ItemScope;
use App\Support\Files\FileOrigin;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Containerns dokumentflik — `GET /containers/{container}/documents`, se issue
 * 178 · [[ADR-0050 Desktopdesignen]] § 12–15.
 *
 * **Fliken visar alla bilagor i containern användaren når** (Beslut 2). En
 * bilaga i en container tillhör alltid ett item, utom containerns egen bild
 * ([[ADR-0047 Containerns bild]] § Beslut), och urvalet är
 * App\Actions\Attachment\ListContainerAttachments — samma `whereHas('item', …)`
 * och samma `ResolveItemScope` som ListRecentImages och ListRecentOpens. Ett
 * item utanför omfånget finns därför inte i listan, och ingen räknare berättar
 * hur många som filtrerats bort (issue 73 § Beslut 6).
 *
 * **Grinden är `view` på containern** (Beslut 1), som varje annan av
 * containerns flikar (ContainerHistoryController, ContainerTaskController,
 * ContainerCostController): en främling får 403 och inte en tom lista — en tom
 * lista hade sagt "inga dokument" om en container hon inte når. Grinden svarar
 * på om hon når containern; VILKA rader hon sedan ser svarar actionen på.
 *
 * **Filtren står i querysträngen och kan kombineras** (Beslut 2): `q`, `kind`,
 * `item`, `uploader`, `from`, `to` och `sort`. `kind`, `item` och `uploader`
 * tar flera värden var — ELLER inom gruppen, OCH mellan grupperna — och både
 * `kind=image` och `kind[]=image&kind[]=document` läses. `q` söker i
 * filnamnet. `filter`-proppen är serverns läsning av samma sträng — förvalen
 * ifyllda, listorna `[]` när gruppen inte filtrerar och ett värde som inte
 * gick att läsa utelämnat — så vyn ritar fälten ur svaret i stället för ur
 * adressen, precis som `maintenance` på uppgiftsfliken och `filter` på
 * kostnadsfliken. Ett okänt värde är INTE ett fel: en handredigerad adress ska
 * ge listan och inte ett formulärfel på ett fält användaren inte har
 * ([[ADR-0038 Gränsen för Pro i kostnaderna]] § Beslut om formulärfel, samma
 * linje).
 *
 * **Sorteringen har fyra lägen** (Beslut 2): `newest`, `oldest`, `name` och
 * `size`. Lista eller rutnät är däremot INGET filter på servern: `?view=grid`
 * är en klientfråga om hur raderna ritas, och kontrollern läser den inte —
 * samma uppdelning som `?tab=` i UiTabs och `?path=` i issue 95, där servern
 * bara äger det som ändrar SVARET.
 *
 * **Senast öppnade ligger överst** (Beslut 3): App\Actions\Attachment\
 * ListRecentOpens från 177, fem rader, och bara användarens EGNA öppningar —
 * `attachment_open.user_id` är frågans första villkor och ingen annan rad kan
 * komma med ([[ADR-0051 Senast öppnade filer]] § Beslut). Är listan tom ritas
 * panelen inte alls; en rubrik över ingenting är ett påstående om att något
 * finns.
 *
 * **Lagringsstapeln visar det konto en uppladdning i containern debiteras**
 * (Beslut 4 · [[ADR-0050 Desktopdesignen]] § 15). Valet är
 * ItemAttachmentSection.vue:s förval, gjort på servern: containerns konto när
 * användaren är medlem i det — `viewStorage` prövar samma medlemskap som
 * AccountPolicy::viewStorage() och StorageController — annars användarens
 * första konto. Talen är `usage_counter.storage_bytes` och
 * `planLimit('storage_bytes')`, exakt de två läsningar
 * App\Support\Plan\Entitlements::assertStorageWithinLimit() gör: samma räknare
 * och samma plan, så stapeln och kvotfelet vid en uppladdning inte kan säga
 * olika saker. Ingen ser någon annans förbrukning — kontot är användarens eget
 * eller containerns, och beloppet läses för det kontot och inget annat.
 *
 * **`percent` räknas här och inte i vyn**, som `usedLabel`/`limitLabel` i
 * App\Actions\Plan\ReadPlanUsage: bytena formateras i vyn av `formatByteSize`
 * (byte-identisk med `Number::fileSize()`), men kvoten är ett tal om
 * förbrukningen och serverns. Ett obegränsat tak — `planLimit` svarar `null` —
 * ger `limitBytes: null` och `percent: null`, och då ritar stapeln bara
 * förbrukningen (Beslut 4).
 *
 * **Lägg till dokument laddar inte upp något härifrån** (Beslut 5). Knappen
 * leder till det valda itemets bilageflik `?tab=attachments`, där
 * uppladdningen redan finns (issue 60), och `items` är de items användaren får
 * SKAPA på — `ItemPolicy::create()` per item, samma grind som
 * `POST …/items/{item}/attachments` prövar. Är listan tom ritas ingen knapp:
 * en meny utan rader är en död yta (issue 174 § Beslut 5).
 *
 * **Kontrollern räknar ingenting själv** ([[ADR-0024 Tunna controllers och
 * actions]]). Listan, uppladdarna, de senast öppnade och urvalet är actions;
 * kvar här är propparnas FORM — `variants` och itemets namn och ULID läggs
 * BREDVID AttachmentResource, samma mönster som `inTrash` i
 * App\Http\Controllers\Settings\StorageController och `recentImages` i
 * ContainerController: resursen är `/api`:s kontrakt och har inte bett om
 * fälten.
 *
 * Omfånget löses upp på den `scoped`-bundna instansen och inte på en färsk
 * (`app()->build()`, som ContainerController::show() gör för sina summeringar):
 * här frågar TVÅ led samma sak — itemlistan i `filterOptions` och actionens
 * urval — och memon är precis vad som gör dem till EN upplösning i stället för
 * två.
 */
class ContainerDocumentController extends Controller
{
    /**
     * GET /containers/{container}/documents — 200.
     */
    public function __invoke(
        Request $request,
        Container $container,
        ListContainerAttachments $listAttachments,
        ListRecentOpens $listRecentOpens,
    ): Response {
        Gate::authorize('view', $container);

        // Ägarkontot och bilden: skalets topprad ritar miniatyren ur `cover`,
        // som resursen läser genom `coverAttachment` (issue 159), och hjälten
        // detsamma över `md:`. Utan den här raden hade båda blivit oplanerade
        // lazy-loads per sidladdning — samma rad som de andra flikarna gör
        // (issue 170).
        $container->loadMissing(['account', 'coverAttachment.storedFile.derivatives']);

        $user = $request->user();

        $scope = app(ResolveItemScope::class)->handle($user, $container);

        // Filtret LÄSES en gång och används två: som fråga till actionen och
        // som `filter`-propp till vyn. Två läsningar av samma sträng glider
        // isär, och då visar fältet ett filter listan inte tillämpade.
        $filter = $this->filter($request) + ['sort' => $this->sort($request)];

        $paginator = $listAttachments->handle($user, $container, $filter);

        // Varianterna byggs ur sidans MODELLER och innan raderna formas om:
        // `through()` ersätter dem med arrayer, och uppslaget ska kosta noll
        // frågor per rad (issue 61b § Beslut 1).
        $variants = $this->variants($paginator->getCollection());

        $rows = $paginator
            ->through(function (Attachment $attachment) use ($request, $user, $container): array {
                $item = $attachment->item;

                // Flytten prövar `delete` på bilagans ITEM ([[ADR-0053 Flytt
                // och kopiering]] § 2), och raden här är den enda platsen i
                // webben där rader från flera items möts: en `read`-mottagare
                // på ett item ser sin rad men får varken flytta eller radera
                // den. Flaggan är presentation — rutten prövar samma grind
                // på nytt (ContainerDocumentController § can ovan).
                //
                // Containern binds på itemet i stället för att slås upp: hela
                // sidan är EN container, och `ItemPolicy::delete()` läser
                // `$item->container->account`, så utan raden hade varje rad
                // kostat ett eget uppslag (ItemPolicy § allows).
                $item->setRelation('container', $container);

                return [
                    ...AttachmentResource::make($attachment)->resolve($request),

                    // Itemet raden hör till. Resursen bär det inte — `/api`s
                    // nästlade bilagerutt vet redan vilket itemet är — men
                    // listan länkar varje rad till itemets bilageflik och
                    // visar dess namn (issue 60).
                    'item' => [
                        'ulid' => (string) $item->ulid,
                        'name' => (string) $item->name,
                    ],
                    'can' => [
                        'delete' => Gate::forUser($user)->allows('delete', $item),
                    ],
                ];
            });

        return Inertia::render('Containers/Documents', [
            'container' => ContainerResource::make($container)->resolve($request),
            // Flaggan ritar hjältens *Redigera container* (issue 170);
            // rutten `PATCH /containers/{container}` prövar `update` på nytt.
            'can' => [
                'update' => Gate::forUser($user)->allows('update', $container),
            ],
            // Sant när containern är en inbox (ADR-0054 § 1). Flaggan döljer
            // *Back to Inbox* på raderna — bilagan är redan där, och rutten
            // hade svarat 422 `attachment.already_in_inbox`.
            'containerIsInbox' => $container->isInbox(),
            // Rader i en sida om trettio, i den valda ordningen. Paginatorn
            // går rakt igenom: sidnumret kommer ur `?page=` och räknas av
            // ramverket, och vyn ritar bara de länkar den får — med filtren
            // kvar, så en `?page=2` inte tyst byter fråga mitt i en listning.
            'attachments' => $rows,
            // Bilagans ULID → de derivatvarianter som FINNS, byggd ur de
            // eager-laddade relationerna. Rutnätet ritar `?variant=thumb` bara
            // när varianten står här: en `<img>` mot en bilaga utan derivat är
            // 404 (issue 19a § Beslut 5), och vyn gissar aldrig — samma propp
            // och samma regel som `variants` i ItemController::show() (issue
            // 61b § Beslut 1).
            'variants' => $variants,
            // Filtret så som servern tillämpade det, med ALLA nycklar: `q` och
            // datumen som `null` när de inte gäller, och `kind`, `item` och
            // `uploader` som LISTOR — `[]` när gruppen inte filtrerar. Vyn
            // ritar sina fält ur proppen och läser aldrig adressen själv —
            // samma form och samma skäl som `filter` i
            // ContainerCostController, och listorna är det Beslut 4 skriver ut.
            'filter' => $filter,
            // Filterfältets alternativ MED antalen (Beslut 6): items och
            // uppladdare användaren ser, var och en med antalet bilagor den
            // svarar för, plus typantalen och totalen. Ett filter utan
            // alternativ ritas inte av vyn — en väljare med bara *Alla* är
            // brus (samma regel som ContainerCostController).
            'filterOptions' => $this->filterOptions(
                $container,
                $scope,
                $listAttachments->uploaders($user, $container),
                $listAttachments->counts($user, $container),
            ),
            // Senast öppnade (Beslut 3), användarens egna, fem rader.
            'recentOpens' => $this->recentOpens($listRecentOpens->handle($user, $container)),
            // Lagringsstapeln (Beslut 4), eller null när användaren inte har
            // något konto att visa — då ritas ingen stapel.
            'storage' => $this->storage($user, $container),
            // Itemväljaren bakom *Lägg till dokument* (Beslut 5).
            'items' => $this->creatableItems($user, $container, $scope),
            // Sant när användarfiler levereras från en egen origin (issue 61a
            // § Beslut 2). Falsk betyder att allt levereras som `attachment`,
            // och då ritar rutnätet varken miniatyr eller filram — samma
            // flagga, samma läse och samma regel som `inlineEnabled` i
            // ItemController::show() och `attachmentPreview()`.
            'inlineEnabled' => FileOrigin::host() !== null,
        ]);
    }

    /**
     * Filtret ur querysträngen, normaliserat (Beslut 1 och 4).
     *
     * **Ett värde som inte går att läsa utelämnas i stället för att avvisas.**
     * En `kind` utanför de tre, en datumsträng som inte är ett datum och en
     * tom sträng är alla "inget filter" — listan svarar då på den fråga
     * användaren faktiskt ställde (ingen), och vyn ritar ett tomt fält i
     * stället för ett fält med ett värde som inte gäller. Ett 422 på en GET
     * hade dessutom varit ett fel användaren inte kan rätta: fältet hon
     * skrev i finns inte i formuläret.
     *
     * **`kind`, `item` och `uploader` är listor**, och både `kind=image` och
     * `kind[]=image&kind[]=document` tas emot: det skalära värdet blir en lista
     * med ett element, så den gamla adressen fortsätter att fungera. En grupp
     * utan värden är `[]` och inte `null` — Beslut 4:s form, och vyn behöver
     * bara hantera en tom lista i stället för två frånvaron.
     *
     * ULID:erna får sin FORM prövad och ingenting slås upp. Att slå upp dem är
     * actionens sak, och ett uppslag mot `user` eller `item` här hade varit
     * samma fråga ställd två gånger — skillnaden är att actionen redan har
     * omfånget, och en ULID som pekar utanför det ska ge noll rader och inte
     * ett avvisat filter.
     *
     * @return array{q: string|null, kind: list<string>, item: list<string>, uploader: list<string>, from: string|null, to: string|null}
     */
    private function filter(Request $request): array
    {
        return [
            // Sökningen (Beslut 3): trimmad, högst 255 tecken, tom är null.
            'q' => $this->search($request->query('q')),
            // Typen normaliseras av actionen och inte av en egen lista här:
            // samma tre värden, en formulering (ListContainerAttachments::
            // kindFilters()).
            'kind' => ListContainerAttachments::kindFilters($request->query('kind')),
            'item' => $this->ulids($request->query('item')),
            'uploader' => $this->ulids($request->query('uploader')),
            'from' => $this->date($request->query('from')),
            'to' => $this->date($request->query('to')),
        ];
    }

    /**
     * Sorteringen ur querysträngen, med förvalet `newest` (Beslut 2).
     */
    private function sort(Request $request): string
    {
        $sort = $request->query('sort');

        return is_string($sort) && in_array($sort, ListContainerAttachments::SORTS, true) ? $sort : 'newest';
    }

    /**
     * Sökordet ur querysträngen, eller null (Beslut 3).
     *
     * Trimmad och högst 255 tecken — samma längd som `IndexItemRequest` sätter
     * på `q` i `/api`, så en sökterm som kommer in härifrån aldrig är längre än
     * den API:et tar emot. En tom sträng är samma sak som ingen sökning. Längden
     * KLIPPS i stället för att avvisas: en för lång term i ett gammalt bokmärke
     * ska ge en lista, inte ett formulärfel på ett fält användaren inte har.
     */
    private function search(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }

    /**
     * ULID:erna ur en query-parameter, som en lista (Beslut 1).
     *
     * Både `item=<ulid>` och `item[]=<ulid>&item[]=<ulid>` tas emot: en sträng
     * blir en lista med ett element, och listan DEDUPLICERAS så att samma ULID
     * två gånger är ett värde. FORMEN prövas och ingenting slås upp:
     * `Str::isUlid()` är samma kontroll som `HasUlid` bygger på, och en sträng
     * som inte är en ULID kan inte matcha en rad — den faller bort i stället för
     * att bli ett filter som ger tomt.
     *
     * @return list<string>
     */
    private function ulids(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $giltiga = array_filter($value, static fn (mixed $ulid): bool => is_string($ulid) && Str::isUlid($ulid));

        return array_values(array_unique($giltiga));
    }

    /**
     * Ett `Y-m-d`-datum ur querysträngen, eller null.
     *
     * `Carbon::hasFormat()` och inte ett reguljärt uttryck: den prövar att
     * strängen GÅR att läsa som ett datum och inte bara att den har rätt
     * antal siffror — `2026-13-45` har formen men är ingen dag, och en
     * `Carbon::parse()` på den hade kastat i actionen.
     */
    private function date(mixed $value): ?string
    {
        return is_string($value) && Carbon::hasFormat($value, 'Y-m-d') ? $value : null;
    }

    /**
     * Bilagans ULID → de derivatvarianter som finns, för raderna på DEN HÄR
     * sidan.
     *
     * Byggd ur de eager-laddade relationerna, precis som `variants()` i
     * ItemController: noll extra frågor per rad, och uppslaget läcker
     * ingenting — en bilaga användaren ser är hennes att se. En bilaga utan
     * derivat får en tom lista och inte en utelämnad nyckel, samma skäl som
     * där: vyns uppslag är detsamma för alla rader.
     *
     * Listan sorteras: relationen har ingen egen ordning, och en propp vars
     * innehåll byter plats mellan två anrop mot samma rad är brus i varje svar
     * Inertia skickar.
     *
     * @param  Collection<int, Attachment>  $attachments
     * @return array<string, list<string>>
     */
    private function variants(Collection $attachments): array
    {
        $variants = [];

        foreach ($attachments as $attachment) {
            $variants[$attachment->ulid] = $attachment->storedFile->derivatives
                ->pluck('variant')
                ->sort()
                ->values()
                ->all();
        }

        return $variants;
    }

    /**
     * De senast öppnade raderna, i den form panelen ritar.
     *
     * `attachment`, dess `item` och dess `storedFile.derivatives` är
     * eager-laddade av ListRecentOpens, så raden kostar ingen fråga.
     * `opened_at` följer med som ISO 8601 och formateras av vyn — samma regel
     * som `created_at` i resurserna: servern skickar ögonblicket, klienten
     * formulerar ordet.
     *
     * `hasThumb` är samma svar som `recentImages()` ger och samma regel som
     * `variants` i itemvyn: en miniatyr ritas bara när `thumb`-varianten
     * FINNS, för `?variant=thumb` mot en bilaga utan derivat svarar 404
     * (issue 61b § Beslut 1). Vyn ska aldrig gissa, och den ska inte heller
     * känna till derivattabellen. En miniatyr i panelen skriver ingen
     * öppning — det gör bara leveransrutten.
     *
     * @param  Collection<int, AttachmentOpen>  $opens
     * @return list<array<string, mixed>>
     */
    private function recentOpens(Collection $opens): array
    {
        return $opens
            ->map(fn (AttachmentOpen $open): array => [
                'ulid' => (string) $open->attachment->ulid,
                'filename' => (string) $open->attachment->filename,
                'hasThumb' => $open->attachment->storedFile->derivatives->contains('variant', 'thumb'),
                'opened_at' => $open->opened_at->toIso8601String(),
                'item' => [
                    'ulid' => (string) $open->attachment->item->ulid,
                    'name' => (string) $open->attachment->item->name,
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * Items användaren når i containern, som `{ulid, name}` — filterfältets
     * itemväljare.
     *
     * Omfånget är det upplösta `ItemScope` och inte en egen fråga: väljaren ska
     * erbjuda exakt de items listan kan visa, och en item utanför omfånget är
     * inget val. En väljare behöver ULID och namn och ingenting mer —
     * App\Actions\Item\ListItems hade dragit med kategorier, taggar och
     * skapare per rad.
     *
     * @return list<array{ulid: string, name: string}>
     */
    private function visibleItems(Container $container, ItemScope $scope): array
    {
        return $container->items()
            ->inScope($scope)
            ->orderBy('name')
            ->get(['ulid', 'name'])
            ->map(static fn (Item $item): array => [
                'ulid' => (string) $item->ulid,
                'name' => (string) $item->name,
            ])
            ->values()
            ->all();
    }

    /**
     * Filterfältets alternativ MED antalen (Beslut 6).
     *
     * `items` och `uploaders` är oförändrade listor — items användaren når,
     * uppladdarna bakom bilagorna hon ser — men var och en bär nu `count`, och
     * ett item utan bilagor får 0. `kinds` bär alla tre nycklarna (en typ utan
     * bilagor är 0 och inte utelämnad), och `total` är antalet bilagor i
     * omfånget.
     *
     * Antalen kommer ur ListContainerAttachments::counts() och följer INTE det
     * valda filtret: siffran bredvid *Image* är alltid antalet bilder
     * användaren når. Att räkna om den mot träfflistan hade visat noll för
     * varje val utom det valda.
     *
     * @param  list<array{ulid: string, name: string}>  $uploaders
     * @param  array{total: int, kind: array<string, int>, item: array<string, int>, uploader: array<string, int>}  $counts
     * @return array{items: list<array{ulid: string, name: string, count: int}>, uploaders: list<array{ulid: string, name: string, count: int}>, kinds: array<string, int>, total: int}
     */
    private function filterOptions(Container $container, ItemScope $scope, array $uploaders, array $counts): array
    {
        return [
            'items' => array_map(
                static fn (array $item): array => [
                    ...$item,
                    'count' => $counts['item'][$item['ulid']] ?? 0,
                ],
                $this->visibleItems($container, $scope),
            ),
            'uploaders' => array_map(
                static fn (array $uploader): array => [
                    ...$uploader,
                    'count' => $counts['uploader'][$uploader['ulid']] ?? 0,
                ],
                $uploaders,
            ),
            'kinds' => $counts['kind'],
            'total' => $counts['total'],
        ];
    }

    /**
     * De items användaren får skapa en bilaga på, sorterade på namn — valet
     * bakom *Lägg till dokument* (Beslut 5).
     *
     * `ItemPolicy::create()` per item, samma grind som
     * `POST …/items/{item}/attachments` prövar (issue 60 § Beslut 3): en
     * `read`-mottagare ser containerns dokument men får ingen itemväljare.
     * `container.account` eager-laddas — regel 4 i ItemPolicy läser
     * ägarkontots status per item, och utan raden blir varje item ett uppslag.
     *
     * @return list<array{ulid: string, name: string}>
     */
    private function creatableItems(User $user, Container $container, ItemScope $scope): array
    {
        $items = $container->items()
            ->inScope($scope)
            ->with('container.account')
            ->orderBy('name')
            ->get();

        $gate = Gate::forUser($user);

        return $items
            ->filter(fn (Item $item): bool => $gate->allows('create', $item))
            ->map(fn (Item $item): array => ['ulid' => $item->ulid, 'name' => $item->name])
            ->values()
            ->all();
    }

    /**
     * Lagringsstapelns konto och tal (Beslut 4), eller null när användaren
     * inte har något konto alls att visa.
     *
     * Valet av konto är ItemAttachmentSection.vue:s förval gjort på servern:
     * containerns konto när användaren är medlem i det, annars hennes första.
     * Medlemskapet prövas med `viewStorage` — samma policyfråga som
     * ContainerCostController ställer för `canUpgrade`, och samma
     * `AccountPolicy::isMember()` som StorageController grindar sin sida på.
     * En gäst med en itemgrant är inte medlem och får sitt EGET kontos
     * förbrukning: en uppladdning från en gäst debiteras gästen (§ 15), och
     * containerns konto är då inte det tal som gäller.
     *
     * Talen kommer ur `usage_counter` och planen — aldrig ur en egen SUM, av
     * samma skäl som App\Support\Plan\Entitlements::assertStorageWithinLimit()
     * anger: räknaren är byggd för den här frågan och hålls i takt av
     * avstämningen. En uppladdning som nekas för att kvoten är full och en
     * stapel som visar ett annat tal vore två svar på samma fråga.
     *
     * `percent` är förbrukningen i hela procent, klippt till 100 — ett
     * överskridet tak ritar en full stapel och aldrig en som sticker utanför.
     *
     * @return array{account: array{ulid: string, name: string}, usedBytes: int, limitBytes: int|null, percent: int|null}|null
     */
    private function storage(User $user, Container $container): ?array
    {
        $account = Gate::forUser($user)->allows('viewStorage', $container->account)
            ? $container->account
            : $user->accounts()->first();

        if ($account === null) {
            return null;
        }

        $used = (int) UsageCounter::query()
            ->where('account_id', $account->id)
            ->value('storage_bytes');

        // `planLimit()` svarar `int|bool|null`, och för storage_bytes är `null`
        // det obegränsade taket (App\Support\Plan\Entitlements § docblock).
        $limit = $account->planLimit('storage_bytes');
        $limitBytes = is_int($limit) ? $limit : null;

        return [
            'account' => [
                'ulid' => (string) $account->ulid,
                'name' => (string) $account->name,
            ],
            'usedBytes' => $used,
            'limitBytes' => $limitBytes,
            'percent' => $limitBytes === null
                ? null
                : min(100, intdiv($used * 100, max($limitBytes, 1))),
        ];
    }
}
