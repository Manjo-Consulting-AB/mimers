<?php

use App\Models\TotpRecoveryCode;
use App\Models\User;
use App\Support\Auth\RecoveryCodeBroker;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutVite;

/*
 * Issue 53b · Säkerhetssidan — tvåfaktorns aktivering, avstängning och
 * återställningskoderna. Se resources/js/pages/Settings/Security.vue,
 * resources/js/layouts/SettingsLayout.vue och
 * App\Http\Controllers\Settings\SecurityController.
 *
 * Backendflödena i sig prövas av tests/Feature/Auth/TotpAktiveringTest.php
 * och AterstallningskoderTest.php — de bevisar brokerklassernas beteende. Den
 * här filen prövar sidan ovanpå dem: att rutten renderar rätt komponent, att
 * de tre lägena följer av propsen, och att de två engångshemligheterna kommer
 * tillbaka EN gång och aldrig igen.
 *
 * Det serverhalvan inte kan avgöra är vad vyn renderar: att läge 1 saknar
 * kodfält, att kodfältet får fokus vid fel, och att varningarna står som text
 * är `v-if`, `focusFirstError` respektive `t()` i komponenten. Testerna nedan
 * bevisar serverns halva — att propsen är de rätta, att felet hamnar på
 * `code` som en färdig mening, och att vyn läser sina texter ur lang/ (den
 * sista kontrollen delas med SprakTest, som fäller varje svensk bokstav
 * utanför en kommentar i en .vue-fil).
 */

it('renderar Settings/Security för en inloggad användare', function () {
    withoutVite();

    actingAs(User::factory()->create())
        ->get('/settings/security')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Settings/Security'));
});

it('skickar en utloggad besökare till inloggningen', function () {
    withoutVite();

    get('/settings/security')->assertRedirect('/login');
});

/*
 * 53b lämnade /settings som en ärlig 404 med flit (§ Beslut 2) och utlovade
 * att 53c gör profilen till inställningarnas förstasida. Här är den
 * utlovningen infriad: adressen omdirigerar i stället för att rendera något
 * eget, och den som bokmärkt den hamnar rätt. Själva målet prövas i
 * InstallningsvyerTest.
 */
it('omdirigerar /settings till profilen sedan 53c', function () {
    withoutVite();

    actingAs(User::factory()->create())->get('/settings')->assertRedirect('/settings/profile');
});

/*
 * Läge 1. Att vyn därmed inte renderar något kodfält är `v-if` i komponenten;
 * serverns halva är att propsen inte bär något som kan öppna läge 2 eller 3.
 */
it('visar läge 1 för ett konto utan tvåfaktor — ingen hemlighet, inget ark', function () {
    withoutVite();

    actingAs(User::factory()->create())
        ->get('/settings/security')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('totpEnabled', false)
            ->where('totpConfirmedAt', null)
            ->where('totpUri', null)
            ->where('recoveryCodes', null)
            ->where('recoveryCodesRemaining', 0)
        );
});

/*
 * Läge 2, och engångsvisningen: POST /totp flashar `totp_uri`, `back()` landar
 * på den här sidan, och nästa rendering bär URI:n. Renderingen därpå — en
 * omladdning — bär den inte, för Laravel tömmer flash efter en request. Det är
 * samma två requests som en webbläsare gör.
 */
it('visar otpauth-URI:n efter POST /totp — och aldrig igen efter en omladdning', function () {
    withoutVite();

    $user = User::factory()->create();
    actingAs($user);

    from('/settings/security')
        ->post('/totp')
        ->assertRedirect('/settings/security')
        ->assertSessionHas('totp_uri');

    actingAs($user)->get('/settings/security')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('totpEnabled', false)
            ->where('totpUri', fn ($uri) => is_string($uri) && str_starts_with($uri, 'otpauth://totp/'))
        );

    actingAs($user)->get('/settings/security')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('totpUri', null)
            ->where('totpEnabled', false)
        );
});

