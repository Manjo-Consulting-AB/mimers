<?php

use App\Actions\User\RemoveUserAvatar;
use App\Actions\User\SetUserAvatar;
use App\Actions\User\SetUserAvatarCrop;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\withoutVite;

/*
 * Issue 382 · Profilbildens utsnitt — fokuspunkt och zoom. Se [[ADR-0076
 * Profilbilden]] § 7 samt [[M59 Profilbilden]] § 382.
 *
 * Filen prövar § 5 i issuen, serversidan:
 *
 * 1. CHECK-villkoret kräver att punkten sätts som ett par, och zoomen ligger
 *    i 100–300 (bara MariaDB, som `container_cover_focus_pair` — sqlite kan
 *    inte lägga till ett CHECK i en befintlig tabell).
 * 2. `PATCH /settings/profile/avatar/crop` sparar punkt och zoom och svarar
 *    `back()` med `avatar-cropped`.
 * 3. Värden utanför gränserna ger valideringsfel på sitt eget fält.
 * 4. Utan bild ger båda ytorna 404.
 * 5. Byte och borttagning av bilden nollställer alla tre kolumnerna.
 * 6. `AuthUserResource` bär `focus` och `zoom`, med `{ 50, 50 }` och 100 när
 *    kolumnerna är NULL.
 * 7. API-rutten svarar 200 med personens resurs.
 * 8. Ingenting skrivs till `attachment` eller `stored_file`: utsnittet är
 *    icke-destruktivt och skapar ingen ny fil.
 *
 * Hjälparna har prefixet `utsnitt` — Pests funktioner delar namnrymd när hela
 * sviten körs, och tests/Feature/Konto/ProfilbildTest.php äger redan
 * `profilbild*`.
 */

beforeEach(function () {
    withoutVite();

    // Ingen miniatyrgenerering i samma request (QUEUE_CONNECTION=sync), och
    // inga byten i den riktiga storage/files/ — samma mönster som
    // ProfilbildTest.
    Queue::fake();
    Storage::fake('files');

    config(['files.internal_redirect' => false, 'files.url' => null]);
});

/**
 * En person som kan bära en profilbild: ett eget personkonto, som är kontot
 * uppladdningen belastar (ADR-0076 § 3).
 *
 * @return array{0: User, 1: Account}
 */
function utsnittKontext(): array
{
    $person = User::factory()->create();

    $konto = Account::factory()->create();
    $konto->users()->attach($person, ['role' => 'owner']);

    return [$person, $konto];
}

/**
 * Deterministiska byten med en bestämd färg, samma teknik som
 * profilbildPng — två anrop med olika färg ger olika `content_hash`.
 */
function utsnittPng(int $röd = 255, int $grön = 0, int $blå = 0): string
{
    $bild = imagecreatetruecolor(20, 20);
    imagefilledrectangle($bild, 0, 0, 19, 19, imagecolorallocate($bild, $röd, $grön, $blå));

    ob_start();
    imagepng($bild);
    $byten = (string) ob_get_clean();
    imagedestroy($bild);

    return $byten;
}

function utsnittFil(string $namn = 'profil.png', int $röd = 255, int $grön = 0, int $blå = 0): UploadedFile
{
    return UploadedFile::fake()->createWithContent($namn, utsnittPng($röd, $grön, $blå));
}

/**
 * En person med en uppladdad profilbild.
 *
 * @return array{0: User, 1: Attachment}
 */
function utsnittMedBild(): array
{
    [$person] = utsnittKontext();

    $bilaga = app(SetUserAvatar::class)->handle($person, utsnittFil());

    return [$person, $bilaga];
}

/*
 * Klart när: CHECK-villkoret för paret finns, och zoomen ligger i 100–300.
 *
 * Villkoret läggs bara på mysql (sqlite kan inte ALTER TABLE ... ADD
 * CONSTRAINT), och formen bevisas av .github/workflows/migreringar.yml mot
 * MariaDB — samma motor som staging och produktion. Proven hoppar därför över
 * i sviten, som kör sqlite i minnet; ett prov som aldrig kör är ingen
 * bevisning.
 */
