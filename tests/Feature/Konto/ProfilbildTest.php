<?php

use App\Actions\User\DeleteUser;
use App\Actions\User\RemoveUserAvatar;
use App\Actions\User\SetUserAvatar;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\ImageDerivative;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 380 · Profilbildens skrivväg — uppladdning, byte, borttagning, kvot,
 * personkontot och livscykeln. Se [[ADR-0076 Profilbilden]] § 2, 3 och 5 samt
 * [[M59 Profilbilden]] § 380.
 *
 * Datamodellen, grinden och leveransen är § 379 och prövas i
 * tests/Feature/Bilaga/PersonbilagaTest.php. Ytan är § 381 och byggs inte här.
 *
 * Filen prövar § 6 i issuen:
 *
 * 1. En uppladdning skapar en personbilaga, sätter pekaren och ökar
 *    personkontots förbrukning med filens storlek.
 * 2. Ett byte rensar den gamla bilagan och minskar `reference_count`.
 * 3. Borttagningen nollställer pekaren och rensar bilagan.
 * 4. En fil som inte är en bild ger ett fältfel; en fil över kvoten ger ett
 *    fältfel och lämnar den gamla bilden orörd.
 * 5. En person utan personkonto får `avatar.no_personal_account`.
 * 6. `DeleteUser` lyckas för en person med profilbild och lämnar ingen
 *    personbilaga kvar.
 * 7. API-rutterna ger 201 och 204, och `avatar` i AuthUserResource speglar
 *    läget.
 *
 * Hjälparna har prefixet `profilbild` — Pests funktioner delar namnrymd när
 * hela sviten körs.
 */

beforeEach(function () {
    withoutVite();

    // Ingen miniatyrgenerering i samma request (QUEUE_CONNECTION=sync), och
    // inga byten i den riktiga storage/files/ — samma mönster som
    // ContainerbildTest och PersonbilagaTest.
    Queue::fake();
    Storage::fake('files');

    // Direkt leverans på appdomänen, som de andra bildproven.
    config(['files.internal_redirect' => false, 'files.url' => null]);
});

/**
 * En person med ett eget personkonto — kontot som betalar (ADR-0076 § 3).
 *
 * @return array{0: User, 1: Account}
 */
function profilbildKontext(): array
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
 * containerbildPng. Två anrop med olika färg ger olika `content_hash`, så ett
 * bildbyte kan skilja de två `stored_file`-raderna åt.
 */
function profilbildPng(int $röd = 255, int $grön = 0, int $blå = 0): string
{
    $bild = imagecreatetruecolor(20, 20);
    imagefilledrectangle($bild, 0, 0, 19, 19, imagecolorallocate($bild, $röd, $grön, $blå));

    ob_start();
    imagepng($bild);
    $byten = (string) ob_get_clean();
    imagedestroy($bild);

    return $byten;
}

/**
 * En uppladdad bild. `getMimeType()` i Illuminate\Http\Testing\File läser
 * filNAMNET, så ändelsen är det som avgör `kind` — samma väg som
 * containerbildFil tar.
 */
function profilbildFil(string $namn = 'profil.png', int $röd = 255, int $grön = 0, int $blå = 0): UploadedFile
{
    return UploadedFile::fake()->createWithContent($namn, profilbildPng($röd, $grön, $blå));
}

/*
 * Klart när: en uppladdning skapar en personbilaga, sätter pekaren och ökar
 * personkontots förbrukning med filens storlek.
 *
 * Personen är också medlem i en organisation, så provet bevisar att det är
 * PERSONKONTOT som belastas och inte vilket annat konto som helst
 * (ADR-0076 § 3).
 */
it('laddar upp en profilbild på personen och belastar personkontot', function () {
    [$person, $konto] = profilbildKontext();

    $organisation = Account::factory()->organisation()->create();
    $organisation->users()->attach($person, ['role' => 'member']);

    $byten = profilbildPng();
    $bilaga = app(SetUserAvatar::class)->handle(
        $person,
        UploadedFile::fake()->createWithContent('profil.png', $byten),
    );

    expect($bilaga->user_id)->toBe($person->id)
        ->and($bilaga->item_id)->toBeNull()
        ->and($bilaga->container_id)->toBeNull()
        ->and($bilaga->kind)->toBe('image')
        ->and($bilaga->billed_account_id)->toBe($konto->id)
        ->and($person->refresh()->avatar_attachment_id)->toBe($bilaga->id);

    expect(DB::table('usage_counter')->where('account_id', $konto->id)->value('storage_bytes'))
        ->toBe(strlen($byten));

    // Organisationskontot rörs inte.
    expect(DB::table('usage_counter')->where('account_id', $organisation->id)->exists())->toBeFalse();
});

