<?php

use App\Actions\Voucher\GenerateVoucher;
use App\Http\Controllers\InvitationResponseController;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\assertGuest;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutVite;
use function Pest\Laravel\withSession;

/*
 * Issue 263 (#789) · den privata betan i webben, se [[ADR-0055
 * Inbjudningskoder och stängd registrering]] § 1, § 2, § 3 och § 8.
 *
 * Servern kom med #788 (issue 262): AdmitRegistration avgör vilka som
 * släpps in, RedeemVoucher löser in koden och RedeemableVoucher nekar en
 * kod som inte går att använda. Den här filen prövar webbens halva — att
 * /register går genom samma action, att kontot får kodens plan, och att de
 * två vyerna bär läget.
 *
 * API:et stänger inte förrän #790 (issue 264); proven här rör därför bara
 * webbens rutt.
 *
 * Hjälparna har prefixet `privatBeta` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * En utestående containerinbjudan till adressen, med token hashad som i
 * produktionen — klartexten finns bara i mejlets länk.
 *
 * Samma form som inslappInbjudan i tests/Feature/Voucher/InslappTest.php:
 * AdmitRegistration prövar inbjudan med sin token och sitt `outstanding()`,
 * inte med adressen allena (ADR-0055 § 3).
 */
function privatBetaInbjudan(string $email, string $token): Invitation
{
    return Invitation::factory()->create([
        'email' => $email,
        'token_hash' => hash('sha256', $token),
        'status' => 'pending',
        'expires_at' => now()->addDays(7),
    ]);
}

/**
 * En källkod utan kommentarer. Samma tre slag som GenomgangTest, SprakTest
 * och InloggningssidaTest rensar: blockkommentarer, HTML-kommentarer och
 * radkommentarer — filerna är fulla av svensk prosa som förklarar varför,
 * och ett prov som letar efter en nyckel ska inte nöjas av en mening.
 */
