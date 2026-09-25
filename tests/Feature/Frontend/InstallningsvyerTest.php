<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * Issue 53c · Profil- och kontosidorna — vyerna och flödena. Se
 * resources/js/pages/Settings/Profile.vue, Settings/Accounts.vue,
 * resources/js/components/AccountSettingsForm.vue,
 * App\Http\Controllers\Settings\ProfileController och
 * App\Http\Controllers\Settings\AccountSettingsController.
 *
 * Den här filen prövar SIDORNA: att rutterna renderar rätt komponent, att
 * propsen bär användarens och kontots gällande värden, och att ett sparande
 * får genomslag i nästa anrop — inklusive språkbytet, som är det som gör
 * ytan till mer än ett formulär (Beslut 6).
 *
 * Behörigheten — rollerna, regel 4 och policyns oförändrade metoder — prövas
 * i tests/Feature/Konto/KontoinstallningarTest.php. Delningen är densamma som
 * mellan SakerhetsvyTest och backendflödena det hänvisar till: den här filen
 * svarar på "renderar sidan rätt", den andra på "får hon".
 *
 * Hjälparna har prefixet installning* för att inte krocka med de globala
 * hjälparna i andra Feature-filer (sprakKontext i SprakTest, kontoMedMedlem i
 * tests/Support/Testhjalpare.php) — Pest lägger alla filer i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem i angiven roll, och medlemmens egen locale.
 *
 * @param  array<string, mixed>  $kontoAttribut
 * @return array{0: Account, 1: User}
 */
function installningKonto(
    string $kontoLocale = 'sv_SE',
    ?string $anvandarLocale = null,
    string $roll = 'owner',
    array $kontoAttribut = [],
): array {
    $konto = Account::factory()->create([...$kontoAttribut, 'locale' => $kontoLocale]);
    $anvandare = User::factory()->create(['locale' => $anvandarLocale]);
    $konto->users()->attach($anvandare, ['role' => $roll]);

    return [$konto, $anvandare];
}

/**
 * Kroppen för PATCH /settings/profile — användarens gällande värden, med
 * valfria överstyrningar. Ett fält som inte nämns skickas alltså som det står
 * i databasen, vilket är vad formuläret gör.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function installningProfilKropp(User $anvandare, array $overrides = []): array
{
    return array_merge([
        'name' => $anvandare->name,
        'locale' => $anvandare->locale,
        'timezone' => $anvandare->timezone,
        'unit_system' => $anvandare->unit_system,
    ], $overrides);
}

/*
 * Beslut 1: `/settings` fick ett hem i den här issuen. 53b lämnade adressen
 * som en ärlig 404 med flit (issue 53b § Beslut 2) och utlovade att 53c gör
 * profilen till förstasidan — den här raden är den utlovningen infriad.
 * SakerhetsvyTest hade motsatt förväntan och är uppdaterad i samma PR.
 */
it('omdirigerar /settings till profilen', function () {
    withoutVite();

    actingAs(User::factory()->create())
        ->get('/settings')
        ->assertRedirect('/settings/profile');
});

it('skickar en utloggad besökare till inloggningen från alla tre sidorna', function () {
    withoutVite();

    foreach (['/settings', '/settings/profile', '/settings/accounts'] as $url) {
        get($url)->assertRedirect('/login');
    }
});

it('renderar Settings/Profile med användarens gällande värden', function () {
    withoutVite();

    $anvandare = User::factory()->create([
        'name' => 'Ada Lovelace',
        'locale' => 'en_GB',
        'timezone' => 'Europe/Oslo',
        'unit_system' => 'imperial',
    ]);

    actingAs($anvandare)->get('/settings/profile')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Settings/Profile')
        ->where('name', 'Ada Lovelace')
        ->where('email', $anvandare->email)
        ->where('emailVerifiedAt', fn ($varde) => is_string($varde))

        // Användarens EGNA värden, inte kontots: en användare med eget
        // `locale` ska se sitt, och "följ kontot" bara när kolumnen är tom.
        ->where('userLocale', 'en_GB')
        ->where('userTimezone', 'Europe/Oslo')
        ->where('userUnitSystem', 'imperial')

        // Den delade propen `locale` är språkKATALOGEN (`en`), orörd av
        // sidans egna värden — de två får inte blandas ihop (LocaleResolver).
        ->where('locale', 'en')

        // Tidszonslistan kommer från kontrollern (Beslut 2) och är samma
        // lista som valideringen använder — inte en datafil i resources/js/.
        ->where('timezones', fn ($zoner) => collect($zoner)->contains('Europe/Stockholm'))
    );
});

