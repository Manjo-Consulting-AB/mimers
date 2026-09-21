<?php

// rott-pa-basen: issue 77b — ordbyte i prosa (kommentar och testnamn), ingen kodändring; bas och head delar applikationskod.

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 59b · Den globala sökningen. Se App\Http\Controllers\SearchController,
 * App\Actions\Item\SearchAccessibleItems, resources/js/pages/Search.vue,
 * resources/js/components/SearchField.vue och routes/web.php.
 *
 * Den viktigaste gränsen i filen är OMFÅNGET (Beslut 2 och 6, issue 73
 * § Beslut 4): frågan går över ALLA containers användaren når och omfånget är
 * olika i varje, så urvalet ÄR behörigheten — det finns ingen grind att
 * glömma. Ett tappat villkor ger inget fel, inget larm och ett svar som ser
 * rätt ut, bara med rader ur andras containers.
 *
 * Den andra är ATT INGEN FRÅGA KÖRS när ingen ställts (Beslut 4): `/search`
 * utan `q` är utgångsläget, inte en tom sökning, och varken 422 eller
 * redirect.
 *
 * Att `/api/items?q=...` svarar exakt som förut prövas av
 * tests/Feature/Item/ItemSokTest.php och tests/Feature/Omfang/SokfilterTest.php,
 * som är gröna utan en enda ändrad förväntan efter utbrytningen i Beslut 2.
 * Här prövas bara att API-resursen inte fick en container-nyckel av utbrytningen.
 *
 * Hjälparna har prefixet `sokvy` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett ägarkonto med en medlem i.
 *
 * @return array{0: Account, 1: User}
 */
function sokvyKonto(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$konto, $anvandare];
}

/**
 * En container under $konto.
 */
function sokvyPärm(Account $konto): Container
{
    return Container::factory()->for($konto, 'account')->create();
}

/**
 * Ett item i containern. `created_by_*` sätts sammanhängande — fabrikens egna
 * default-skapare hade annars blivit två ovidkommande rader per item, och
 * ItemResource läser `createdByAccount`.
 *
 * @param  array<string, mixed>  $attribut
 */
function sokvyItem(Container $container, string $namn, ?User $skapare = null, array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => ($skapare ?? User::factory()->create())->id,
        'created_by_account_id' => $container->account_id,
        ...$attribut,
    ]);
}

/**
 * En grant på containern: item-bred när $item ges, container-bred annars.
 *
 * $mottagare är den som når fram — utelämnad skapas en ny användare utanför
 * ägarkontot, vilket är den vanliga formen. Att kunna peka ut en befintlig
 * behövs när en OCH samma användare ska nå flera containers, eller en container på
 * olika sätt i samma test.
 */
