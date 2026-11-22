<?php

use App\Actions\Container\SetContainerCover;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutVite;

/*
 * Issue 682 · Fokuspunkten på containerns bild — den del av bilden som ska
 * synas när en yta beskär den (`object-position: x% y%`) — och issue 433 ·
 * [[ADR-0090 Dashboardens hjälte]] § 5, där utsnittet får en ZOOM. Se
 * [[ADR-0047 Containerns bild]] och fyndet från testarna 2026-10-03
 * (docs/Design/findings_20261003_b/items.png): hjälten är bred och låg,
 * `object-cover` beskär mitt i bilden, och motivet hamnar utanför.
 *
 * Filen är syskon till ContainerbildBehorighetTest.php (grinden för bilden)
 * och tests/Feature/Attachment/ContainerbildTest.php (formen och åtgärderna).
 * Här prövas kedjan: lagringen (`container.cover_focus_x/y` och
 * `container.cover_zoom`), App\Actions\Container\SetContainerCoverFocus,
 * rutten `PATCH /containers/{container}/cover/focus` och utsnittet i
 * App\Http\Resources\ContainerResource::cover().
 *
 * Tre saker är värda att se efter:
 *
 * 1. **404 när containern saknar bild.** Ett utsnitt på en bild som inte finns
 *    är ett anrop mot ett objekt som inte finns — till skillnad från att TA
 *    BORT en bild, där frånvaron är ett giltigt tillstånd (RemoveContainerCover).
 * 2. **Ingen flash-kod.** Bilden som byter utsnitt är kvittensen; en grön ruta
 *    per sparning vore brus (issuens beslut 5).
 * 3. **Utsnittet nollställs när bilden byts eller tas bort.** En punkt och en
 *    zoom valda på en bild säger ingenting om nästa.
 *
 * Hjälparna har prefixet `bildfokus` — Pests funktioner delar namnrymd när hela
 * sviten körs.
 */

beforeEach(function () {
    withoutVite();

    // Kön ritar miniatyrer i samma request annars (QUEUE_CONNECTION=sync i
    // phpunit.xml), och inga byten ska hamna i den riktiga storage/files/ —
    // samma mönster som ContainerbildTest och ContainerbildvyTest.
    Queue::fake();
    Storage::fake('files');
});

/**
 * Ett konto med en medlem, en container ägd av kontot, och en bild på
 * containern.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Attachment}
 */
function bildfokusKontext(): array
{
    [$konto, $ägare] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    $bilaga = bildfokusSätt($ägare, $container, $konto);

    return [$konto, $ägare, $container, $bilaga];
}

/**
 * Sätter bilden genom actionen, som $användare — samma väg rutten går.
 */
function bildfokusSätt(User $användare, Container $container, Account $konto, int $nyans = 10): Attachment
{
    actingAs($användare);

    return app(SetContainerCover::class)->handle(
        $container,
        UploadedFile::fake()->createWithContent('båt.png', bildfokusPng($nyans)),
        $användare,
        $konto,
    );
}

/**
 * En giltig PNG med en känd nyans — två anrop med olika nyans ger olika
 * `content_hash` och därmed två `stored_file`-rader, samma grepp som
 * ContainerbildTest.
 */
function bildfokusPng(int $nyans = 10): string
{
    $bild = imagecreatetruecolor(20, 20);
    imagefilledrectangle($bild, 0, 0, 19, 19, imagecolorallocate($bild, $nyans, 20, 30));

    ob_start();
    imagepng($bild);
    $byten = (string) ob_get_clean();
    imagedestroy($bild);

    return $byten;
}

/*
 * Klart när: ägaren PATCH:ar {x: 30, y: 80, zoom: 180}, får 302 utan
 * sessionsfel, raden har 30/80/180 och `/containers/{ulid}` bär
 * `container.cover.focus` = [x, y] och `container.cover.zoom` = 180.
 */
it('sparar utsnittet och skickar det i resursen', function () {
    [, $ägare, $container] = bildfokusKontext();

    actingAs($ägare);

    patch("/containers/{$container->ulid}/cover/focus", ['x' => 30, 'y' => 80, 'zoom' => 180])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $container->refresh();
    expect($container->cover_focus_x)->toBe(30);
    expect($container->cover_focus_y)->toBe(80);
    expect($container->cover_zoom)->toBe(180);

    get("/containers/{$container->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Overview')
            ->where('container.cover.focus', ['x' => 30, 'y' => 80])
            ->where('container.cover.zoom', 180));
});

