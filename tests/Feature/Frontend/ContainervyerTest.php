<?php

// rott-pa-basen: issue 77b och 83 — ordbyte i prosa (kommentar och testnamn) och en struken rad för den borttagna rutten, ingen ändring av applikationskoden; bas och head delar den.

use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\UsageCounter;
use App\Models\User;
use App\Support\Frontend\ApiErrorTranslator;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutVite;

/*
 * Issue 54 · Containerytan i webben — listan, skapandet och redigeringen.
 * Se App\Http\Controllers\ContainerController,
 * App\Actions\Container\CreateContainer,
 * resources/js/layouts/ContainerLayout.vue,
 * resources/js/layouts/containerSections.js och
 * resources/js/pages/Containers/**.
 *
 * Den här filen prövar SIDORNA och skrivningarna: att rutterna renderar rätt
 * komponent, att urvalet och `can.update` kommer ur samma frågor som `/api`
 * ställer, att en container blir aktiv av skapandet, och att kvotgränsen blir ett
 * FORMULÄRFEL i stället för en rå JSON-kropp (Beslut 4) — det sista är hela
 * skälet till att App\Support\Frontend\ApiErrorTranslator finns.
 *
 * Att `/api/containers` är oförändrat prövas av tests/Feature/Container/**,
 * som ska vara grön utan en enda ändrad förväntan efter utbrytningen i
 * Beslut 3 — det är beviset för att utbrytningen var ren.
 *
 * Hjälparna har prefixet `container` för att inte krocka med de globala
 * hjälparna i tests/Support/Testhjalpare.php och med installning- och
 * sprak-hjälparna i grannfilerna — Pest lägger alla filer i samma namnrymd
 * när hela sviten körs.
 */

/**
 * Ett konto med en medlem i angiven roll och status, plus en container ägd av
 * kontot. Medlemmens locale går att styra, så kvotfelets språk kan prövas.
 *
 * @param  array<string, mixed>  $kontoAttribut
 * @return array{0: Account, 1: User, 2: Container}
 */