function sokvyMottagare(Container $container, ?Item $item = null, string $nivå = 'read', ?User $mottagare = null): User
{
    $mottagare ??= User::factory()->create();

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
 * Sökningens URL — samma form fältet i AppLayout skickar.
 */
function sokvyUrl(string $q): string
{
    return '/search?q='.urlencode($q);
}

/**
 * Träffarnas namn ur svaret, i serverns ordning.
 *
 * @return list<string>
 */
function sokvyNamn(TestResponse $svar): array
{
    /** @var array{results: list<array{name: string}>} $proppar */
    $proppar = $svar->inertiaProps();

    return array_map(fn (array $rad): string => $rad['name'], $proppar['results']);
}

/**
 * Antalet frågor $anrop ställer, mätt efter ett omätt anrop som värmer
 * guarderna, kontocachen och texten — samma mönster som
 * ItemlistaTest::itemlistaFrågor().
 */
function sokvyFrågor(Closure $värm, Closure $anrop): int
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
 * Beslut 1: rutten ligger bakom `auth`. En utloggad besökare skickas till
 * inloggningen och når aldrig kontrollern.
 */
it('skickar en utloggad besökare till inloggningen', function () {
    withoutVite();

    get('/search')->assertRedirect('/login');
    get(sokvyUrl('Impeller'))->assertRedirect('/login');
});

/*
 * Klart när: `/search?q=impeller` listar träffar över ALLA containers användaren
 * når, sorterade på namn.
 *
 * Träffarna ligger i tre containers — två egna och en delad — och skapas i omvänd
 * bokstavsordning, så en lista som råkade behålla skapelseordningen faller.
 */
it('listar träffar ur alla containers användaren når, sorterade på namn', function () {
    withoutVite();

    [$konto, $anvandare] = sokvyKonto();

    $första = sokvyPärm($konto);
    $andra = sokvyPärm($konto);

    // En container under ett FRÄMMANDE konto som användaren når genom en
    // container-bred grant — "alla containers användaren når" är inte "sina egna".
    $delad = sokvyPärm(Account::factory()->create());
    sokvyMottagare($delad, null, 'read', $anvandare);

    foreach (['Storseglet Impeller', 'Motorn Impeller', 'Impellern'] as $namn) {
        sokvyItem($första, $namn, $anvandare);
    }

    sokvyItem($andra, 'Ankaret Impeller', $anvandare);
    sokvyItem($delad, 'Backen Impeller');
    sokvyItem($första, 'Kontrollen', $anvandare);

    $svar = actingAs($anvandare)->get(sokvyUrl('Impeller'));

    $svar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Search')
        ->where('q', 'Impeller')
    );

    expect(sokvyNamn($svar))->toBe([
        'Ankaret Impeller',
        'Backen Impeller',
        'Impellern',
        'Motorn Impeller',
        'Storseglet Impeller',
    ]);
});

/*
 * Klart när: en sökning på en delsträng MITT i ett ord ger träff via
 * webbrutten.
 *
 * Det är samma `LIKE '%ord%'` som /api svarar med — tests/Feature/Item/
 * ItemSokTest.php äger den sidan — men frågan går här genom SearchController
 * och svaret ritas av Search.vue. Att webben och API:t svarar lika är hela
 * skälet att urvalet bröts ut till SearchAccessibleItems (Beslut 2).
 *
 * "ladd" står inuti "Batteriladdare", från och med den sjunde bokstaven: en
 * fråga som krävde hela ord hade gett noll träffar, och det är precis vad
 * utgångslägets gamla mening lovade (issue 78 § Beslut 5).
 */
it('ger träff på en delsträng mitt i ett ord', function () {
    withoutVite();

    [$konto, $anvandare] = sokvyKonto();
    sokvyItem(sokvyPärm($konto), 'Batteriladdare', $anvandare);

    $svar = actingAs($anvandare)->get(sokvyUrl('ladd'));

    expect(sokvyNamn($svar->assertOk()))->toBe(['Batteriladdare']);
});

/*
 * Klart när: en sökning på ett helt ord ger träff via webbrutten.
 *
 * Andra halvan av samma regel: delsträngen träffar, och det gör hela ordet
 * också. Motsatsen — att bara hela ord gav träff — var påståendet som stod i
 * utgångsläget före issue 78, och den som sökte på ett ord hon mindes fel
 * fick då veta att sökningen var trasig.
 */
it('ger träff på ett helt ord', function () {
    withoutVite();

    [$konto, $anvandare] = sokvyKonto();
    sokvyItem(sokvyPärm($konto), 'Batteriladdare Victron', $anvandare);

    $svar = actingAs($anvandare)->get(sokvyUrl('Victron'));

    expect(sokvyNamn($svar->assertOk()))->toBe(['Batteriladdare Victron']);
});

/*
 * Klart när: varje träff visar vilken container den ligger i.
 *
 * `ItemResource` bär ingen container med flit, så containern läggs BREDVID resursen
 * (Beslut 3) — ULID, namn och `kind`, för vyn ritar en länk och en etikett.
 */
