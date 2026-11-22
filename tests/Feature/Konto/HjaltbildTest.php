<?php

use App\Actions\User\DeleteUser;
use App\Actions\User\RemoveUserHero;
use App\Actions\User\SetUserAvatar;
use App\Actions\User\SetUserHero;
use App\Actions\User\SetUserHeroCrop;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\ImageDerivative;
use App\Models\User;
use App\Support\Mcp\ForbiddenDomains;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutVite;

/*
 * Issue 434 · Personens hjältebild — skrivvägen, utsnittet, livscykeln och
 * ytan. Se [[ADR-0090 Dashboardens hjälte]] § 2 och 7 samt [[ADR-0076
 * Profilbilden]] § 1–5.
 *
 * Hjältebilden följer profilbilden i allt: bara bilder, en åt gången, en ny
 * bild ersätter den gamla som rensas direkt, bytena räknas mot personkontot,
 * utsnittet nollställs vid byte och borttagning, och `DeleteUser` rensar den.
 * Skillnaden är synligheten: bara personen själv ser hjältebilden
 * (`UserPolicy::viewHero`), och den prövas i
 * tests/Feature/Bilaga/PersonbilagaTest.php tillsammans med leveransen.
 *
 * Filen prövar Klart när-punkterna 1–5, 6, 8 och den del av 7 som går att
 * avgöra på serversidan (resursen och att inget MCP-verktyg når bilden).
 * Ytan prövas som källkod i tests/Feature/Frontend/ProfilbildvyTest.php.
 *
 * Hjälparna har prefixet `hjaltbild` — Pests funktioner delar namnrymd när
 * hela sviten körs.
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
 * En person med ett eget personkonto — kontot som betalar (ADR-0076 § 3).
 *
 * @return array{0: User, 1: Account}
 */
function hjaltbildKontext(): array
{
    $person = User::factory()->create();

    // AccountFactory drar `type = personal`, samma uppslag som
    // App\Support\Account\ActingAccount gör.
    $konto = Account::factory()->create();
    $konto->users()->attach($person, ['role' => 'owner']);

    return [$person, $konto];
}

/**
 * Deterministiska byten med en bestämd färg — samma teknik som
 * profilbildPng. Två anrop med olika färg ger olika `content_hash`.
 */
function hjaltbildPng(int $röd = 255, int $grön = 0, int $blå = 0): string
{
    $bild = imagecreatetruecolor(20, 20);
    imagefilledrectangle($bild, 0, 0, 19, 19, imagecolorallocate($bild, $röd, $grön, $blå));

    ob_start();
    imagepng($bild);
    $byten = (string) ob_get_clean();
    imagedestroy($bild);

    return $byten;
}

function hjaltbildFil(string $namn = 'hjalte.png', int $röd = 255, int $grön = 0, int $blå = 0): UploadedFile
{
    return UploadedFile::fake()->createWithContent($namn, hjaltbildPng($röd, $grön, $blå));
}

/*
 * Klart när: en uppladdad bild blir personens hjältebild, och bytena räknas
 * mot personkontot.
 *
 * Personen är också medlem i en organisation, så provet bevisar att det är
 * PERSONKONTOT som belastas (ADR-0076 § 3).
 */
it('laddar upp en hjältebild på personen och belastar personkontot', function () {
    [$person, $konto] = hjaltbildKontext();

    $organisation = Account::factory()->organisation()->create();
    $organisation->users()->attach($person, ['role' => 'member']);

    $byten = hjaltbildPng();
    $bilaga = app(SetUserHero::class)->handle(
        $person,
        UploadedFile::fake()->createWithContent('hjalte.png', $byten),
    );

    expect($bilaga->user_id)->toBe($person->id)
        ->and($bilaga->item_id)->toBeNull()
        ->and($bilaga->container_id)->toBeNull()
        ->and($bilaga->kind)->toBe('image')
        ->and($bilaga->billed_account_id)->toBe($konto->id)
        ->and($person->refresh()->hero_attachment_id)->toBe($bilaga->id);

    expect(DB::table('usage_counter')->where('account_id', $konto->id)->value('storage_bytes'))
        ->toBe(strlen($byten));

    // Organisationskontot rörs inte.
    expect(DB::table('usage_counter')->where('account_id', $organisation->id)->exists())->toBeFalse();
});