function containerKontext(
    string $roll = 'owner',
    ?string $anvandarLocale = null,
    array $kontoAttribut = [],
): array {
    $konto = Account::factory()->create($kontoAttribut);
    $anvandare = User::factory()->create(['locale' => $anvandarLocale]);
    $konto->users()->attach($anvandare, ['role' => $roll]);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

/**
 * En containerbred eller item-bunden container_access-rad för $user.
 */
function containerGrant(Container $container, User $user, string $level, ?Item $item = null): ContainerAccess
{
    return ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item?->id,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => $level,
        'kind' => 'member',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

/*
 * Beslut 1: sex rutter, alla bakom `auth`. En utloggad besökare skickas till
 * inloggningen och når aldrig en kontrollermetod.
 *
 * Raderingen kom med issue 62b § Beslut 4 och är den SJUNDE — den ligger i
 * samma grupp och av samma skäl: raderingsknappen på inställningssidan får
 * inte vara den enda vägen in i kontrollern som en gäst når.
 */
it('skickar en utloggad besökare till inloggningen från alla sex rutterna', function () {
    withoutVite();

    [$konto] = containerKontext();
    $container = Container::factory()->for($konto, 'account')->create();

    get('/containers')->assertRedirect('/login');
    get('/containers/create')->assertRedirect('/login');
    post('/containers', [])->assertRedirect('/login');
    get("/containers/{$container->ulid}/edit")->assertRedirect('/login');
    patch("/containers/{$container->ulid}", [])->assertRedirect('/login');
    delete("/containers/{$container->ulid}")->assertRedirect('/login');
});

it('renderar Containers/Index för en inloggad användare', function () {
    withoutVite();

    [, $anvandare] = containerKontext();

    actingAs($anvandare)
        ->get('/containers')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Containers/Index'));
});

/*
 * Urvalet är samma fråga som API:ets index() ställer —
 * Container::scopeAccessibleBy() — så en container i ett främmande konto utan
 * container_access syns inte, och en container med en giltig access gör det.
 * "Delad med dig" härleds i vyn ur `account`-ULID:n mot `auth.accounts`
 * (Beslut 10), så serverns halva är att `account` bär ÄGARKONTOT.
 */
it('listar egna och delade containers sorterade på namn, och ingenting annat', function () {
    withoutVite();

    // Kontot och medlemmen byggs för hand i stället för med containerKontext():
    // den hjälparen skapar en container, och den hade blivit en tredje rad.
    $eget = Account::factory()->create();
    $anvandare = User::factory()->create();
    $eget->users()->attach($anvandare, ['role' => 'owner']);
    Container::factory()->for($eget, 'account')->create(['name' => 'Zebra']);

    $agare = Account::factory()->create();
    $delad = Container::factory()->for($agare, 'account')->create(['name' => 'Alfa']);
    containerGrant($delad, $anvandare, 'read');

    $frammande = Account::factory()->create();
    Container::factory()->for($frammande, 'account')->create(['name' => 'Främmande']);

    actingAs($anvandare)->get('/containers')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('containers', 2)
        ->where('containers.0.name', 'Alfa')
        ->where('containers.1.name', 'Zebra')

        // Ägarkontots ULID, inte ett eget `shared`-fält: vyn jämför den mot
        // `auth.accounts` och ser att Alfa är någon annans.
        ->where('containers.0.account', $agare->ulid)
        ->where('containers.1.account', $eget->ulid)

        ->where('containers', fn ($containers) => collect($containers)->doesntContain('name', 'Främmande'))
    );
});

it('märker en delad container som delad och en egen som egen i vyn', function () {
    // Härledningen bor i vyn (Beslut 10), och det enda en serverhalva kan
    // bevisa är att den läser rätt fält: `account` mot `auth.accounts`.
    $vy = File::get(resource_path('js/pages/Containers/Index.vue'));

    expect($vy)->toContain('auth?.accounts');
    expect($vy)->toContain('container.account');
    expect($vy)->toContain("t('container.index.shared')");
});

/*
 * Beslut 8 och issue 84 · [[ADR-0036 Containerns art]]: `kind` är
 * presentation och ett FRITT fält. Propen bär de arter ANVÄNDAREN redan
 * använt — underlaget för autocomplete, samma mönster som leverantörsfältet i
 * [[ADR-0016 Kostnadsregistrering]] — och aldrig en fast mängd. Den är
 * sorterad och utan dubbletter.
 *
 * **Mängden är användarens containers, inte hennes konton.** En container som
 * delats DIREKT med henne ligger utanför "konton hon är med i" men innanför
 * hennes containerlista ([[Konton och åtkomst]] § container_access), och
 * navigeringen grupperar på `kind` över just den listan ([[ADR-0036
 * Containerns art]]) — föreslog vi ur en snävare mängd skulle autocomplete
 * själv producera de stavningsvarianter den finns för att förhindra. Ett
 * annat kontos container, som hon varken är medlem i eller har en access
 * till, hör däremot inte hit.
 *
 * SAMMA lista går till båda formulären, ur samma servermetod: en metod, en
 * prop.
 */
it('skickar användarens redan använda arter till båda formulären', function () {
    withoutVite();

    [$konto, $anvandare, $container] = containerKontext();

    // Kontextens container bär fabrikens ord — byt den så listan blir läsbar.
    $container->update(['kind' => 'Segelbåt']);

    Container::factory()->for($konto, 'account')->create(['kind' => 'Husvagn']);
    Container::factory()->for($konto, 'account')->create(['kind' => 'Husvagn']);

    // En container utan art ger inget förslag — fältet är frivilligt.
    Container::factory()->for($konto, 'account')->create(['kind' => null]);

    // Delad direkt med henne: i hennes containerlista, men inte i hennes konto.
    $delad = Container::factory()->for(Account::factory()->create(), 'account')->create(['kind' => 'Delad art']);
    containerGrant($delad, $anvandare, 'read');

    // Ett annat kontos container, utan access: varken hennes lista eller
    // hennes förslag.
    $annat = Account::factory()->create();
    Container::factory()->for($annat, 'account')->create(['kind' => 'Främmande art']);

    actingAs($anvandare)->get('/containers/create')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Containers/Create')
        ->where('kinds', ['Delad art', 'Husvagn', 'Segelbåt'])
    );

    actingAs($anvandare)->get("/containers/{$container->ulid}/edit")->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Containers/Edit')
        ->where('kinds', ['Delad art', 'Husvagn', 'Segelbåt'])
        ->where('container.ulid', $container->ulid)
        ->where('container.name', $container->name)
        ->where('container.kind', 'Segelbåt')
        ->where('container.account', $konto->ulid)
    );

    expect(File::get(resource_path('js/layouts/containerSections.js')))
        ->toContain('containerSections');
    expect(File::get(resource_path('js/layouts/ContainerLayout.vue')))
        ->toContain('v-for="section in containerSections"');
});