it('bär varje träffs container bredvid resursen', function () {
    withoutVite();

    [$konto, $anvandare] = sokvyKonto();
    $pärm = sokvyPärm($konto);
    sokvyItem($pärm, 'Impellern', $anvandare);

    $svar = actingAs($anvandare)->get(sokvyUrl('Impeller'));

    $svar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('results', 1)
        ->where('results.0.name', 'Impellern')
        ->where('results.0.container.ulid', $pärm->ulid)
        ->where('results.0.container.name', $pärm->name)
        ->where('results.0.container.kind', $pärm->kind)
    );

    // Ingen fråga per rad: containern kom med actionens eager load, och
    // fältet ligger utanpå resursen. `/api` har inte bett om det och ska
    // fortfarande inte få det.
    $token = $anvandare->createToken('api');

    getJson('/api/items?q=Impeller', ['Authorization' => "Bearer {$token->plainTextToken}"])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonMissingPath('data.0.container');
});

/*
 * Klart när: samma sak i sökträffen — en egenskriven art visas ORDAGRANT och
 * aldrig som en översättningsnyckel (issue 84 · [[ADR-0036 Containerns art]]).
 *
 * Före issue 84 stod `t('container.kind.' + värdet)` här, och `t()` returnerar
 * nyckeln själv när uppslaget misslyckas: första gången någon skrev en egen
 * art hade träffen läst `container.kind.Segelbåt`. Fältet är fritt nu, så
 * värdet går oförändrat genom API-lagret och vyn bygger ingen nyckel alls.
 */
it('visar containerns art ordagrant i träffen', function () {
    withoutVite();

    [$konto, $anvandare] = sokvyKonto();
    $pärm = sokvyPärm($konto);
    $pärm->update(['kind' => 'Segelbåt']);
    sokvyItem($pärm, 'Impellern', $anvandare);

    $svar = actingAs($anvandare)->get(sokvyUrl('Impeller'));

    $svar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('results.0.container.kind', 'Segelbåt')
    );

    expect(str_contains($svar->getContent(), 'container.kind.'))->toBeFalse();

    $vy = File::get(resource_path('js/pages/Search.vue'));

    expect($vy)->toContain('{{ result.container.kind }}');
    expect(str_contains($vy, 't(`container.kind'))->toBeFalse();

    // En träff i en container utan art visar ingen art alls: elementet döljs i
    // stället för att ritas tomt. Närvarokontrollen frågar om fältet är SATT
    // och aldrig VILKET värde det bär — den grenar inte på arten.
    expect($vy)->toContain('v-if="result.container.kind"');
});

/*
 * Klart när: träffens namn länkar till itemets detaljvy och containernamnet till
 * containerns ITEMLISTA.
 *
 * Containernamnet länkade till containerns egen URL redan före issue 89, och
 * menade listan hela tiden; sedan flytten ligger listan på `…/items` och
 * containerns egen URL är en översikt ([[ADR-0039 Containerns översikt]]
 * § Konsekvenser). `containers.show` står därför kvar oförändrat i raden ovan,
 * medan vyns href får `/items`.
 *
 * Länkarna prövas mot de href ruttnamnen faktiskt ger — en vy som länkar till
 * en påhittad adress hade annars sett rätt ut i en strukturell kontroll.
 */
it('länkar träffen till detaljvyn och containern till förstasidan', function () {
    [$konto, $anvandare] = sokvyKonto();
    $pärm = sokvyPärm($konto);
    $item = sokvyItem($pärm, 'Impellern', $anvandare);

    expect(route('containers.items.show', [$pärm, $item], false))
        ->toBe("/containers/{$pärm->ulid}/items/{$item->ulid}")
        ->and(route('containers.show', $pärm, false))
        ->toBe("/containers/{$pärm->ulid}");

    $vy = File::get(resource_path('js/pages/Search.vue'));

    expect($vy)->toContain(':href="`/containers/${result.container.ulid}/items/${result.ulid}`"')
        ->toContain(':href="`/containers/${result.container.ulid}/items`"')
        ->toContain('{{ result.name }}')
        ->toContain('{{ result.container.name }}');
});

