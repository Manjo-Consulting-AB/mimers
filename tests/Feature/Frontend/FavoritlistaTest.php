<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Favorite;
use App\Models\Item;
use App\Models\User;
use App\Support\Access\AccessLevel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * Issue 106 · Favoritlistan i skalet. Se App\Actions\Item\ListFavorites,
 * App\Http\Middleware\HandleInertiaRequests::favorites(),
 * resources/js/layouts/AppLayout.vue och [[M17 Designsystemet]] § 106.
 *
 * Varje "Klart när"-punkt i issuen motsvaras av ett namngivet test här.
 *
 * **Listan är ett omfångsprov, inte ett vyprov.** Favoriterna är användarens
 * egna rader — hon har satt dem själv och de är per definition items hon en
 * gång nådde — så det som kan gå fel är inte att främmande rader smyger in.
 * Det som kan gå fel är att en rad blir KVAR när åtkomsten dras in, och att
 * svaret samtidigt berättar hur många som föll bort. Båda är fel av samma
 * slag som issue 73 § Beslut 6 handlar om: svaret ska vara de rader man når
 * och ingenting mer.
 *
 * **Frågekostnaden prövas med mönstret *värm, nollställ, mät*** från
 * tests/Feature/Frontend/SokvyTest.php. `ResolveItemScope` är `scoped` och
 * memoiserar per request i drift, men i testsviten överlever memon mellan
 * HTTP-anropen — utan `app()->forgetScopedInstances()` mäter man ett uppvärmt
 * anrop och provet bevisar ingenting.
 *
 * Proven kör webbens sidor, inte `/api`: sektionen är en yta i skalet och
 * `/api` får ingen ändpunkt för den (issue 105 § Beslut om markeringen).
 * Fixturen är FavoritTest:s (tests/Feature/Item/FavoritTest.php): ett
 * ägarkonto med en container, och en mottagare utanför kontot med en grant på
 * ett enskilt item.
 *
 * Hjälparna har prefixet `favoritlista` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ägarkontot, dess medlem och containern, i ordningen [$konto, $ägare, $container].
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function favoritlistaKontext(): array
{
    $konto = Account::factory()->create();
    $ägare = User::factory()->create();
    $konto->users()->attach($ägare, ['role' => 'owner']);

    return [$konto, $ägare, Container::factory()->for($konto, 'account')->create()];
}

/**
 * Ett item i containern. `created_by_*` sätts sammanhängande — fabrikens egna
 * default-skapare hade annars blivit två ovidkommande rader per item.
 */
function favoritlistaItem(Container $container, string $namn, ?User $skapare = null): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => ($skapare ?? User::factory()->create())->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * Itemets detaljvy — adressen `back()` landar på.
 */
function favoritlistaUrl(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}";
}

/**
 * Sätter markeringen genom rutten, som stjärnan gör — inte genom att skriva
 * raden förbi kontrollern. Ett prov som seedade `favorite` för hand hade
 * kunnat bevisa listan mot ett tillstånd stjärnan aldrig kan producera.
 *
 * `forgetScopedInstances()` FÖRE anropet: stjärnan prövar `ItemPolicy::view()`,
 * och i drift löses omfånget på nytt i varje request. I testsviten överlever
 * memon mellan anropen, så en grant som skapats efter förra markeringen syns
 * inte förrän den glöms — och provet hade fallit på 403 utan att något var
 * fel i koden.
 */
function favoritlistaMarkera(User $anvandare, Container $container, Item $item): void
{
    app()->forgetScopedInstances();

    $url = favoritlistaUrl($container, $item);

    actingAs($anvandare)->from($url)->post("{$url}/favorite")->assertRedirect($url);
}

/**
 * En mottagare UTANFÖR ägarkontot med `read` på ett enda item — samma form
 * som `favoritMottagare()` i tests/Feature/Item/FavoritTest.php.
 *
 * Returnerar BÅDE användaren och raden: åtkomsten ska kunna dras in igen, och
 * det är `ContainerAccess::revoked_at` som drar in den.
 *
 * @return array{0: User, 1: ContainerAccess}
 */
function favoritlistaItemgrant(Container $container, Item $item, ?User $mottagare = null): array
{
    $mottagare ??= User::factory()->create();

    $access = ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => AccessLevel::READ,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return [$mottagare, $access];
}