/*
 * Klart när: en container kan skapas UTAN art (issue 84).
 *
 * Propen ovan är ett förslag; fältet självt är fritt. En ny användare som ännu
 * inte vet vad hennes container är ska kunna lämna rutan tom — ingen förvald
 * art ärvs, och `null` är vad som sparas. Kolumnen är nullbar, för en tom
 * sträng är just den sentinel [[ADR-0004 Fria taggar och kategorier]] vill
 * undvika.
 */
it('skapar en container utan art', function () {
    withoutVite();

    [$konto, $anvandare] = containerKontext();

    from('/containers/create')->actingAs($anvandare)->post('/containers', [
        'name' => 'Utan art',
        'account' => $konto->ulid,
    ])->assertSessionHasNoErrors();

    expect(Container::query()->where('name', 'Utan art')->firstOrFail()->kind)->toBeNull();
});

/*
 * Klart när: en container kan sparas med en art utanför den gamla listan.
 *
 * `Segelbåt` är lika giltig som `boat`: valideringen är längd och format,
 * aldrig medlemskap i en mängd ([[ADR-0036 Containerns art]]).
 */
it('tar emot en egenskriven art', function () {
    withoutVite();

    [$konto, $anvandare] = containerKontext();

    from('/containers/create')->actingAs($anvandare)->post('/containers', [
        'name' => 'Egenskriven',
        'kind' => 'Segelbåt',
        'account' => $konto->ulid,
    ])->assertSessionHasNoErrors();

    expect(Container::query()->where('name', 'Egenskriven')->firstOrFail()->kind)->toBe('Segelbåt');
});

/*
 * Klart när: en container med en egenskriven art visas med arten ORDAGRANT i
 * containerlistan, och aldrig som en översättningsnyckel (issue 84).
 *
 * `t()` returnerar nyckeln själv när uppslaget misslyckas, så den gamla raden
 * `t('container.kind.' + värdet)` hade skrivit `container.kind.Segelbåt` på
 * skärmen. Provet är tvådelat: värdet går ORÖRAT genom API-lagret och står
 * ordagrant i listans prop, och vyn bygger ingen nyckel ur det — de fem
 * nycklarna under `container.kind` finns inte kvar i `lang/`.
 */
it('skriver ut arten ordagrant i listan', function () {
    withoutVite();

    [, $anvandare, $container] = containerKontext();
    $container->refresh()->update(['kind' => 'Segelbåt']);

    actingAs($anvandare)->get('/containers')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Containers/Index')
        ->where('containers.0.kind', 'Segelbåt')
    );

    $index = File::get(resource_path('js/pages/Containers/Index.vue'));

    // Ingen nyckel byggs ur värdet — mönstret `t(`container.kind...`)` är
    // precis det som gav `container.kind.Segelbåt` på skärmen.
    expect($index)->toContain('{{ container.kind }}');
    expect(str_contains($index, 't(`container.kind'))->toBeFalse();

    // En container utan art visar ingen art alls: elementet döljs i stället för
    // att ritas tomt. Närvarokontrollen frågar om fältet är SATT och aldrig
    // VILKET värde det bär — den grenar inte på arten.
    expect($index)->toContain('v-if="container.kind"');

    expect(array_key_exists('kind', trans('ui.container', [], 'en')))->toBeFalse();
});

/*
 * Ett för långt värde avvisas: `max:40` är kolumnens bredd, och regeln är
 * densamma i både skapa- och redigeringsformuläret (issue 84).
 */