/*
 * Klart när: ett item i en container användaren inte når finns aldrig i
 * resultatet.
 *
 * Det är hela läckagetestet ([[ADR-0012 Sök]] § Konsekvenser: "en allvarlig
 * incident"). Både ULID:n och namnet prövas i svarskroppen — ett svar som ser
 * rätt ut men bär en rad för mycket är precis felet.
 */
it('visar aldrig ett item ur en container användaren inte når', function () {
    withoutVite();

    [$konto, $anvandare] = sokvyKonto();
    $egen = sokvyPärm($konto);
    $främmande = sokvyPärm(Account::factory()->create());

    sokvyItem($egen, 'Impellern', $anvandare);
    $dolt = sokvyItem($främmande, 'Impeller hemlig');

    $svar = actingAs($anvandare)->get(sokvyUrl('Impeller'));

    expect(sokvyNamn($svar->assertOk()))->toBe(['Impellern'])
        ->and($svar->getContent())->not->toContain($dolt->ulid)
        ->and($svar->getContent())->not->toContain('Impeller hemlig');
});

/*
 * Klart när: en omfångsbegränsad mottagare får bara träffar inom sitt omfång
 * — i den container hon har en itemgrant, och inget mer ur samma container.
 *
 * Mottagaren når containern genom granten men bara det itemet: den som når
 * containern når inte nödvändigtvis allt i den (issue 73 § Beslut 4).
 */
it('ger en omfångsbegränsad mottagare bara det hon har en grant på', function () {
    withoutVite();

    $pärm = sokvyPärm(Account::factory()->create());

    $mitt = sokvyItem($pärm, 'Impellern');
    $dolt = sokvyItem($pärm, 'Impeller hemlig');

    $mottagare = sokvyMottagare($pärm, $mitt);

    $svar = actingAs($mottagare)->get(sokvyUrl('Impeller'));

    expect(sokvyNamn($svar->assertOk()))->toBe(['Impellern'])
        ->and($svar->getContent())->not->toContain($dolt->ulid);
});

/*
 * Klart när: en användare som når en container helt och en annan bara genom en
 * itemgrant får rätt urval ur BÅDA i samma sökning.
 *
 * Det är den svåra halvan av OR-villkoret (issue 73 § Beslut 4): den
 * obegränsade delen ger allt i sin container, den begränsade ger exakt sina
 * itemnummer — i samma svar, utan att den ena smittar den andra.
 */
it('blandar ett obegränsat och ett begränsat omfång i samma svar', function () {
    withoutVite();

    $ägarkonto = Account::factory()->create();

    $obegränsad = sokvyPärm($ägarkonto);
    $begränsad = sokvyPärm($ägarkonto);

    $a1 = sokvyItem($obegränsad, 'Impeller ett');
    $a2 = sokvyItem($obegränsad, 'Impeller två');
    sokvyItem($begränsad, 'Impeller dold ett');
    sokvyItem($begränsad, 'Impeller dold två');

    $mottagare = sokvyMottagare($obegränsad, null, 'read');

    $b1 = sokvyItem($begränsad, 'Impeller tre');
    sokvyMottagare($begränsad, $b1, 'read', $mottagare);

    $svar = actingAs($mottagare)->get(sokvyUrl('Impeller'));

    expect(collect(sokvyNamn($svar->assertOk()))->sort()->values()->all())
        ->toBe(collect([$a1->name, $a2->name, $b1->name])->sort()->values()->all())
        ->and($svar->getContent())->not->toContain('Impeller dold')
        ->and($svar->getContent())->not->toContain('Impeller dold två');
});

/*
 * Klart när: en användare som inte når någonting alls får ett tomt resultat,
 * ordagrant identiskt med ett resultat utan träffar.
 *
 * Samma URL, två användare, samma svar: meningen vet ingenting om omfånget,
 * och ingen rad i svaret röjer att det fanns träffar att dölja (Beslut 6,
 * issue 73 § Beslut 6).
 */
