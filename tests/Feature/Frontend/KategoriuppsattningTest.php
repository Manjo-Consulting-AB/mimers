<?php

// rott-pa-basen: issue 77b — ordbyte i prosa (kommentar och testnamn), ingen kodändring; bas och head delar applikationskod.

use App\Http\Controllers\CategoryController;
use App\Http\Requests\Category\StoreCategoryPresetRequest;
use App\Models\Account;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutVite;

/*
 * Issue 56b · Färdiga kategoriuppsättningar per språk. Se
 * resources/js/data/categoryPresets.js,
 * resources/js/components/CategoryPresetCard.vue,
 * App\Http\Requests\Category\StoreCategoryPresetRequest och
 * App\Http\Controllers\CategoryController::storePreset()/dismissPreset().
 *
 * Uppsättningarna hängde fram till issue 84 på containerns `kind` och valdes
 * åt användaren. Den nyckeln finns inte längre ([[ADR-0036 Containerns art]]):
 * uppsättningen har ett eget NAMN, och den som vill ha en pekar på den.
 *
 * Den bärande arkitekturen i filen är att SERVERN ALDRIG VET VAD ORDEN
 * BETYDER ([[ADR-0004 Fria taggar och kategorier]] § Konsekvenser). Därför
 * prövas två saker på var sitt plan:
 *
 * - VILKA ord förslaget innehåller: i node, mot samma modul klienten
 *   importerar. En assertion på en PHP-variant av listan hade bevisat noll.
 * - ATT de aldrig blir serverns: inget kategorinamn får förekomma i `lang/`,
 *   `config/` eller en PHP-fil, och ingen sida får skicka orden till
 *   webbläsaren som prop.
 *
 * Ordningen är alltså den omvända mot övriga filer i katalogen: det som
 * bevisas på serversidan är TAKET, TOMHETSKONTROLLEN, BEHÖRIGHETEN och
 * OMDIRIGERINGEN — det som faktiskt är serverkod.
 *
 * Hjälparna har prefixet `uppsattning` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en ägare, och en container med given art. Ägarens locale styr den
 * delade propen `locale` och därmed vilken uppsättning kortet erbjuder — arten
 * styr ingenting (issue 84).
 *
 * @param  array<string, mixed>  $kontoAttribut
 * @return array{0: Account, 1: User, 2: Container}
 */
function uppsattningKontext(
    ?string $anvandarLocale = null,
    string $kind = 'boat',
    array $kontoAttribut = [],
): array {
    $konto = Account::factory()->create($kontoAttribut);
    $anvandare = User::factory()->create(['name' => 'Testperson', 'locale' => $anvandarLocale]);
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create(['kind' => $kind]);

    return [$konto, $anvandare, $container];
}

/**
 * Kör ett uttryck mot resources/js/data/categoryPresets.js i node, med
 * modulen bunden till `mod`.
 *
 * Sökvägen går genom pathToFileURL() i stället för att klistras in rått, samma
 * skäl som SprakTest::korTranslate(): en Windows-sökväg är ingen giltig
 * ESM-specificerare.
 */
function uppsattningKor(string $uttryck): mixed
{
    $skript = implode("\n", [
        "const { pathToFileURL } = await import('node:url');",
        'const mod = await import(pathToFileURL('
            .json_encode(resource_path('js/data/categoryPresets.js'), JSON_UNESCAPED_SLASHES).').href);',
        "process.stdout.write(JSON.stringify({$uttryck}));",
    ]);

    $rader = [];
    $kod = 0;

    exec('node --input-type=module -e '.escapeshellarg($skript).' 2>&1', $rader, $kod);

    expect($kod)->toBe(0, implode("\n", $rader));

    return json_decode(implode("\n", $rader), true);
}

/**
 * Kategorierna i uppsättningen som heter $namn på $locale, eller `null` om
 * namnet inte finns där.
 *
 * Sedan issue 84 slås uppsättningarna inte längre upp på containerns `kind`
 * ([[ADR-0036 Containerns art]]) — de har ett eget namn, och användaren väljer
 * bland dem. Hjälparen letar därför på namnet, precis som väljaren i
 * resources/js/components/CategoryPresetCard.vue gör.
 *
 * @return list<array{name: string, children?: list<string>}>|null
 */
