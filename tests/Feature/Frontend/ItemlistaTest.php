<?php

// rott-pa-basen: issue 77b — ordbyte i prosa (kommentar och testnamn), ingen kodändring; bas och head delar applikationskod.

use App\Actions\Container\SetContainerCover;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\ImageDerivative;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use App\Support\Item\ItemStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * Issue 57a · Containerns itemlista. Se
 * App\Http\Controllers\ItemController::index(),
 * App\Actions\Item\ListItems och
 * resources/js/pages/Containers/Items/Index.vue.
 *
 * **Listan låg på containerns egen URL till och med issue 88.** Sedan issue 89
 * · [[ADR-0039 Containerns översikt]] ligger den på
 * `GET /containers/{container}/items`, och containerns egen URL svarar med
 * översikten — den prövas i tests/Feature/Frontend/ContainervyerTest.php, som
 * äger App\Http\Controllers\ContainerController. Filen här följer med flytten:
 * samma sex tester, samma förväntningar, en annan sökväg.
 *
 * Den viktigaste gränsen i filen är OMFÅNGET (Beslut 4, issue 73 § Beslut 2
 * och 6): en omfångsbegränsad mottagare ser bara de items hon når, och svaret
 * får aldrig bära ett tal om hur många rader som filtrerats bort. Sidan visar
 * antalet rader den ritar och ingenting mer. Samma regel bär översiktens
 * itembricka, och att de två talen är samma tal prövas i ContainervyerTest.
 *
 * Den andra är RUTTORDNINGEN (Beslut 1): `GET /containers/create` måste
 * registreras före de rutter som bär `{container}`, annars matchar `{container}`
 * strängen `create` och formuläret blir en 404.
 *
 * Att `/api/containers/{container}/items` svarar exakt som förut prövas av
 * tests/Feature/Item/** och tests/Feature/Omfang/ListningsfilterTest.php, som
 * är gröna utan en enda ändrad förväntan efter utbrytningen i Beslut 3. En ny
 * formulering av samma sak här hade bevisat noll.
 *
 * **Radens status kom med issue 92** · [[ADR-0040 Underträdets summor]]:
 * `statuses` är itemets ULID → `ok` eller `overdue`, härlett ur underträdet av
 * App\Support\Item\ItemStatus. Slutningen prövas i
 * tests/Feature/Item/ItemstatusTest.php; här prövas det listan svarar med —
 * att varje rad bär en status, att den ligger BREDVID `ItemResource` och
 * aldrig inuti den, att en förekomst på en rad utanför omfånget inte färgar
 * någon status mottagaren ser, och att frågekostnaden är konstant även när
 * raderna har barn och förekomster.
 *
 * Hjälparna har prefixet `itemlista` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem i angiven roll, och en container ägd av kontot.
 *
 * @param  array<string, mixed>  $kontoAttribut
 * @return array{0: Account, 1: User, 2: Container}
 */