it('visar den overifierade adressen som overifierad', function () {
    withoutVite();

    actingAs(User::factory()->unverified()->create())
        ->get('/settings/profile')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('emailVerifiedAt', null));
});

/*
 * E-postformuläret, issue 130. Sidans andra formulär och det enda som behöver
 * veta något om kontot för att ritas: `hasPassword` väljer mellan formuläret
 * och hänvisningen till säkerhetssidan, och `totpEnabled` avgör om kodfältet
 * finns. Serverns halva är propparna — att ett konto utan lösenord ändå
 * avvisas prövas i tests/Feature/Auth/EpostbyteTest.php, och att fälten följer
 * propparna är `v-if` i komponenten.
 *
 * Propparna är JA/NEJ och inte hashen: `password_hash` är dold i
 * serialiseringen sedan issue 3, och en sidprop är samma yta som ett svar.
 */
it('säger att kontot har ett lösenord och om tvåfaktorn är på', function () {
    withoutVite();

    actingAs(User::factory()->create(['password_hash' => 'ratt-losenord']))
        ->get('/settings/profile')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('hasPassword', true)
            ->where('totpEnabled', false));

    somAnvandare(User::factory()->create(['password_hash' => null]))
        ->get('/settings/profile')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('hasPassword', false));
});

/*
 * Parentesen i "Följ kontots språk (svenska)" kommer ur kontot — servern
 * skickar värdet, vyn hittar inte på det. Är användaren medlem i exakt ett
 * konto går värdet att avgöra; annars är `accountDefaults` null och valet
 * står utan parentes (ProfileController::accountDefaults()).
 */
it('skickar kontots gällande värden för följ-alternativen', function () {
    withoutVite();

    [$konto, $anvandare] = installningKonto('sv_SE', 'en_GB');

    actingAs($anvandare)->get('/settings/profile')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('accountDefaults.locale', 'sv_SE')
        ->where('accountDefaults.timezone', $konto->timezone)
        ->where('accountDefaults.unitSystem', $konto->unit_system)
    );
});

it('lämnar följ-alternativet utan värde när användaren är med i flera konton', function () {
    withoutVite();

    $anvandare = User::factory()->create(['locale' => null]);

    foreach ([['sv_SE', 'Europe/Stockholm', 'metric'], ['en_GB', 'Europe/Oslo', 'imperial']] as [$locale, $zon, $enhet]) {
        Account::factory()
            ->create(['locale' => $locale, 'timezone' => $zon, 'unit_system' => $enhet])
            ->users()->attach($anvandare, ['role' => 'member']);
    }

    actingAs($anvandare)->get('/settings/profile')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('accountDefaults', null)
    );
});

it('renderar Settings/Accounts med ett kort per konto användaren är med i', function () {
    withoutVite();

    [$konto, $anvandare] = installningKonto('sv_SE', null, 'owner');

    // Ett konto hon INTE är med i — det ska inte ge något kort, och det är
    // hela skillnaden mellan listan och en öppen kontolista.
    Account::factory()->create(['name' => 'Främmande varv']);

    actingAs($anvandare)->get('/settings/accounts')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Settings/Accounts')
        ->has('accounts', 1)
        ->where('accounts.0.ulid', $konto->ulid)
        ->where('accounts.0.role', 'owner')
        ->where('accounts.0.canUpdate', true)
        ->where('accounts.0.locale', $konto->locale)
        ->where('accounts.0.timezone', $konto->timezone)
        ->where('accounts.0.unitSystem', $konto->unit_system)
        ->where('accounts', fn ($konton) => collect($konton)->doesntContain('name', 'Främmande varv'))
    );
});

it('sparar namn, locale, timezone och unit_system', function () {
    withoutVite();

    $anvandare = User::factory()->create(['name' => 'Gammalt namn']);
    actingAs($anvandare);

    from('/settings/profile')
        ->patch('/settings/profile', [
            'name' => 'Nytt namn',
            'locale' => 'en_GB',
            'timezone' => 'Europe/Oslo',
            'unit_system' => 'imperial',
        ])
        ->assertRedirect('/settings/profile')
        ->assertSessionHas('status', 'profile-updated');

    $anvandare->refresh();

    expect($anvandare->name)->toBe('Nytt namn');
    expect($anvandare->locale)->toBe('en_GB');
    expect($anvandare->timezone)->toBe('Europe/Oslo');
    expect($anvandare->unit_system)->toBe('imperial');
});