function uppsattningFor(string $locale, string $namn): ?array
{
    foreach (uppsattningKor('mod.presetsFor('.json_encode($locale).')') as $uppsattning) {
        if ($uppsattning['name'] === $namn) {
            return $uppsattning['categories'];
        }
    }

    return null;
}

/**
 * Hela datan, så att varje uppsättning kan prövas på en gång.
 *
 * @return array<string, list<array{name: string, categories: list<array<string, mixed>>}>>
 */
function uppsattningAlla(): array
{
    return uppsattningKor('mod.categoryPresets');
}

/**
 * Varje namn i en uppsättning, rotnamn och barnnamn, i den ordning de står.
 *
 * @param  list<array{name: string, children?: list<string>}>  $uppsattning
 * @return list<string>
 */
function uppsattningNamn(array $uppsattning): array
{
    $namn = [];

    foreach ($uppsattning as $rot) {
        $namn[] = $rot['name'];

        foreach ($rot['children'] ?? [] as $barn) {
            $namn[] = $barn;
        }
    }

    return $namn;
}

/**
 * Den delade propen `locale` för en inloggad användare, läst ur Inertias
 * rotvy.
 */
function uppsattningLocale(User $anvandare, Container $container): string
{
    /** @var array{props: array{locale: string}} $sida */
    $sida = actingAs($anvandare)
        ->get("/containers/{$container->ulid}/categories")
        ->assertOk()
        ->viewData('page');

    return $sida['props']['locale'];
}

/*
 * Beslut 3: rutterna ligger bakom `auth`. En utloggad besökare skickas till
 * inloggningen och når aldrig en kontrollermetod.
 */
it('skickar en utloggad besökare till inloggningen från uppsättningsrutten', function () {
    withoutVite();

    [, , $container] = uppsattningKontext();

    post("/containers/{$container->ulid}/categories/preset", ['categories' => [['name' => 'Rigg']]])
        ->assertRedirect('/login');

    delete("/containers/{$container->ulid}/categories/preset")->assertRedirect('/login');

    expect(Category::query()->count())->toBe(0);
});

/*
 * Klart när: en tom container visar förslaget, och servern har inte skickat orden.
 *
 * Att kortet ritas är presentationslogik och prövas i källfilerna; det som
 * prövas HÄR är att servern bara säger sitt om nej:et (Beslut 2 och 4) och att
 * uppsättningens ord inte finns i svaret — servern får aldrig veta vad de
 * betyder.
 */
it('visar förslaget på en tom container och skickar aldrig orden till webbläsaren', function () {
    withoutVite();

    [, $anvandare, $container] = uppsattningKontext('sv_SE');

    // Namnen är satta och inte slumpade: assertionen nedan läser hela
    // svarskroppen, och ett kontonamn ur fake() hade kunnat råka innehålla ett
    // av orden.
    $container->account->update(['name' => 'Testkontot']);
    $container->update(['name' => 'Vindil']);

    $svar = actingAs($anvandare)->get("/containers/{$container->ulid}/categories");

    $svar->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Containers/Categories')
        ->has('categories', 0)
        ->where('presetDismissed', false)
        ->where('can.manage', true)
    );

    /*
     * Orden bor i klienten. Hade de kommit som prop hade ADR-0004 varit
     * upphävd, oavsett vad kortet visade — så ALLA uppsättningar prövas, inte
     * bara den testet råkade peka på.
     *
     * Språkkatalogen `translations` undantas med flit: den ÄR `lang/en/ui.php`
     * och bär sidans egna ord (`Other` har en kategori som heter `Storage`, och
     * navigeringen har en flik med samma ord). Att orden inte står i `lang/`
     * vaktas i stället av provet `har inga kategorinamn i lang/, config/ eller
     * någon PHP-fil` längre ner i filen.
     */
    $proppar = $svar->inertiaProps();
    unset($proppar['translations']);

    foreach (uppsattningAlla() as $samling) {
        foreach ($samling as $uppsattning) {
            foreach (uppsattningNamn($uppsattning['categories']) as $namn) {
                expect(json_encode($proppar))->not->toContain($namn);
            }
        }
    }

    // Sidans eget formulär fungerar medan kortet står kvar obesvarat — det är
    // vad "kan tacka nej utan att fastna" betyder (Beslut 4).
    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/categories")
        ->post("/containers/{$container->ulid}/categories", ['name' => 'Rigg och segel'])
        ->assertSessionHas('status', 'category-created');

    expect(Category::query()->where('container_id', $container->id)->pluck('name')->all())
        ->toBe(['Rigg och segel']);
});