function itemlistaKontext(array $kontoAttribut = []): array
{
    $konto = Account::factory()->create($kontoAttribut);
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

/**
 * Ett item i containern. `created_by_*` sätts sammanhängande — fabrikens egna
 * default-skapare hade annars blivit två ovidkommande rader per item.
 */
function itemlistaItem(Container $container, string $namn, ?User $skapare = null): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => ($skapare ?? User::factory()->create())->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * En mottagare UTANFÖR ägarkontot: en itemgrant när $item ges, en
 * container-bred grant annars.
 */
function itemlistaMottagare(Container $container, ?Item $item = null, string $nivå = 'read'): User
{
    $mottagare = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item?->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $nivå,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $mottagare;
}

/**
 * En öppen förekomst på itemet, `$dagar` från idag — negativt är förfallet.
 *
 * Produktionen går alltid genom App\Actions\Schedule\OpenNextOccurrence
 * (fabrikens docblock), men här byggs raden direkt så att datumet är känt utan
 * att räkna kalender. `visible_from` följer `due_at`: ett förfallet datum är
 * alltid synligt.
 */
function itemlistaFörekomst(Item $item, int $dagar): ScheduleOccurrence
{
    $datum = Carbon::today()->addDays($dagar)->toDateString();

    $schema = Schedule::factory()->for($item, 'item')->create([
        'anchor_date' => $datum,
        'lead_days' => 0,
        'is_active' => true,
    ]);

    return ScheduleOccurrence::factory()->create([
        'schedule_id' => $schema->id,
        'due_at' => $datum,
        'visible_from' => $datum,
        'status' => 'open',
    ]);
}

/**
 * En kant skriven direkt i tabellen, förbi App\Actions\Item\LinkItems — samma
 * skäl som i tests/Feature/Item/AttlingsupplosningTest.php: Actionen är
 * garanten för att API:et aldrig skapar en cykel, och garanten ska inte kunna
 * maskera ett fel i vandringen.
 */
function itemlistaKant(Item $förälder, Item $barn, string $relation = 'parent'): void
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
 * En bilaga på itemet, med ett `thumb`-derivat när $thumb är sant.
 *
 * Derivatet skapas av kön i drift (issue 61b § Beslut 1); här byggs raden
 * direkt så att `hasThumb` är känd utan att köa ett jobb. `kind` är `image`
 * som standard — det är den sortens bilaga omslaget väljer.
 */
function itemlistaBild(
    Item $item,
    string $filnamn = 'foto.jpg',
    bool $thumb = true,
    string $kind = 'image',
): Attachment {
    $bilaga = Attachment::factory()->for($item, 'item')->create([
        'filename' => $filnamn,
        'kind' => $kind,
    ]);

    if ($thumb) {
        ImageDerivative::factory()->create([
            'stored_file_id' => $bilaga->stored_file_id,
            'variant' => 'thumb',
            'storage_path' => $bilaga->storedFile->storage_path.'_thumb.jpg',
        ]);
    }

    return $bilaga;
}

/**
 * Sätter containerns EGEN bild genom App\Actions\Container\SetContainerCover —
 * samma väg rutten går. Raden ska aldrig låna den.
 */
function itemlistaContainerbild(User $användare, Container $container): Attachment
{
    actingAs($användare);

    return app(SetContainerCover::class)->handle(
        $container,
        UploadedFile::fake()->image('container.png'),
        $användare,
        $container->account,
    );
}

/**
 * Antalet frågor $anrop ställer, mätt efter ett omätt anrop som värmer
 * guarderna och kontocachen — samma mönster som
 * ListningsfilterTest::listningsFrågor().
 */
function itemlistaFrågor(Closure $värm, Closure $anrop): int
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

/**
 * Listblocket i Index.vue — från `<ul v-else` till första `</ul>`.
 *
 * Raden prövas mot blocket och inte mot hela filen: docblocken ovan räknar upp
 * ord och proppar som raden inte får bära (som `container.cover` och
 * `statuses`), och en kontroll mot hela filen hade fällt på dem i stället för
 * på raden.
 */
function itemlistaListblock(): string
{
    $vy = File::get(resource_path('js/pages/Containers/Items/Index.vue'));

    preg_match('#<ul v-else.*?</ul>#s', $vy, $träff);

    expect($träff[0] ?? '')->not->toBe('', 'listblocket finns inte i Index.vue');

    return $träff[0];
}

/*
 * Beslut 1: båda rutterna ligger bakom `auth`. En utloggad besökare skickas
 * till inloggningen och når aldrig en kontrollermetod.
 */
it('skickar en utloggad besökare till inloggningen från listan och detaljvyn', function () {
    withoutVite();

    [, , $container] = itemlistaKontext();
    $item = itemlistaItem($container, 'Motorn');

    get("/containers/{$container->ulid}")->assertRedirect('/login');
    get("/containers/{$container->ulid}/items")->assertRedirect('/login');
    get("/containers/{$container->ulid}/items/{$item->ulid}")->assertRedirect('/login');
});

/*
 * Klart när: itemlistan ligger på `/containers/{container}/items` och renderar
 * listan för en medlem i ägarkontot, sorterad på namn.
 *
 * Sorteringen är serverns — raderna skapas i omvänd bokstavsordning så att en
 * lista som råkade behålla skapelseordningen faller.
 */
it('renderar itemlistan sorterad på namn för en medlem i ägarkontot', function () {
    withoutVite();

    [, $anvandare, $container] = itemlistaKontext();

    foreach (['Storseglet', 'Motorn', 'Impellern'] as $namn) {
        itemlistaItem($container, $namn, $anvandare);
    }

    actingAs($anvandare)->get("/containers/{$container->ulid}/items")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Index')
            ->where('container.ulid', $container->ulid)
            ->has('items', 3)
            ->where('items.0.name', 'Impellern')
            ->where('items.1.name', 'Motorn')
            ->where('items.2.name', 'Storseglet')
            // `can.create` är presentationsflaggan för 57b:s skapayta. Ägar-
            // kontots medlem får den; ytan ritas inte av den här issuen.
            ->where('can.create', true)
    );
});

/*
 * Klart när: `/containers/create` når fortfarande formuläret — ingen av
 * containerrutterna skuggar den.
 *
 * Det är hela skälet att containerns egen rutt har en plats i filen och inte
 * bara en rutt (Beslut 1), och skälet växer med antalet rutter under
 * `{container}`: `create` är ett giltigt värde för en ruttparameter.
 */