/**
 * Favoritlistan ur svaret, som namn i serverns ordning.
 *
 * @return list<string>
 */
function favoritlistaNamn(TestResponse $svar): array
{
    /** @var array{favorites: list<array{name: string}>} $proppar */
    $proppar = $svar->inertiaProps();

    return array_map(fn (array $rad): string => $rad['name'], $proppar['favorites']);
}

/**
 * Antalet frågor $anrop ställer, mätt efter ett omätt anrop som värmer
 * guarderna, kontocachen och omfånget — samma mönster som
 * `sokvyFrågor()` i tests/Feature/Frontend/SokvyTest.php.
 */
function favoritlistaFrågor(Closure $värm, Closure $anrop): int
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
 * Klart när: sidopanelen visar användarens favoriter i namnordning.
 *
 * Itemena skapas i en ordning som inte är bokstavsordning, och markeringarna
 * sätts i ännu en annan: en lista som råkade behålla skapelse- eller
 * markringsordningen faller. Ett item utan markering finns inte med, och
 * adressen pekar på itemets detaljvy — raden är en genväg och inte en etikett.
 */
it('sidopanelen visar användarens favoriter i namnordning', function () {
    withoutVite();

    [, $ägare, $container] = favoritlistaKontext();

    $masten = favoritlistaItem($container, 'Masten', $ägare);
    $ankaret = favoritlistaItem($container, 'Ankaret', $ägare);
    $motorn = favoritlistaItem($container, 'Motorn', $ägare);
    favoritlistaItem($container, 'Seglet', $ägare);

    foreach ([$masten, $ankaret, $motorn] as $item) {
        favoritlistaMarkera($ägare, $container, $item);
    }

    $svar = actingAs($ägare)->get('/dashboard')->assertOk();

    expect(favoritlistaNamn($svar))->toBe(['Ankaret', 'Masten', 'Motorn']);

    $svar->assertInertia(fn (AssertableInertia $page) => $page
        ->has('favorites', 3)
        ->where('favorites.0.url', favoritlistaUrl($container, $ankaret))
        ->where('favorites.1.url', favoritlistaUrl($container, $masten))
        ->where('favorites.2.url', favoritlistaUrl($container, $motorn))
    );

    // Raden bär namn och adress och ingenting mer: ingen ULID, ingen
    // container, ingen tidsstämpel. Formen är skalets och inte itemets.
    expect(array_keys($svar->inertiaProps()['favorites'][0]))->toBe(['name', 'url']);
});

/*
 * Klart när: ett item utanför omfånget finns inte i listan — åtkomsten dras
 * in och raden försvinner.
 *
 * Mottagaren når två item genom var sin grant och märker båda medan hon når
 * dem. Sedan dras den ena granten in, och raden ska bort. Det är hela
 * skillnaden mellan en genväg och en trasig länk: itemet finns kvar, hon når
 * det inte, och listan får varken visa det eller säga att det göms.
 *
 * `forgetScopedInstances()` FÖRE den sista läsningen är inte kosmetiskt:
 * omfånget memoiseras per `{user, container}` och memon överlever mellan
 * anropen i en testsvit. Utan nollställningen läses det gamla, vidare
 * omfånget och provet faller av fel skäl.
 */
it('ett item utanför omfånget finns inte i listan', function () {
    withoutVite();

    [, , $container] = favoritlistaKontext();

    $motorn = favoritlistaItem($container, 'Motorn');
    $masten = favoritlistaItem($container, 'Masten');

    [$mottagare, $motornsGrant] = favoritlistaItemgrant($container, $motorn);
    [, $mastensGrant] = favoritlistaItemgrant($container, $masten, $mottagare);

    favoritlistaMarkera($mottagare, $container, $motorn);
    favoritlistaMarkera($mottagare, $container, $masten);

    // Båda raderna syns medan hon når båda — utan den halvan hade det tomma
    // svaret nedan kunnat vara en trasig lista i stället för ett omfång.
    $före = actingAs($mottagare)->get('/dashboard')->assertOk();
    expect(favoritlistaNamn($före))->toBe(['Masten', 'Motorn']);

    $mastensGrant->revoked_at = now();
    $mastensGrant->save();

    // Motorns grant står kvar orörd, så skillnaden i svaret beror på den
    // indragna raden och ingenting annat.
    expect($motornsGrant->fresh()->revoked_at)->toBeNull();

    app()->forgetScopedInstances();

    $efter = actingAs($mottagare)->get('/dashboard')->assertOk();

    expect(favoritlistaNamn($efter))->toBe(['Motorn'])
        ->and($efter->getContent())->not->toContain($masten->ulid)
        ->and($efter->getContent())->not->toContain('Masten');

    // Markeringen ligger kvar i tabellen — det är omfånget som gömmer raden,
    // inte en städning. Dras åtkomsten tillbaka syns favoriten igen.
    expect($mottagare->favorites()->where('item_id', $masten->id)->exists())->toBeTrue();
});