/*
 * Beslut 4: villkoret är att trädet är tomt. Efter att uppsättningen lagts in
 * är det inte tomt, och samma prop gäller — servern räknar inte tomhet, den
 * skickar listan.
 */
it('visar inte förslaget när trädet har kategorier', function () {
    withoutVite();

    [, $anvandare, $container] = uppsattningKontext('sv_SE');

    Category::factory()->for($container, 'container')->create(['name' => 'Rigg', 'position' => 1]);

    actingAs($anvandare)->get("/containers/{$container->ulid}/categories")->assertInertia(
        fn (AssertableInertia $page) => $page->has('categories', 1)
    );
});

/*
 * Klart när: *Lägg till uppsättningen* skapar rot- och underkategorierna i
 * rätt ordning, med rätt föräldrar, i en transaktion.
 *
 * Ordningen prövas som `position`, för det är den ordningen 56a:s ytor sedan
 * visar. Den nya kategorin hamnar sist bland sina syskon — servern räknar
 * aldrig positionen här, App\Actions\Category\CreateCategory gör det
 * (Beslut 3).
 */
it('lägger in uppsättningen i ordning, med rätt föräldrar och positioner', function () {
    withoutVite();

    [, $anvandare, $container] = uppsattningKontext('sv_SE', 'boat');

    $uppsattning = uppsattningFor('en', 'Boat');

    $svar = actingAs($anvandare)
        ->from("/containers/{$container->ulid}/categories")
        ->post("/containers/{$container->ulid}/categories/preset", ['categories' => $uppsattning]);

    $svar->assertRedirect("/containers/{$container->ulid}/categories")
        ->assertSessionHas('status', 'category-preset-applied');

    $rotnamn = array_column($uppsattning, 'name');

    $rotter = Category::query()
        ->where('container_id', $container->id)
        ->whereNull('parent_id')
        ->orderBy('position')
        ->get();

    expect($rotter->pluck('name')->all())->toBe($rotnamn);
    expect($rotter->pluck('position')->all())->toBe(range(1, count($rotnamn)));

    // Barnen ligger under sin rot, i uppsättningens ordning.
    $motor = $rotter->firstWhere('name', 'Engine');

    $barn = Category::query()
        ->where('parent_id', $motor->id)
        ->orderBy('position')
        ->get();

    expect($barn->pluck('name')->all())->toBe(['Drive train', 'Cooling']);
    expect($barn->pluck('position')->all())->toBe([1, 2]);
    expect($barn->pluck('container_id')->unique()->all())->toBe([$container->id]);

    // Ingen märkning: raderna ÄR vanliga kategorier (Beslut 6). Lövet går att
    // radera med 56a:s yta, och ingenting i systemet minns att det kom från
    // ett förslag.
    $drivlina = $barn->firstWhere('name', 'Drive train');

    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/categories")
        ->delete("/containers/{$container->ulid}/categories/{$drivlina->ulid}")
        ->assertSessionHas('status', 'category-deleted');

    expect($drivlina->refresh()->trashed())->toBeTrue();
});

/*
 * Klart när: ett andra anrop mot `preset` på en container som redan har kategorier
 * ger 422 och skapar ingenting.
 *
 * Utan den kontrollen är rutten ett sätt att fördubbla trädet med en knapp som
 * ser ut som ett förslag (Beslut 3).
 */
it('vägrar en uppsättning i en container som redan har kategorier och skapar ingenting', function () {
    withoutVite();

    [, $anvandare, $container] = uppsattningKontext('sv_SE', 'boat');

    $befintlig = Category::factory()->for($container, 'container')->create(['name' => 'Rigg']);

    $svar = actingAs($anvandare)
        ->from("/containers/{$container->ulid}/categories")
        ->post("/containers/{$container->ulid}/categories/preset", [
            'categories' => uppsattningFor('en', 'Boat'),
        ]);

    $svar->assertRedirect("/containers/{$container->ulid}/categories");
    $svar->assertSessionHasErrors('categories');

    // Meningen är läsbar och inte en felkod, och den finns på användarens
    // språk — rutten finns bara på webben.
    $mening = session('errors')->get('categories')[0];

    expect($mening)->toBe(trans('ui.container.categories.preset_not_empty', [], 'en'));
    expect($mening)->not->toBe('preset_not_empty');

    expect(Category::query()->where('container_id', $container->id)->count())->toBe(1);
    expect($befintlig->refresh()->name)->toBe('Rigg');
});