it('når fortfarande skapaformuläret på /containers/create', function () {
    withoutVite();

    [, $anvandare] = itemlistaKontext();

    actingAs($anvandare)->get('/containers/create')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->component('Containers/Create')
    );
});

/*
 * Klart när: containernamnet i `/containers` länkar till ITEMLISTAN.
 *
 * Länken prövas mot den href ruttnamnen faktiskt ger — en vy som länkar till
 * en påhittad adress hade annars sett rätt ut i en strukturell kontroll. Det
 * är den kontrollen som fångar att länken hamnade på containerns översikt i
 * stället: `containers.show` pekar fortfarande på `/containers/{ulid}`, och
 * `containers.items.index` är det nya målet (issue 89 · [[ADR-0039
 * Containerns översikt]] § Konsekvenser).
 */
it('länkar containernamnet i containerlistan till itemlistan', function () {
    [, , $container] = itemlistaKontext();

    expect(route('containers.show', $container, false))->toBe("/containers/{$container->ulid}")
        ->and(route('containers.items.index', $container, false))->toBe("/containers/{$container->ulid}/items");

    $sida = File::get(resource_path('js/pages/Containers/Index.vue'));

    expect($sida)->toContain(':href="`/containers/${container.ulid}/items`"')
        ->toContain('{{ container.name }}');
});

/*
 * Klart när: `items` är den första raden i containerns undernavigering, och
 * listan har fortfarande TRETTON rader.
 *
 * Navigationen renderas ur containerSections, så raden är beviset — och
 * ordningen ligger i listan, inte i layouten. Raden pekar på itemlistan sedan
 * issue 89, och översikten fick ingen egen rad där: flikraden och
 * omfördelningen av sektionerna var designarbete, och den kom med issue 101.
 *
 * Antalet rader räknas och inte bara nämns: en rad fler vore en ny sida ingen
 * issue bad om, och en rad färre en yta ingen hittar.
 *
 * **Nio blev tio i issue 116.** Historikfliken är en ny SIDA och därmed en ny
 * rad i listan — containerns fjärde flik, på samma villkor som de andra.
 * Provet fångade den med flit: raden är tillagd av en issue som bad om den,
 * och räkningen är vad som skiljer det från en rad någon lagt till i smyg.
 *
 * **Tio blev elva i issue 174.** Uppgiftsfliken är samma sorts rad: en ny
 * sida, en ny rutt och en ny kontrollermetod, bokförd i
 * resources/js/layouts/containerSections.js på den plats ADR-0050 § 4 anger.
 *
 * **Elva blev tolv i issue 175.** Kostnadsfliken är samma sorts rad igen, och
 * av samma skäl: en ny sida, en ny rutt och en ny kontrollermetod.
 *
 * **Tolv blev tretton i issue 178.** Dokumentfliken är samma sorts rad en
 * fjärde gång — en ny sida, en ny rutt och en ny kontrollermetod — och den
 * sista av de fyra som M24 lade till i containerns flikrad.
 *
 * **Räkningen gäller `containerSections` och ingenting annat.** Sedan issue 101
 * bär filen också `containerTabs`, och översiktsraden där är en adress utan
 * sektion — den hör till flikraden och räknas inte hit. En räkning över hela
 * filen hade räknat den som en sektion.
 */
it('lägger itemlistan först i containerns navigation och behåller tretton rader', function () {
    $sektioner = File::get(resource_path('js/layouts/containerSections.js'));

    expect($sektioner)->toContain("key: 'items'")
        ->toContain('/containers/${ulid}/items');

    expect(strpos($sektioner, "key: 'items'"))
        ->toBeLessThan(strpos($sektioner, "key: 'categories'"));

    preg_match('#export const containerSections = \[(.*?)\n\];#s', $sektioner, $träff);

    expect($träff[1] ?? '')->not->toBe('', 'containerSections finns inte i filen');
    expect(substr_count($träff[1], 'href: (ulid) =>'))->toBe(13);
});

/*
 * Klart när: en användare utan åtkomst till containern får 403 på listan.
 */
it('nekar en främling listan med 403', function () {
    withoutVite();

    [, , $container] = itemlistaKontext();
    itemlistaItem($container, 'Motorn');

    actingAs(User::factory()->create())->get("/containers/{$container->ulid}/items")->assertForbidden();
});