it('ger en användare utan åtkomst samma tomma svar som ett resultat utan träffar', function () {
    withoutVite();

    // Träffen ligger hos en TREDJE part: varken ägaren eller främlingen når
    // den, och båda söker på samma ord. Då är de två svaren jämförbara
    // stycke för stycke — främlingen får inte ett annat svar än den som
    // sökte på något som inte finns.
    $främmande = sokvyPärm(Account::factory()->create());
    sokvyItem($främmande, 'Impeller hemlig');

    [, $ägare] = sokvyKonto();
    $främling = User::factory()->create();

    $url = sokvyUrl('Impeller');

    $ägarens = actingAs($ägare)->get($url)->assertOk();
    $främlings = actingAs($främling)->get($url)->assertOk();

    expect($ägarens->inertiaProps()['q'])->toBe('Impeller')
        ->and($främlings->inertiaProps()['q'])->toBe('Impeller')
        ->and($ägarens->inertiaProps()['results'])->toBe([])
        ->and($främlings->inertiaProps()['results'])->toBe([])
        ->and($främlings->getContent())->not->toContain('Impeller hemlig');
});

/*
 * Klart när: en mjukraderad container och ett mjukraderat item ger aldrig en
 * träff.
 *
 * SoftDeletes' globala scope gäller i underfrågan (whereHas, issue 15b
 * § Att se upp med) och på items-frågan själv — en mjukraderad rad ska aldrig
 * gå att söka fram, varken för ägaren eller för en mottagare med grant.
 */
it('ger aldrig träff på en mjukraderad container eller ett mjukraderat item', function () {
    withoutVite();

    [$konto, $anvandare] = sokvyKonto();

    $raderadPärm = sokvyPärm($konto);
    sokvyItem($raderadPärm, 'Impeller i raderad pärm', $anvandare);
    $raderadPärm->delete();

    $pärm = sokvyPärm($konto);
    $raderatItem = sokvyItem($pärm, 'Impeller som är raderad', $anvandare);
    $raderatItem->delete();

    sokvyItem($pärm, 'Impellern', $anvandare);

    $svar = actingAs($anvandare)->get(sokvyUrl('Impeller'));

    expect(sokvyNamn($svar->assertOk()))->toBe(['Impellern'])
        ->and($svar->getContent())->not->toContain($raderatItem->ulid);
});

/*
 * Klart när: `/search` utan `q` renderar utgångsläget utan att köra en fråga,
 * och ger varken 422 eller redirect.
 *
 * Beviset är bindningarna: kördes en sökning hade sökordet stått i någon
 * frågas bindningar. Träffen finns där, så en fråga som kördes hade svarat.
 */
it('renderar utgångsläget utan att köra en sökfråga', function () {
    withoutVite();

    [$konto, $anvandare] = sokvyKonto();
    sokvyItem(sokvyPärm($konto), 'Impellern', $anvandare);

    actingAs($anvandare);

    $sedda = [];
    DB::listen(function ($query) use (&$sedda) {
        $sedda[] = $query->sql.' '.implode(' ', $query->bindings);
    });

    $svar = get('/search');

    $svar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Search')
        ->where('q', null)
        ->has('results', 0)
    );

    expect(implode(' ', $sedda))->not->toContain('Impellern');

    // Utgångsläget säger vad man kan söka på och vad frågan matchar — och vyn
    // ritar de två raderna ur lang/, inte ur en sträng (Beslut 7 och 8).
    // Ingen "menade du"-rad finns. Nyckeln hette `whole_words` fram till
    // issue 78 § Beslut 5: påståendet den bar var falskt.
    $vy = File::get(resource_path('js/pages/Search.vue'));

    expect($vy)->toContain("t('search.intro')")
        ->toContain("t('search.match_rule')")
        ->toContain("t('search.empty', { q })");
});

