<?php

use App\Models\Account;
use App\Models\CalendarFeed;
use App\Models\Container;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutVite;

/*
 * Issue 65b · Pärmens kalenderlänk — ytan som skapar, visar och återkallar de
 * hemliga ICS-adresserna. Se
 * App\Http\Controllers\CalendarFeedController,
 * resources/js/pages/Containers/CalendarFeed.vue,
 * resources/js/components/CalendarFeedRow.vue och
 * resources/js/components/SecretOnce.vue.
 *
 * Backendflödet i sig prövas av tests/Feature/Notis/KalenderfeedTest.php (36a)
 * och tests/Feature/Notis/IcsFeedTest.php (36b) — de filerna äger `/api` och
 * själva feeden. Rutten `/kalender/{token}.ics` rörs inte av den här issuen
 * och prövas inte här. Den här filen prövar SIDAN ovanpå registret: att
 * adressen visas en gång och aldrig igen, att den aldrig ligger i en lista
 * eller i en `<a href>`, att återkallandet är idempotent, och att grinden är
 * `view` på alla tre rutterna.
 *
 * Att `/api/containers/{container}/calendar-feeds` svarar som förut prövas av
 * filerna ovan, som är gröna utan en enda ändrad förväntan. En ny formulering
 * av samma sak här hade bevisat noll.
 *
 * "Klart när" i issuen motsvaras var sitt test nedan, med undantag för
 * "ingen svensk sträng står kvar i en .vue-fil; varje ny nyckel finns på sv
 * och en" — den vaktas av tests/Feature/Frontend/SprakTest.php, som läser
 * varje fil under resources/js/ och jämför de två språkfilerna nyckel för
 * nyckel. Den här issuen lägger inga strängar i Vue-lagret och inga nycklar
 * på bara ett språk, så de två testerna är gröna utan ändring.
 *
 * Hjälparna har prefixet `feedvy` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs, och tests/Feature/Notis/KalenderfeedTest.php
 * har redan `kalenderfeedRad` och `kalenderfeedUrlToken`.
 */

/**
 * Ett konto med en ägare och en pärm ägd av kontot.
 *
 * @param  array<string, mixed>  $kontoAttribut
 * @return array{0: Account, 1: User, 2: Container}
 */
function feedvyKontext(array $kontoAttribut = []): array
{
    $konto = Account::factory()->create($kontoAttribut);
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

/**
 * En feed-rad skapad direkt, förbi kontrollern — samma form som
 * CalendarFeedFactory ger och samma upplägg som `kalenderfeedRad()` i
 * tests/Feature/Notis/KalenderfeedTest.php.
 */
function feedvyRad(Container $container, User $user, array $attribut = []): CalendarFeed
{
    return CalendarFeed::factory()
        ->for($container, 'container')
        ->for($user, 'user')
        ->create($attribut);
}

/**
 * Klartexten ur en feed-URL: `/kalender/<token>.ics`.
 */
function feedvyToken(string $url): string
{
    return (string) preg_replace('#^.*/kalender/(.+)\.ics$#', '$1', $url);
}

/**
 * Sidans props, så att ett test kan läsa dem som en array i stället för
 * genom AssertableInertia — samma teknik som sprakEgenskaper() i
 * SprakTest.
 *
 * @return array<string, mixed>
 */
function feedvyProps(User $anvandare, Container $container): array
{
    /** @var array{props: array<string, mixed>} $sida */
    $sida = actingAs($anvandare)
        ->get("/containers/{$container->ulid}/calendar")
        ->assertOk()
        ->viewData('page');

    return $sida['props'];
}

/*
 * Beslut 1: tre rutter, alla bakom `auth`. En utloggad besökare skickas till
 * inloggningen och når aldrig en kontrollermetod.
 */
it('skickar en utloggad besökare till inloggningen från kalenderrutterna', function () {
    withoutVite();

    [, , $container] = feedvyKontext();
    $feed = feedvyRad($container, User::factory()->create());

    get("/containers/{$container->ulid}/calendar")->assertRedirect('/login');
    post("/containers/{$container->ulid}/calendar")->assertRedirect('/login');
    delete("/containers/{$container->ulid}/calendar/{$feed->ulid}")->assertRedirect('/login');
});

/*
 * Klart när: `/containers/{c}/calendar` visar pärmens feeds för den
 * inloggade användaren.
 *
 * Bara hennes EGNA: en feed visar det den användaren får se, och en annan
 * medlems länkar är varken hennes eller något hon ska se (36a § Beslut 4).
 * Den andra medlemmen här har samma behörighet till pärmen — skillnaden är
 * vems feed det är.
 */
it('listar den inloggade användarens egna länkar och ingen annans', function () {
    withoutVite();

    [$konto, $anvandare, $container] = feedvyKontext();
    $kollega = User::factory()->create();
    $konto->users()->attach($kollega, ['role' => 'member']);

    $min = feedvyRad($container, $anvandare);
    feedvyRad($container, $kollega);

    actingAs($anvandare)
        ->get("/containers/{$container->ulid}/calendar")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/CalendarFeed')
            ->where('container.ulid', $container->ulid)
            ->has('feeds', 1)
            ->where('feeds.0.ulid', $min->ulid)
            // Ingen adress i listan — flashen är tom vid en vanlig visning.
            ->where('url', null)
        );
});