it('kräver att fokuspunkten sätts som ett par och zoomen ligger i 100–300', function () {
    $person = User::factory()->create();

    // En halv punkt är ingen punkt: x utan y faller på CHECK-villkoret.
    expect(fn () => DB::table('user')->where('id', $person->id)->update(['avatar_focus_x' => 30]))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('user')->where('id', $person->id)->update(['avatar_focus_y' => 70]))
        ->toThrow(QueryException::class);

    // Hela paret är en giltig punkt, och zoomen ryms i intervallet.
    DB::table('user')->where('id', $person->id)->update([
        'avatar_focus_x' => 30,
        'avatar_focus_y' => 70,
        'avatar_zoom' => 180,
    ]);

    $person->refresh();
    expect($person->avatar_focus_x)->toBe(30)
        ->and($person->avatar_focus_y)->toBe(70)
        ->and($person->avatar_zoom)->toBe(180);

    // Ingen punkt är mitten, och då är också zoomen NULL.
    DB::table('user')->where('id', $person->id)->update([
        'avatar_focus_x' => null,
        'avatar_focus_y' => null,
        'avatar_zoom' => null,
    ]);

    expect($person->refresh()->avatar_focus_x)->toBeNull();

    // Zoomen utanför intervallet avvisas av sitt eget villkor.
    expect(fn () => DB::table('user')->where('id', $person->id)->update(['avatar_zoom' => 99]))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('user')->where('id', $person->id)->update(['avatar_zoom' => 301]))
        ->toThrow(QueryException::class);
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'mysql',
    'CHECK-villkoren finns bara på MariaDB; sviten kör sqlite.',
);

/*
 * Klart när: webbens PATCH sparar punkt och zoom.
 *
 * Punkten skrivs på personens egen rad, och kvittensen är flash-koden
 * `avatar-cropped` — arket stänger efter sparningen, och servern svarar
 * `back()` med färska proppar.
 */
it('sparar fokuspunkt och zoom med webbens rutt', function () {
    [$person] = utsnittMedBild();

    somAnvandare($person);

    patch(route('settings.profile.avatar.crop'), ['x' => 25, 'y' => 75, 'zoom' => 180])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'avatar-cropped');

    $person->refresh();

    expect($person->avatar_focus_x)->toBe(25)
        ->and($person->avatar_focus_y)->toBe(75)
        ->and($person->avatar_zoom)->toBe(180);
});

/*
 * Klart när: värden utanför gränserna ger valideringsfel.
 *
 * `x` och `y` är heltal 0–100, `zoom` ett heltal 100–300. Felet hamnar på sitt
 * eget fält, och ingenting skrivs — actionen körs aldrig.
 */
it('avvisar värden utanför gränserna', function (array $kropp, string $fält) {
    [$person] = utsnittMedBild();

    somAnvandare($person);

    patch(route('settings.profile.avatar.crop'), $kropp)->assertSessionHasErrors([$fält]);

    $person->refresh();
    expect($person->avatar_focus_x)->toBeNull()
        ->and($person->avatar_focus_y)->toBeNull()
        ->and($person->avatar_zoom)->toBeNull();
})->with([
    'x över taket' => [['x' => 101, 'y' => 50, 'zoom' => 150], 'x'],
    'y under noll' => [['x' => 50, 'y' => -1, 'zoom' => 150], 'y'],
    'x är en sträng' => [['x' => 'a', 'y' => 50, 'zoom' => 150], 'x'],
    'zoom under 100' => [['x' => 50, 'y' => 50, 'zoom' => 99], 'zoom'],
    'zoom över 300' => [['x' => 50, 'y' => 50, 'zoom' => 301], 'zoom'],
    'zoom saknas' => [['x' => 50, 'y' => 50], 'zoom'],
]);

/*
 * Klart när: en person utan bild får 404, på båda ytorna.
 *
 * Ett utsnitt på en bild som inte finns är ett anrop mot ett objekt som inte
 * finns — till skillnad från att ta bort en bild, där frånvaron är ett giltigt
 * tillstånd (RemoveUserAvatar).
 */
it('svarar 404 på webben när personen saknar bild', function () {
    $person = User::factory()->create();

    somAnvandare($person);

    patch(route('settings.profile.avatar.crop'), ['x' => 50, 'y' => 50, 'zoom' => 150])
        ->assertNotFound();
});

it('svarar 404 i api:et när personen saknar bild', function () {
    $person = User::factory()->create();
    $token = $person->createToken('api');

    medToken($token->plainTextToken)
        ->patchJson('/api/me/avatar/crop', ['x' => 50, 'y' => 50, 'zoom' => 150])
        ->assertNotFound();
});

