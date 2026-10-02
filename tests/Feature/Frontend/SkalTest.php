<?php

// rott-pa-basen: issue 82a — testfölje till att lang/sv utgår, ingen ändring av
// vad filen prövar; raden påstår samma engelska ord (`Settings`) som basens
// katalog bär, så filen kan inte falla utan språkbytet. Katalogen själv prövas i
// tests/Feature/Frontend/SprakTest.php.

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
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
    $sektioner = File::get(resource_path('js/components/ShellSections.vue'));

    // Raden bor i skalets sektionslista sedan issue 151 — samma lista som
    // sidomenyn ritar ur — och ritas bara för en inloggad användare, samma
    // `v-if="user"` som de fyra raderna ovanför (Beslut 2).
    // `matchPrefix` kom med issue 169: raden är den enda som också är aktuell
    // på en undersida, eftersom `/settings` omdirigerar till profilen (53c).
    expect($sektioner)->toContain("{ key: 'settings', href: '/settings', matchPrefix: true }")
        ->and($sektioner)->toContain('v-if="user"');

    // Listan ritas innanför `#huvudmenyn` — samma div som raderna låg i förut
    // (issue 68a § Beslut 2) — och den ritas av samma komponent som sidomenyn
    // (issue 151). En rad utanför den vore en rad som försvinner på en telefon.
    $start = (int) strpos($layout, 'id="huvudmenyn"');
    $menyn = substr($layout, $start, (int) strpos($layout, '</nav>', $start) - $start);

    expect($menyn)->toContain('<ShellSections');

    // Före utloggningen (Beslut 1), och som en <Link> med samma träffyta som
    // grannarna (Beslut 4) — ingen <div> med @click, som tappar tangentbordet.
    // Fokusringen blev en token i issue 169, när raderna flyttade in i
    // sidopanelen: `outline-none` utan en ring som tar över river
    // tangentbordsarbetet ([[ADR-0042 Designsystemet]] § Beslut).
    expect(strpos($sektioner, "{ key: 'settings'"))->toBeLessThan(strpos($sektioner, "t('auth.logout')"))
        ->and($sektioner)->toContain('class="inline-flex min-h-11 items-center rounded-control outline-none hover:underline focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"');

    // Texten bor i lang/ (Beslut 5).
    expect(trans('ui.nav.settings', [], 'en'))->toBe('Settings');

    // Och nyckeln följer med i de delade propsen till varje sida layouten
    // renderar, alltså är raden läsbar överallt och inte bara på en sida.
    // Användarens locale är oberoende av katalogens standardspråk: fabriken
    // sätter ingen `locale`, och en användare utan locale och utan entydigt
    // konto faller rakt igenom till `'en'` i LocaleResolver::forUser() — därför
    // det engelska ordet.
    actingAs(User::factory()->create())->get('/dashboard')->assertInertia(
        fn (AssertableInertia $page) => $page->where('locale', 'en')
            ->where('translations.nav.settings', 'Settings')
    );
});

/*
 * Issue 163 · Namnet i stället för ordet. Raden *Settings* i skalets
 * navigering bar ordet *Settings*, och den inloggade användarens namn stod som
 * en egen, oklickbar text bredvid. Namnet är data och ordet tog plats ingen
 * behövde: raden ritar nu namnet som sin text och pekar fortfarande på
 * `/settings`. Raden står kvar i `sections` på samma plats, och båda ytorna —
 * sidhuvudet över `md:` och sidomenyn under — ritas av samma komponent, så
 * ordningen är oförändrad.
 *
 * Klart när: namnet är länkens text och ordet *Settings* inte är det, namnet
 * ritas inte längre som en egen text utanför länken, länken bär `nav.settings`
 * som `title`, nyckeln finns kvar med värdet `Settings`, och en gäst ser
 * varken länken eller något namn.
 */