/*
 * Klart när: en omfångsbegränsad mottagare ser bara de items hon når, och
 * listan bär inget tal om hur många som filtrerats bort.
 *
 * Sidan får visa antalet rader den RITAR — det är längden på en lista
 * användaren ser. Ingen totalsumma, ingen "av N", ingen rad om att något
 * dolts: ett sådant tal är precis vad issue 73 § Beslut 6 förbjuder.
 */
it('visar bara items inom omfånget och bär inget tal om de dolda', function () {
    withoutVite();

    [, , $container] = itemlistaKontext();

    $motorn = itemlistaItem($container, 'Motorn');

    // Tio dolda items med ett eget ord i namnet — namnet i sig är avslöjandet.
    foreach (range(1, 10) as $i) {
        itemlistaItem($container, "Hemlig $i");
    }

    $mottagare = itemlistaMottagare($container, $motorn);

    $svar = actingAs($mottagare)->get("/containers/{$container->ulid}/items");

    $svar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Containers/Items/Index')
        ->has('items', 1)
        ->where('items.0.name', 'Motorn')
        ->missing('total')
        ->missing('meta')
    );

    // Varken namnet, ULID:n eller ett räknande tal någonstans i svaret.
    expect($svar->getContent())->not->toContain('Hemlig');

    foreach (Item::query()->where('name', 'like', 'Hemlig%')->get() as $dold) {
        expect($svar->getContent())->not->toContain($dold->ulid);
    }
});

/*
 * Klart när: listan kostar ett konstant antal frågor oavsett antal items, mätt
 * med DB::listen.
 *
 * N+1-skyddet är `with(['category', 'createdByAccount', 'tags'])` i
 * App\Actions\Item\ListItems, som bars vidare oförändrat ur API:ets index()
 * (issue 13b § Beslut 10).
 */
it('kostar ett konstant antal frågor oavsett antal items', function () {
    withoutVite();

    [, $anvandare, $container] = itemlistaKontext();

    foreach (range(1, 10) as $i) {
        itemlistaItem($container, "Item $i", $anvandare);
    }

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80, 477).
    Carbon::setTestNow(now());

    actingAs($anvandare);

    $url = "/containers/{$container->ulid}/items";

    $värm = fn () => get($url)->assertOk();

    $tioItems = itemlistaFrågor($värm, function () use ($url) {
        get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('items', 10));
    });

    // Tio nya items — omfånget växer med items, inte med frågor.
    foreach (range(11, 20) as $i) {
        itemlistaItem($container, "Item $i", $anvandare);
    }

    $tjugoItems = itemlistaFrågor($värm, function () use ($url) {
        get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('items', 20));
    });

    expect($tjugoItems)->toBe($tioItems);

    Carbon::setTestNow();
});

/*
 * Klart när: ett `read_only`-ägarkonto tillåter listning men ger `can.create`
 * falskt.
 *
 * Flaggan är presentation; grinden är `ContainerPolicy::createItem()`, som
 * nekar allt skrivande när ägarkontot är fryst (regel 4).
 */
it('låter ett fryst ägarkonto lista men ger can.create falskt', function () {
    withoutVite();

    [, $anvandare, $container] = itemlistaKontext(['status' => 'read_only']);
    itemlistaItem($container, 'Motorn', $anvandare);

    actingAs($anvandare)->get("/containers/{$container->ulid}/items")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('items', 1)
            ->where('can.create', false)
    );
});

/*
 * Klart när: en container-bred `create`-innehavare får `can.create` sant, och
 * en omfångsbegränsad mottagare falskt.
 *
 * En itemgrant ger aldrig en rot i containern — hon skapar barn-items under det
 * hon nått, och den ytan hör till detaljvyn (ContainerPolicy::createItem()).
 */
it('ger can.create efter container-bred create och aldrig efter en itemgrant', function () {
    withoutVite();

    [, , $container] = itemlistaKontext();
    $motorn = itemlistaItem($container, 'Motorn');

    $bred = itemlistaMottagare($container, null, 'create');
    $begränsad = itemlistaMottagare($container, $motorn, 'delete');

    actingAs($bred)->get("/containers/{$container->ulid}/items")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('can.create', true)
    );

    actingAs($begränsad)->get("/containers/{$container->ulid}/items")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('can.create', false)
    );
});

/*
 * Plusknappens mål på itemlistan, se issue 152 · [[ADR-0048 Mobilen och
 * plusknappen]] § 2. Målet är sidans och kommer ur
 * App\Support\Frontend\CreateTarget, med SAMMA grind som `can.create` ovan:
 * knappen i skalet och raden *Nytt item* i vyn kommer och går tillsammans, och
 * en mottagare som inte får skapa ett toppnivå-item får ingen knapp.
 * Menyn på ett item prövas i tests/Feature/Frontend/PlusknappTest.php.
 */