it('avvisar en art längre än kolumnen', function () {
    withoutVite();

    [$konto, $anvandare, $container] = containerKontext();

    from('/containers/create')->actingAs($anvandare)->post('/containers', [
        'name' => 'För lång',
        'kind' => str_repeat('a', 41),
        'account' => $konto->ulid,
    ])->assertSessionHasErrors('kind');

    expect(Container::query()->where('name', 'För lång')->exists())->toBeFalse();

    from("/containers/{$container->ulid}/edit")->actingAs($anvandare)->patch("/containers/{$container->ulid}", [
        'kind' => str_repeat('a', 41),
    ])->assertSessionHasErrors('kind');
});

/*
 * Beslut 5: ägarkontot väljs i formuläret. Servern har inget "aktivt konto"
 * (issue 8 § Beslut 8), och kontolistan kommer ur den delade propen
 * `auth.accounts` — ingen egen fråga för samma lista.
 */
it('skickar ingen egen kontolista till skapaformuläret', function () {
    withoutVite();

    [, $anvandare] = containerKontext();

    actingAs($anvandare)->get('/containers/create')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('auth.accounts', 1)
        ->missing('accounts')
    );

    // Ett enda konto: förvalt och visat som text. Flera: en väljare utan
    // förval — `singleAccount` är hela grenen i vyn.
    expect(File::get(resource_path('js/pages/Containers/Create.vue')))
        ->toContain('singleAccount');
});

/*
 * Beslut 3 och 5: POST skapar med name, kind och det valda kontot, gör den
 * nya containern aktiv och ökar ägarkontots räknare med ett — allt genom samma
 * action som /api anropar.
 */
it('skapar en container med namn, typ och konto och gör den aktiv', function () {
    withoutVite();

    [$konto, $anvandare] = containerKontext();

    $svar = from('/containers/create')
        ->actingAs($anvandare)
        ->post('/containers', [
            'name' => 'Vindil',
            'kind' => 'boat',
            'account' => $konto->ulid,
        ])
        ->assertSessionHas('status', 'container-created');

    $skapad = Container::query()->where('name', 'Vindil')->firstOrFail();

    // Issue 56b § Beslut 5: målet är den nya containerns kategoriyta, inte
    // listan — den som just skapat en container möts av förslaget.
    $svar->assertRedirect("/containers/{$skapad->ulid}/categories");

    expect($skapad->kind)->toBe('boat');
    expect($skapad->account_id)->toBe($konto->id);

    // Räknaren på ÄGARKONTOT, i samma transaktion som raden (issue 26a).
    // Utgångsläget är 0: kontextens container är fabriksgjord och räknas
    // aldrig — räknaren hålls i takt av skrivvägarna, inte av databasen.
    expect((int) UsageCounter::query()->where('account_id', $konto->id)->value('container_count'))->toBe(1);

    // Den som just skapat en container vill arbeta i den (Beslut 6).
    actingAs($anvandare)->get('/containers')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('activeContainer', $skapad->ulid)
    );
});

/*
 * Ett konto användaren inte är medlem i: 403 ur ContainerPolicy::create(),
 * och ingen rad skapas. Behörighet FÖRE kvot (issue 27 § Beslut 3) — svaret
 * ska inte avslöja hur kontot ligger till.
 */
it('nekar skapande åt ett konto användaren inte är medlem i och skapar ingen rad', function () {
    withoutVite();

    [, $anvandare] = containerKontext();
    $frammande = Account::factory()->create();

    $svar = from('/containers/create')->actingAs($anvandare)->post('/containers', [
        'name' => 'Smygare',
        'kind' => 'boat',
        'account' => $frammande->ulid,
    ]);

    $svar->assertForbidden();

    expect(Container::query()->where('name', 'Smygare')->exists())->toBeFalse();
});

/*
 * Regel 4 i [[Konton och åtkomst]] § Behörighetsregler: ett fryst konto
 * nekas allt skrivande oavsett behörighet. Även för sin `owner`.
 */