/*
 * Beslut 2: `null` betyder "följ kontots inställning", och det valet måste gå
 * att spara. Kolumnen är nullbar sedan issue 3 — det här är första gången
 * något skriver null i den.
 */
it('sparar null när användaren väljer att följa kontot', function () {
    withoutVite();

    $anvandare = User::factory()->create([
        'locale' => 'en_GB',
        'timezone' => 'Europe/Oslo',
        'unit_system' => 'imperial',
    ]);
    actingAs($anvandare);

    from('/settings/profile')
        ->patch('/settings/profile', installningProfilKropp($anvandare, [
            'locale' => null,
            'timezone' => null,
            'unit_system' => null,
        ]))
        ->assertRedirect('/settings/profile');

    $anvandare->refresh();

    expect($anvandare->locale)->toBeNull();
    expect($anvandare->timezone)->toBeNull();
    expect($anvandare->unit_system)->toBeNull();
});

it('avvisar en ogiltig tidszon på fältet timezone', function () {
    withoutVite();

    $anvandare = User::factory()->create(['timezone' => 'Europe/Stockholm']);
    actingAs($anvandare);

    $svar = from('/settings/profile')->patch('/settings/profile', installningProfilKropp($anvandare, [
        'timezone' => 'Mars/Olympus',
    ]));

    $svar->assertRedirect('/settings/profile');
    $svar->assertSessionHasErrors('timezone');

    expect($anvandare->fresh()->timezone)->toBe('Europe/Stockholm');
});

it('avvisar ett tomt namn på fältet name och skriver ingenting', function () {
    withoutVite();

    $anvandare = User::factory()->create(['name' => 'Behållet namn']);
    actingAs($anvandare);

    $svar = from('/settings/profile')->patch('/settings/profile', installningProfilKropp($anvandare, [
        'name' => '',
        'locale' => 'en_GB',
    ]));

    $svar->assertRedirect('/settings/profile');
    $svar->assertSessionHasErrors('name');

    // Valideringen faller innan modellen rörs: inget av fälten skrevs.
    $anvandare->refresh();

    expect($anvandare->name)->toBe('Behållet namn');
    expect($anvandare->locale)->toBeNull();
});

/*
 * Beslut 6, och det test som gör ytan till mer än ett formulär: språket slås
 * upp per anrop ur LocaleResolver och SetLocale, så nästa anrop är redan
 * engelskt. Ingen utloggning, ingen omladdning, ingen cache att tömma — och
 * skulle någon börja cacha språket någonstans det inte hör hemma, faller den
 * här raden.
 */
it('svarar engelska på nästa anrop efter att locale sparats som en_GB', function () {
    withoutVite();

    $anvandare = User::factory()->create(['locale' => 'sv_SE']);

    actingAs($anvandare)->get('/settings/profile')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('locale', 'en')
        ->where('userLocale', 'sv_SE')
        ->where('translations.settings.nav.profile', 'Profile')
    );

    from('/settings/profile')
        ->patch('/settings/profile', installningProfilKropp($anvandare, ['locale' => 'en_GB']))
        ->assertRedirect('/settings/profile');

    // Omdirigeringens mål, hämtat direkt efter: redan engelskt.
    actingAs($anvandare->fresh())->get('/settings/profile')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('locale', 'en')
        ->where('userLocale', 'en_GB')
        ->where('translations.settings.nav.profile', 'Profile')
    );
});

/*
 * Samma sak för kontot: en användare UTAN eget locale ärver kontots, och
 * `User::preferredLocale()` läser om kontot varje anrop. Att kontot byter
 * språk ska därför synas i användarens vyer utan att hon gjort något.
 */