it('bär plusknappens mål efter samma grind som can.create', function () {
    withoutVite();

    [, , $container] = itemlistaKontext();
    $motorn = itemlistaItem($container, 'Motorn');

    $bred = itemlistaMottagare($container, null, 'create');
    $begränsad = itemlistaMottagare($container, $motorn, 'delete');

    actingAs($bred)->get("/containers/{$container->ulid}/items")->assertInertia(
        fn (AssertableInertia $page) => $page->where('create.kind', 'item')
            ->where('create.href', "/containers/{$container->ulid}/items/create"),
    );

    actingAs($begränsad)->get("/containers/{$container->ulid}/items")->assertInertia(
        fn (AssertableInertia $page) => $page->where('create', null),
    );
});

/*
 * Klart när: en tom container säger att den är tom — inte att den kanske är det.
 *
 * Meningen ligger i `lang/`, aldrig i vyn (Beslut 10), och den får inte
 * antyda att rader dolts: en omfångsbegränsad mottagare med noll items ser
 * samma mening, och "inga träffar bland N" hade avslöjat N.
 */
it('säger att containern är tom och aldrig att den kanske är det', function () {
    withoutVite();

    [, $anvandare, $container] = itemlistaKontext();

    $svar = actingAs($anvandare)->get("/containers/{$container->ulid}/items");

    $svar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('items', 0)
        ->where('can.create', true)
    );

    $sv = require lang_path('en/ui.php');
    $en = require lang_path('en/ui.php');

    expect($en['item']['index']['empty'])->toBe('The container is empty.');
    expect($en['item']['index']['empty'])->not->toBe('');

    // Ingen totalsumma i vyn — raden är hela sidans svar på en tom container.
    $vy = File::get(resource_path('js/pages/Containers/Items/Index.vue'));

    expect($vy)->toContain("t('item.index.empty')");
    expect($vy)->not->toContain('items.length}/');
});

/*
 * Klart när: ingen svensk sträng står kvar i en `.vue`-fil; varje ny nyckel
 * finns på `sv` och `en`.
 *
 * Den första halvan vaktas av SprakTest (som läser varje fil under
 * resources/js). Här prövas den andra: nyckelparen, nyckel för nyckel.
 */
it('har varje item-nyckel', function () {
    $sv = require lang_path('en/ui.php');
    $en = require lang_path('en/ui.php');

    foreach (['index', 'show'] as $grupp) {
        expect(array_keys($en['item'][$grupp]))->toBe(array_keys($sv['item'][$grupp]));

        foreach ($sv['item'][$grupp] as $nyckel => $varde) {
            expect(trim($varde))->not->toBe('', "item.{$grupp}.{$nyckel} är");
            expect(trim($en['item'][$grupp][$nyckel]))->not->toBe('', "item.{$grupp}.{$nyckel} är tom på en");
        }
    }

    // Navigationsraden läses som `container.nav.items` (samma `key` i
    // containerSections.js som i lang-filen).
    expect($sv['container']['nav']['items'])->not->toBe('');
    expect($en['container']['nav']['items'])->not->toBe('');

    // De nya vyerna har inga svenska strängar kvar — samma regel som
    // SprakTest prövar för hela resources/js, riktad mot den här issuen.
    foreach ([
        'pages/Containers/Items/Index.vue',
        'pages/Containers/Items/Show.vue',
        'components/ItemTagList.vue',
    ] as $fil) {
        $kod = File::get(resource_path("js/{$fil}"));
        $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
        $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

        expect($kod)->not->toMatch('/[åäöÅÄÖ]/u', "svensk text utanför kommentar i {$fil}");
    }
});

/*
 * Klart när: varje rad i itemlistan bär en härledd status.
 *
 * Statusen ligger BREDVID resursen (issue 92), samma linje som kategorinamnet:
 * `statuses` är itemets ULID → status, och `ItemResource` — som delas med
 * `/api` — bär inget `status`-fält. Förekomsten på masten är framtida och
 * räknas inte; den på impellern har förfallit och färgar både impellern och
 * motorn ovanför.
 */
it('ger varje rad i itemlistan en härledd status bredvid resursen', function () {
    withoutVite();

    [, $anvandare, $container] = itemlistaKontext();

    $motorn = itemlistaItem($container, 'Motorn', $anvandare);
    $impellern = itemlistaItem($container, 'Impellern', $anvandare);
    $masten = itemlistaItem($container, 'Masten', $anvandare);

    itemlistaKant($motorn, $impellern);
    itemlistaFörekomst($impellern, -1);
    itemlistaFörekomst($masten, 30);

    actingAs($anvandare)->get("/containers/{$container->ulid}/items")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('items', 3)
            ->where("statuses.{$motorn->ulid}", ItemStatus::OVERDUE)
            ->where("statuses.{$impellern->ulid}", ItemStatus::OVERDUE)
            ->where("statuses.{$masten->ulid}", ItemStatus::OK)
            ->missing('items.0.status')
    );
});