/*
 * Klart när (issue 433): utelämnad `zoom` betyder 100.
 *
 * Kroppen med bara x och y är den gamla formen, och den ska fortsätta att
 * fungera: zoomen är frivillig i App\Http\Requests\Container\
 * ContainerCoverFocusRequest och kontrollern läser den med
 * `integer('zoom', 100)`. Raden får då 100, inte NULL — ett utsnitt är valt.
 */
it('tolkar utelämnad zoom som 100', function () {
    [, $ägare, $container] = bildfokusKontext();

    actingAs($ägare);

    patch("/containers/{$container->ulid}/cover/focus", ['x' => 30, 'y' => 80])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($container->refresh()->cover_zoom)->toBe(100);

    get("/containers/{$container->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('container.cover.zoom', 100));
});

/*
 * Klart när: efter att bilden satts är `container.cover.focus` och
 * `container.cover.zoom` null.
 *
 * NULL betyder mitten och 100 % — en container utan valt utsnitt beskärs som
 * förut.
 */
it('skickar utsnittet som null när inget är valt', function () {
    [, $ägare, $container] = bildfokusKontext();

    actingAs($ägare);

    get("/containers/{$container->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('container.cover.focus', null)
            ->where('container.cover.zoom', null));
});

/*
 * Klart när (arkitektsvaret på issue 210 och issue 433): kortet som byggs av
 * App\Actions\Container\ListContainerSummaries bär samma utsnitt som
 * containerlistan — både punkt och zoom.
 *
 * Kortet formulerar inte sin egen `cover` — det läser samma
 * ContainerResource::cover(), och den läser `cover_focus_x`/`cover_focus_y`/
 * `cover_zoom` ur containerraden. Står kolumnerna inte i actionens
 * `get([...])` är de null på modellen, och korten svarar `focus: null` och
 * `zoom: null` även när ett utsnitt är satt: fel data, inte "inget utsnitt
 * valt". Provet låser raden mot att försvinna igen.
 */
it('skickar utsnittet även på sammanfattningskorten', function () {
    [, $ägare, $container] = bildfokusKontext();

    actingAs($ägare);
    patch("/containers/{$container->ulid}/cover/focus", ['x' => 30, 'y' => 80, 'zoom' => 180])
        ->assertRedirect();

    get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('containerGroups.0.containers.0.cover.focus', ['x' => 30, 'y' => 80])
            ->where('containerGroups.0.containers.0.cover.zoom', 180));
});

/*
 * Klart när: värden utanför sina intervall och icke-heltal avvisas, och raden
 * är orörd.
 *
 * Ett ensamt x utan y är ingen punkt (required på båda), `'a'` faller på
 * `integer`, ett värde utanför bilden faller på `between:0,100`, och en zoom
 * utanför 100–300 faller på `between:100,300`. Felet hamnar på sitt eget
 * fält, och actionen körs aldrig.
 */
it('avvisar punkten utanför 0–100 och zoomen utanför 100–300', function (array $kropp, string $fält) {
    [, $ägare, $container] = bildfokusKontext();

    actingAs($ägare);

    patch("/containers/{$container->ulid}/cover/focus", $kropp)
        ->assertSessionHasErrors([$fält]);

    $container->refresh();
    expect($container->cover_focus_x)->toBeNull();
    expect($container->cover_focus_y)->toBeNull();
    expect($container->cover_zoom)->toBeNull();
})->with([
    'x över taket' => [['x' => 101, 'y' => 50], 'x'],
    'y under noll' => [['x' => 50, 'y' => -1], 'y'],
    'x är en sträng' => [['x' => 'a', 'y' => 50], 'x'],
    'y saknas' => [['x' => 50], 'y'],
    'zoom under 100' => [['x' => 50, 'y' => 50, 'zoom' => 99], 'zoom'],
    'zoom över 300' => [['x' => 50, 'y' => 50, 'zoom' => 301], 'zoom'],
    'zoom är en sträng' => [['x' => 50, 'y' => 50, 'zoom' => 'a'], 'zoom'],
]);