it('bekräftar TOTP med rätt kod och visar läge 3', function () {
    withoutVite();

    $user = User::factory()->create();
    actingAs($user);

    postJson('/totp');
    $secret = $user->fresh()->totp_secret;

    from('/settings/security')
        ->post('/totp/confirm', ['code' => totpKodFör($secret)])
        ->assertRedirect('/settings/security')
        ->assertSessionHas('status', 'totp-confirmed');

    expect($user->fresh()->totp_confirmed_at)->not->toBeNull();

    actingAs($user)->get('/settings/security')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('totpEnabled', true)
            ->where('totpConfirmedAt', fn ($datum) => is_string($datum) && $datum !== '')
        );
});

it('visar fältfelet som en färdig mening när bekräftelsekoden är fel', function () {
    withoutVite();

    $user = User::factory()->create(['locale' => 'sv_SE']);
    actingAs($user);

    postJson('/totp');

    $svar = from('/settings/security')->post('/totp/confirm', ['code' => '000000']);

    $svar->assertRedirect('/settings/security');
    $svar->assertSessionHasErrors('code');

    // Meningen, inte nyckeln: `auth.totp_invalid` var det råa som syntes i
    // formuläret innan nycklarna fanns (se lang/en/auth.php).
    expect(session('errors')->get('code')[0])->toBe(trans('auth.totp_invalid', [], 'en'));
    expect(session('errors')->get('code')[0])->not->toBe('auth.totp_invalid');

    expect($user->fresh()->totp_confirmed_at)->toBeNull();
});

/*
 * Kodarket är den andra engångsvisningen, och går genom samma flash. Det
 * renderas bara i läge 3 i vyn (`v-if="props.recoveryCodes"`); serverns halva
 * är att det finns i propsen en gång och inte två.
 */
it('visar kodarket en gång efter POST /totp/recovery-codes', function () {
    withoutVite();

    [$user] = användareMedBekräftadTotp();
    actingAs($user);

    from('/settings/security')
        ->post('/totp/recovery-codes')
        ->assertRedirect('/settings/security')
        ->assertSessionHas('recovery_codes');

    actingAs($user)->get('/settings/security')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('recoveryCodes', 10)
            ->where('recoveryCodesRemaining', 10)
        );

    actingAs($user)->get('/settings/security')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('recoveryCodes', null)
            ->where('recoveryCodesRemaining', 10)
        );
});

/*
 * Antalet är oförbrukade rader, inte utfärdade: det ska sjunka när en kod
 * använts vid inloggning — se RecoveryCodeBroker::consume() och
 * SakerhetsvyTest-testets granne i tests/Feature/Auth/AterstallningskoderTest.php.
 */
it('räknar oförbrukade koder och räknar ned när en förbrukas vid inloggning', function () {
    withoutVite();

    [$user] = användareMedBekräftadTotp();
    $koder = RecoveryCodeBroker::generate($user);
    actingAs($user);

    actingAs($user)->get('/settings/security')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('recoveryCodesRemaining', 10));

    postJson('/api/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
        'code' => $koder[0],
    ])->assertOk();

    actingAs($user)->get('/settings/security')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('recoveryCodesRemaining', 9));
});

/*
 * Varningen på knappen, bevisad: en ny omgång via rutten dödar en kod ur det
 * FÖRSTA arket — RecoveryCodeBroker § Beslut 3.
 */
it('gör de gamla koderna obrukbara när nya genereras via sidan', function () {
    withoutVite();

    [$user] = användareMedBekräftadTotp();
    $gamlaKoder = RecoveryCodeBroker::generate($user);
    actingAs($user);

    from('/settings/security')->post('/totp/recovery-codes')->assertRedirect('/settings/security');

    $medGammalKod = postJson('/api/login', [
        'email' => $user->email,
        'password' => 'ratt-losenord',
        'code' => $gamlaKoder[0],
    ]);

    $medGammalKod->assertStatus(422);
    expect($medGammalKod->json('error.code'))->toBe('auth.totp_invalid');

    // Det nya arket är intakt: tio oförbrukade rader, ingen av dem förbrukad
    // av försöket ovan.
    expect(TotpRecoveryCode::query()->where('user_id', $user->id)->whereNull('used_at')->count())->toBe(10);
});