/*
 * Klart när: en container vars enda kategorier är mjukraderade är TOM. Villkoret är
 * "minst en levande kategori", alltså Eloquents SoftDeletes-scope och inte en
 * rå `count()`.
 */
it('räknar en container med bara mjukraderade kategorier som tom', function () {
    withoutVite();

    [, $anvandare, $container] = uppsattningKontext('sv_SE', 'boat');

    $gammal = Category::factory()->for($container, 'container')->create(['name' => 'Gammal']);
    $gammal->delete();

    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/categories")
        ->post("/containers/{$container->ulid}/categories/preset", [
            'categories' => [['name' => 'Rig and sails']],
        ])
        ->assertSessionHas('status', 'category-preset-applied');

    expect(Category::query()->where('container_id', $container->id)->pluck('name')->all())
        ->toBe(['Rig and sails']);
});

/*
 * Klart när: fler än tolv rotkategorier, eller fler än tolv barn under en rot,
 * avvisas av valideringen.
 *
 * Taket är bindande (Beslut 3): en yta som skapar obegränsat många rader per
 * anrop är en missbruksvektor, och kategorier har ingen kvot som fångar det.
 */
it('avvisar fler än tolv rötter och fler än tolv barn under en rot', function () {
    withoutVite();

    [, $anvandare, $container] = uppsattningKontext('sv_SE');

    $förMångaRötter = array_map(
        fn (int $i): array => ['name' => "Rot {$i}"],
        range(1, StoreCategoryPresetRequest::MAX_ROOTS + 1),
    );

    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/categories")
        ->post("/containers/{$container->ulid}/categories/preset", ['categories' => $förMångaRötter])
        ->assertSessionHasErrors('categories');

    $förMångaBarn = [[
        'name' => 'Motor',
        'children' => array_map(
            fn (int $i): string => "Barn {$i}",
            range(1, StoreCategoryPresetRequest::MAX_CHILDREN + 1),
        ),
    ]];

    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/categories")
        ->post("/containers/{$container->ulid}/categories/preset", ['categories' => $förMångaBarn])
        ->assertSessionHasErrors('categories.0.children');

    expect(Category::query()->count())->toBe(0);

    // Precis på taket går bra — annars vore reglerna av ett annat skäl än de
    // påstår.
    $påTaket = array_map(
        fn (int $i): array => ['name' => "Rot {$i}"],
        range(1, StoreCategoryPresetRequest::MAX_ROOTS),
    );

    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/categories")
        ->post("/containers/{$container->ulid}/categories/preset", ['categories' => $påTaket])
        ->assertSessionHas('status', 'category-preset-applied');

    expect(Category::query()->where('container_id', $container->id)->count())
        ->toBe(StoreCategoryPresetRequest::MAX_ROOTS);
});

/*
 * Klart när: ett namn längre än 255 tecken avvisas på rätt fält — rotnamnet på
 * `categories.N.name`, barnnamnet på `categories.N.children.M`.
 */
it('avvisar ett namn längre än 255 tecken på rätt fält', function () {
    withoutVite();

    [, $anvandare, $container] = uppsattningKontext('sv_SE');

    $långt = str_repeat('a', 256);

    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/categories")
        ->post("/containers/{$container->ulid}/categories/preset", [
            'categories' => [['name' => $långt]],
        ])
        ->assertSessionHasErrors('categories.0.name');

    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/categories")
        ->post("/containers/{$container->ulid}/categories/preset", [
            'categories' => [['name' => 'Motor', 'children' => [$långt]]],
        ])
        ->assertSessionHasErrors('categories.0.children.0');

    expect(Category::query()->count())->toBe(0);
});