it('nekar skapande i ett read_only-konto', function () {
    withoutVite();

    [$konto, $anvandare] = containerKontext('owner', null, ['status' => 'read_only']);

    from('/containers/create')
        ->actingAs($anvandare)
        ->post('/containers', [
            'name' => 'Fryst',
            'kind' => 'boat',
            'account' => $konto->ulid,
        ])
        ->assertForbidden();

    expect(Container::query()->where('name', 'Fryst')->exists())->toBeFalse();
});

/*
 * Beslut 4 — kärnan i issuen. Containertaket kastar ApiException, som
 * implementerar Responsable och renderar JSON var den än kastas. På webben
 * ska användaren i stället få formuläret tillbaka med en läsbar mening under
 * `errors.quota`, på sitt eget språk. Ingen JSON, och ingen rå felkod.
 */
it('ger ett läsbart kvotfel i stället för JSON när containertaket slår i', function () {
    withoutVite();

    // Gratisplanen har `containers => 1` (issue 27), så den andra containern
    // fyller kontot.
    [$konto, $anvandare] = containerKontext('owner', 'sv_SE');

    $förstaSvar = from('/containers/create')
        ->actingAs($anvandare)
        ->post('/containers', ['name' => 'Första', 'kind' => 'boat', 'account' => $konto->ulid]);

    $första = Container::query()->where('name', 'Första')->firstOrFail();

    // Den första containern skapas och landar på sin kategoriyta (issue 56b
    // § Beslut 5); den andra är den som slår i taket.
    $förstaSvar->assertRedirect("/containers/{$första->ulid}/categories");

    $svar = from('/containers/create')->actingAs($anvandare)->post('/containers', [
        'name' => 'Andra',
        'kind' => 'boat',
        'account' => $konto->ulid,
    ]);

    $svar->assertRedirect('/containers/create');
    $svar->assertSessionHasErrors('quota');

    $mening = session('errors')->get('quota')[0];

    expect($mening)->toBe(trans('ui.error.quota.containers_exceeded', ['limit' => 1, 'used' => 1], 'en'));
    expect($mening)->not->toBe('quota.containers_exceeded');
    expect($svar->headers->get('content-type'))->toContain('text/html');

    expect(Container::query()->where('name', 'Andra')->exists())->toBeFalse();
});

it('formulerar kvotfelet på engelska för en engelsktalande användare', function () {
    withoutVite();

    [$konto, $anvandare] = containerKontext('owner', 'en_GB');

    $firstSvar = from('/containers/create')
        ->actingAs($anvandare)
        ->post('/containers', ['name' => 'First', 'kind' => 'boat', 'account' => $konto->ulid]);

    $first = Container::query()->where('name', 'First')->firstOrFail();

    $firstSvar->assertRedirect("/containers/{$first->ulid}/categories");

    from('/containers/create')->actingAs($anvandare)->post('/containers', [
        'name' => 'Second',
        'kind' => 'boat',
        'account' => $konto->ulid,
    ])->assertSessionHasErrors('quota');

    expect(session('errors')->get('quota')[0])
        ->toBe(trans('ui.error.quota.containers_exceeded', ['limit' => 1, 'used' => 1], 'en'));
});

/*
 * Reserven: en kod utan nyckel ska bli en begriplig mening, aldrig en rå kod
 * på skärmen och aldrig ett undantag i undantagshanteringen.
 */
it('ger den generiska meningen för en okänd felkod i stället för att kasta', function () {
    $translator = app(ApiErrorTranslator::class);

    expect($translator->message(ApiException::make('finns.inte', [], 403)))
        ->toBe(trans('ui.error.generic'));

    expect($translator->message(ApiException::make('finns.inte', [], 403)))
        ->not->toBe('finns.inte');

    // En känd kod ger meningen med `data` som ersättningar.
    expect($translator->message(ApiException::make('quota.containers_exceeded', ['limit' => 3, 'used' => 3], 403)))
        ->toBe(trans('ui.error.quota.containers_exceeded', ['limit' => 3, 'used' => 3]));
});

/*
 * Namnet är det enda fältet som måste vara ifyllt. En art är det inte längre
 * (issue 84), och en art utanför den gamla listan är inget fel — den sidan av
 * gamla provet bor numera i `skapar en container utan art` och
 * `tar emot en egenskriven art` ovan.
 */