/*
 * Klart när: en ny bild ersätter den gamla, och den gamla rensas direkt.
 *
 * Den gamla bilagan RENSAS — den går inte till papperskorgen (ADR-0076 § 2) —
 * så raden är borta på riktigt och `reference_count` har gått från ett till
 * noll. Profilbildens bilaga rörs inte.
 */
it('byter hjältebild och rensar den förra bilagan', function () {
    [$person] = hjaltbildKontext();

    $profil = app(SetUserAvatar::class)->handle($person, hjaltbildFil('profil.png', 0, 255, 0));

    $första = app(SetUserHero::class)->handle($person, hjaltbildFil('första.png', 255, 0, 0));
    $förstaFil = $första->storedFile;

    $andra = app(SetUserHero::class)->handle($person, hjaltbildFil('andra.png', 0, 0, 255));

    expect($andra->id)->not->toBe($första->id)
        ->and($person->refresh()->hero_attachment_id)->toBe($andra->id)
        ->and(Attachment::withTrashed()->whereKey($första->id)->exists())->toBeFalse()
        ->and($förstaFil->refresh()->reference_count)->toBe(0)
        ->and($andra->storedFile->refresh()->reference_count)->toBe(1)
        // Profilbilden är en annan bilaga och står kvar.
        ->and($person->avatar_attachment_id)->toBe($profil->id);
});

/*
 * Klart när: en fil som inte är en bild avvisas.
 *
 * Kravet ställs på den SNIFFADE typen, före varje skrivning: ingen
 * attachment-rad, ingen `stored_file` och ingen pekare.
 */
it('avvisar en fil som inte är en bild med ett fältfel', function () {
    [$person] = hjaltbildKontext();

    somAnvandare($person);

    post(route('settings.profile.hero.store'), [
        'file' => UploadedFile::fake()->createWithContent('manual.pdf', 'en manual'),
    ])->assertSessionHasErrors('file');

    expect(Attachment::withTrashed()->count())->toBe(0)
        ->and($person->refresh()->hero_attachment_id)->toBeNull();
});

/*
 * Klart när: en uppladdning över kvoten avvisas och lämnar den gamla bilden
 * orörd.
 *
 * Den tidiga kontrollen i SetUserHero nekar innan något skrivs; den
 * auktoritativa kontrollen ligger i StoreAttachments transaktion (issue 27b
 * § Beslut 4).
 */
it('nekar en hjältebild över kvoten och lämnar den gamla bilden orörd', function () {
    sättPlangräns('free', 'storage_bytes', 10 * 1024 * 1024);

    [$person, $konto] = hjaltbildKontext();

    $gammal = app(SetUserHero::class)->handle($person, hjaltbildFil());

    DB::table('usage_counter')->where('account_id', $konto->id)->update(['storage_bytes' => 9 * 1024 * 1024]);

    somAnvandare($person);

    post(route('settings.profile.hero.store'), [
        'file' => UploadedFile::fake()->createWithContent('stor.png', str_repeat('a', 4 * 1024 * 1024)),
    ])->assertSessionHasErrors('file');

    expect($person->refresh()->hero_attachment_id)->toBe($gammal->id)
        ->and(Attachment::withTrashed()->whereKey($gammal->id)->exists())->toBeTrue()
        ->and(Attachment::withTrashed()->count())->toBe(1)
        ->and(DB::table('usage_counter')->where('account_id', $konto->id)->value('storage_bytes'))
        ->toBe(9 * 1024 * 1024);
});

/*
 * Klart när: utsnittet sparas och nollställs vid byte och borttagning.
 */