/*
 * Klart när: ett byte rensar den gamla bilagan och minskar dess
 * `reference_count` exakt en gång.
 *
 * Den gamla bilagan RENSAS — den går inte till papperskorgen (ADR-0076 § 2) —
 * så raden är borta på riktigt och räknaren har gått från ett till noll.
 */
it('byter bild och rensar den förra bilagan', function () {
    [$person] = profilbildKontext();

    $första = app(SetUserAvatar::class)->handle($person, profilbildFil('första.png', 255, 0, 0));
    $förstaFil = $första->storedFile;

    $andra = app(SetUserAvatar::class)->handle($person, profilbildFil('andra.png', 0, 0, 255));

    expect($andra->id)->not->toBe($första->id)
        ->and($person->refresh()->avatar_attachment_id)->toBe($andra->id)
        ->and(Attachment::withTrashed()->whereKey($första->id)->exists())->toBeFalse()
        ->and($förstaFil->refresh()->reference_count)->toBe(0)
        ->and($andra->storedFile->refresh()->reference_count)->toBe(1);
});

/*
 * Klart när: borttagningen nollställer pekaren och rensar bilagan. Ett andra
 * anrop är en no-op och svarar false — ytan ska kunna visa "ta bort bilden"
 * utan att först veta om det finns någon.
 */
it('tar bort bilden, nollställer pekaren och tål att köras igen', function () {
    [$person] = profilbildKontext();

    $bilaga = app(SetUserAvatar::class)->handle($person, profilbildFil());
    $fil = $bilaga->storedFile;

    expect(app(RemoveUserAvatar::class)->handle($person))->toBeTrue();

    expect($person->refresh()->avatar_attachment_id)->toBeNull()
        ->and(Attachment::withTrashed()->whereKey($bilaga->id)->exists())->toBeFalse()
        ->and($fil->refresh()->reference_count)->toBe(0);

    expect(app(RemoveUserAvatar::class)->handle($person))->toBeFalse();
});

/*
 * Klart när: en fil som inte är en bild ger ett fältfel.
 *
 * Kravet ställs på den SNIFFADE typen, före varje skrivning: ingen
 * attachment-rad, ingen `stored_file` och ingen pekare.
 */
it('avvisar en fil som inte är en bild med ett fältfel', function () {
    [$person] = profilbildKontext();

    somAnvandare($person);

    post(route('settings.profile.avatar.store'), [
        'file' => UploadedFile::fake()->createWithContent('manual.pdf', 'en manual'),
    ])->assertSessionHasErrors('file');

    expect(Attachment::withTrashed()->count())->toBe(0)
        ->and($person->refresh()->avatar_attachment_id)->toBeNull();
});

/*
 * Klart när: en fil över kvoten ger ett fältfel och lämnar den gamla bilden
 * orörd.
 *
 * Den tidiga kontrollen i SetUserAvatar nekar innan något skrivs.
 * Totalkvoten prövas en gång till, auktoritativt, inne i StoreAttachments
 * transaktion (issue 27b § Beslut 4) — att den tidiga räcker här är hela
 * poängen med att den ligger före lagringen.
 */
it('nekar en fil över kvoten och lämnar den gamla bilden orörd', function () {
    sättPlangräns('free', 'storage_bytes', 10 * 1024 * 1024);

    [$person, $konto] = profilbildKontext();

    $gammal = app(SetUserAvatar::class)->handle($person, profilbildFil());

    // Räknaren ställs strax under taket, som en samtidig uppladdning hade
    // kunnat göra mellan den billiga kontrollen och lagringen.
    DB::table('usage_counter')->where('account_id', $konto->id)->update(['storage_bytes' => 9 * 1024 * 1024]);

    somAnvandare($person);

    post(route('settings.profile.avatar.store'), [
        'file' => UploadedFile::fake()->createWithContent('stor.png', str_repeat('a', 4 * 1024 * 1024)),
    ])->assertSessionHasErrors('file');

    expect($person->refresh()->avatar_attachment_id)->toBe($gammal->id)
        ->and(Attachment::withTrashed()->whereKey($gammal->id)->exists())->toBeTrue()
        ->and(Attachment::withTrashed()->count())->toBe(1)
        ->and(DB::table('usage_counter')->where('account_id', $konto->id)->value('storage_bytes'))
        ->toBe(9 * 1024 * 1024);
});