/*
 * Klart när: en container utan bild svarar 404 och ingenting skrivs.
 *
 * Skillnaden mot att TA BORT en bild: där är frånvaron ett giltigt tillstånd
 * (RemoveContainerCover är en no-op), här är den ett fel i anropet.
 */
it('svarar 404 när containern saknar bild', function () {
    [$konto, $ägare] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    actingAs($ägare);

    patch("/containers/{$container->ulid}/cover/focus", ['x' => 30, 'y' => 80])
        ->assertNotFound();

    $container->refresh();
    expect($container->cover_focus_x)->toBeNull();
    expect($container->cover_focus_y)->toBeNull();
});

/*
 * Klart när: en container-bred `write`-mottagare sätter punkten.
 *
 * Samma pinne som att sätta bilden (ContainerPolicy::update), och den enda
 * vägen in som inte är medlemskap i ägarkontot.
 */
it('låter en container-bred write-mottagare sätta punkten', function () {
    [, , $container] = bildfokusKontext();

    $mottagare = User::factory()->create();
    beviljaAccess($container, $mottagare, 'write', 'member');

    somAnvandare($mottagare);

    patch("/containers/{$container->ulid}/cover/focus", ['x' => 10, 'y' => 90, 'zoom' => 150])
        ->assertRedirect();

    $container->refresh();
    expect($container->cover_focus_x)->toBe(10);
    expect($container->cover_focus_y)->toBe(90);
    expect($container->cover_zoom)->toBe(150);
});

/*
 * Klart när: `read`-mottagaren och en främling nekas med 403, och raden är
 * orörd.
 *
 * Båda når containern på sitt vis men får inte ÄNDRA den — `read` är under
 * `update` i laddern, och främlingen når den inte alls. Grinden nekar innan
 * något skrivs.
 */
it('nekar läsaren och den som inte når containern', function (string $slag) {
    [, , $container] = bildfokusKontext();

    $mottagare = User::factory()->create();

    if ($slag === 'read') {
        beviljaAccess($container, $mottagare, 'read', 'member');
    }

    somAnvandare($mottagare);

    patch("/containers/{$container->ulid}/cover/focus", ['x' => 30, 'y' => 80, 'zoom' => 180])
        ->assertForbidden();

    $container->refresh();
    expect($container->cover_focus_x)->toBeNull();
    expect($container->cover_focus_y)->toBeNull();
    expect($container->cover_zoom)->toBeNull();
})->with(['read', 'främling']);

/*
 * Klart när: efter `POST /containers/{ulid}/cover` är alla tre kolumnerna null.
 *
 * Ett utsnitt valt på den förra bilden säger ingenting om den nya (issuens
 * beslut 6); SetContainerCover nollställer på samma rad som den byter pekaren.
 */
it('nollställer utsnittet när bilden byts', function () {
    [$konto, $ägare, $container] = bildfokusKontext();

    actingAs($ägare);
    patch("/containers/{$container->ulid}/cover/focus", ['x' => 30, 'y' => 80, 'zoom' => 180])
        ->assertRedirect();

    expect($container->refresh()->cover_focus_x)->toBe(30);

    actingAs($ägare);
    post("/containers/{$container->ulid}/cover", [
        'file' => UploadedFile::fake()->createWithContent('ny.png', bildfokusPng(50)),
        'account' => $konto->ulid,
    ])->assertRedirect();

    $container->refresh();
    expect($container->cover_focus_x)->toBeNull();
    expect($container->cover_focus_y)->toBeNull();
    expect($container->cover_zoom)->toBeNull();
});

/*
 * Klart när: efter `DELETE /containers/{ulid}/cover` är alla tre kolumnerna
 * null.
 *
 * RemoveContainerCover::clearPointer() nollställer utsnittet där den nollar
 * pekaren (issuens beslut 6).
 */
it('nollställer utsnittet när bilden tas bort', function () {
    [, $ägare, $container] = bildfokusKontext();

    actingAs($ägare);
    patch("/containers/{$container->ulid}/cover/focus", ['x' => 30, 'y' => 80, 'zoom' => 180])
        ->assertRedirect();

    expect($container->refresh()->cover_focus_x)->toBe(30);

    actingAs($ägare);
    delete("/containers/{$container->ulid}/cover")->assertRedirect();

    $container->refresh();
    expect($container->cover_focus_x)->toBeNull();
    expect($container->cover_focus_y)->toBeNull();
    expect($container->cover_zoom)->toBeNull();
});