/*
 * Klart när: en `q` på bara blanksteg behandlas som ingen `q`.
 *
 * Samma normalisering som `IndexItemRequest::prepareForValidation()` gör för
 * `/api` (Beslut 4): trimning, och blankt är samma sak som tomt.
 */
it('behandlar en blank q som ingen q', function () {
    withoutVite();

    [$konto, $anvandare] = sokvyKonto();
    sokvyItem(sokvyPärm($konto), 'Impellern', $anvandare);

    $svar = actingAs($anvandare)->get('/search?q=%20%20');

    $svar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('q', null)
        ->has('results', 0)
    );
});

/*
 * Klart när: en `q` över 255 tecken ger ett valideringsfel vid fältet.
 *
 * Ingen tyst trunkering (Beslut 4): regeln är `IndexItemRequest`s, och felet
 * är ett vanligt webbformulärs — servern svarar med felpåsen, fältet i
 * AppLayout ritar det.
 */
it('ger ett valideringsfel för en q över 255 tecken', function () {
    withoutVite();

    [, $anvandare] = sokvyKonto();

    actingAs($anvandare)->get(sokvyUrl(str_repeat('a', 256)))->assertSessionHasErrors('q');

    expect(session('errors')->get('q')[0])->toContain('255');

    // Fältet ritar serverns fel och sätter aria-describedby till det — ingen
    // egen regel i JavaScript (Beslut 4 och 5).
    $fält = File::get(resource_path('js/components/SearchField.vue'));

    expect($fält)->toContain('page.props.errors?.q')
        ->toContain(':error="error"')
        ->toContain('method="get"')
        ->toContain('action="/search"')
        ->toContain('name="q"');
});

/*
 * Klart när: sökfältet finns i layouten på varje inloggad sida och saknas
 * utloggad.
 *
 * Fältet ritas ur AppLayout, som varje sida under resources/js/pages/ wrappar
 * sitt innehåll i (Beslut 5), och villkoret är den inloggade användaren —
 * rutten ligger bakom `auth`, så en gäst har inget att söka i.
 */
it('ritar sökfältet i layouten för en inloggad användare och inte för en gäst', function () {
    withoutVite();

    [, $anvandare] = sokvyKonto();

    $layout = File::get(resource_path('js/layouts/AppLayout.vue'));

    expect($layout)->toContain("import SearchField from '../components/SearchField.vue'")
        ->toContain('<SearchField v-if="user" />')
        // Villkoret är den inloggade användaren ur den delade propen — inte
        // en egen fråga och inte en egen flagga.
        ->toContain('const user = computed(() => page.props.auth.user)');

    // Gästen har ingen användare, alltså ritas fältet inte.
    get('/')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('auth.user', null)
    );

    // Varje inloggad sida renderar layouten — översikten och söksidan är två
    // av dem, och båda bär nycklarna som fältet läser.
    actingAs($anvandare)->get('/dashboard')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('translations.search.field.label', 'Search all containers')
    );

    actingAs($anvandare)->get('/search')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->component('Search')
    );
});

/*
 * Klart när: navigeringen har en väg till sökningen för en inloggad användare,
 * också i det hopfällda mobilläget, och ingen för en utloggad besökare (issue
 * 78 § Beslut 2).
 *
 * Fältet i headern är en väg in, men bara för den som redan vet att sökningen
 * finns. Raden ligger därför innanför `#huvudmenyn` — samma div som de andra
 * länkarna fälls ihop i (issue 68a § Beslut 2) — och en länk utanför den vore
 * en länk som försvinner på en telefon.
 */