it('sparar utsnittet och nollställer det vid byte och borttagning', function () {
    [$person] = hjaltbildKontext();

    app(SetUserHero::class)->handle($person, hjaltbildFil());

    somAnvandare($person);

    patch(route('settings.profile.hero.crop'), ['x' => 25, 'y' => 75, 'zoom' => 180])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'hero-cropped');

    $person->refresh();

    expect($person->hero_focus_x)->toBe(25)
        ->and($person->hero_focus_y)->toBe(75)
        ->and($person->hero_zoom)->toBe(180);

    // Ett byte nollställer utsnittet — en punkt på den förra bilden säger
    // ingenting om den nya (ADR-0076 § 7).
    app(SetUserHero::class)->handle($person, hjaltbildFil('ny.png', 0, 0, 255));

    $person->refresh();

    expect($person->hero_focus_x)->toBeNull()
        ->and($person->hero_focus_y)->toBeNull()
        ->and($person->hero_zoom)->toBeNull();

    // Och borttagningen gör detsamma.
    app(SetUserHeroCrop::class)->handle($person, 30, 80, 200);
    app(RemoveUserHero::class)->handle($person);

    $person->refresh();

    expect($person->hero_focus_x)->toBeNull()
        ->and($person->hero_focus_y)->toBeNull()
        ->and($person->hero_zoom)->toBeNull()
        ->and($person->hero_attachment_id)->toBeNull();
});

/*
 * Klart när: värden utanför gränserna avvisas.
 */
it('avvisar utsnitt utanför gränserna', function (array $kropp, string $fält) {
    [$person] = hjaltbildKontext();

    app(SetUserHero::class)->handle($person, hjaltbildFil());

    somAnvandare($person);

    patch(route('settings.profile.hero.crop'), $kropp)->assertSessionHasErrors([$fält]);

    $person->refresh();

    expect($person->hero_focus_x)->toBeNull()
        ->and($person->hero_focus_y)->toBeNull()
        ->and($person->hero_zoom)->toBeNull();
})->with([
    'x över taket' => [['x' => 101, 'y' => 50, 'zoom' => 150], 'x'],
    'y under noll' => [['x' => 50, 'y' => -1, 'zoom' => 150], 'y'],
    'x är en sträng' => [['x' => 'a', 'y' => 50, 'zoom' => 150], 'x'],
    'zoom under 100' => [['x' => 50, 'y' => 50, 'zoom' => 99], 'zoom'],
    'zoom över 300' => [['x' => 50, 'y' => 50, 'zoom' => 301], 'zoom'],
    'zoom saknas' => [['x' => 50, 'y' => 50], 'zoom'],
]);

/*
 * Klart när: en person utan bild får 404 på utsnittet, och borttagningen är
 * en no-op.
 */
it('svarar 404 på utsnittet utan bild, och borttagningen tål att köras igen', function () {
    [$person] = hjaltbildKontext();

    somAnvandare($person);

    patch(route('settings.profile.hero.crop'), ['x' => 50, 'y' => 50, 'zoom' => 150])
        ->assertNotFound();

    delete(route('settings.profile.hero.destroy'))
        ->assertSessionHas('status', 'hero-removed');

    expect(app(RemoveUserHero::class)->handle($person))->toBeFalse();
});

/*
 * Klart när: `DeleteUser` rensar hjältebilden.
 *
 * `attachment.user_id` är ON DELETE RESTRICT, så utan rensningen hade
 * personraden fallit på sin egen bild (ADR-0090 § 2 och ADR-0076 § 5).
 */
it('raderar en person med hjältebild utan att lämna personbilagan kvar', function () {
    [$person] = hjaltbildKontext();

    $hjälte = app(SetUserHero::class)->handle($person, hjaltbildFil());
    $profil = app(SetUserAvatar::class)->handle($person, hjaltbildFil('profil.png', 0, 255, 0));
    $fil = $hjälte->storedFile;
    $profilFil = $profil->storedFile;

    app(DeleteUser::class)->handle($person);

    expect(User::query()->whereKey($person->id)->exists())->toBeFalse()
        ->and(Attachment::withTrashed()->whereKey($hjälte->id)->exists())->toBeFalse()
        ->and(Attachment::withTrashed()->whereNotNull('user_id')->count())->toBe(0)
        ->and($fil->refresh()->reference_count)->toBe(0)
        ->and($profilFil->refresh()->reference_count)->toBe(0);
});

/*
 * Klart när: webbens tre rutter svarar med sina flash-koder.
 */