/*
 * Klart när: en ny feed kan skapas, och URL:en visas EN gång med en mening om
 * att den inte går att se igen.
 *
 * Testet prövar tre saker på samma väg: att raden skapas med en HASH och
 * aldrig med klartexten, att adressen finns i propsen i visningen direkt efter
 * skapandet, och att hashen stämmer med tokenet i den visade adressen — det är
 * vad som gör adressen användbar och vad som bevisar att den kom ur samma
 * generering.
 */
it('skapar en länk och visar adressen en gång', function () {
    withoutVite();

    [, $anvandare, $container] = feedvyKontext();

    actingAs($anvandare)
        ->post("/containers/{$container->ulid}/calendar")
        ->assertRedirect("/containers/{$container->ulid}/calendar");

    $rad = CalendarFeed::query()->sole();
    expect($rad->user_id)->toBe($anvandare->id);
    expect($rad->container_id)->toBe($container->id);
    expect($rad->revoked_at)->toBeNull();
    // Klartexten lagras aldrig: kolumnen bär en SHA-256-hex, aldrig tokenet
    // (36a § Beslut 5).
    expect($rad->token_hash)->toHaveLength(64);

    $props = feedvyProps($anvandare, $container);

    expect($props['url'])->toBeString()
        ->and($props['url'])->toStartWith(rtrim((string) config('app.url'), '/').'/kalender/')
        ->and($props['url'])->toEndWith('.ics')
        ->and(hash('sha256', feedvyToken($props['url'])))->toBe($rad->token_hash)
        ->and($props['feeds'])->toHaveCount(1);
});

/*
 * Klart när: URL:en finns inte i listan efteråt och inte i något `<a href>`.
 *
 * Andra halvan prövas mot komponenten, för den är en egenskap hos markupen
 * och inte hos svaret: SecretOnce renderar värdet i ett `<code>`-element, och
 * filen innehåller ingen `href` alls. Ett värde i en `href` hamnar i
 * historiken, i `Referer` och i varje proxylogg på vägen (Beslut 3).
 */
it('visar adressen i svaret men aldrig i listan efteråt', function () {
    withoutVite();

    [, $anvandare, $container] = feedvyKontext();

    actingAs($anvandare)->post("/containers/{$container->ulid}/calendar");

    $första = feedvyProps($anvandare, $container);
    $token = feedvyToken($första['url']);

    // Flashen är förbrukad av visningen ovan: nästa sidvisning är en vanlig
    // visning, och då finns adressen varken i `url` eller någon annanstans i
    // propsen.
    $andra = feedvyProps($anvandare, $container);

    expect($andra['url'])->toBeNull()
        ->and(json_encode($andra, JSON_THROW_ON_ERROR))->not->toContain($token);

    // Raden finns kvar, med sina fyra fält och inget mer: ingen token, ingen
    // hash, ingen härledd status.
    expect(array_keys($andra['feeds'][0]))->toBe([
        'ulid', 'created_at', 'revoked_at', 'last_fetched_at',
    ]);
});