/*
 * Klart när: en förekomst på ett item användaren inte når påverkar inte den
 * status hon ser.
 *
 * Mottagaren har en grant på motorn och ser därför motorn och dess ättlingar
 * och ingenting annat ([[ADR-0028 Åtkomst på itemnivå]] regel 3). Det hemliga
 * syskonet bär en förfallen förekomst, och den får inte färga motorn: raden
 * hon ser svarar på vad som hänger under DEN.
 */
it('låter en förekomst utanför omfånget stå utan verkan på statusen', function () {
    withoutVite();

    [, , $container] = itemlistaKontext();

    $motorn = itemlistaItem($container, 'Motorn');
    $impellern = itemlistaItem($container, 'Impellern');
    $hemlig = itemlistaItem($container, 'Hemlig impeller');

    itemlistaKant($motorn, $impellern);
    itemlistaFörekomst($hemlig, -1);

    $mottagare = itemlistaMottagare($container, $motorn);

    $svar = actingAs($mottagare)->get("/containers/{$container->ulid}/items");

    $svar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('items', 2)
        ->where("statuses.{$motorn->ulid}", ItemStatus::OK)
        ->where("statuses.{$impellern->ulid}", ItemStatus::OK)
    );

    // Det dolda itemet nämns inte någonstans i svaret — varken dess ULID eller
    // ett tal som antyder att det finns.
    expect($svar->getContent())->not->toContain($hemlig->ulid);
});

/*
 * Klart när (issue 183): varje rad bär sitt EGET omslag bredvid resursen.
 *
 * `covers` är itemets ULID → `{ulid, hasThumb}` eller null, och ligger BREDVID
 * `ItemResource` — `/api` har inte bett om fältet och resursen rörs inte
 * (`missing('items.0.cover')`). Tre rader täcker de tre svaren: en med en vald
 * bild som har ett `thumb`-derivat, en med en bild UTAN derivat, och en med
 * bara ett dokument.
 *
 * Den valda bilden är den NYASTE av två — annars vore skillnaden mellan "vald"
 * och "äldst" osynlig i listvägen, precis som i enhetsprovet.
 */
it('ger varje rad sitt eget omslag bredvid resursen', function () {
    withoutVite();

    [, $anvandare, $container] = itemlistaKontext();

    $medThumb = itemlistaItem($container, 'Med thumb', $anvandare);
    itemlistaBild($medThumb, 'gammal.jpg', false);
    $vald = itemlistaBild($medThumb, 'ny.jpg', true);
    $medThumb->cover_attachment_id = $vald->id;
    $medThumb->save();

    $utanDerivat = itemlistaItem($container, 'Utan derivat', $anvandare);
    $utanThumb = itemlistaBild($utanDerivat, 'foto.jpg', false);

    $baraDokument = itemlistaItem($container, 'Bara dokument', $anvandare);
    itemlistaBild($baraDokument, 'manual.pdf', false, 'document');

    actingAs($anvandare)->get("/containers/{$container->ulid}/items")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('items', 3)
            ->where("covers.{$medThumb->ulid}.ulid", $vald->ulid)
            ->where("covers.{$medThumb->ulid}.hasThumb", true)
            ->where("covers.{$utanDerivat->ulid}.ulid", $utanThumb->ulid)
            ->where("covers.{$utanDerivat->ulid}.hasThumb", false)
            ->where("covers.{$baraDokument->ulid}", null)
            ->missing('items.0.cover')
    );
});

/*
 * Klart när (issue 183): containerns omslag används ALDRIG för en rad.
 *
 * Containern har en egen bild satt genom samma action som rutten använder,
 * men itemet har inga bilder — och då är radens svar null, inte containerns
 * foto. Utan `expect`-raden nedan hade provet varit grönt även om bilden
 * aldrig satts, och bevisat noll.
 */
it('tar aldrig containerns omslag till en rad', function () {
    withoutVite();

    [, $anvandare, $container] = itemlistaKontext();

    $containerbild = itemlistaContainerbild($anvandare, $container);

    expect($container->refresh()->cover_attachment_id)->toBe($containerbild->id);

    $item = itemlistaItem($container, 'Motorn', $anvandare);

    actingAs($anvandare)->get("/containers/{$container->ulid}/items")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('items', 1)
            ->where("covers.{$item->ulid}", null)
    );
});