it('avvisar ett tomt namn', function () {
    withoutVite();

    [$konto, $anvandare] = containerKontext();

    $svar = from('/containers/create')->actingAs($anvandare)->post('/containers', [
        'name' => '',
        'kind' => 'boat',
        'account' => $konto->ulid,
    ]);

    $svar->assertRedirect('/containers/create');
    $svar->assertSessionHasErrors('name');

    // Bara kontextens container finns kvar.
    expect(Container::query()->count())->toBe(1);
});

/*
 * Redigeringen: en medlem i ägarkontot skriver `name` och `kind` genom
 * samma UpdateContainerRequest som /api använder. `account` finns inte i
 * dess regler — ett klientskickat sådant fält ändrar aldrig ägaren.
 */
it('sparar namn och typ för en medlem i ägarkontot', function () {
    withoutVite();

    [$konto, $anvandare, $container] = containerKontext();
    $annatKonto = Account::factory()->create();

    from("/containers/{$container->ulid}/edit")
        ->actingAs($anvandare)
        ->patch("/containers/{$container->ulid}", [
            'name' => 'Nytt namn',
            'kind' => 'caravan',
            'account' => $annatKonto->ulid,
        ])
        ->assertRedirect("/containers/{$container->ulid}/edit")
        ->assertSessionHas('status', 'container-updated');

    $container->refresh();

    expect($container->name)->toBe('Nytt namn');
    expect($container->kind)->toBe('caravan');
    expect($container->account_id)->toBe($konto->id);
});

/*
 * Beslut 9: `can.update` räknas med policyn per rad, och rutterna
 * auktoriserar ändå. En containerbred `write` får redigera; en `read` nekas
 * och får ingen flagga — alltså ingen länk i listan.
 */
it('låter en containerbred write-access redigera men nekar en read', function () {
    withoutVite();

    [, , $container] = containerKontext();

    $skrivare = User::factory()->create();
    containerGrant($container, $skrivare, 'write');

    $lasare = User::factory()->create();
    containerGrant($container, $lasare, 'read');

    actingAs($skrivare)
        ->get("/containers/{$container->ulid}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('container.ulid', $container->ulid));

    actingAs($lasare)->get("/containers/{$container->ulid}/edit")->assertForbidden();
    actingAs($lasare)->patch("/containers/{$container->ulid}", ['name' => 'Tjuvnamn'])->assertForbidden();

    expect($container->fresh()->name)->toBe($container->name);

    actingAs($skrivare)->get('/containers')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('containers.0.can.update', true)
    );

    actingAs($lasare)->get('/containers')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('containers.0.can.update', false)
    );
});

/*
 * Issue 70: ContainerPolicy::update() kräver en CONTAINERBRED rad. En
 * itemåtkomst på `write` får inte byta namn på containern — annars hade en
 * itemgrant blivit en ContainerPolicy::create() i smyg.
 */
it('nekar en itemåtkomst på write att redigera containern', function () {
    withoutVite();

    [, , $container] = containerKontext();

    $mottagare = User::factory()->create();
    $item = Item::factory()->for($container, 'container')->create();
    containerGrant($container, $mottagare, 'write', $item);

    actingAs($mottagare)->get("/containers/{$container->ulid}/edit")->assertForbidden();

    actingAs($mottagare)->get('/containers')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('containers', 1)
        ->where('containers.0.can.update', false)
    );
});

/*
 * Layouten är skalet fem issues fyller (Beslut 7), och redigeringssidan är
 * den första som bor i den. Sidpropen `container` är kontraktet.
 */
it('renderar redigeringssidan i ContainerLayout med containerns namn', function () {
    withoutVite();

    [, $anvandare, $container] = containerKontext();

    actingAs($anvandare)
        ->get("/containers/{$container->ulid}/edit")
        // `can.delete` kom med issue 62b § Beslut 4 och bor i samma prop som
        // sidan redan bar: flaggan är presentation, och raderingsknappen ritas
        // bara när den är sann. Grinden är `containers.destroy`.
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Edit')
            ->where('can.delete', true)
        );

    $vy = File::get(resource_path('js/pages/Containers/Edit.vue'));
    $layout = File::get(resource_path('js/layouts/ContainerLayout.vue'));

    expect($vy)->toContain('<ContainerLayout :container="container">');
    expect($layout)->toContain('container.name');
});