/*
 * Klart när: ingenting avslöjar hur många som filtrerats bort.
 *
 * Två användare med EXAKT samma synliga favorit — samma item — där den ena
 * dessutom har fyra favoriter hon förlorat åtkomsten till. Svaren ska vara
 * ordagrant lika. Skillnaden mellan dem är hela läckan: ett tal, en gråad rad
 * eller en "och 4 till" hade berättat för den första att det finns items i
 * containern hon inte når, vilket är precis vad omfångsmodellen finns till
 * för att inte göra (issue 73 § Beslut 6).
 */
it('ingenting avslöjar hur många som filtrerats bort', function () {
    withoutVite();

    [, , $container] = favoritlistaKontext();

    $motorn = favoritlistaItem($container, 'Motorn');

    [$medDolda, $medDoldasGrant] = favoritlistaItemgrant($container, $motorn);
    [$utanDolda] = favoritlistaItemgrant($container, $motorn);

    favoritlistaMarkera($medDolda, $container, $motorn);
    favoritlistaMarkera($utanDolda, $container, $motorn);

    // Fyra item som bara den ena når, och som hon märker medan hon når dem.
    $dolda = [];

    foreach (['Dold ett', 'Dold två', 'Dold tre', 'Dold fyra'] as $namn) {
        $item = favoritlistaItem($container, $namn);

        [, $grant] = favoritlistaItemgrant($container, $item, $medDolda);
        $dolda[] = [$item, $grant];

        favoritlistaMarkera($medDolda, $container, $item);
    }

    foreach ($dolda as [, $grant]) {
        $grant->revoked_at = now();
        $grant->save();
    }

    app()->forgetScopedInstances();
    $medDoltSvar = actingAs($medDolda)->get('/dashboard')->assertOk();

    app()->forgetScopedInstances();
    $utanDoltSvar = actingAs($utanDolda)->get('/dashboard')->assertOk();

    // Ordagrant samma favoritlista: samma rad, samma form, samma längd.
    expect($medDoltSvar->inertiaProps()['favorites'])
        ->toBe($utanDoltSvar->inertiaProps()['favorites'])
        ->toHaveCount(1);

    // Och ingenting i svarskroppen röjer de fyra: varken namnet, ULID:n eller
    // ett ord om dolda rader.
    foreach ($dolda as [$item]) {
        expect($medDoltSvar->getContent())->not->toContain($item->name)
            ->and($medDoltSvar->getContent())->not->toContain($item->ulid);
    }

    // Markeringarna finns kvar — det är filtreringen som är tyst, inte datan
    // som städats bort. Dras åtkomsten tillbaka är de fem raderna där igen.
    expect($medDolda->favorites()->count())->toBe(5)
        ->and(Favorite::query()->count())->toBe(6);
});

/*
 * Klart när: en användare utan favoriter ser ingen sektion.
 *
 * Proppen är en tom lista, och skalet ritar sektionen på listans LÄNGD — inte
 * på att en användare finns, och inte med en egen tom-text. En rubrik över en
 * tom lista är en yta som lovar något den inte har; därför prövas både svaret
 * och villkoret i källan, eftersom Inertia renderar mallen i klienten och den
 * renderade sektionen aldrig når svarskroppen i en testsvit.
 */