it('visar användarens namn som länken till inställningarna', function () {
    withoutVite();

    // Gästen prövas FÖRST, som i provet ovanför: actingAs() sätter guardens
    // användare för resten av testet, och därefter är varje anrop inloggat.
    get('/')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('auth.user', null)
    );

    $sektioner = File::get(resource_path('js/components/ShellSections.vue'));

    // Raden i mallen: samma `v-for` som grannarna, så ordningen är listans.
    // Fönstret är radens egen markup och inte filen i stort — etiketten och
    // `title` ska sitta på DEN raden.
    $start = (int) strpos($sektioner, 'v-for="section in sections"');
    $raden = substr($sektioner, $start, (int) strpos($sektioner, '</li>', $start) - $start);

    // Namnet är radens text. Grannarna väljer sin etikett ur nyckeln, och
    // raden för inställningarna väljer `user.name` i stället — ingen ny
    // översättningsnyckel, för namnet är data och inte text.
    expect($raden)->toContain('{{ section.key === \'settings\' ? user.name : t(`nav.${section.key}`) }}');

    // Ordet *Settings* är därför INTE radens text, men nyckeln bär vad raden
    // GÖR, och länken får den som `title` — bara inställningsraden, så att
    // grannarna inte får en tooltip de aldrig haft. Ingen `aria-label`, som
    // hade ersatt det synliga namnet.
    expect($raden)->toContain(':title="section.key === \'settings\' ? t(\'nav.settings\') : undefined"')
        ->and($raden)->not->toContain('aria-label');

    // Målet är oförändrat, och raden ritas bara för en inloggad användare:
    // `user` är `auth.user` ur den delade proppen, som är null för en gäst, så
    // en gäst ser varken länken eller något namn.
    expect($raden)->toContain('v-if="user"')
        ->and($sektioner)->toContain("{ key: 'settings', href: '/settings', matchPrefix: true }")
        ->and($sektioner)->toContain('const user = computed(() => page.props.auth.user)');

    // Namnet ritas på ETT ställe i filen — i länkens etikett — och inte i en
    // egen rad: `<li>`-raden med namnet i en `<span>` är borta.
    expect(substr_count($sektioner, 'user.name'))->toBe(1)
        ->and($raden)->not->toContain('<span')
        ->and($sektioner)->not->toContain('text-slate-600');

    // Nyckeln finns kvar med ordet ur katalogen: den bär fortfarande vad raden
    // gör, och två befintliga prov läser den.
    expect(trans('ui.nav.settings', [], 'en'))->toBe('Settings');

    // Och namnet kommer ur den delade proppen, inte ur en egen fråga på
    // klientsidan — samma `auth.user` som skalet ritar på varje sida.
    actingAs(User::factory()->create(['name' => 'Ada Lovelace']))->get('/dashboard')->assertInertia(
        fn (AssertableInertia $page) => $page->where('auth.user.name', 'Ada Lovelace')
    );
});

/*
 * Issue 122 · Vägen till uppgifterna. Todo-vyn flyttade från `/dashboard` till
 * `/tasks` när dashboarden tog över startsidan, och en vy som bara nås genom
 * att skriva adressen är en vy ingen hittar — samma skäl som länken till
 * inställningarna (issue 79, provet ovanför).
 *
 * Klart när: huvudmenyn länkar till `/tasks`, och raden följer med i det
 * hopfällda mobillaget som alla andra rader i `#huvudmenyn`.
 */