/*
 * Nycklarna finns och vyn läser dem. En nyckel som finns men
 * inte används är en text ingen ser, och en svensk sträng i en .vue-fil blir
 * aldrig engelsk — SprakTest fäller den bredare varianten, den här kontrollerar
 * att just de här texterna kom med.
 */
it('har containerytans texter och läser dem ur lang/', function () {
    $nycklar = [
        'nav.containers',
        'container.index.heading',
        'container.index.create',
        'container.index.empty',
        'container.index.shared',
        'container.index.active',
        'container.index.edit',
        'container.create.heading',
        'container.create.account',
        'container.create.account_choose',
        'container.create.description',
        'container.create.submit',
        'container.edit.heading',
        'container.edit.description',
        'container.edit.submit',
        'container.destroy.action',
        'container.nav.settings',
        'error.generic',
        'error.quota.containers_exceeded',
        'flash.container-created',
        'flash.container-updated',
        'flash.container-trashed',
    ];

    foreach ($nycklar as $nyckel) {
        $mening = trans("ui.{$nyckel}", [], 'en');

        expect($mening)->not->toBe("ui.{$nyckel}", "{$nyckel} saknas");
        expect(trim($mening))->not->toBe('');
    }

    // Ingen etikett per art sedan issue 84: fältet är fritt, och vyn skriver
    // ut värdet användaren matat in ([[ADR-0036 Containerns art]]).
    expect(trans('ui.container', [], 'en'))->not->toHaveKey('kind');

    // Kvotmeningen bär gränsen och värdet, och är en mening och inte nyckeln.
    expect(trans('ui.error.quota.containers_exceeded', ['used' => 1, 'limit' => 1], 'en'))
        ->toBe('The account has reached its limit for the number of containers (1 of 1).');

    $index = File::get(resource_path('js/pages/Containers/Index.vue'));

    foreach (['container.index.create', 'container.index.shared', 'container.index.active'] as $nyckel) {
        expect($index)->toContain($nyckel);
    }

    expect(File::get(resource_path('js/pages/Containers/Create.vue')))->toContain('container.create.submit');
    expect(File::get(resource_path('js/pages/Containers/Edit.vue')))->toContain('container.edit.submit');
});

/*
 * Issue 88 · Containern får en beskrivning. Se [[ADR-0039 Containerns
 * översikt]] § Beslut.
 *
 * ETT fritextfält, nullbart och frivilligt, i BÅDE skapa- och redigeravyn.
 * Skapandevägen går genom App\Actions\Container\CreateContainer, och
 * parametern lades SIST med ett förval — ingen befintlig anropare rördes.
 *
 * API:ets halva prövas i tests/Feature/Container/ContainerCrudTest.php.
 */

/*
 * Klart när: en beskrivning som anges vid skapandet sparas — via webbens
 * `store()` (issue 88).
 *
 * Värdet är mockupens egen underrubrik, med punkten kvar, och det sparas
 * ORDAGRANT: ingen kod plockar isär fältet i modell och årtal, och hade den
 * gjort det hade raden kommit tillbaka i delar.
 */
it('skapar en container med en beskrivning', function () {
    withoutVite();

    [$konto, $anvandare] = containerKontext();

    from('/containers/create')->actingAs($anvandare)->post('/containers', [
        'name' => 'Vindil',
        'description' => 'Malö 116 • 1984',
        'account' => $konto->ulid,
    ])->assertSessionHasNoErrors();

    expect(Container::query()->where('name', 'Vindil')->firstOrFail()->description)
        ->toBe('Malö 116 • 1984');
});

/*
 * Klart när: en container kan skapas och sparas UTAN beskrivning (issue 88).
 *
 * Fältet är frivilligt i BÅDA vyerna — att kräva en beskrivning vid skapandet
 * är att ställa en fråga användaren ännu inte kan svara på, samma resonemang
 * som gjorde `kind` frivillig i issue 84. Det som sparas är `null`, och
 * skapavyns ruta är tom och inte förifylld med något.
 */