it('en användare utan favoriter ser ingen sektion', function () {
    withoutVite();

    [, $ägare, $container] = favoritlistaKontext();

    $motorn = favoritlistaItem($container, 'Motorn', $ägare);
    favoritlistaItem($container, 'Masten', $ägare);

    $utan = actingAs($ägare)->get('/dashboard')->assertOk();

    expect($utan->inertiaProps()['favorites'])->toBe([]);

    // Även den vars ENDA favorit fallit ur omfånget får en tom lista. "Inga
    // favoriter" och "inga favoriter du når" är samma yta, och den ritas inte
    // — skillnaden mellan dem är precis vad en räknare hade avslöjat.
    [$mottagare, $grant] = favoritlistaItemgrant($container, $motorn);
    favoritlistaMarkera($mottagare, $container, $motorn);

    $grant->revoked_at = now();
    $grant->save();

    app()->forgetScopedInstances();

    expect(actingAs($mottagare)->get('/dashboard')->assertOk()->inertiaProps()['favorites'])->toBe([]);

    // Och den som HAR en favorit får sektionen — samma skal, samma sida.
    favoritlistaMarkera($ägare, $container, $motorn);

    expect(favoritlistaNamn(actingAs($ägare)->get('/dashboard')->assertOk()))->toBe(['Motorn']);

    $layout = File::get(resource_path('js/layouts/AppLayout.vue'));

    expect($layout)->toContain('v-if="favorites.length"')
        // Villkoret är listans längd — inte `user`, som redan är sant för en
        // inloggad utan favoriter.
        ->toContain('const favorites = computed(() => page.props.favorites ?? [])')
        ->toContain("t('nav.favorites')")
        ->toContain('<UiListRow');
});

/*
 * Klart när: antalet frågor är konstant oavsett antal favoriter, mätt efter
 * `forgetScopedInstances()`.
 *
 * Fem favoriter i samma container får inte kosta mer än en: omfånget löses
 * upp i ETT anrop över containern (issue 70 § Beslut 2), containern
 * eager-laddas i EN fråga och favoritraderna hämtas i EN. En fråga per rad är
 * den N+1 issue 9a § Att se upp med varnar för, och den hade gömt sig i den
 * här listan eftersom den är kort.
 */
it('antalet frågor är konstant oavsett antal favoriter', function () {
    withoutVite();

    [, $ägare, $container] = favoritlistaKontext();

    $första = favoritlistaItem($container, 'Favorit ett', $ägare);
    favoritlistaMarkera($ägare, $container, $första);

    actingAs($ägare);

    $värm = fn () => get('/dashboard')->assertOk();

    $medEn = favoritlistaFrågor($värm, function () {
        get('/dashboard')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->has('favorites', 1)
        );
    });

    foreach (['två', 'tre', 'fyra', 'fem'] as $nummer) {
        favoritlistaMarkera($ägare, $container, favoritlistaItem($container, "Favorit {$nummer}", $ägare));
    }

    $medFem = favoritlistaFrågor($värm, function () {
        get('/dashboard')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->has('favorites', 5)
        );
    });

    expect($medFem)->toBe($medEn);
});

/*
 * Klart när: en mjukraderad container eller ett mjukraderat item ger ingen
 * rad.
 *
 * Samma regel som sökningen (tests/Feature/Frontend/SokvyTest.php): en rad
 * som pekar in i papperskorgen är en genväg till något användaren inte längre
 * ser i någon annan listning, och `Container::accessibleBy` i en `whereHas`
 * låter SoftDeletes' globala scope gälla i underfrågan.
 */
it('ger ingen rad för en mjukraderad container eller ett mjukraderat item', function () {
    withoutVite();

    [$konto, $ägare, $raderadPärm] = favoritlistaKontext();

    $iRaderadPärm = favoritlistaItem($raderadPärm, 'Favorit i raderad pärm', $ägare);
    favoritlistaMarkera($ägare, $raderadPärm, $iRaderadPärm);

    $pärm = Container::factory()->for($konto, 'account')->create();
    $raderatItem = favoritlistaItem($pärm, 'Favorit som är raderad', $ägare);
    favoritlistaMarkera($ägare, $pärm, $raderatItem);

    $kvar = favoritlistaItem($pärm, 'Favorit som finns', $ägare);
    favoritlistaMarkera($ägare, $pärm, $kvar);

    $raderadPärm->delete();
    $raderatItem->delete();

    app()->forgetScopedInstances();
    $svar = actingAs($ägare)->get('/dashboard')->assertOk();

    expect(favoritlistaNamn($svar))->toBe(['Favorit som finns'])
        ->and($svar->getContent())->not->toContain($iRaderadPärm->ulid)
        ->and($svar->getContent())->not->toContain($raderatItem->ulid);
});