/*
 * Klart när: ett byte av bilden nollställer utsnittet.
 *
 * En punkt vald på en bild säger ingenting om nästa (ADR-0076 § 7).
 */
it('nollställer utsnittet när bilden byts', function () {
    [$person] = utsnittMedBild();

    app(SetUserAvatarCrop::class)->handle($person, 30, 80, 200);

    expect($person->refresh()->avatar_focus_x)->toBe(30);

    app(SetUserAvatar::class)->handle($person, utsnittFil('ny.png', 0, 0, 255));

    $person->refresh();

    expect($person->avatar_focus_x)->toBeNull()
        ->and($person->avatar_focus_y)->toBeNull()
        ->and($person->avatar_zoom)->toBeNull();
});

/*
 * Klart när: borttagningen nollställer utsnittet.
 */
it('nollställer utsnittet när bilden tas bort', function () {
    [$person] = utsnittMedBild();

    app(SetUserAvatarCrop::class)->handle($person, 30, 80, 200);

    expect($person->refresh()->avatar_zoom)->toBe(200);

    app(RemoveUserAvatar::class)->handle($person);

    $person->refresh();

    expect($person->avatar_focus_x)->toBeNull()
        ->and($person->avatar_focus_y)->toBeNull()
        ->and($person->avatar_zoom)->toBeNull();
});

/*
 * Klart när: AuthUserResource bär `focus` och `zoom` med standardvärden.
 *
 * NULL betyder mitten och 100 %. Resursen fyller i dem, så klienten slipper
 * två former av samma sak: en person som aldrig beskuret sin bild får
 * `{ focus: { x: 50, y: 50 }, zoom: 100 }`.
 */
it('bär focus och zoom med standardvärden i AuthUserResource', function () {
    [$person] = utsnittMedBild();

    somAnvandare($person);

    get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.user.avatar.focus', ['x' => 50, 'y' => 50])
        ->where('auth.user.avatar.zoom', 100));

    app(SetUserAvatarCrop::class)->handle($person, 20, 90, 220);

    get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.user.avatar.focus', ['x' => 20, 'y' => 90])
        ->where('auth.user.avatar.zoom', 220));
});

/*
 * Klart när: API-rutten svarar 200 med personens resurs.
 *
 * Samma AuthUserResource som de andra rösterna, med det nya `focus`-paret och
 * `zoom` — klienten ser resultatet utan en andra läsning.
 */
it('svarar 200 med personens resurs i api:et', function () {
    [$person] = utsnittMedBild();
    $token = $person->createToken('api');

    $svar = medToken($token->plainTextToken)
        ->patchJson('/api/me/avatar/crop', ['x' => 15, 'y' => 85, 'zoom' => 250])
        ->assertOk();

    expect($svar->json('data.avatar.focus'))->toBe(['x' => 15, 'y' => 85])
        ->and($svar->json('data.avatar.zoom'))->toBe(250);

    $person->refresh();
    expect($person->avatar_focus_x)->toBe(15)
        ->and($person->avatar_focus_y)->toBe(85)
        ->and($person->avatar_zoom)->toBe(250);
});

/*
 * Klart när: ingen ny fil skapas när utsnittet ändras.
 *
 * Beskärningen är icke-destruktiv (ADR-0076 § 7): originalet lagras orört,
 * utsnittet ritas med CSS, och varken `attachment` eller `stored_file` får en
 * ny rad. Kvoten påverkas inte heller.
 */
it('skapar ingen ny fil när utsnittet ändras', function () {
    [$person, $bilaga] = utsnittMedBild();

    $bilagor = Attachment::withTrashed()->count();
    $filer = DB::table('stored_file')->count();
    $förbrukning = DB::table('usage_counter')->value('storage_bytes');
    $original = $bilaga->stored_file_id;

    app(SetUserAvatarCrop::class)->handle($person, 10, 20, 150);

    expect(Attachment::withTrashed()->count())->toBe($bilagor)
        ->and(DB::table('stored_file')->count())->toBe($filer)
        ->and(DB::table('usage_counter')->value('storage_bytes'))->toBe($förbrukning)
        ->and($bilaga->refresh()->stored_file_id)->toBe($original);
});
