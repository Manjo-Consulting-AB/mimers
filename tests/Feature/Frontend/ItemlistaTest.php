<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * Issue 57a · Pärmens itemlista — pärmens förstasida. Se
 * App\Http\Controllers\ItemController::index(),
 * App\Actions\Item\ListItems och
 * resources/js/pages/Containers/Items/Index.vue.
 *
 * Den viktigaste gränsen i filen är OMFÅNGET (Beslut 4, issue 73 § Beslut 2
 * och 6): en omfångsbegränsad mottagare ser bara de items hon når, och svaret
 * får aldrig bära ett tal om hur många rader som filtrerats bort. Sidan visar
 * antalet rader den ritar och ingenting mer.
 *
 * Den andra är RUTTORDNINGEN (Beslut 1): `GET /containers/{container}` måste
 * registreras efter `GET /containers/create`, annars matchar `{container}`
 * strängen `create` och formuläret blir en 404.
 *
 * Att `/api/containers/{container}/items` svarar exakt som förut prövas av
 * tests/Feature/Item/** och tests/Feature/Omfang/ListningsfilterTest.php, som
 * är gröna utan en enda ändrad förväntan efter utbrytningen i Beslut 3. En ny
 * formulering av samma sak här hade bevisat noll.
 *
 * Hjälparna har prefixet `itemlista` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem i angiven roll, och en pärm ägd av kontot.
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
 * Ett item i pärmen. `created_by_*` sätts sammanhängande — fabrikens egna
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

/*
 * Beslut 1: båda rutterna ligger bakom `auth`. En utloggad besökare skickas
 * till inloggningen och når aldrig en kontrollermetod.
 */
it('skickar en utloggad besökare till inloggningen från listan och detaljvyn', function () {
    withoutVite();

    [, , $container] = itemlistaKontext();
    $item = itemlistaItem($container, 'Motorn');

    get("/containers/{$container->ulid}")->assertRedirect('/login');
    get("/containers/{$container->ulid}/items/{$item->ulid}")->assertRedirect('/login');
});

/*
 * Klart när: `/containers/{container}` renderar itemlistan för en medlem i
 * ägarkontot, sorterad på namn.
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

    actingAs($anvandare)->get("/containers/{$container->ulid}")->assertOk()->assertInertia(
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
 * Klart när: `/containers/create` når fortfarande formuläret — rutten för
 * pärmens förstasida skuggar den inte.
 *
 * Det är hela skälet att `GET /containers/{container}` har en plats i filen
 * och inte bara en rutt (Beslut 1).
 */
it('når fortfarande skapaformuläret på /containers/create', function () {
    withoutVite();

    [, $anvandare] = itemlistaKontext();

    actingAs($anvandare)->get('/containers/create')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->component('Containers/Create')
    );
});

/*
 * Klart när: pärmnamnet i `/containers` länkar till pärmens förstasida.
 *
 * Länken prövas mot den href ruttnamnet faktiskt ger — en vy som länkar till
 * en påhittad adress hade annars sett rätt ut i en strukturell kontroll.
 */
it('länkar pärmnamnet i pärmlistan till pärmens förstasida', function () {
    [, , $container] = itemlistaKontext();

    expect(route('containers.show', $container, false))->toBe("/containers/{$container->ulid}");

    $sida = File::get(resource_path('js/pages/Containers/Index.vue'));

    expect($sida)->toContain(':href="`/containers/${container.ulid}`"')
        ->toContain('{{ container.name }}');
});

/*
 * Klart när: `items` är den första raden i pärmens undernavigering.
 *
 * Navigationen renderas ur containerSections, så raden är beviset — och
 * ordningen ligger i listan, inte i layouten.
 */
it('lägger itemlistan först i pärmens navigation', function () {
    $sektioner = File::get(resource_path('js/layouts/containerSections.js'));

    expect($sektioner)->toContain("key: 'items'")
        ->toContain('/containers/${ulid}');

    expect(strpos($sektioner, "key: 'items'"))
        ->toBeLessThan(strpos($sektioner, "key: 'categories'"));
});

/*
 * Klart när: en användare utan åtkomst till pärmen får 403 på listan.
 */
it('nekar en främling listan med 403', function () {
    withoutVite();

    [, , $container] = itemlistaKontext();
    itemlistaItem($container, 'Motorn');

    actingAs(User::factory()->create())->get("/containers/{$container->ulid}")->assertForbidden();
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

    $svar = actingAs($mottagare)->get("/containers/{$container->ulid}");

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

    actingAs($anvandare);

    $url = "/containers/{$container->ulid}";

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

    actingAs($anvandare)->get("/containers/{$container->ulid}")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('items', 1)
            ->where('can.create', false)
    );
});

/*
 * Klart när: en container-bred `create`-innehavare får `can.create` sant, och
 * en omfångsbegränsad mottagare falskt.
 *
 * En itemgrant ger aldrig en rot i pärmen — hon skapar barn-items under det
 * hon nått, och den ytan hör till detaljvyn (ContainerPolicy::createItem()).
 */
it('ger can.create efter container-bred create och aldrig efter en itemgrant', function () {
    withoutVite();

    [, , $container] = itemlistaKontext();
    $motorn = itemlistaItem($container, 'Motorn');

    $bred = itemlistaMottagare($container, null, 'create');
    $begränsad = itemlistaMottagare($container, $motorn, 'delete');

    actingAs($bred)->get("/containers/{$container->ulid}")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('can.create', true)
    );

    actingAs($begränsad)->get("/containers/{$container->ulid}")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('can.create', false)
    );
});

/*
 * Klart när: en tom pärm säger att den är tom — inte att den kanske är det.
 *
 * Meningen ligger i `lang/`, aldrig i vyn (Beslut 10), och den får inte
 * antyda att rader dolts: en omfångsbegränsad mottagare med noll items ser
 * samma mening, och "inga träffar bland N" hade avslöjat N.
 */
it('säger att pärmen är tom och aldrig att den kanske är det', function () {
    withoutVite();

    [, $anvandare, $container] = itemlistaKontext();

    $svar = actingAs($anvandare)->get("/containers/{$container->ulid}");

    $svar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('items', 0)
        ->where('can.create', true)
    );

    $sv = require lang_path('sv/ui.php');
    $en = require lang_path('en/ui.php');

    expect($sv['item']['index']['empty'])->toBe('Containern är tom.');
    expect($en['item']['index']['empty'])->not->toBe('');

    // Ingen totalsumma i vyn — raden är hela sidans svar på en tom pärm.
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
it('har varje item-nyckel på båda språken', function () {
    $sv = require lang_path('sv/ui.php');
    $en = require lang_path('en/ui.php');

    foreach (['index', 'show'] as $grupp) {
        expect(array_keys($en['item'][$grupp]))->toBe(array_keys($sv['item'][$grupp]));

        foreach ($sv['item'][$grupp] as $nyckel => $varde) {
            expect(trim($varde))->not->toBe('', "item.{$grupp}.{$nyckel} är tom på sv");
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