it('skapar en container utan beskrivning', function () {
    withoutVite();

    [$konto, $anvandare] = containerKontext();

    from('/containers/create')->actingAs($anvandare)->post('/containers', [
        'name' => 'Utan beskrivning',
        'account' => $konto->ulid,
    ])->assertSessionHasNoErrors();

    expect(Container::query()->where('name', 'Utan beskrivning')->firstOrFail()->description)
        ->toBeNull();
});

/*
 * Klart när: `ContainerResource` bär fältet och alltid som `null` när det
 * saknas, aldrig utelämnat (issue 88 · issue 8 § Beslut 7).
 *
 * Redigeravyns sidprop är samma resurs som `/api` svarar med, så provet
 * gäller båda ytorna: `has()` fäller en nyckel som saknas, och `where()`
 * fäller ett värde som är fel.
 */
it('bär beskrivningen i redigeravyns sidprop, som null när den saknas', function () {
    withoutVite();

    [, $anvandare, $container] = containerKontext();

    actingAs($anvandare)->get("/containers/{$container->ulid}/edit")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Edit')
            ->has('container.description')
            ->where('container.description', null)
        );

    $container->update(['description' => 'Malö 116 • 1984']);

    actingAs($anvandare)->get("/containers/{$container->ulid}/edit")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('container.description', 'Malö 116 • 1984')
        );
});

/*
 * Klart när: en beskrivning kan sättas och ändras i redigeravyn (issue 88).
 */
it('sätter och ändrar beskrivningen i redigeravyn', function () {
    withoutVite();

    [, $anvandare, $container] = containerKontext();

    from("/containers/{$container->ulid}/edit")
        ->actingAs($anvandare)
        ->patch("/containers/{$container->ulid}", ['description' => 'Malö 116 • 1984'])
        ->assertRedirect("/containers/{$container->ulid}/edit")
        ->assertSessionHas('status', 'container-updated');

    expect($container->fresh()->description)->toBe('Malö 116 • 1984');

    from("/containers/{$container->ulid}/edit")
        ->actingAs($anvandare)
        ->patch("/containers/{$container->ulid}", ['description' => 'Såld 2019.'])
        ->assertSessionHasNoErrors();

    expect($container->fresh()->description)->toBe('Såld 2019.');
});

/*
 * Klart när: en beskrivning kan TÖMMAS i redigeravyn (issue 88).
 *
 * En tom ruta är ett giltigt svar — fältet är frivilligt hela vägen, och
 * `null` är vad som sparas. Att tömma det är inte samma sak som att låta
 * nyckeln vara: `sometimes` skiljer de två åt, och den halvan prövas i
 * ContainerCrudTest.
 */
it('tömmer beskrivningen i redigeravyn', function () {
    withoutVite();

    [, $anvandare, $container] = containerKontext();
    $container->update(['description' => 'Malö 116 • 1984']);

    from("/containers/{$container->ulid}/edit")
        ->actingAs($anvandare)
        ->patch("/containers/{$container->ulid}", ['description' => ''])
        ->assertSessionHasNoErrors();

    expect($container->fresh()->description)->toBeNull();
});

/*
 * Fältet finns i BÅDA vyerna och läses ur `lang/` (issue 88).
 *
 * En nyckel som finns men inte används är en text ingen ser. Provet är
 * tvådelat: nycklarna finns i `lang/` (prövat i testet ovan) och vyerna
 * binder sina rutor till `form.description` med sin egen nyckel.
 */
it('har beskrivningsfältet i både skapa- och redigeravyn', function () {
    $skapa = File::get(resource_path('js/pages/Containers/Create.vue'));
    $redigera = File::get(resource_path('js/pages/Containers/Edit.vue'));

    expect($skapa)->toContain("t('container.create.description')");
    expect($skapa)->toContain('v-model="form.description"');
    expect($redigera)->toContain("t('container.edit.description')");
    expect($redigera)->toContain('v-model="form.description"');

    // Redigeravyn fyller rutan ur resursen och faller tillbaka på en tom
    // sträng: en container utan beskrivning bär `null`, och rutan ska vara
    // tom — inte visa ordet "null".
    expect($redigera)->toContain('props.container.description ??');
});
