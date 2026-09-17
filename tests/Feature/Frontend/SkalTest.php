<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\followingRedirects;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutVite;

/*
 * Issue 51 · Frontendskalet. Filen bevisar rutterna och vyerna: vilken
 * Inertia-komponent en URL renderar, vart en gäst skickas, att serverns
 * valideringsfel når formuläret, och att rotvyn går att cacha.
 *
 * De delade propsen och deras frågekostnad ligger i DeladePropsTest.php,
 * felhanteringen i FelsidorTest.php.
 *
 * Sedan issue 79 prövar filen också skalets väg till INSTÄLLNINGARNA: raden i
 * navigeringen och sträckan därifrån till säkerhetssidan, där tvåfaktorn slås
 * på. Villkoren — den inloggade användaren, det hopfällda mobillaget — är
 * skalets, och därför hör de hemma här och inte i en vyfil.
 */

it('renderar Dashboard för en inloggad användare', function () {
    withoutVite();

    actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Dashboard'));
});

it('skickar en utloggad besökare till inloggningssidan i stället för 405', function () {
    withoutVite();

    // `auth`-middlewaren skickar en gäst till route('login') — POST-rutten.
    // Utan GET-rutten på samma URL blev svaret 405 i stället för ett
    // formulär.
    get('/dashboard')
        ->assertRedirect('/login');
});

it('renderar Auth/Login för en gäst', function () {
    withoutVite();

    get('/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/Login'));
});

it('skickar en redan inloggad användare vidare till dashboard', function () {
    withoutVite();

    actingAs(User::factory()->create())
        ->get('/login')
        ->assertRedirect('/dashboard');
});

it('skickar tillbaka ett felaktigt lösenord med felet på fältet email', function () {
    withoutVite();

    User::factory()->create([
        'email' => 'nagon@example.com',
        'password_hash' => 'ratt-losenord',
    ]);

    from('/login')
        ->post('/login', ['email' => 'nagon@example.com', 'password' => 'fel-losenord'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');
});

it('renderar serverns valideringsfel på fältet email', function () {
    withoutVite();

    User::factory()->create([
        'email' => 'nagon@example.com',
        'password_hash' => 'ratt-losenord',
    ]);

    // Följ omdirigeringen tillbaka till formuläret: felet ligger i
    // sessionens felpåse, och Inertias middleware lägger den i propsen —
    // ingen egen `errors`-prop delas i HandleInertiaRequests. FormField
    // renderar `form.errors.email` under fältet.
    followingRedirects()
        ->from('/login')
        ->post('/login', ['email' => 'nagon@example.com', 'password' => 'fel-losenord'])
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Auth/Login')
            ->has('errors.email')
        );
});

it('cachar rotvyn och renderar en sida efteråt', function () {
    withoutVite();

    Artisan::call('view:cache');

    get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Welcome'));

    Artisan::call('view:clear');
});

/*
 * Issue 79 · Vägen till inställningarna. Sju inställningssidor var byggda och
 * ingen av dem gick att nå: sektionslistan i SettingsLayout renderas först på
 * en inställningssida, och AppLayout hade ingen rad dit. Det är inte
 * kosmetiskt — tvåfaktorn slås på under Säkerhet, och en användare som inte
 * hittar dit kan varken koppla sin kodapp eller stänga av den igen.
 *
 * Klart när: en inloggad användare når inställningarna från vilken sida som
 * helst, en utloggad besökare ser ingen länk, och raden följer med i det
 * hopfällda mobillaget.
 */
it('har en väg till inställningarna i navigeringen för en inloggad och ingen för en gäst', function () {
    withoutVite();

    // Gästen prövas FÖRST: actingAs() sätter guardens användare för resten av
    // testet, och därefter är varje anrop inloggat.
    get('/')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('auth.user', null)
    );

    $layout = File::get(resource_path('js/layouts/AppLayout.vue'));

    // Villkoret är den inloggade användaren ur den delade propen — samma
    // `v-if="user"` som de fyra raderna ovanför (Beslut 2).
    expect($layout)->toContain('<Link v-if="user" href="/settings"');

    // Raden ligger innanför `#huvudmenyn` — samma div som de andra länkarna
    // fälls ihop i (issue 68a § Beslut 2). En länk utanför den vore en länk
    // som försvinner på en telefon (Beslut 3).
    $menyn = substr($layout, (int) strpos($layout, 'id="huvudmenyn"'));

    expect($menyn)->toContain("t('nav.settings')");

    // Före utloggningen (Beslut 1), och som en <Link> med samma träffyta som
    // grannarna (Beslut 4) — ingen <div> med @click, som tappar tangentbordet.
    expect(strpos($menyn, "t('nav.settings')"))->toBeLessThan(strpos($menyn, "t('auth.logout')"))
        ->and($menyn)->toContain('href="/settings" class="inline-flex min-h-11 items-center');

    // Texten bor i lang/ på båda språken (Beslut 5), och orden är olika —
    // annars vore den ena översättningen en kopia.
    expect(trans('ui.nav.settings', [], 'sv'))->toBe('Inställningar')
        ->and(trans('ui.nav.settings', [], 'en'))->toBe('Settings');

    // Och nyckeln följer med i de delade propsen till varje sida layouten
    // renderar, alltså är raden läsbar överallt och inte bara på en sida.
    // Användarens locale är oberoende av katalogens standardspråk, och
    // fabriken ger `en_GB` — därför det engelska ordet.
    actingAs(User::factory()->create())->get('/dashboard')->assertInertia(
        fn (AssertableInertia $page) => $page->where('locale', 'en')
            ->where('translations.nav.settings', 'Settings')
    );
});

/*
 * Klart när: ett test visar vägen hela sträckan — inloggad användare →
 * navigeringens länk → säkerhetssidan, som är där tvåfaktorn slås på.
 *
 * Länken pekar på /settings, och omdirigeringen till profilen är 53c:s beslut:
 * adressen renderar ingenting eget (routes/web.php). Sektionslistan därifrån är
 * det andra klicket.
 */
it('når säkerhetssidan från navigeringen i två klick', function () {
    withoutVite();

    $anvandare = User::factory()->create();

    // Klick ett: raden i navigeringen.
    actingAs($anvandare)->get('/settings')
        ->assertRedirect('/settings/profile');

    actingAs($anvandare)->get('/settings/profile')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Settings/Profile'));

    // Klick två: säkerhetsraden i SettingsLayout — sidan som bär tvåfaktorn.
    actingAs($anvandare)->get('/settings/security')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Settings/Security'));
});

/*
 * Klart när: varje sida i settingsSections.js nås därifrån i högst två klick.
 *
 * Listan läses ur filen i stället för att skrivas av här: den är det enda som
 * växer när en sida kommer (issue 53b § Beslut 2), och en post som inte svarar
 * ska fällas här och inte först i webbläsaren.
 */
it('svarar på varje sektion i settingsSections efter två klick', function () {
    withoutVite();

    preg_match_all("/href: '([^']+)'/", File::get(resource_path('js/layouts/settingsSections.js')), $traffar);

    expect($traffar[1])->toContain('/settings/profile')
        ->and($traffar[1])->toContain('/settings/security');

    // Användaren är `owner`: webhookarnas sida kräver ett förval
    // (App\Policies\AccountPolicy::manageWebhooks()).
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    // Klick ett: /settings, som landar på profilen.
    actingAs($anvandare)->get('/settings')->assertRedirect('/settings/profile');

    foreach ($traffar[1] as $url) {
        // Klick två: raden i SettingsLayout.
        actingAs($anvandare)->get($url)->assertOk();
    }
});