it('har en väg till uppgifterna i navigeringen för en inloggad och ingen för en gäst', function () {
    withoutVite();

    // Gästen prövas FÖRST: actingAs() sätter guardens användare för resten av
    // testet, och därefter är varje anrop inloggat.
    get('/')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('auth.user', null)
    );

    $sektioner = File::get(resource_path('js/components/ShellSections.vue'));

    // Raden ligger i skalets sektionslista, innanför samma `v-if="user"` som
    // de andra raderna (issue 68a § Beslut 2) och i samma lista som sidomenyn
    // ritar (issue 151).
    // Träffytan (44 px) och fokusringen delas av varje rad i skalet — se
    // provet för inställningsraden ovanför, där klassen står med skäl.
    expect($sektioner)->toContain("{ key: 'tasks', href: '/tasks' }")
        ->and($sektioner)->toContain('class="inline-flex min-h-11 items-center rounded-control outline-none hover:underline focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"');

    // Texten bor i lang/, och ordet är sidans eget (`todo.heading`) och inte
    // ruttens — användaren ska möta samma ord i menyn som på sidan.
    expect(trans('ui.nav.tasks', [], 'en'))->toBe('To do')
        ->and(trans('ui.todo.heading', [], 'en'))->toBe('To do');

    // Och nyckeln följer med i de delade propsen till varje sida layouten
    // renderar, alltså är raden läsbar överallt och inte bara på en sida.
    actingAs(User::factory()->create())->get('/dashboard')->assertInertia(
        fn (AssertableInertia $page) => $page->where('translations.nav.tasks', 'To do')
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

/*
 * Issue 169 · Skalets bredd. Klart när: innehållsytan är inte längre
 * `max-w-3xl` över `md:`.
 *
 * Skalet var en vit topprad och en yta på 768 px. Sidopanelen tar sin plats
 * bredvid ytan, och varje bild designern lämnat ritar en yta på över
 * 1 200 px ([[ADR-0050 Desktopdesignen]] § Kontext) — itemets trepanel
 * (issue 181) ska rymmas i den. Måttet prövas på skalet, inte i en webbläsare:
 * att 1 536 px ser rätt ut mot `docs/Design/main.jpeg` är handprov.
 */
it('släpper innehållsytan från max-w-3xl när skalet får sin sidopanel', function () {
    $layout = File::get(resource_path('js/layouts/AppLayout.vue'));

    // Ingen yta i skalet är kvar på 768 px — varken innehållet, toppraden
    // eller verifieringsbannern.
    expect($layout)->not->toContain('max-w-3xl');

    // Och den nya bredden står på <main>, där innehållet faktiskt ritas.
    preg_match('/<main\b[^>]*>/', $layout, $träff);

    expect($träff[0] ?? '')->toContain('max-w-[96rem]');

    // Sidopanelen är den mörka ytan ur ADR-0042 och ritas bara över `md:`.
    expect($layout)->toContain('bg-shell')
        ->toContain('md:flex');

    // Menyn ritar samma sektioner — se MobilskalTest, som äger mobilskalet.
    expect(File::get(resource_path('js/components/MobileMenu.vue')))
        ->toContain('<ShellContainerList');
});

/*
 * Issue 106 · Favoritlistan. Klart när: `FAVORITER`-sektionen är SKALETS och
 * inte en sidas.
 *
 * Den ritas ur den delade proppen, så den följer med varje sida layouten
 * wrappar utan att en enda sida importerar den. Provet läser samma rad på två
 * adresser — översikten och containersidan — och de två sidorna vet ingenting
 * om varandra. Att raden sedan filtreras på omfång prövas i
 * FavoritlistaTest; här prövas bara att den bor i skalet.
 */
it('ritar favoritlistan i skalet för varje sida layouten wrappar', function () {
    withoutVite();

    $konto = Account::factory()->create();
    $ägare = User::factory()->create();
    $konto->users()->attach($ägare, ['role' => 'owner']);

    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $ägare->id,
        'created_by_account_id' => $konto->id,
    ]);

    $url = "/containers/{$container->ulid}/items/{$item->ulid}";

    actingAs($ägare)->from($url)->post("{$url}/favorite")->assertRedirect($url);

    foreach (['/dashboard', '/containers'] as $sida) {
        actingAs($ägare)->get($sida)->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->where('favorites.0.name', 'Motorn')
        );
    }

    // Rubriken kommer ur lang/ som all annan text i layouten, och raden är
    // UiListRow — samma form som resten av skalet (issue 99).
    expect(trans('ui.nav.favorites', [], 'en'))->toBe('Favourites');

    // Sektionen ritas av `ShellSections part="favorites"` sedan issue 151:
    // samma komponent ritar den i skalets band över `md:` och i sidomenyn
    // under `md:`, så sektionen finns på båda ställena eller på inget av dem.
    expect(File::get(resource_path('js/components/ShellSections.vue')))
        ->toContain("import UiListRow from './UiListRow.vue'");

    expect(File::get(resource_path('js/layouts/AppLayout.vue')))
        ->toContain('<ShellSections part="favorites" />');
});

/*
 * Notisklockan, se issue 127 och resources/js/components/NotificationBell.vue.
 *
 * Klockan är sidhuvudets, som sökfältet: den hör till skalet och inte till en
 * sida, och den ritas bara för en inloggad — en gäst har inga notiser, och de
 * delade propsen bär noll för henne. Provet är samma form som länkprov
 * ovanför: villkoret och placeringen läses ur layouten, och nycklarna ur de
 * delade propsen på varje sida layouten wrappar.
 */
it('ritar notisklockan i sidhuvudet för en inloggad och ingen för en gäst', function () {
    withoutVite();

    // Gästen prövas FÖRST: actingAs() sätter guardens användare för resten av
    // testet, och därefter är varje anrop inloggat.
    get('/')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('auth.user', null)
    );

    $layout = File::get(resource_path('js/layouts/AppLayout.vue'));

    expect($layout)
        ->toContain("import NotificationBell from '../components/NotificationBell.vue'")
        ->and($layout)->toContain('<NotificationBell v-if="user" />');

    // Klockan står i sidhuvudet, bredvid sökfältet och ovanför <main> — den
    // ritas på varje sida layouten wrappar.
    $huvud = substr($layout, 0, (int) strpos($layout, '<main'));

    expect($huvud)
        ->toContain('<SearchField v-if="user" />')
        ->and($huvud)->toContain('<NotificationBell v-if="user" />');

    // Nycklarna följer med till varje sida. Siffran delas alltid; listan gör
    // det inte — den är optional och hämtas först när klockan öppnas.
    $anvandare = User::factory()->create();

    foreach (['/dashboard', '/containers'] as $sida) {
        actingAs($anvandare)->get($sida)->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('unreadNotificationCount', 0)
                ->missing('notifications')
        );
    }
});