/*
 * Klart när: *Nej tack* döljer förslaget för den containern under sessionen, och
 * sidans övriga formulär fungerar oförändrat.
 */
it('döljer förslaget i sessionen och lämnar skapa-formuläret orört', function () {
    withoutVite();

    [, $anvandare, $container] = uppsattningKontext('sv_SE');

    actingAs($anvandare)->get("/containers/{$container->ulid}/categories")->assertInertia(
        fn (AssertableInertia $page) => $page->where('presetDismissed', false)
    );

    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/categories")
        ->delete("/containers/{$container->ulid}/categories/preset")
        ->assertRedirect("/containers/{$container->ulid}/categories");

    actingAs($anvandare)->get("/containers/{$container->ulid}/categories")->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('presetDismissed', true)
            ->has('categories', 0)
            ->where('can.manage', true)
    );

    // Att tacka nej är ingen skrivning i containern, och sidans eget formulär
    // fungerar precis som förut — det är vad "kan tacka nej utan att fastna"
    // betyder.
    expect(session(CategoryController::PRESET_DISMISSED_SESSION_KEY))->toBe([$container->ulid]);

    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/categories")
        ->post("/containers/{$container->ulid}/categories", ['name' => 'Rig and sails'])
        ->assertSessionHas('status', 'category-created');

    expect(Category::query()->where('container_id', $container->id)->pluck('name')->all())
        ->toBe(['Rig and sails']);
});

/*
 * Klart när: *Nej tack* på en container döljer inte förslaget på en ANNAN tom container.
 *
 * Nej:et är per container — en lista av ULID:n, inte en enda flagga för användaren.
 */
it('döljer inte förslaget på en annan tom container', function () {
    withoutVite();

    [$konto, $anvandare, $container] = uppsattningKontext('sv_SE');

    $annan = Container::factory()->for($konto, 'account')->create(['kind' => 'caravan']);

    actingAs($anvandare)->delete("/containers/{$container->ulid}/categories/preset");

    actingAs($anvandare)->get("/containers/{$container->ulid}/categories")->assertInertia(
        fn (AssertableInertia $page) => $page->where('presetDismissed', true)
    );

    actingAs($anvandare)->get("/containers/{$annan->ulid}/categories")->assertInertia(
        fn (AssertableInertia $page) => $page->where('presetDismissed', false)
    );
});

/*
 * Klart när: en användare med `read` på containern får 403 på `preset`, och ett
 * `read_only`-ägarkonto likaså.
 *
 * Grinden är `ContainerPolicy::update()` — samma som att skapa en kategori för
 * hand, för det är precis vad rutten gör (Beslut 3).
 */
it('nekar en read-innehavare och ett fryst ägarkonto uppsättningsrutten', function () {
    withoutVite();

    [, , $container] = uppsattningKontext();

    $läsare = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => null,
        'grantee_type' => 'user',
        'grantee_id' => $läsare->id,
        'level' => 'read',
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    actingAs($läsare)
        ->post("/containers/{$container->ulid}/categories/preset", ['categories' => [['name' => 'Rigg']]])
        ->assertForbidden();

    actingAs($läsare)->delete("/containers/{$container->ulid}/categories/preset")->assertForbidden();

    // Läsaren ser trädet och den tomma containern, men ingen skrivyta.
    actingAs($läsare)->get("/containers/{$container->ulid}/categories")->assertInertia(
        fn (AssertableInertia $page) => $page->where('can.manage', false)
    );

    // Ett fryst ägarkonto nekas allt skrivande oavsett roll — förslaget med.
    [, $ägare, $fryst] = uppsattningKontext('sv_SE', 'boat', ['status' => 'read_only']);

    actingAs($ägare)
        ->post("/containers/{$fryst->ulid}/categories/preset", ['categories' => [['name' => 'Rigg']]])
        ->assertForbidden();

    expect(Category::query()->count())->toBe(0);
});

/*
 * Klart när: en container i en annan användares konto går inte att fylla via rutten
 * (403).
 */
it('nekar en främling både uppsättningen och ett nej', function () {
    withoutVite();

    [, , $container] = uppsattningKontext();
    $främmande = User::factory()->create();

    actingAs($främmande)
        ->post("/containers/{$container->ulid}/categories/preset", ['categories' => [['name' => 'Rigg']]])
        ->assertForbidden();

    actingAs($främmande)->delete("/containers/{$container->ulid}/categories/preset")->assertForbidden();

    expect(Category::query()->count())->toBe(0);
});