it('renderar hemligheten som text och aldrig som en länk', function () {
    $kalla = File::get(resource_path('js/components/SecretOnce.vue'));

    // Kommentarerna talar om `href` — de förklarar VARFÖR ingen finns — så
    // de stryks före kontrollen, samma tre uttryck som SprakTest använder för
    // att skilja kommentar från kod.
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kalla);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);
    $kod = (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);

    expect($kod)->not->toContain('href')
        ->and($kod)->toContain('<code');
});

/*
 * Klart när: en feed kan återkallas, återkallandet är idempotent, och
 * `revoked_at` skrivs inte om vid ett andra anrop (Beslut 4).
 *
 * Den frysta klockan är hela beviset: andra anropet sker en dag senare, och
 * tidsstämpeln ska fortfarande vara den första. Skrevs den om vore historiken
 * — när länken faktiskt slutade fungera — borta.
 */
it('återkallar en länk och skriver inte om tidsstämpeln vid ett andra anrop', function () {
    withoutVite();

    [, $anvandare, $container] = feedvyKontext();
    $feed = feedvyRad($container, $anvandare);

    $sida = "/containers/{$container->ulid}/calendar";
    $rutt = "{$sida}/{$feed->ulid}";

    Carbon::setTestNow('2026-09-16 12:00:00');

    from($sida)->actingAs($anvandare)->delete($rutt)->assertRedirect($sida);

    $feed->refresh();
    expect($feed->revoked_at)->not->toBeNull();
    $första = $feed->revoked_at->toIso8601String();

    Carbon::setTestNow('2026-09-17 12:00:00');

    from($sida)->actingAs($anvandare)->delete($rutt)->assertRedirect($sida);

    $feed->refresh();
    expect($feed->revoked_at?->toIso8601String())->toBe($första);

    Carbon::setTestNow();
});

/*
 * Klart när: en återkallad feed visas som återkallad, inte som borttagen.
 *
 * Raden raderas aldrig (Beslut 4), och den som undrar varför kalendern slutade
 * uppdateras ska se svaret i listan i stället för en tom yta.
 */
it('listar en återkallad länk som återkallad', function () {
    withoutVite();

    [, $anvandare, $container] = feedvyKontext();
    $feed = feedvyRad($container, $anvandare, ['revoked_at' => now()]);

    $props = feedvyProps($anvandare, $container);

    expect($props['feeds'])->toHaveCount(1)
        ->and($props['feeds'][0]['ulid'])->toBe($feed->ulid)
        ->and($props['feeds'][0]['revoked_at'])->toBeString();
});

/*
 * Klart när: en användare utan `view` på pärmen får 403 på alla tre rutterna.
 *
 * Grinden är ContainerPolicy::view() och inget annat — samma som `/api`:s
 * CalendarFeedController, av samma skäl: den som får läsa pärmen får
 * prenumerera på dess kalender (36a § Beslut 4). En främling har ingen rad i
 * pärmen alls.
 */
it('ger 403 på alla tre rutterna för en användare utan view', function () {
    withoutVite();

    [, , $container] = feedvyKontext();
    $feed = feedvyRad($container, User::factory()->create());
    $frammande = User::factory()->create();

    actingAs($frammande)->get("/containers/{$container->ulid}/calendar")->assertForbidden();
    actingAs($frammande)->post("/containers/{$container->ulid}/calendar")->assertForbidden();
    actingAs($frammande)
        ->delete("/containers/{$container->ulid}/calendar/{$feed->ulid}")
        ->assertForbidden();

    expect(DB::table('calendar_feed')->count())->toBe(1);
    expect(CalendarFeed::query()->sole()->revoked_at)->toBeNull();
});

/*
 * Beslut 1: `{calendar_feed}` nästlas under `{container}` med
 * `scopeBindings()`, som allt annat. En ULID från en annan pärm löser aldrig
 * upp här — den finns inte, och svaret är 404 och inte 403 (36a § Beslut 3).
 */
it('svarar 404 för en feed som hör till en annan pärm', function () {
    withoutVite();

    [$konto, $anvandare, $container] = feedvyKontext();
    $annan = Container::factory()->for($konto, 'account')->create();
    $feed = feedvyRad($annan, $anvandare);

    actingAs($anvandare)
        ->delete("/containers/{$container->ulid}/calendar/{$feed->ulid}")
        ->assertNotFound();

    expect($feed->refresh()->revoked_at)->toBeNull();
});