/*
 * Klart när (issue 183): träd- och kartläget betalar ingenting.
 *
 * `covers` är `[]` där, samma grind som `categories` och `statuses`: en yta
 * ingen ser ska inte kosta en fråga. Itemet har en bild — hade grinden saknats
 * hade fältet burit den.
 */
it('skickar inga omslag i träd- och kartläget', function () {
    withoutVite();

    [, $anvandare, $container] = itemlistaKontext();

    $item = itemlistaItem($container, 'Motorn', $anvandare);
    itemlistaBild($item, 'foto.jpg');

    actingAs($anvandare)->get("/containers/{$container->ulid}/items?view=tree")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('covers', [])
    );

    actingAs($anvandare)->get("/containers/{$container->ulid}/items?view=map")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('covers', [])
    );
});

/*
 * Klart när: antalet frågor är konstant oavsett antalet rader.
 *
 * Det är issuens svåraste del, och det är DÄRFÖR filen mäter om det: raderna
 * nedan har både barn och förekomster, så en lösning som vandrade per rad
 * hade vuxit med trädet och inte bara med listan. Kanterna och förekomsterna
 * hämtas en gång per lista och slutningen sker i minnet.
 */
it('kostar ett konstant antal frågor även när raderna har barn och förekomster', function () {
    withoutVite();

    [, $anvandare, $container] = itemlistaKontext();

    $båten = itemlistaItem($container, 'Båten', $anvandare);
    $motorn = itemlistaItem($container, 'Motorn', $anvandare);
    $masten = itemlistaItem($container, 'Masten', $anvandare);
    $impellern = itemlistaItem($container, 'Impellern', $anvandare);

    itemlistaKant($båten, $motorn);
    itemlistaKant($båten, $masten);
    itemlistaKant($motorn, $impellern);
    itemlistaFörekomst($impellern, -1);

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80, 477).
    Carbon::setTestNow(now());

    actingAs($anvandare);

    $url = "/containers/{$container->ulid}/items";

    $värm = fn () => get($url)->assertOk();

    $fyraRader = itemlistaFrågor($värm, function () use ($url) {
        get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('items', 4));
    });

    // Trettio rotitems till, vardera med ett barn som bär en förfallen
    // förekomst: sextiofyra rader och sextiofyra underträd, samma frågor.
    foreach (range(1, 30) as $i) {
        $rot = itemlistaItem($container, "Rot $i", $anvandare);
        $barn = itemlistaItem($container, "Barn $i", $anvandare);

        itemlistaKant($rot, $barn);
        itemlistaFörekomst($barn, -1);
    }

    $sextiofyraRader = itemlistaFrågor($värm, function () use ($url) {
        get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('items', 64));
    });

    expect($sextiofyraRader)->toBe($fyraRader);

    Carbon::setTestNow();
});

/*
 * Klart när (issue 183): omslaget kostar ett konstant antal frågor oavsett
 * antalet rader.
 *
 * Samma mätning som provet ovan, men varje rad bär två bilder och ett derivat:
 * en lösning som vandrade per rad — en fråga per item, eller ett derivat
 * uppslaget i taget — hade vuxit med listan. Bilderna hämtas en gång och valet
 * sker i minnet (App\Actions\Item\ResolveItemCover::forItems()).
 */
it('kostar ett konstant antal frågor även när raderna har bilder', function () {
    withoutVite();

    [, $anvandare, $container] = itemlistaKontext();

    $fyra = [
        itemlistaItem($container, 'Båten', $anvandare),
        itemlistaItem($container, 'Motorn', $anvandare),
        itemlistaItem($container, 'Masten', $anvandare),
        itemlistaItem($container, 'Impellern', $anvandare),
    ];

    foreach ($fyra as $item) {
        itemlistaBild($item, 'ett.jpg', false);
        itemlistaBild($item, 'tva.jpg', true);
    }

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80, 477).
    Carbon::setTestNow(now());

    actingAs($anvandare);

    $url = "/containers/{$container->ulid}/items";

    $värm = fn () => get($url)->assertOk();

    $fyraRader = itemlistaFrågor($värm, function () use ($url) {
        get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('items', 4));
    });

    // Sextio rader till, vardera med två bilder och ett derivat: sextiofyra
    // rader, samma frågor.
    foreach (range(1, 60) as $i) {
        $item = itemlistaItem($container, "Rad $i", $anvandare);
        itemlistaBild($item, 'ett.jpg', false);
        itemlistaBild($item, 'tva.jpg', true);
    }

    $sextiofyraRader = itemlistaFrågor($värm, function () use ($url) {
        get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('items', 64));
    });

    expect($sextiofyraRader)->toBe($fyraRader);

    Carbon::setTestNow();
});

