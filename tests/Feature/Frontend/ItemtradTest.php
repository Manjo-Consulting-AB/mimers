<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 154 · Växeln i containerns itemflik och trädläget. Se
 * App\Http\Controllers\ItemController::index(),
 * App\Actions\Item\ResolveItemTree,
 * resources/js/pages/Containers/Items/Index.vue,
 * resources/js/components/ItemViewSwitch.vue och
 * resources/js/components/ItemStructureTree.vue.
 *
 * **Växeln har tre lägen sedan issue 157.** [[ADR-0046 Containerns karta]]
 * ritar *Lista*, *Träd* och *Karta*, och kartan kom i § 157 — den ägs av
 * tests/Feature/Frontend/ContainerkartaTest.php. Här står regeln som gäller
 * alla lägen: det FÖRSTA är förvalet, och ett värde som inte är ett läge är
 * samma sak som inget läge — samma linje som filtret i issue 59a § Beslut 3
 * (en gammal länk är ingen felsida).
 *
 * **Läget är en adress.** Det står i querysträngen och ingenstans annat, och
 * `view`-proppen är serverns läsning av samma sträng som avgör vilken yta
 * sidan ritade. Provet prövar därför båda halvorna: att svaret bär läget, och
 * att växelns adresser skriver det — en växel vars länkar tappar läget ser
 * rätt ut i en strukturell kontroll och gör ingenting i en webbläsare.
 *
 * **Trädet är hela containern inom omfånget.** Rötter enligt [[ADR-0041
 * Itemets vy]] § Beslut, ett item med två föräldrar på båda ställena, och
 * ingenting om dem hon inte når (issue 73 § Beslut 6). Upplösningen är
 * App\Actions\Item\ResolveItemTree och skrivs inte om här: proven jämför mot
 * svaret och inte mot en avskrift av vyn.
 *
 * **Frågekostnaden är konstant.** Trädet är två frågor — itemen och kanterna
 * — oavsett hur många noder de bär, och det mäts på samma container före och
 * efter att skogen växer. Sidan ställer inga andra frågor i trädläget: den
 * som öppnar listan betalar inte för trädet (proppen finns bara där), och den
 * som öppnar trädet betalar inte för listan.
 *
 * Hjälparna har prefixet `itemtrad` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem som äger en container.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function itemtradKontext(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

/**
 * Ett item i containern med sammanhängande `created_by_*`.
 *
 * @param  array<string, mixed>  $attribut
 */
function itemtradItem(Container $container, string $namn, array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create(array_merge([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ], $attribut));
}

/**
 * En mottagare utanför ägarkontot med en grant på $item.
 */
function itemtradMottagare(Container $container, Item $item, string $nivå = 'read'): User
{
    $mottagare = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $nivå,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $mottagare;
}

/**
 * En kant skriven direkt i tabellen, förbi App\Actions\Item\LinkItems — samma
 * skäl som i ItemlistaTest: Actionen är garanten för att API:et aldrig skapar
 * en cykel, och garanten ska inte kunna maskera ett fel i vandringen.
 */
function itemtradKant(Item $förälder, Item $barn, string $relation = 'parent'): void
{
    ItemLink::query()->insert([
        'from_item_id' => $förälder->id,
        'to_item_id' => $barn->id,
        'relation' => $relation,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Itemflikens adress, med en querysträng om någon ges.
 */
function itemtradUrl(Container $container, string $query = ''): string
{
    return "/containers/{$container->ulid}/items".($query === '' ? '' : "?{$query}");
}

/**
 * Källkoden med kommentarer borta — samma tre slag som GenomgangTest rensar.
 */
function itemtradKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Växelns lägeslista: stycket från `const views = computed(` till radens `});`.
 *
 * Listan är den ENDA förteckningen över lägena, och proven läser den i stället
 * för en avskrift: en avskrift hade bevisat att testet och koden säger samma
 * sak, inte att vyn gör det. Samma form som ItemflikTest läser flikraden med.
 */
function itemtradLagen(string $vy): string
{
    $start = strpos($vy, 'const views = computed(');

    expect($start)->not->toBeFalse('vyn bygger ingen växel');

    $slut = strpos($vy, '});', (int) $start);

    expect($slut)->not->toBeFalse('växelns lista stänger aldrig');

    return substr($vy, (int) $start, (int) $slut - (int) $start);
}

/**
 * Antalet frågor $anrop ställer, mätt efter ett omätt anrop som värmer
 * guarderna och kontocachen — samma mönster som itemlistaFrågor().
 */
function itemtradFragor(Closure $värm, Closure $anrop): int
{
    // ResolveItemScope är `scoped` och memoiserar per request i drift, men i
    // testsviten överlever den mellan HTTP-anropen. Glöm den därför inför
    // varje mätning — annars mäter man förra anropets omfång.
    app()->forgetScopedInstances();

    $värm();

    $frågor = 0;
    DB::listen(function () use (&$frågor) {
        $frågor++;
    });

    $anrop();

    return $frågor;
}

/*
 * Klart när: itemfliken har växeln med *Lista* som förval.
 *
 * Förvalet är frånvaron av `view` och ingenting annat — samma val som
 * översiktsfliken gör med `tab` (issue 100 och 102): den aktuella adressen
 * innehåller ingen parameter, och den som öppnar fliken utan en länk landar i
 * listan. Provet prövar båda halvorna: svaret bär `list`, och växelns första
 * läge skriver sin adress utan `view`.
 */
it('har växeln med Lista som förval', function () {
    withoutVite();

    [, $anvandare, $container] = itemtradKontext();
    itemtradItem($container, 'Motorn');

    actingAs($anvandare)->get(itemtradUrl($container))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Index')
            ->where('view', 'list')
            // Listan är hämtad, trädet är det inte: en yta ingen ser ska inte
            // kosta en fråga (samma linje som historikens rader, issue 116).
            ->where('structure', null)
            ->has('items', 1)
    );

    $vy = itemtradKod('pages/Containers/Items/Index.vue');

    expect($vy)->toContain("import ItemViewSwitch from '../../../components/ItemViewSwitch.vue'")
        ->toContain('<ItemViewSwitch')
        ->toContain(':views="views"')
        ->toContain(':current="view"');

    $lagen = itemtradLagen($vy);

    // Listan först, och den skrivs utan `view`. Trädet står före kartan: de två
    // ritar samma struktur, och den som läser växeln från vänster möter den
    // enkla formen först.
    expect(strpos($lagen, "key: 'list'"))->toBeLessThan(strpos($lagen, "key: 'tree'"))
        ->and(strpos($lagen, "key: 'tree'"))->toBeLessThan(strpos($lagen, "key: 'map'"));

    preg_match_all("/key: '(\w+)'/", $lagen, $träffar);

    expect($träffar[1])->toBe(['list', 'tree', 'map']);
});

/*
 * Klart när: ett okänt läge är listan, aldrig ett fel.
 *
 * Sedan issue 157 finns *Karta* i växeln, och den ägs av
 * tests/Feature/Frontend/ContainerkartaTest.php. Kvar här står regeln som
 * gäller varje läge: `?view[]=…`, `?view=` och skräp läses som "inget läge",
 * och svaret är listan — den som klickat på en gammal länk är inte här.
 *
 * Provet prövar båda halvorna: att lägeslistan ÄR de tre lägena, i ordning,
 * och att ett värde utanför den ger listan med dess rader.
 */
it('svarar listan för ett okänt läge', function () {
    withoutVite();

    [, $anvandare, $container] = itemtradKontext();
    itemtradItem($container, 'Motorn');

    $lagen = itemtradLagen(itemtradKod('pages/Containers/Items/Index.vue'));

    preg_match_all("/key: '(\w+)'/", $lagen, $träffar);

    expect($träffar[1])->toBe(['list', 'tree', 'map']);

    foreach (['view=skräp', 'view=', 'view[]=map'] as $fråga) {
        actingAs($anvandare)->get(itemtradUrl($container, $fråga))->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Containers/Items/Index')
                ->where('view', 'list')
                ->where('structure', null)
                ->has('items', 1)
        );
    }
});

/*
 * Klart när: *Träd* visar containerns rötter enligt ADR-0041 och ett item med
 * två föräldrar på båda ställena.
 *
 * Trädet är App\Actions\Item\ResolveItemTree, oförändrat: samma nodform
 * (`{ulid, name, children}`), namnet stigande på varje nivå, och ett item med
 * två föräldrar på båda ställena — som två noder och inte som en sammanlagd
 * rad ([[ADR-0041 Itemets vy]] § Beslut: ingen kolumn pekar ut en huvudplats).
 *
 * Rötter är de items vars samtliga föräldrar ligger utanför omfånget eller
 * saknas. Provet visar båda: en vanlig rot (båten) och en mottagare vars
 * närmaste förfader ligger utanför hennes omfång — hon ser impellern som sin
 * rot, inte motorn och inte ett tomrum där motorn skulle stått.
 */
it('visar containerns rötter och ett item med två föräldrar på båda ställena', function () {
    withoutVite();

    [, $anvandare, $container] = itemtradKontext();

    $båten = itemtradItem($container, 'Båten');
    $motorn = itemtradItem($container, 'Motorn');
    $masten = itemtradItem($container, 'Masten');
    $impellern = itemtradItem($container, 'Impellern');

    itemtradKant($båten, $motorn);
    itemtradKant($båten, $masten);
    itemtradKant($motorn, $impellern);
    // Samma item under två föräldrar: grafen är en DAG och inte ett träd.
    itemtradKant($masten, $impellern);

    actingAs($anvandare)->get(itemtradUrl($container, 'view=tree'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Index')
            ->where('view', 'tree')
            ->where('structure', [
                [
                    'ulid' => $båten->ulid,
                    'name' => 'Båten',
                    'children' => [
                        ['ulid' => $masten->ulid, 'name' => 'Masten', 'children' => [
                            ['ulid' => $impellern->ulid, 'name' => 'Impellern', 'children' => []],
                        ]],
                        ['ulid' => $motorn->ulid, 'name' => 'Motorn', 'children' => [
                            ['ulid' => $impellern->ulid, 'name' => 'Impellern', 'children' => []],
                        ]],
                    ],
                ],
            ])
    );

    // Mottagaren når impellern och ingenting annat, och hennes träd är
    // ordagrant det hon hade sett om resten inte fanns: impellern är hennes
    // rot, och ingen rad säger att något dolts (issue 73 § Beslut 6).
    $mottagare = itemtradMottagare($container, $impellern);

    actingAs($mottagare)->get(itemtradUrl($container, 'view=tree'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('structure', [
            ['ulid' => $impellern->ulid, 'name' => 'Impellern', 'children' => []],
        ])
    );
});

/*
 * Klart när: läget överlever en omladdning.
 *
 * Läget bor i adressen och ingenstans annat, och servern läser samma sträng
 * varje gång: en omladdning skickar samma URL och får samma svar. Vyn håller
 * inget `ref` med valet — det är hela skillnaden mellan en flik man kan dela
 * och en som bara finns i minnet.
 *
 * Provet prövar därför två saker: att samma adress ger samma läge två gånger,
 * och att växelns länkar SKRIVER läget. Den andra halvan är den som faller om
 * någon bygger länkarna ur ett tillstånd i stället för ur adressen.
 */
it('låter läget överleva en omladdning', function () {
    withoutVite();

    [, $anvandare, $container] = itemtradKontext();
    itemtradItem($container, 'Motorn');

    foreach (range(1, 2) as $gång) {
        actingAs($anvandare)->get(itemtradUrl($container, 'view=tree'))->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('view', 'tree')
                ->has('structure', 1)
        );
    }

    $lagen = itemtradLagen(itemtradKod('pages/Containers/Items/Index.vue'));

    // Trädläget bär läget i sin adress, och listan bär det inte: förvalet
    // skrivs som frånvaron av parametern. (`view=tree` står två gånger i
    // raden — en gång för den tomma querysträngen och en för den fyllda — och
    // det är samma läge i båda.)
    expect($lagen)->toContain('view=tree')
        ->and($lagen)->not->toContain('view=list');

    // Och `current` är proppen och inte en egen läsning av adressen: vyn
    // jämför ingenting själv, för en andra läsning är en andra regel.
    $vy = itemtradKod('pages/Containers/Items/Index.vue');

    expect($vy)->not->toContain('usePage')
        ->and($vy)->not->toContain('page.url');
});

/*
 * Klart när: antalet frågor för trädet är konstant oavsett antal items.
 *
 * Trädet är två frågor — itemen i omfånget och containerns kanter — och
 * slutningen sker i minnet (App\Actions\Item\ResolveItemTree § Frågekostnaden).
 * Provet mäter på SAMMA container före och efter att skogen växer från tre
 * items till tretti: samma sida, samma frågor, en större skog. Det är så
 * "inga nya frågor ställs" går att bevisa utan en webbläsare.
 *
 * Jämförelsen är mot trädlägets egen mätning och inte mot listans: de två
 * lägena hämtar olika saker med flit, och det som prövas här är att trädet
 * inte växer med antalet noder.
 */
it('kostar ett konstant antal frågor oavsett antal items', function () {
    withoutVite();

    [, $anvandare, $container] = itemtradKontext();

    $båten = itemtradItem($container, 'Båten');
    $motorn = itemtradItem($container, 'Motorn');
    itemtradKant($båten, $motorn);

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80, 477).
    Carbon::setTestNow(now());

    $medTvå = itemtradFragor(
        fn () => actingAs($anvandare)->get(itemtradUrl($container, 'view=tree')),
        fn () => actingAs($anvandare)->get(itemtradUrl($container, 'view=tree')),
    );

    foreach (range(1, 30) as $i) {
        $barn = itemtradItem($container, sprintf('Del %02d', $i));
        itemtradKant($båten, $barn);
    }

    $medTrettiotvå = itemtradFragor(
        fn () => actingAs($anvandare)->get(itemtradUrl($container, 'view=tree')),
        fn () => actingAs($anvandare)->get(itemtradUrl($container, 'view=tree')),
    );

    expect($medTrettiotvå)->toBe(
        $medTvå,
        'trädet kostar fler frågor när det växer — upplösningen vandrar per nod',
    );

    Carbon::setTestNow();

    // Och komponenterna ber aldrig servern om något: trädet och växeln ritar
    // proppar, och ingen av dem hämtar eller navigerar själv.
    foreach ([
        'components/ItemStructureTree.vue',
        'components/ItemViewSwitch.vue',
    ] as $fil) {
        $kod = itemtradKod($fil);

        foreach (['fetch(', 'axios', 'router.', 'usePage'] as $hämtning) {
            expect($kod)->not->toContain($hämtning, "{$fil} ställer en egen fråga: {$hämtning}");
        }
    }
});

/*
 * Klart när: filtret följer med genom växeln.
 *
 * Trädläget filtrerar ingenting — trädet är containern som användaren når,
 * medan `q`, `tags[]` och `category` hör till listan ([[ADR-0046 Containerns
 * karta]] § Beslut) — och därför ritas filterraden bara i listläget. Men
 * växelns länkar bär filtret med sig, så den som står i en filtrerad lista och
 * tittar på trädet kommer tillbaka till samma träfflista.
 *
 * Provet läser adresserna ur vyns egen växel och prövar dem mot servern: en
 * länk som tappar filtret ser rätt ut i en strukturell kontroll och gör
 * ingenting i en webbläsare.
 */
it('bär filtret med sig genom växeln', function () {
    withoutVite();

    [, $anvandare, $container] = itemtradKontext();

    $motorn = itemtradItem($container, 'Motorn');
    itemtradItem($container, 'Masten');

    $vy = itemtradKod('pages/Containers/Items/Index.vue');
    $lagen = itemtradLagen($vy);

    // Adresserna byggs ur `filter`-proppen och ur ingenting annat — samma
    // uppslag som filterraden ritar, med bortfallna värden redan borta.
    foreach (['props.filter.q', 'props.filter.tags', 'props.filter.category'] as $källa) {
        expect($vy)->toContain($källa);
    }

    // Och växelns båda adresser byggs ur samma sträng: filtret läggs på
    // listans egen sökväg, och trädläget får `view` före det.
    expect($lagen)->toContain('base.value')
        ->and($lagen)->toContain('query');

    // Och en filtrerad lista svarar med filtret kvar i svaret, så växeln har
    // något att bygga adressen ur.
    actingAs($anvandare)->get(itemtradUrl($container, 'q=Motor'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('view', 'list')
            ->where('filter.q', 'Motor')
            ->has('items', 1)
            ->where('items.0.ulid', $motorn->ulid)
    );

    // Trädläget svarar med hela containern: filtret hör till listan, och det
    // är därför `items`-proppen inte styr trädet.
    actingAs($anvandare)->get(itemtradUrl($container, 'view=tree&q=Motor'))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('view', 'tree')
            ->has('structure', 2)
    );
});