/*
 * Varningen på avstängningsknappen, bevisad: kodarket följer med TOTP:n ned
 * — RecoveryCodeBroker § Beslut 5.
 */
it('stänger av tvåfaktorn med rätt kod — läge 1 igen och kodarket borta', function () {
    withoutVite();

    [$user, $secret] = användareMedBekräftadTotp();
    RecoveryCodeBroker::generate($user);
    actingAs($user);

    from('/settings/security')
        ->delete('/totp', ['code' => totpKodFör($secret)])
        ->assertRedirect('/settings/security')
        ->assertSessionHas('status', 'totp-disabled');

    expect($user->fresh()->totp_confirmed_at)->toBeNull();
    expect(TotpRecoveryCode::query()->where('user_id', $user->id)->count())->toBe(0);

    actingAs($user)->get('/settings/security')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('totpEnabled', false)
            ->where('totpConfirmedAt', null)
            ->where('recoveryCodesRemaining', 0)
        );
});

it('ändrar ingenting när avstängningen får fel kod — felet hamnar på fältet', function () {
    withoutVite();

    [$user] = användareMedBekräftadTotp();
    RecoveryCodeBroker::generate($user);
    actingAs($user);

    $svar = from('/settings/security')->delete('/totp', ['code' => '000000']);

    $svar->assertRedirect('/settings/security');
    $svar->assertSessionHasErrors('code');

    expect(session('errors')->get('code')[0])->not->toBe('auth.totp_invalid');
    expect($user->fresh()->totp_confirmed_at)->not->toBeNull();
    expect(TotpRecoveryCode::query()->where('user_id', $user->id)->count())->toBe(10);
});

it('bär båda varningarna som text', function () {
    foreach (['recovery_warning', 'disable_warning'] as $nyckel) {
        $mening = trans("ui.settings.security.totp.{$nyckel}", [], 'en');

        expect($mening)->not->toBe("ui.settings.security.totp.{$nyckel}", "{$nyckel} saknas");
        expect(trim($mening))->not->toBe('');
    }

    // De två varningarna är inte samma mening — en nyckel som pekar på fel
    // text är en varning som säger fel sak.
    expect(trans('ui.settings.security.totp.recovery_warning', [], 'en'))
        ->not->toBe(trans('ui.settings.security.totp.disable_warning', [], 'en'));

    // Och att vyn faktiskt använder dem: en nyckel som finns men inte läses
    // är en varning ingen ser. Vyns enda väg till text går genom t().
    $vy = File::get(resource_path('js/pages/Settings/Security.vue'));

    foreach (['recovery_warning', 'disable_warning', 'code_label'] as $nyckel) {
        expect($vy)->toContain("settings.security.totp.{$nyckel}");
    }
});

/*
 * Navigationen är en lista, inte fyra hårdkodade länkar: en post till (53c,
 * 65, 66) läggs i settingsSections.js och kräver ingen ändring i layouten.
 */
it('renderar inställningsnavigationen ur en lista', function () {
    $layout = File::get(resource_path('js/layouts/SettingsLayout.vue'));

    expect($layout)->toContain('v-for="section in settingsSections"');
    expect($layout)->not->toContain('href="/settings');

    $sektioner = File::get(resource_path('js/layouts/settingsSections.js'));

    expect($sektioner)->toContain("href: '/settings/security'");

    // Och att säkerhetssidan är en av dem som renderas i skalet.
    expect(File::get(resource_path('js/pages/Settings/Security.vue')))->toContain('<SettingsLayout>');

    expect(trans('ui.settings.nav.security', [], 'en'))
        ->not->toBe('ui.settings.nav.security');

});