/*
 * Klart när: en svensk användare får den svenska uppsättningen, en användare
 * med `en_GB` den engelska — samma container, olika ord.
 *
 * Valet görs av den delade propen `locale` och containerns `kind` (Beslut 2), och
 * prövas därför med klientens egen väljare på den locale servern faktiskt
 * skickade.
 */
it('ger varje användare samma uppsättning för samma container', function () {
    withoutVite();

    [, $svensk, $container] = uppsattningKontext('sv_SE', 'boat');
    $engelsk = User::factory()->create(['locale' => 'en_GB']);
    $container->account->users()->attach($engelsk, ['role' => 'member']);

    // Propen `locale` är `en` för båda: `en` är den enda katalogen
    // ([[ADR-0034 Engelska vid lansering]]), och klientens väljare följer den.
    expect(uppsattningLocale($svensk, $container))->toBe('en');
    expect(uppsattningLocale($engelsk, $container))->toBe('en');

    $uppsattning = uppsattningFor(uppsattningLocale($svensk, $container), 'Boat');

    expect(array_column($uppsattning, 'name'))->toContain('Rig and sails');
});

/*
 * Klart när: en locale utan samling möts av engelska, utan fel.
 *
 * Ingen containeregenskap styr längre vilken uppsättning som erbjuds (issue
 * 84), så kvar att falla tillbaka på är språket: `en` är den enda katalogen
 * ([[ADR-0034 Engelska vid lansering]]). Ett okänt NAMN är inget fel heller —
 * det betyder bara att kortet inte har någon förhandsvisning att rita.
 */
it('faller tillbaka på engelska för en okänd locale', function () {
    withoutVite();

    [, $anvandare, $container] = uppsattningKontext('sv_SE', 'Segelbåt');

    actingAs($anvandare)
        ->get("/containers/{$container->ulid}/categories")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('presetDismissed', false));

    // Samma samling, oavsett locale — och samma uppsättning ur den.
    expect(uppsattningFor('de', 'Boat'))->toBe(uppsattningAlla()['en'][0]['categories']);
    expect(uppsattningFor('de', 'Boat'))->toBe(uppsattningFor('en', 'Boat'));

    // Ett namn som inte finns ger ingenting att visa, inte ett fel.
    expect(uppsattningFor('en', 'rymdskepp'))->toBeNull();
});

/*
 * Beslut 1: varje uppsättning har ett eget namn, sex till tolv rotkategorier
 * och högst två nivåer.
 *
 * Namnet är nytt med issue 84: uppsättningarna hade tidigare containerns `kind`
 * som nyckel, och den nyckeln finns inte längre. Det är namnet väljaren visar,
 * så det får inte vara tomt och inte dubbelt.
 */
it('har namngivna uppsättningar med sex till tolv rötter och två nivåer', function () {
    $alla = uppsattningAlla();

    expect(array_keys($alla))->toBe(['en']);

    foreach ($alla as $locale => $samling) {
        $namn = array_column($samling, 'name');

        expect($namn)->toBe(array_unique($namn), "{$locale} har två uppsättningar med samma namn");

        foreach ($samling as $uppsattning) {
            expect(trim($uppsattning['name']))->not->toBe('');

            $antal = count($uppsattning['categories']);

            expect($antal)->toBeGreaterThanOrEqual(6, "{$locale}/{$uppsattning['name']} har för få rötter");
            expect($antal)->toBeLessThanOrEqual(12, "{$locale}/{$uppsattning['name']} har för många rötter");

            foreach ($uppsattning['categories'] as $rot) {
                expect($rot['name'])->toBeString();
                expect(trim($rot['name']))->not->toBe('');

                foreach ($rot['children'] ?? [] as $barn) {
                    // Två nivåer: ett barn är ett NAMN och inte en nod med
                    // egna barn.
                    expect($barn)->toBeString();
                    expect(trim($barn))->not->toBe('');
                }

                expect(count($rot['children'] ?? []))->toBeLessThanOrEqual(12);
            }
        }
    }
});

