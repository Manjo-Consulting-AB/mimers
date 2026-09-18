<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\User;
use App\Support\Frontend\ActiveContainer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\put;
use function Pest\Laravel\withoutVite;

/*
 * Issue 83 · Containerkontexten sätts av att containern öppnas.
 * Se App\Support\Frontend\ActiveContainer,
 * App\Http\Middleware\HandleInertiaRequests::setActiveContainer() och
 * App\Http\Controllers\ContainerController::store().
 *
 * Klassens mekanism är issue 51:s (§ Beslut 4) och ändras inte här —
 * `forUser()` prövar fortfarande åtkomsten vid varje läsning, och `set()`
 * glömmer fortfarande nyckeln i stället för att skriva den. Det som ändras är
 * VEM som anropar `set()` i vardagen: den som öppnar en container, inte den
 * som trycker på en knapp. `PUT /containers/{container}/active` finns inte
 * längre, och filen prövar att den är borta, att öppnandet sätter nyckeln, och
 * att en container utan åtkomst lämnar kontexten orörd.
 */

/**
 * Ett konto med en medlem och en container i kontot.
 *
 * @return array{0: User, 1: Container}
 */
function aktivContainerKontext(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$anvandare, $container];
}

/*
 * Rutten är borta, och ingenting svarar på sökvägen. Det är hela skillnaden
 * mot issue 54: kontexten har ingen egen ingång, den sätts av att containern
 * öppnas.
 */
it('har ingen rutt som sätter den aktiva containern', function () {
    withoutVite();

    [$anvandare, $container] = aktivContainerKontext();

    expect(Route::has('containers.active'))->toBeFalse();

    // 405 och inte 404: fallback-rutten i routes/web.php svarar på GET, så en
    // PUT mot en sökväg ingen rutt bär blir "metoden finns inte här" ur
    // routern. Att svaret inte längre är en omdirigering är det som prövas.
    actingAs($anvandare)->put("/containers/{$container->ulid}/active")->assertMethodNotAllowed();

    expect(session(ActiveContainer::SESSION_KEY))->toBeNull();
});

/*
 * Huvudregeln: att öppna en container gör den till kontext. Sessionen bär
 * ULID:t, och den delade propen bär samma ULID på SJÄLVA sidan — `share()`
 * läser sessionen efter att kontrollern satt den.
 */
it('sätter sessionsnyckeln när en container öppnas, och delar dess ulid på sidan', function () {
    withoutVite();

    [$anvandare, $container] = aktivContainerKontext();

    actingAs($anvandare)
        ->get("/containers/{$container->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('activeContainer', $container->ulid)
        );

    expect(session(ActiveContainer::SESSION_KEY))->toBe($container->ulid);
});

/*
 * Samma väg för en delad container: den som når den via en `read`-grant
 * öppnar den och får den som kontext — samma grind (`view`) som listan
 * ställer för att raden alls ska synas.
 */
it('gör en delad container till kontext för en läsare', function () {
    withoutVite();

    [, $container] = aktivContainerKontext();

    $lasare = User::factory()->create(['locale' => 'sv_SE']);
    beviljaAccess($container, $lasare, 'read', 'member');

    actingAs($lasare)->get("/containers/{$container->ulid}")->assertOk();

    expect(session(ActiveContainer::SESSION_KEY))->toBe($container->ulid);
});

/*
 * Den delade propen bär ULID:t och INGENTING annat — issue 51 § Beslut 4
 * satte formen med flit, och den delas på varje webbanrop. En sida som
 * behöver containerns namn får det som sin egen `container`-prop, se
 * resources/js/layouts/ContainerLayout.vue.
 */
it('utökar inte den delade propen med containerns namn eller typ', function () {
    withoutVite();

    [$anvandare, $container] = aktivContainerKontext();

    actingAs($anvandare)->get("/containers/{$container->ulid}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('activeContainer', $container->ulid)
    );
});

/*
 * Klart när: att öppna en container användaren saknar åtkomst till lämnar
 * kontexten orörd. Middlewaren prövar `view` innan den rör sessionen, så
 * `set()` anropas aldrig för en container användaren inte når — och svaret
 * blir 403. Både den som saknar kontext och den som redan har en behåller
 * sitt läge.
 */
it('lämnar kontexten orörd när en container utan åtkomst öppnas', function () {
    withoutVite();

    [, $container] = aktivContainerKontext();

    $utomstaende = User::factory()->create(['locale' => 'sv_SE']);

    actingAs($utomstaende)->get("/containers/{$container->ulid}")->assertForbidden();

    expect(session(ActiveContainer::SESSION_KEY))->toBeNull();

    // Och den som redan hade en öppnad container behåller den — ett nekat
    // öppnande rör inte sessionen.
    [$anvandare, $egen] = aktivContainerKontext();

    actingAs($anvandare)->get("/containers/{$egen->ulid}")->assertOk();

    actingAs($anvandare)->get("/containers/{$container->ulid}")->assertForbidden();

    expect(session(ActiveContainer::SESSION_KEY))->toBe($egen->ulid);
});