/*
 * Klart när: statusens text ligger i `lang/` och inte i en `.vue`-fil.
 *
 * Ordet är mockupens, och uppslaget ligger i `item.index.status_*` — en text i
 * en komponent blir aldrig engelsk (issue 52 § Beslut 4, [[ADR-0021
 * Frontendteknik]]).
 *
 * **Uppslaget läses ur kartan sedan issue 212** · [[ADR-0050
 * Desktopdesignen]]: listans rad ritar ingen status längre, så raden som bar
 * `t(\`item.index.status_${statuses[item.ulid]}\`)` finns inte kvar. Samma
 * nyckel, samma ord — nu per nod i `ContainerMapNode.vue`
 * (`t(\`item.index.status_${status}\`)`). Att orden inte hamnat i listans vy
 * prövas fortfarande mot `Index.vue`: ett `OK` där vore en text utanför
 * `lang/`, oavsett vilken rad som ritar den.
 */
it('har statusens text i lang och inte i vyn', function () {
    $en = require lang_path('en/ui.php');

    expect($en['item']['index']['status_ok'])->toBe('OK');
    expect($en['item']['index']['status_overdue'])->not->toBe('');

    $noden = File::get(resource_path('js/components/ContainerMapNode.vue'));

    expect($noden)->toContain('t(`item.index.status_${status}`)');

    $vy = File::get(resource_path('js/pages/Containers/Items/Index.vue'));

    expect($vy)->not->toContain('>OK<');
    expect($vy)->not->toContain("'OK'");
});

/*
 * Klart när (issue 212): raden ritar itemets EGET omslag som miniatyr, och en
 * ikon när omslaget saknas eller inte har något `thumb`-derivat.
 *
 * Omslaget kommer ur `covers` (App\Actions\Item\ResolveItemCover), och
 * `hasThumb` är serverns svar på om `?variant=thumb` svarar — utan derivat är
 * en `<img>` mot varianten en trasig bild, och då ritas lådikonen. Provet
 * läser KÄLLAN och inte det renderade svaret: det är samma sorts kontroll som
 * `har statusens text i lang och inte i vyn`, och den fångar att containerns
 * eget omslag aldrig lånas in i raden.
 */
it('ritar itemets eget omslag som miniatyr och annars en ikon', function () {
    $vy = File::get(resource_path('js/pages/Containers/Items/Index.vue'));

    expect($vy)->toContain('covers: { type: Object, required: true }');
    expect($vy)->toContain('covers[item.ulid]?.hasThumb');
    expect($vy)->toContain('?variant=thumb');

    // Lådikonen på reservytan: sista ledet i den inline-ritade ikonen.
    expect($vy)->toContain('d="M12 22V12"');

    // Containerns omslag läses aldrig i raden — ett item utan bild ärver inte
    // containerns foto.
    expect($vy)->not->toContain('container.cover');
});

/*
 * Klart när (issue 212): raden bär namnet, beskrivningen och en pil — och
 * ingenting av det mockupen inte längre ritar.
 *
 * Kontrollerna mot det borttagna är lika viktiga som de mot det nya: en rad
 * som behöll `item.manufacturer` eller `statuses[item.ulid]` hade sett rätt ut
 * vid en blick och ändå burit en yta issuen tog bort.
 */
it('visar namn, beskrivning och pil på raden', function () {
    $raden = itemlistaListblock();

    expect($raden)->toContain('item.name');
    expect($raden)->toContain('item.description');
    expect($raden)->toContain('d="m9 6 6 6-6 6"');

    expect($raden)->not->toContain('item.manufacturer');
    expect($raden)->not->toContain('item.model');
    expect($raden)->not->toContain('categories[item.category]');
    expect($raden)->not->toContain('statuses[item.ulid]');
    expect($raden)->not->toContain('<ItemTagList');
});

/*
 * Klart när (issue 212): HELA raden är länken till itemet.
 *
 * Exakt en `<Link` i blocket: en andra hade delat raden i två mål, och en rad
 * som bara länkade namnet är den form issuen tar bort. Träffytan är
 * `min-h-11` — 44 px (issue 68a § Beslut 3) — och adressen är itemets egen.
 */
it('gör hela raden till en länk till itemet', function () {
    $raden = itemlistaListblock();

    expect(substr_count($raden, '<Link'))->toBe(1);
    expect($raden)->toContain(':href="`/containers/${container.ulid}/items/${item.ulid}`"');
    expect($raden)->toContain('min-h-11');
});