it('svarar med flash-koderna på webbens tre rutter', function () {
    [$person] = hjaltbildKontext();

    somAnvandare($person);

    post(route('settings.profile.hero.store'), ['file' => hjaltbildFil()])
        ->assertSessionHas('status', 'hero-updated');

    expect($person->refresh()->hero_attachment_id)->not->toBeNull();

    patch(route('settings.profile.hero.crop'), ['x' => 40, 'y' => 60, 'zoom' => 150])
        ->assertSessionHas('status', 'hero-cropped');

    delete(route('settings.profile.hero.destroy'))
        ->assertSessionHas('status', 'hero-removed');

    expect($person->refresh()->hero_attachment_id)->toBeNull();
});

/*
 * Klart när: `AuthUserResource` bär `hero` i samma form som `avatar`, med
 * `{ 50, 50 }` och 100 när kolumnerna är NULL, och `null` när ingen bild
 * finns.
 */
it('bär hero i AuthUserResource med standardvärden', function () {
    [$person] = hjaltbildKontext();

    somAnvandare($person);

    get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.user.hero', null));

    $bilaga = app(SetUserHero::class)->handle($person, hjaltbildFil());

    ImageDerivative::factory()->create([
        'stored_file_id' => $bilaga->stored_file_id,
        'variant' => 'thumb',
    ]);

    get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.user.hero.ulid', $bilaga->ulid)
        ->where('auth.user.hero.has_thumb', true)
        ->where('auth.user.hero.focus', ['x' => 50, 'y' => 50])
        ->where('auth.user.hero.zoom', 100));

    app(SetUserHeroCrop::class)->handle($person, 20, 90, 220);

    get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.user.hero.focus', ['x' => 20, 'y' => 90])
        ->where('auth.user.hero.zoom', 220));
});

/*
 * Klart när: CHECK-villkoren för paret och zoomen finns, i samma form som
 * `avatar_*`.
 *
 * Villkoren läggs bara på mysql (sqlite kan inte ALTER TABLE ... ADD
 * CONSTRAINT), och formen bevisas av .github/workflows/migreringar.yml mot
 * MariaDB — samma motor som staging och produktion.
 */
it('kräver att hjältens fokuspunkt sätts som ett par och zoomen ligger i 100–300', function () {
    $person = User::factory()->create();

    expect(fn () => DB::table('user')->where('id', $person->id)->update(['hero_focus_x' => 30]))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('user')->where('id', $person->id)->update(['hero_focus_y' => 70]))
        ->toThrow(QueryException::class);

    DB::table('user')->where('id', $person->id)->update([
        'hero_focus_x' => 30,
        'hero_focus_y' => 70,
        'hero_zoom' => 180,
    ]);

    $person->refresh();
    expect($person->hero_focus_x)->toBe(30)
        ->and($person->hero_focus_y)->toBe(70)
        ->and($person->hero_zoom)->toBe(180);

    expect(fn () => DB::table('user')->where('id', $person->id)->update(['hero_zoom' => 99]))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('user')->where('id', $person->id)->update(['hero_zoom' => 301]))
        ->toThrow(QueryException::class);
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'mysql',
    'CHECK-villkoren finns bara på MariaDB; sviten kör sqlite.',
);

/*
 * Klart när: inget MCP-verktyg når hjältebilden (ADR-0090 § 7).
 *
 * ForbiddenDomains är den enda listan som prövas av ForbudTest, så ett nytt
 * verktyg som rör hjältebilden fälls av både namnet och actionklassen.
 */
it('förbjuder hjältebilden för MCP-verktyg', function () {
    expect(ForbiddenDomains::offence('profile_hero_set', []))->toBe('profile_hero_set')
        ->and(ForbiddenDomains::offence('items_create', [SetUserHero::class]))->toBe(SetUserHero::class)
        ->and(ForbiddenDomains::offence('items_create', [SetUserHeroCrop::class]))->toBe(SetUserHeroCrop::class)
        ->and(ForbiddenDomains::offence('items_create', [RemoveUserHero::class]))->toBe(RemoveUserHero::class)
        ->and(ForbiddenDomains::offence('items_create', []))->toBeNull();
});