/*
 * Hela arkitekturen i issuen: servern tar emot en lista med namn den inte
 * förstår.
 *
 * Inget kategorinamn får förekomma i `lang/`, i `config/` eller i en PHP-fil —
 * som ett citattecken-omslutet värde, för svensk prosa i docblock och
 * kommentarer är något annat än ett inbakat ord. Den dag någon lägger
 * uppsättningarna i en språkfil faller det här testet.
 */
it('har inga kategorinamn i lang/, config/ eller någon PHP-fil', function () {
    $namn = [];

    foreach (uppsattningAlla() as $samling) {
        foreach ($samling as $uppsattning) {
            $namn = [...$namn, ...uppsattningNamn($uppsattning['categories'])];
        }
    }

    expect($namn)->not->toBeEmpty();

    $filer = [
        ...File::allFiles(lang_path()),
        ...File::allFiles(config_path()),
        ...File::allFiles(app_path()),
    ];

    expect($filer)->not->toBeEmpty();

    foreach ($filer as $fil) {
        if ($fil->getExtension() !== 'php') {
            continue;
        }

        $innehåll = $fil->getContents();

        foreach ($namn as $ett) {
            expect($innehåll)->not->toContain("'{$ett}'", sprintf(
                'kategorinamnet "%s" finns i %s',
                $ett,
                $fil->getRelativePathname(),
            ));

            expect($innehåll)->not->toContain("\"{$ett}\"", sprintf(
                'kategorinamnet "%s" finns i %s',
                $ett,
                $fil->getRelativePathname(),
            ));
        }
    }
});

/*
 * Beslut 4: förslaget är ett kort och ingen modal. Det ritas i stället för
 * tomtexten på en tom container, och sidans eget formulär finns kvar oavsett.
 */
it('renderar förslaget som ett kort ovanför trädet', function () {
    $sida = File::get(resource_path('js/pages/Containers/Categories.vue'));
    $kort = File::get(resource_path('js/components/CategoryPresetCard.vue'));
    $data = File::get(resource_path('js/data/categoryPresets.js'));

    expect($sida)->toContain('<CategoryPresetCard')
        ->toContain('showsPreset')
        ->toContain('presetDismissed')
        // Villkoret är att trädet är tomt: efter en applicerad uppsättning är
        // det inte tomt, och kortet försvinner av sig självt (Beslut 4).
        ->toContain('categories.length === 0')
        // Skapa-formuläret ritas oberoende av kortet — "kan tacka nej utan
        // att fastna".
        ->toContain('<CategoryCreateForm');

    // Ingen modal, ingen overlay: ett <section> i sidflödet.
    expect($kort)->toContain('<section')
        ->toContain('presetsFor')
        ->toContain('preset_apply')
        ->toContain('preset_dismiss');

    expect($data)->toContain('export function presetsFor')
        ->toContain('export const categoryPresets');
});

/*
 * Klart när: kategorimallen väljs uttryckligen av användaren och härleds inte
 * ur `kind` (issue 84).
 *
 * Tre påståenden, alla om källfilen och inte om renderingen: kortet får ingen
 * art att slå upp en mall med, det väljer bland uppsättningarna på deras EGET
 * namn, och den uppsättning som ligger i listan är den användaren pekat på —
 * inte den första. Det sista är det som skiljer "ett val" från "ett förval".
 */
it('väljer mallen på användarens val och inte ur containerns art', function () {
    $kort = File::get(resource_path('js/components/CategoryPresetCard.vue'));
    $sida = File::get(resource_path('js/pages/Containers/Categories.vue'));

    // Ingen art in i kortet, och ingen uppslagning på en.
    expect(str_contains($sida, ':kind="container.kind"'))->toBeFalse();
    expect(str_contains($kort, 'props.kind'))->toBeFalse();
    expect(str_contains($kort, 'presetFor('))->toBeFalse();

    // Valet är användarens: en `<select>` över uppsättningarnas namn, och
    // förhandsvisningen ritas ur det valda namnet.
    expect($kort)->toContain('<select')
        ->toContain('chosenName')
        ->toContain('presetsFor(page.props.locale)')
        ->toContain('find((one) => one.name === chosenName.value)')
        // Ingen förvald uppsättning: listan är tom tills hon pekat på en.
        ->toContain("ref('')");
});