it('ger engelska vyer när kontot byter till en_GB och användaren saknar egen locale', function () {
    withoutVite();

    [$konto, $anvandare] = installningKonto('sv_SE', null, 'owner');

    actingAs($anvandare)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('locale', 'en')
    );

    from('/settings/accounts')
        ->patch("/settings/accounts/{$konto->ulid}", [
            'name' => $konto->name,
            'locale' => 'en_GB',
            'timezone' => $konto->timezone,
            'unit_system' => $konto->unit_system,
        ])
        ->assertRedirect('/settings/accounts');

    // `fresh()` därför att testet delar modellinstans mellan anropen: PATCH:en
    // uppdaterar en annan Account-instans än den som hänger på användarens
    // redan laddade `accounts`-relation, och utan en omläsning svarar
    // User::preferredLocale() på det gamla kontot. I drift är varje anrop en
    // ny process och frågan ställs på nytt — det är den verkligheten testet
    // ska spegla.
    actingAs($anvandare->fresh())->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('locale', 'en')
        ->where('translations.nav.dashboard', 'Dashboard')
    );
});

/*
 * Navigationen växer i listan, inte i layouten (se SakerhetsvyTest för den
 * strukturella kontrollen). Här prövas att de två nya posterna finns och att
 * deras etiketter är formulerade.
 */
it('har Profil och Konton i inställningsnavigationen', function () {
    $sektioner = File::get(resource_path('js/layouts/settingsSections.js'));

    expect($sektioner)->toContain("href: '/settings/profile'");
    expect($sektioner)->toContain("href: '/settings/accounts'");

    foreach (['profile', 'accounts'] as $nyckel) {
        expect(trans("ui.settings.nav.{$nyckel}", [], 'en'))
            ->not->toBe("ui.settings.nav.{$nyckel}", "settings.nav.{$nyckel} saknas");
    }

});

/*
 * Nycklarna finns och vyn läser dem — en nyckel som finns men
 * inte används är en text ingen ser. Att ingen av dem är tom prövas dessutom av
 * SprakTest § "har inga tomma strängar i ui.php"; den här kontrollen fångar att
 * just de här texterna kom med.
 */
it('har profilens och kontots texter och läser dem ur lang/', function () {
    $nycklar = [
        'settings.profile.heading',
        'settings.profile.email_change.heading',
        'settings.profile.email_change.intro',
        'settings.profile.email_change.new_label',
        'settings.profile.email_change.current_label',
        'settings.profile.email_change.submit',
        'settings.profile.email_change.password_first',
        'settings.profile.email_change.taken',
        'settings.profile.locale_follow',
        'settings.profile.locale_follow_plain',
        'settings.profile.timezone_follow',
        'settings.profile.unit_follow',
        'settings.accounts.heading',
        'settings.accounts.intro',
        'settings.accounts.read_only',
        'settings.accounts.empty',
        'settings.locales.sv_SE',
        'settings.locales.en_GB',
        'settings.units.metric',
        'settings.units.imperial',
        'flash.profile-updated',
        'flash.account-updated',
        'flash.email-change-requested',
        'flash.email-changed',
    ];

    foreach ($nycklar as $nyckel) {
        $mening = trans("ui.{$nyckel}", [], 'en');

        expect($mening)->not->toBe("ui.{$nyckel}", "{$nyckel} saknas");
        expect(trim($mening))->not->toBe('');
    }

    // Texten lovar att följa kontots språk, och nämner kontot vid namn.
    expect(trans('ui.settings.profile.locale_follow', [], 'en'))
        ->toContain(':account');

    // Vyns enda väg till text går genom t(); står nycklarna inte i filerna är
    // de döda.
    $profil = File::get(resource_path('js/pages/Settings/Profile.vue'));

    foreach (['settings.profile.locale_follow', 'settings.profile.timezone_follow', 'settings.profile.unit_follow'] as $nyckel) {
        expect($profil)->toContain($nyckel);
    }

    // E-postformuläret bor i sin egen komponent (issue 130) och renderas av
    // profilsidan — en komponent ingen sida ritar är en yta ingen ser.
    expect($profil)->toContain('EmailChangeForm');

    $epostformulär = File::get(resource_path('js/components/EmailChangeForm.vue'));

    foreach (['settings.profile.email_change.heading', 'settings.profile.email_change.submit', 'settings.profile.email_change.password_first'] as $nyckel) {
        expect($epostformulär)->toContain($nyckel);
    }

    $konton = File::get(resource_path('js/pages/Settings/Accounts.vue'));

    expect($konton)->toContain('AccountSettingsForm');
    expect(File::get(resource_path('js/components/AccountSettingsForm.vue')))
        ->toContain('settings.accounts.submit');
});