it('har en väg till sökningen i navigeringen för en inloggad och ingen för en gäst', function () {
    withoutVite();

    [, $anvandare] = sokvyKonto();

    // Gästen prövas FÖRST: actingAs() sätter guardens användare för resten av
    // testet, och därefter är varje anrop inloggat.
    get('/')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('auth.user', null)
    );

    $layout = File::get(resource_path('js/layouts/AppLayout.vue'));

    expect($layout)->toContain('id="huvudmenyn"')
        ->toContain('<Link v-if="user" href="/search"');

    $menyn = substr($layout, (int) strpos($layout, 'id="huvudmenyn"'));

    expect($menyn)->toContain("t('nav.search')");

    // Nyckeln finns, med ordet ur katalogen (Beslut 8).
    expect(trans('ui.nav.search', [], 'en'))->toBe('Search');

    // Villkoret är den inloggade användaren ur den delade propen — inte en
    // egen fråga och inte en egen flagga. En gäst har ingen användare, och
    // får därför ingen rad.
    actingAs($anvandare)->get('/dashboard')->assertInertia(
        fn (AssertableInertia $page) => $page->where('translations.nav.search', 'Search')
    );
});

/*
 * Klart när: sökningen kostar ett konstant antal frågor oavsett antal containers
 * och träffar, mätt med DB::listen.
 *
 * Omfångsupplösningen sker i ETT anrop över alla containers (issue 70
 * § Beslut 2), containern eager-laddas (Beslut 3) och sökfrågan är en enda —
 * fler containers och fler träffar får inte lägga en fråga till.
 */
it('kostar ett konstant antal frågor oavsett antal containers och träffar', function () {
    withoutVite();

    $ägarkonto = Account::factory()->create();
    $mottagare = User::factory()->create();

    $första = sokvyPärm($ägarkonto);
    sokvyItem($första, 'Impeller ett');

    sokvyMottagare($första, null, 'read', $mottagare);

    actingAs($mottagare);

    $url = sokvyUrl('Impeller');

    $värm = fn () => get($url)->assertOk();

    $medEnPärm = sokvyFrågor($värm, function () use ($url) {
        get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('results', 1));
    });

    // Fyra containers till, alla nådda, alla med träffar.
    foreach (range(2, 5) as $i) {
        $extra = sokvyPärm($ägarkonto);
        sokvyItem($extra, "Impeller $i");
        sokvyMottagare($extra, null, 'read', $mottagare);
    }

    $medFemPärmar = sokvyFrågor($värm, function () use ($url) {
        get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('results', 5));
    });

    expect($medFemPärmar)->toBe($medEnPärm);
});

/*
 * Klart när: ingen svensk sträng står kvar i en `.vue`-fil; varje ny nyckel
 * finns på `sv` och `en`.
 *
 * Den första halvan vaktas av SprakTest (som läser varje fil under
 * resources/js). Här prövas den andra: nycklarna under `search`, nyckel för
 * nyckel — och att den tomma meningen inte bär ett tal (Beslut 6 och 8).
 */
it('har varje sök-nyckel och ingen svensk sträng i vyn', function () {
    $sv = require lang_path('en/ui.php');
    $en = require lang_path('en/ui.php');

    expect(array_keys($en['search']))->toBe(array_keys($sv['search']))
        ->and(array_keys($en['search']['field']))->toBe(array_keys($sv['search']['field']));

    foreach ($sv['search'] as $nyckel => $varde) {
        if (is_array($varde)) {
            continue;
        }

        expect(trim($varde))->not->toBe('', "search.{$nyckel} är")
            ->and(trim($en['search'][$nyckel]))->not->toBe('', "search.{$nyckel} är tom på en");
    }

    // Meningen nämner sökordet och slutar där — inget tal om dolda rader.
    expect($sv['search']['empty'])->toContain(':q')
        ->and($en['search']['empty'])->toContain(':q');

    expect($sv['search']['empty'])->not->toMatch('/\d/')
        ->and($en['search']['empty'])->not->toMatch('/\d/');

    // Ingen svensk sträng utanför kommentar i den nya sidan och fältet.
    foreach (['pages/Search.vue', 'components/SearchField.vue'] as $fil) {
        $kod = File::get(resource_path("js/{$fil}"));
        $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
        $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

        expect($kod)->not->toMatch('/[åäöÅÄÖ]/u', "svensk text utanför kommentar i {$fil}");
    }
});