/*
 * Den senast öppnade containern vinner: kontexten är ETT värde, och att gå
 * från den ena containern till den andra byter det.
 */
it('bär ulid:t för den senast öppnade containern', function () {
    withoutVite();

    [$anvandare, $forsta] = aktivContainerKontext();
    $andra = Container::factory()->for($forsta->account, 'account')->create();

    actingAs($anvandare)->get("/containers/{$forsta->ulid}")->assertOk();
    expect(session(ActiveContainer::SESSION_KEY))->toBe($forsta->ulid);

    actingAs($anvandare)
        ->get("/containers/{$andra->ulid}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('activeContainer', $andra->ulid)
        );

    expect(session(ActiveContainer::SESSION_KEY))->toBe($andra->ulid);
});

/*
 * Issue 51 § Beslut 4, oförändrat: en ULID som inte längre är åtkomlig glöms
 * av sig själv vid NÄSTA läsning. Ingen krasch — sessionen är användarens,
 * inte systemets. Här bevisat genom webbens egen väg: öppna, återkalla
 * delningen, läs en sida.
 */
it('glömmer den aktiva containern när åtkomsten återkallas', function () {
    withoutVite();

    [, $container] = aktivContainerKontext();

    $mottagare = User::factory()->create(['locale' => 'sv_SE']);
    $access = beviljaAccess($container, $mottagare, 'read', 'member');

    actingAs($mottagare)->get("/containers/{$container->ulid}")->assertOk();

    expect(session(ActiveContainer::SESSION_KEY))->toBe($container->ulid);

    $access->revoked_at = now();
    $access->save();

    actingAs($mottagare)->get('/containers')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('activeContainer', null)
    );

    expect(session(ActiveContainer::SESSION_KEY))->toBeNull();
});

/*
 * Kontexten sätts fortfarande vid skapad container (Beslut 6 i issue 54) —
 * den nya containern är den användaren vill arbeta i, oavsett att hon inte
 * hunnit öppna den.
 */
it('behåller skapandet som en väg in i kontexten', function () {
    withoutVite();

    [$anvandare] = aktivContainerKontext();
    $konto = $anvandare->accounts->first();

    actingAs($anvandare)->post('/containers', [
        'name' => 'Vindil',
        'kind' => 'boat',
        'account' => $konto->ulid,
    ]);

    $skapad = Container::query()->where('name', 'Vindil')->firstOrFail();

    expect(session(ActiveContainer::SESSION_KEY))->toBe($skapad->ulid);
});

/*
 * Och ägarbytet: mottagaren har just FÅTT containern och landar i den — samma
 * väg in i kontexten som efter en antagen inbjudan (55b § Beslut 4), orörd av
 * issue 83. Den antagna inbjudan prövas i
 * tests/Feature/Frontend/InbjudanMottagareTest.php, som redan bär raden.
 */
it('behåller ägarbytet som en väg in i kontexten', function () {
    withoutVite();

    [, $container] = aktivContainerKontext();

    $mottagare = User::factory()->create(['locale' => 'sv_SE']);
    $mottagarkonto = Account::factory()->create();
    $mottagarkonto->users()->attach($mottagare, ['role' => 'owner']);

    $rad = skapaÄgarbyteRad($container, [
        'to_account_id' => $mottagarkonto->id,
        'to_email' => null,
    ]);

    actingAs($mottagare)
        ->post("/transfers/{$rad->ulid}/accept")
        ->assertRedirect('/containers');

    expect(session(ActiveContainer::SESSION_KEY))->toBe($container->ulid);
});

/*
 * Listan markerar den aktiva raden med `aria-current` och en synlig etikett,
 * och har ingen knapp som sätter kontexten (issue 83). Vyerna ligger utanför
 * serverns räckvidd — det som går att bevisa här är att vyn läser rätt prop
 * och att knappen är borta.
 */
it('markerar den aktiva raden i listan och har ingen knapp som sätter kontexten', function () {
    $vy = File::get(resource_path('js/pages/Containers/Index.vue'));

    expect($vy)->toContain('aria-current="true"');
    expect($vy)->toContain("t('container.index.active')");
    expect($vy)->toContain('activeContainer');

    // Ingen knapp, ingen rutt till den: sökvägen och textnyckeln är borta ur
    // vyn, och nyckeln `make_active` läses inte längre av någon.
    expect($vy)->not->toContain('/active');
    expect($vy)->not->toContain('make_active');
});