/*
 * Klart när: en person utan personkonto får `avatar.no_personal_account`.
 *
 * På webben blir koden ett fältfel på `file` och den översatta meningen
 * (App\Support\Frontend\ApiErrorTranslator); på `/api` står koden kvar i
 * höljet (AGENTS.md § Felformat). Ingen bilaga skrivs i något av fallen.
 */
it('nekar en person utan personkonto med avatar.no_personal_account', function () {
    $person = User::factory()->create();

    $organisation = Account::factory()->organisation()->create();
    $organisation->users()->attach($person, ['role' => 'member']);

    somAnvandare($person);

    post(route('settings.profile.avatar.store'), ['file' => profilbildFil()])
        ->assertSessionHasErrors(['file' => __('ui.error.avatar.no_personal_account')]);

    expect(Attachment::withTrashed()->count())->toBe(0);

    $token = $person->createToken('api');

    medToken($token->plainTextToken)
        ->postJson('/api/me/avatar', ['file' => profilbildFil()])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'avatar.no_personal_account');

    expect(Attachment::withTrashed()->count())->toBe(0);
});

/*
 * Klart när: `DeleteUser` lyckas för en person med profilbild och lämnar
 * ingen personbilaga kvar.
 *
 * `attachment.user_id` är ON DELETE RESTRICT, så utan rensningen i början av
 * raderingen hade personraden fallit på sin egen bild (ADR-0076 § 5).
 */
it('raderar en person med profilbild utan att lämna personbilagan kvar', function () {
    [$person] = profilbildKontext();

    $bilaga = app(SetUserAvatar::class)->handle($person, profilbildFil());
    $fil = $bilaga->storedFile;

    app(DeleteUser::class)->handle($person);

    expect(User::query()->whereKey($person->id)->exists())->toBeFalse()
        ->and(Attachment::withTrashed()->whereKey($bilaga->id)->exists())->toBeFalse()
        ->and(Attachment::withTrashed()->whereNotNull('user_id')->count())->toBe(0)
        ->and($fil->refresh()->reference_count)->toBe(0);
});

/*
 * Klart när: webbens två rutter finns med sina namn och svarar `back()` med
 * `avatar-updated` och `avatar-removed` (§ 3).
 */
it('svarar med flash-koderna på webbens två rutter', function () {
    [$person] = profilbildKontext();

    somAnvandare($person);

    post(route('settings.profile.avatar.store'), ['file' => profilbildFil()])
        ->assertSessionHas('status', 'avatar-updated');

    expect($person->refresh()->avatar_attachment_id)->not->toBeNull();

    delete(route('settings.profile.avatar.destroy'))
        ->assertSessionHas('status', 'avatar-removed');

    expect($person->refresh()->avatar_attachment_id)->toBeNull();
});

/*
 * Klart när: API-rutterna ger 201 och 204, och `avatar` i AuthUserResource
 * speglar läget.
 *
 * `has_thumb` är samma läsning som App\Actions\Item\ResolveItemCover: sant
 * först när varianten `thumb` finns. Inertia-proppen `auth.user` är samma
 * resurs som API-svaret bär, så båda ytorna prövas mot samma sanning.
 */
it('svarar 201 och 204 i api:et och speglar avatar i AuthUserResource', function () {
    [$person] = profilbildKontext();

    $token = $person->createToken('api');

    $svar = postJson('/api/me/avatar', ['file' => profilbildFil()], [
        'Authorization' => "Bearer {$token->plainTextToken}",
    ])->assertCreated();

    $bilaga = Attachment::withTrashed()->firstOrFail();

    expect($svar->json('data.avatar.ulid'))->toBe($bilaga->ulid)
        ->and($svar->json('data.avatar.has_thumb'))->toBeFalse();

    ImageDerivative::factory()->create([
        'stored_file_id' => $bilaga->stored_file_id,
        'variant' => 'thumb',
    ]);

    // API-anropet autentiserade en EGEN modell ur tokenet; testets instans
    // bär fortfarande den gamla pekaren och måste läsas om.
    $person->refresh();

    somAnvandare($person);

    get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.user.avatar.ulid', $bilaga->ulid)
        ->where('auth.user.avatar.has_thumb', true));

    medToken($token->plainTextToken)
        ->deleteJson('/api/me/avatar')
        ->assertNoContent();

    expect($person->refresh()->avatar_attachment_id)->toBeNull()
        ->and(Attachment::withTrashed()->whereKey($bilaga->id)->exists())->toBeFalse();

    somAnvandare($person);

    get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('auth.user.avatar', null));
});