function privatBetaKalla(string $sokvag): string
{
    $kod = File::get(resource_path($sokvag));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

it('invite_only utan kod nekas', function () {
    withoutVite();
    config(['konton.registration' => 'invite_only']);

    $svar = from('/register')->post('/register', [
        'name' => 'Ny Person',
        'email' => 'utan-kod@example.com',
        'password' => 'giltigt-losenord',
    ]);

    // ADR-0055 § 8: fältet är obligatoriskt i invite_only, och svaret är
    // `validation.required` på voucher_code. Meningen prövas ordagrant:
    // platshållaren byts ut av validatorn i AdmitRegistration::missingCode(),
    // och en rå `:attribute` i formuläret vore fel.
    $svar->assertSessionHasErrors(['voucher_code' => 'The voucher code field is required.']);

    expect(User::query()->where('email', 'utan-kod@example.com')->exists())->toBeFalse();
    assertGuest();
});

it('invite_only med giltig kod skapar kontot med pro', function () {
    withoutVite();
    config(['konton.registration' => 'invite_only']);

    ['voucher' => $voucher, 'code' => $kod] = app(GenerateVoucher::class)
        ->handle('pro', 365, 1, true, 'Testare', null);

    from('/register')->post('/register', [
        'name' => 'Ny Person',
        'email' => 'med-kod@example.com',
        'password' => 'giltigt-losenord',
        'voucher_code' => $kod,
    ])->assertRedirect('/dashboard');

    $user = User::query()->where('email', 'med-kod@example.com')->firstOrFail();

    expect($user->accounts()->firstOrFail()->currentPlan()->code)->toBe('pro');

    // Koden förbrukas i samma transaktion som kontot skapas (ADR-0055 § 6).
    expect($voucher->fresh()->used_count)->toBe(1);
});

it('en ogiltig kod nekas och skapar ingenting', function () {
    withoutVite();
    config(['konton.registration' => 'invite_only']);

    $svar = from('/register')->post('/register', [
        'name' => 'Ny Person',
        'email' => 'ogiltig-kod@example.com',
        'password' => 'giltigt-losenord',
        'voucher_code' => 'AAAA-BBBB-CCCC',
    ]);

    $svar->assertSessionHasErrors('voucher_code');

    expect(User::query()->where('email', 'ogiltig-kod@example.com')->exists())->toBeFalse();
    assertGuest();
});

it('en förbrukad kod nekas', function () {
    withoutVite();
    config(['konton.registration' => 'invite_only']);

    ['code' => $kod] = app(GenerateVoucher::class)->handle('pro', 365, 1, true, null, null);

    from('/register')->post('/register', [
        'name' => 'Första Personen',
        'email' => 'forsta@example.com',
        'password' => 'giltigt-losenord',
        'voucher_code' => $kod,
    ])->assertRedirect('/dashboard');

    // Ut ur sessionen mellan varven: rutten ligger bakom `guest`, och en
    // inloggad besökare skickas till /dashboard i stället för att prövas.
    post('/logout');

    $andra = from('/register')->post('/register', [
        'name' => 'Andra Personen',
        'email' => 'andra@example.com',
        'password' => 'giltigt-losenord',
        'voucher_code' => $kod,
    ]);

    $andra->assertSessionHasErrors('voucher_code');

    expect(User::query()->where('email', 'andra@example.com')->exists())->toBeFalse();
});

it('en containerinbjudan i sessionen släpper in utan kod och ger free', function () {
    withoutVite();
    config(['konton.registration' => 'invite_only']);

    $token = 'inbjudan-'.Str::random(32);
    privatBetaInbjudan('inbjuden@example.com', $token);

    withSession([InvitationResponseController::SESSION_KEY => $token])
        ->from('/register')
        ->post('/register', [
            'name' => 'Inbjuden Person',
            'email' => 'inbjuden@example.com',
            'password' => 'giltigt-losenord',
        ])
        ->assertRedirect('/dashboard');

    $user = User::query()->where('email', 'inbjuden@example.com')->firstOrFail();

    // Utan kod blir planen Free (ADR-0055 § 3).
    expect($user->accounts()->firstOrFail()->currentPlan()->code)->toBe('free');
});

it('en inbjudan till en annan adress släpper inte in', function () {
    withoutVite();
    config(['konton.registration' => 'invite_only']);

    $token = 'inbjudan-'.Str::random(32);
    privatBetaInbjudan('nagon-annan@example.com', $token);

    // Tokenet är rätt men adressen en annan: inbjudan bevisar att MEJLET nått
    // fram, och det har det inte till den här adressen (ADR-0055 § 3).
    $svar = withSession([InvitationResponseController::SESSION_KEY => $token])
        ->from('/register')
        ->post('/register', [
            'name' => 'Objuden Person',
            'email' => 'objuden@example.com',
            'password' => 'giltigt-losenord',
        ]);

    $svar->assertSessionHasErrors('voucher_code');

    expect(User::query()->where('email', 'objuden@example.com')->exists())->toBeFalse();
});

it('läget open släpper in utan kod', function () {
    withoutVite();
    config(['konton.registration' => 'open']);

    from('/register')->post('/register', [
        'name' => 'Öppen Person',
        'email' => 'oppen@example.com',
        'password' => 'giltigt-losenord',
    ])->assertRedirect('/dashboard');

    expect(User::query()->where('email', 'oppen@example.com')->exists())->toBeTrue();
});

it('ett tomt kodfält behandlas som ingen kod', function () {
    withoutVite();

    // Ett formulär skickar ett tomt textfält som en TOM STRÄNG, inte som ett
    // saknat fält. ConvertEmptyStringsToNull gör den till null, så `nullable`
    // håller och RedeemableVoucher aldrig prövas mot '' — annars hade ett
    // orört fält nekats i läget open och felat på fel regel i invite_only.
    config(['konton.registration' => 'invite_only']);

    from('/register')->post('/register', [
        'name' => 'Tomt Fält',
        'email' => 'tomt-falt@example.com',
        'password' => 'giltigt-losenord',
        'voucher_code' => '',
    ])->assertSessionHasErrors(['voucher_code' => 'The voucher code field is required.']);

    expect(User::query()->where('email', 'tomt-falt@example.com')->exists())->toBeFalse();

    config(['konton.registration' => 'open']);

    from('/register')->post('/register', [
        'name' => 'Tomt Fält',
        'email' => 'tomt-falt@example.com',
        'password' => 'giltigt-losenord',
        'voucher_code' => '',
    ])->assertRedirect('/dashboard');

    expect(User::query()->where('email', 'tomt-falt@example.com')->exists())->toBeTrue();
});

it('registreringssidan bär läget och inbjudan', function () {
    withoutVite();
    config(['konton.registration' => 'invite_only']);

    get('/register')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Auth/Register')
        ->where('registration.mode', 'invite_only')
        ->where('registration.invitation', false)
    );

    // Ett token i sessionen gör inbjudan sann — läget står kvar.
    $token = 'inbjudan-'.Str::random(32);
    privatBetaInbjudan('inbjuden@example.com', $token);

    withSession([InvitationResponseController::SESSION_KEY => $token])
        ->get('/register')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Auth/Register')
            ->where('registration.mode', 'invite_only')
            ->where('registration.invitation', true)
        );

    config(['konton.registration' => 'open']);

    get('/register')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('registration.mode', 'open')
    );
});

it('inloggningssidan har beta-texten i invite_only', function () {
    withoutVite();
    config(['konton.registration' => 'invite_only']);

    get('/login')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Auth/Login')
        ->where('registrationMode', 'invite_only')
    );

    // Vyn bär rubriken, och ritar den bara i invite_only. Källkodsprovet är
    // det som håller villkoret kvar: att texten faktiskt döljs i läget open
    // är `v-if` i komponenten och syns inte i ett serversvar.
    $kod = privatBetaKalla('js/pages/Auth/Login.vue');

    expect($kod)->toContain('auth.login.beta_heading')
        ->and($kod)->toContain('auth.login.beta_prompt')
        ->and($kod)->toContain('registrationMode === \'invite_only\'');
});
