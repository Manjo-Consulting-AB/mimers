<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\User;
use App\Support\Notification\LocaleResolver;
use App\Support\Notification\UnsubscribeLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\withHeaders;
use function Pest\Laravel\withoutVite;

/*
 * Issue 52 · Språk i frontenden, se [[ADR-0013 Språk och i18n]],
 * App\Http\Middleware\SetLocale och App\Http\Middleware\HandleInertiaRequests.
 *
 * Filen bevisar reglerna i ADR-0013 § Konsekvenser — användarens `locale`
 * åsidosätter kontots, språket kommer aldrig ur requestens `Accept-Language`,
 * och frontenden får sin text ur `lang/`, bara ur ui.php — och de regler
 * [[ADR-0034 Engelska vid lansering]] ändrade: `en` är den ENDA levererade
 * katalogen, ingen väljare byggs, och ett språk till läggs genom att lägga en
 * katalog under `lang/`.
 *
 * Kontona och användarna i filen har svensk locale med flit. Det är hela
 * beviset: locale-kolumnen står kvar enligt ADR-0013, men en svensk locale
 * möts av engelska därför att katalogen inte finns.
 */

/**
 * Ett konto och en medlem med önskade språk — samma form som
 * avregistreringsKontext i tests/Feature/Notis/AvregistreringsTest.php.
 *
 * @return array{0: Account, 1: User}
 */
function sprakKontext(string $kontoLocale = 'sv_SE', ?string $anvandarLocale = null): array
{
    $konto = Account::factory()->create(['locale' => $kontoLocale]);
    $anvandare = User::factory()->create(['locale' => $anvandarLocale]);
    $konto->users()->attach($anvandare, ['role' => 'member']);

    return [$konto, $anvandare];
}

/**
 * Den delade prop-listan för en sida, läst ur Inertias rotvy.
 *
 * @return array<string, mixed>
 */
function sprakEgenskaper(User $anvandare): array
{
    /** @var array{props: array<string, mixed>} $sida */
    $sida = actingAs($anvandare)->get('/dashboard')->assertOk()->viewData('page');

    return $sida['props'];
}

/**
 * En språkfil som en platt lista punktnycklar → värde, så att varje nyckel kan
 * prövas för sig.
 *
 * @param  array<mixed>  $gren
 * @return array<string, string>
 */
function sprakLov(array $gren, string $prefix = ''): array
{
    $lov = [];

    foreach ($gren as $nyckel => $varde) {
        $nyckel = $prefix === '' ? (string) $nyckel : $prefix.'.'.$nyckel;

        $lov = is_array($varde) ? [...$lov, ...sprakLov($varde, $nyckel)] : [...$lov, $nyckel => (string) $varde];
    }

    return $lov;
}

/**
 * @return array<string, mixed>
 */
function sprakFil(string $locale): array
{
    /** @var array<string, mixed> $ui */
    $ui = Lang::get('ui', [], $locale);

    return $ui;
}

/**
 * Kör translate() ur resources/js/i18n/translate.js i node.
 *
 * Sökvägen går genom pathToFileURL() i stället för att klistras in rått: en
 * Windows-sökväg (`C:\...`) är ingen giltig ESM-specificerare.
 */
function korTranslate(string $anrop): string
{
    $skript = implode("\n", [
        "const { pathToFileURL } = await import('node:url');",
        'const { translate } = await import(pathToFileURL('
            .json_encode(resource_path('js/i18n/translate.js'), JSON_UNESCAPED_SLASHES).').href);',
        'const translations = '.json_encode([
            'nav' => ['dashboard' => 'Dashboard'],
            'error' => ['title' => 'Error :status'],
        ], JSON_UNESCAPED_UNICODE).';',
        "process.stdout.write(String(translate(translations, {$anrop})));",
    ]);

    $rader = [];
    $kod = 0;

    exec('node --input-type=module -e '.escapeshellarg($skript).' 2>&1', $rader, $kod);

    expect($kod)->toBe(0, implode("\n", $rader));

    return implode("\n", $rader);
}

it('delar locale och översättningar på varje webbsida', function () {
    withoutVite();

    [, $anvandare] = sprakKontext();

    // /login är en gästvy — `guest`-middlewaren skickar en inloggad vidare
    // till /dashboard — så den läses först, innan actingAs() sätter guardens
    // användare för resten av testet.
    get('/login')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('locale', config('app.locale'))
        ->has('translations.auth.login')
    );

    foreach (['/', '/dashboard'] as $url) {
        actingAs($anvandare)->get($url)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('locale', 'en')
            ->has('translations.nav')
        );
    }
});

it('har en enda språkkatalog, och den heter en', function () {
    expect(array_map(fn (string $sokvag): string => basename($sokvag), File::directories(lang_path())))
        ->toBe(['en']);
});

/*
 * LocaleResolver äger valet av katalog, och regeln är katalogen och inte
 * språklistan: en användare vars locale pekar på en katalog som inte finns får
 * `en`. Utan den regeln valde leveransloopen `sv` åt en svensk mottagare,
 * `Lang::has(…, 'sv', false)` svarade nej, och varje mejl blev `failed` i
 * stället för skickat — se EmailChannel.
 */
it('väljer aldrig en locale som saknar katalog', function () {
    $resolver = app(LocaleResolver::class);

    $svensk = User::factory()->create(['locale' => 'sv_SE']);

    expect($resolver->forUser($svensk))->toBe('en');
    expect($resolver->forUser(null))->toBe('en');
    expect($resolver->forUser(User::factory()->create(['locale' => null])))->toBe('en');
});

it('ger engelska åt en medlem med svensk locale', function () {
    withoutVite();

    [, $medlem] = sprakKontext('sv_SE', 'sv_SE');

    actingAs($medlem)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('locale', 'en')
        ->where('translations.nav.dashboard', 'Dashboard')
    );
});

it('ger engelska när användaren saknar egen locale och kontot är svenskt', function () {
    withoutVite();

    [, $medlem] = sprakKontext('sv_SE', null);

    actingAs($medlem)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('locale', 'en')
        ->where('translations.nav.dashboard', 'Dashboard')
    );
});

it('ger engelska för en användare utan egen locale i två konton', function () {
    withoutVite();

    $anvandare = User::factory()->create(['locale' => null]);

    foreach (['sv_SE', 'en_GB'] as $locale) {
        Account::factory()->create(['locale' => $locale])->users()->attach($anvandare, ['role' => 'member']);
    }

    // User::preferredLocale() ger null när kontona inte är entydiga, och
    // LocaleResolver gör null till `en`.
    actingAs($anvandare)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('locale', 'en')
    );
});

it('renderar ett gästanrop på appens standardspråk', function () {
    withoutVite();

    get('/')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('locale', config('app.locale'))
        ->where('translations.nav.login', 'Log in')
    );
});

/*
 * Accept-Language läses aldrig, och det finns inget att välja emellan: samma
 * svenska konto möts av engelska oavsett vad webbläsaren ber om. Rubriken står
 * kvar från issue 52 — regeln är äldre än ADR-0034, som gjorde svaret
 * entydigt i stället för beroende av vilken locale användaren hade.
 */
it('läser aldrig requestens Accept-Language', function () {
    withoutVite();

    [, $medlem] = sprakKontext('sv_SE', 'sv_SE');

    foreach (['sv-SE,sv;q=0.9', 'en-US,en;q=0.9'] as $huvud) {
        withHeaders(['Accept-Language' => $huvud])
            ->actingAs($medlem)
            ->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('locale', 'en')
                ->where('translations.nav.dashboard', 'Dashboard')
            );
    }
});

it('sätter html-attributet lang till en', function () {
    withoutVite();

    [, $svensk] = sprakKontext('sv_SE', 'sv_SE');

    actingAs($svensk)->get('/dashboard')->assertSee('lang="en"', false);
});

it('delar bara nycklarna ur ui.php med frontenden', function () {
    withoutVite();

    [, $anvandare] = sprakKontext();

    actingAs($anvandare)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->has('translations.common')
        // notiser.php och export.php är serverrenderat innehåll — mejl, ICS,
        // PDF — och når aldrig en Vue-komponent.
        ->missing('translations.task_due')
        ->missing('translations.unsubscribe')
        ->missing('translations.heading')
    );
});

it('returnerar nyckeln själv när översättningen saknas', function () {
    expect(korTranslate("'finns.inte'"))->toBe('finns.inte');
});

it('byter ut :param mot det skickade värdet', function () {
    expect(korTranslate("'error.title', { status: 404 }"))->toBe('Error 404');
});

it('resolvar auth.totp_required och auth.totp_invalid', function () {
    foreach (['totp_required', 'totp_invalid'] as $nyckel) {
        $mening = trans("auth.{$nyckel}", [], 'en');

        expect($mening)->not->toBe("auth.{$nyckel}", "auth.{$nyckel} saknas");
        expect($mening)->not->toBe('');
    }
});

/*
 * Valideringsmeningarna kommer ur `validation.php`, och `en`-katalogen har
 * ingen egen fil för den: nycklarna löses ur ramverkets katalog, som FileLoader
 * läser vid sidan av appens (se lang/en/auth.php). Att både en svensk och en
 * engelsk användare får engelska prövar att reserven fungerar för en locale
 * som inte längre har en fil.
 */
it('renderar valideringsfel på engelska för varje användare', function () {
    withoutVite();

    Route::middleware('web')->post('/test-validering', function (Request $request): void {
        $request->validate(['email' => ['required']]);
    });

    [, $svensk] = sprakKontext('sv_SE', 'sv_SE');
    [, $engelsk] = sprakKontext('sv_SE', 'en_GB');

    foreach ([$svensk, $engelsk] as $anvandare) {
        actingAs($anvandare)->post('/test-validering', ['email' => ''])->assertSessionHasErrors('email');

        expect(session('errors')->get('email')[0])->toContain('required');
    }
});

/*
 * Inloggningens POST sker av en GÄST — anroparen är inte autentiserad, så
 * SetLocale har ingen användare att läsa locale ur och språket blir appens
 * standard (`config('app.locale')`). Det är samma regel som för ett gästanrop
 * till `/`.
 */
it('renderar gästens POST /login-validering på appens standardspråk', function () {
    withoutVite();

    from('/login')->post('/login', ['email' => '', 'password' => ''])->assertSessionHasErrors('email');

    expect(session('errors')->get('email')[0])
        ->toBe(trans('validation.required', ['attribute' => 'email'], config('app.locale')));
});

it('har inga tomma strängar i ui.php', function () {
    foreach (sprakLov(sprakFil('en')) as $nyckel => $varde) {
        expect(trim($varde))->not->toBe('', "ui.{$nyckel} är tom");
    }
});

/*
 * Datumregeln, se issue 104 och [[ADR-0042 Designsystemet]] § Konsekvenser.
 *
 * Även datumsträngarna kommer ur `lang/`: regeln väljer NYCKEL och katalogen
 * äger orden. Nycklarna läses ur källkoden i stället för att räknas upp här —
 * en mening som läggs till i regeln och glöms i katalogen faller då, och en
 * nyckel som byter namn följer med utan att provet skrivs om.
 *
 * Den sista raden är den som stänger den andra vägen in: en färdig mening
 * skriven direkt i komposabeln är samma fel som en hårdkodad sträng i en
 * komponent, och den hade annars passerat obemärkt eftersom regeln är
 * engelskt textad (svenskprovet ovan letar bara efter å, ä och ö).
 */
it('hämtar datumsträngarna ur ui.php och inte ur komposabeln', function () {
    $nycklar = [];

    foreach (['js/composables/useRelativeDate.js'] as $fil) {
        preg_match_all("/t\\('([a-z0-9_.]+)'/", File::get(resource_path($fil)), $träffar);

        $nycklar = [...$nycklar, ...$träffar[1]];
    }

    $datum = array_values(array_filter($nycklar, fn (string $nyckel): bool => str_starts_with($nyckel, 'date.')));

    expect($datum)->not->toBeEmpty('datumregeln väljer inga nycklar ur lang/');

    foreach ($datum as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "ui.{$nyckel} saknas");
    }

    $kod = (string) preg_replace('#/\*.*?\*/#s', '', File::get(resource_path('js/composables/useRelativeDate.js')));

    expect($kod)->not->toMatch('/\b(Today|Tomorrow|Overdue by)\b/');
});

/*
 * Varje handling i händelseloggen har en mening (issue 116).
 *
 * Historikflikarna formulerar varje rad ur handlingens namn —
 * `audit.action.<handling>`, se resources/js/components/HistoryRow.vue — och
 * `translate()` skriver NYCKELN SJÄLV när uppslaget misslyckas (provet strax
 * ovanför pinnar det). En handling utan mening syns därför inte som ett fel i
 * vyn: den syns som `audit.action.item.created` i en rad, på engelska, i en
 * produkt vars enda katalog är engelsk ([[ADR-0034 Engelska vid lansering]]).
 *
 * **Nycklarna räknas upp ur konstanterna och inte ur en avskrift här.**
 * Handlingarna är ett ÖPPET namnrum — `AuditLog` har ingen `ACTIONS`-lista med
 * flit (se modellens docblock), bara konstanterna — så avskriften hade varit
 * en andra lista att hålla i takt, och den hade glidit isär inom en milstolpe.
 * En handling som läggs till och glöms i katalogen faller här, och det är
 * samma form som provet för skalets strängar och datumregelns.
 *
 * Kontoraderna (`account.deleted`) och gallringsraden (`container.purged`)
 * prövas som alla andra: de skrivs av ett jobb och inte av en person, men de
 * LÄSES av en människa inom sina tolv månader (issue 115), och en rad utan
 * mening är lika oläslig vem som än skrev den.
 */
it('har en mening åt varje handling i händelseloggen', function () {
    $lov = sprakLov(sprakFil('en'));

    $handlingar = [];

    foreach ((new ReflectionClass(AuditLog::class))->getConstants() as $namn => $varde) {
        if (str_starts_with($namn, 'ACTION_') && is_string($varde)) {
            $handlingar[$namn] = $varde;
        }
    }

    expect($handlingar)->not->toBeEmpty('AuditLog har inga ACTION_-konstanter');

    foreach ($handlingar as $namn => $handling) {
        $mening = $lov["audit.action.{$handling}"] ?? '';

        expect($mening)->not->toBe('', "ui.audit.action.{$handling} saknas ({$namn})");
    }
});

/*
 * Skalets egna strängar, se issue 106 och resources/js/layouts/AppLayout.vue.
 *
 * `translate()` returnerar NYCKELN SJÄLV när uppslaget misslyckas (provet
 * strax ovanför pinnar det), så en nyckel som glöms i katalogen syns inte som
 * ett fel — den syns som `nav.favorites` i rubriken. Provet läser nycklarna ur
 * källkoden i stället för att räkna upp dem, samma form som datumregelns prov:
 * en mening som läggs till i layouten och glöms i katalogen faller då, och en
 * nyckel som byter namn följer med utan att provet skrivs om.
 */
it('hämtar skalets strängar ur ui.php', function () {
    $nycklar = [];

    /*
     * Notisklockan kom med issue 127 och ligger i samma svep som layouten:
     * den är sidhuvudets, och dess två egna nycklar — `inbox.label`
     * och `inbox.empty` — är skalets. Radmeningarna står inte här:
     * de byggs ur radens TYP och prövas i provet nedanför, där nycklarna
     * räknas upp ur `Notification`-konstanterna i stället för ur en avskrift.
     */
    /*
     * Mobilskalet kom med issue 151 och ligger i samma svep: toppraden,
     * flikraden och sidomenyn är skalets ytor precis som sidhuvudet, och
     * sektionerna flyttade ur AppLayout till ShellSections. Ett uppslag som
     * glöms i katalogen renderas som sin egen nyckel — `nav.tabbar` i en
     * etikett — och det är vad raden nedan fångar.
     */
    /*
     * Plusknappen och arket kom med issue 152 och ligger i samma svep: knappen,
     * arket och menyn är skalets ytor precis som flikraden och sidomenyn, och
     * deras ord — `create.label`, `create.close`, `create.heading` — är
     * skalets. Menyradernas etiketter byggs ur radens nyckel och fångas därför
     * inte av mönstret nedan; de prövas i tests/Feature/Frontend/
     * PlusknappTest.php.
     */
    foreach ([
        'js/layouts/AppLayout.vue',
        'js/layouts/ContainerLayout.vue',
        'js/components/NotificationBell.vue',
        'js/components/ShellSections.vue',
        'js/components/MobileTabBar.vue',
        'js/components/MobileMenu.vue',
        'js/components/CreateButton.vue',
        'js/components/CreateMenu.vue',
        'js/components/UiSheet.vue',
        // *Nyligen besökta* kom med issue 160 och ligger i samma svep: raden
        // är skalets, och `nav.recent_visits` är dess enda nya nyckel —
        // ordet om tiden kommer ur `date.*` och skrivs av useRelativeDate().
        'js/components/RecentVisitList.vue',
        // Containerlistan kom med issue 169 och ligger i samma svep av samma
        // skäl: raden är skalets. Dess två nycklar är `nav.containers` —
        // listans tillgängliga namn — och `dashboard.containers.others`, ordet
        // för högen. Arterubrikerna fångas inte av mönstret och ska inte
        // heller: en art är användarens egen sträng ([[ADR-0036 Containerns
        // art]]) och slås aldrig upp i katalogen.
        'js/components/ShellContainerList.vue',
        // Hjälten kom med issue 170 och ligger i samma svep av samma skäl: den
        // ritas av containerns skal på varje flik, och dess två egna ord är
        // `container.hero.edit` — knappen som ersätter raden *Inställningar* i
        // flikraden ([[ADR-0050 Desktopdesignen]] § 2–3) — och
        // `container.nav.settings`, läsarens länk till samma sida.
        // Artens värde och containerns namn är användarens egna strängar och
        // slås aldrig upp.
        'js/components/ContainerHero.vue',
    ] as $fil) {
        /*
         * Fönstret `(?<![\w$.])` är det som skiljer ett uppslag från ett
         * anrop — samma som i proven för lösenords- och e-postformuläret.
         * Sidomenyn stänger sig med `emit('close')`, och utan fönstret hade
         * `t('close')` inuti det anropet lästs som en översättningsnyckel.
         */
        preg_match_all("/(?<![\w$.])t\('([a-z0-9_.]+)'/", File::get(resource_path($fil)), $träffar);

        $nycklar = [...$nycklar, ...$träffar[1]];
    }

    // Favoritlistans rubrik kom med issue 106 och är skalets enda nya nyckel.
    expect($nycklar)->toContain('nav.favorites');

    expect($nycklar)->toContain('inbox.label');
    expect($nycklar)->toContain('inbox.empty');

    foreach (array_unique($nycklar) as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "ui.{$nyckel} saknas");
    }

    /*
     * Sektionernas etiketter slås upp med en BYGGD nyckel —
     * ``t(`nav.${section.key}`)`` — och fångas därför inte av mönstret ovan.
     * Nycklarna läses ur listan i stället för att skrivas av här, av samma
     * skäl som proven ovanför: en rad som läggs till i skalet och glöms i
     * katalogen ska falla, och en rad som byter namn ska följa med utan att
     * provet skrivs om.
     */
    preg_match_all("/key: '([a-z0-9_]+)', href/", File::get(resource_path('js/components/ShellSections.vue')), $sektioner);

    expect($sektioner[1])->toContain('dashboard')->toContain('settings');

    foreach ($sektioner[1] as $nyckel) {
        expect(Lang::get("ui.nav.{$nyckel}", [], 'en'))->not->toBe("ui.nav.{$nyckel}", "ui.nav.{$nyckel} saknas");
    }
});

/*
 * Formulärets och föräldraväljarens strängar (issue 153), samma form som
 * skalets prov ovanför: nycklarna läses ur källkoden i stället för att räknas
 * upp här, så en mening som läggs till i formuläret och glöms i katalogen
 * faller — och den syns annars som sin egen nyckel, `item.form.location`, i
 * placeringsraden.
 *
 * `ParentPicker` är ingen `Ui*.vue` och prövas därför inte av provet för
 * hårdkodad text; den bär sina ord i `t()` som varje annan komponent, och det
 * är vad raden nedan håller fast.
 */
it('hämtar formulärets och väljarens strängar ur ui.php', function () {
    $nycklar = [];

    foreach ([
        'js/components/ItemForm.vue',
        'js/components/ParentPicker.vue',
    ] as $fil) {
        preg_match_all("/(?<![\w$.])t\('([a-z0-9_.]+)'/", File::get(resource_path($fil)), $träffar);

        $nycklar = [...$nycklar, ...$träffar[1]];
    }

    expect($nycklar)->toContain('item.form.location')
        ->toContain('item.form.location_change')
        ->toContain('item.form.parent_heading')
        ->toContain('item.form.parent_choose');

    foreach (array_unique($nycklar) as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "ui.{$nyckel} saknas");
    }
});

/*
 * Varje notistyp klockan visar har en mening (issue 127).
 *
 * Klockan formulerar raden ur typens EGET namn — `task.due` slås upp som
 * `inbox.task.due`, se
 * resources/js/components/notificationPresentation.js — och `translate()`
 * skriver NYCKELN SJÄLV när uppslaget misslyckas (provet strax ovanför pinnar
 * det). En typ utan mening syns därför inte som ett fel i vyn: den syns som
 * `inbox.task.due` i en rad, på engelska, i en produkt vars enda
 * katalog är engelsk ([[ADR-0034 Engelska vid lansering]]).
 *
 * **Typerna räknas upp ur konstanterna och inte ur en avskrift här.**
 * Namnrummet är ÖPPET ([[Notiser]] § notification, Beslut 4) — `Notification`
 * har därför ingen `TYPES`-lista, bara konstanterna — så en avskrift hade
 * varit en andra lista att hålla i takt. En typ som läggs till och glöms i
 * katalogen faller här, och det är samma form som provet för händelseloggens
 * handlingar.
 *
 * **Inbjudningarna har ingen typmening, men klockans rad har en.** Sedan
 * issue 146 finns ingen notistyp för en inbjudan: `CreateInvitation` skickar
 * mejlet direkt med `InvitationNotification`, och klockan visar de sex typer
 * som faktiskt skrivs ur `notification`. Hennes inbjudningsrad kom med issue
 * 131 och läses ur `invitation` i stället ([[M20 Kontot]] § 131), så nyckeln
 * `inbox.invitation.received` finns kvar och prövas sist i provet. Raden i
 * klockan skulle stå utan text om nyckeln glömdes, och det är vad den sista
 * raden fångar.
 */
it('har en mening åt varje notistyp klockan visar', function () {
    $lov = sprakLov(sprakFil('en'));

    $typer = [];

    foreach ((new ReflectionClass(Notification::class))->getConstants() as $namn => $varde) {
        if (str_starts_with($namn, 'TYPE_') && is_string($varde)) {
            $typer[$namn] = $varde;
        }
    }

    expect($typer)->toHaveCount(6);

    foreach ($typer as $namn => $typ) {
        $mening = $lov["inbox.{$typ}"] ?? '';

        expect($mening)->not->toBe('', "ui.inbox.{$typ} saknas ({$namn})");
    }

    // Klockans inbjudningsrad, ur `invitation` och inte ur `notification`.
    expect($lov['inbox.invitation.received'] ?? '')->not->toBe('');
});

/*
 * Dashboardens strängar, se issue 122, 124, 125 och 126.
 *
 * Samma form som skalets prov strax ovanför: nycklarna läses ur källkoden i
 * stället för att räknas upp här, så att en mening som läggs till i en
 * komponent och glöms i katalogen faller. Det spelar roll just för de här
 * filerna därför att grupprubriken för en art skrivs ORDAGRANT — artens namn
 * kommer ur användarens tangentbord och har ingen nyckel — så den enda
 * strängen som SKA slås upp är högens namn och talens etiketter. Ett uppslag
 * som glider över till att omfatta artens namn syns då här.
 *
 * Sidan själv står med sedan issue 125: rubriken över donuten är hennes, och
 * en rubrik som glöms i katalogen renderas som `dashboard.costs.heading` —
 * `translate()` skriver nyckeln själv när uppslaget misslyckas. Donutens
 * legend har ingen nyckel alls: en tårtbit namnges av containerns eget namn,
 * som skrivs ordagrant och aldrig slås upp.
 *
 * Händelsepanelen kom med issue 126. Dess rubrik och tomma läge är panelens
 * egna, och raderna är historikflikens — `HistoryRow` slår upp
 * `audit.action.*`, `audit.field.*` och `audit.fallback.container`, som
 * prövas i HistorikflikTest och HandelsepanelTest. Här står alltså bara de två
 * nycklar panelen äger själv.
 */
it('hämtar dashboardens strängar ur ui.php', function () {
    $nycklar = [];

    foreach ([
        'js/pages/Dashboard.vue',
        'js/components/DashboardStats.vue',
        'js/components/ContainerCard.vue',
        'js/components/CostDonut.vue',
        'js/components/DashboardActivityPanel.vue',
        // Informationsytan (issue 128) står på dashboarden och på containerns
        // översikt. Dess rubrik och brödtext kommer ur `tips.{nyckel}.*` och
        // är dynamiska — de prövas nyckel för nyckel i InformationsytaTest —
        // men panelens egna tre ord står i filen och fångas här.
        'js/components/InfoPanel.vue',
    ] as $fil) {
        preg_match_all("/t\\('([a-z0-9_.]+)'/", File::get(resource_path($fil)), $träffar);

        expect($träffar[1])->not->toBeEmpty("{$fil} slår inte upp någon nyckel");

        $nycklar = [...$nycklar, ...$träffar[1]];
    }

    foreach (array_unique($nycklar) as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "ui.{$nyckel} saknas");
    }
});

/*
 * Containerns översikt fick sina paneler i issue 172 · [[ADR-0050
 * Desktopdesignen]] § 7, och två av dem är nya filer med egna nycklar. Samma
 * form som provet ovanför: nycklarna läses ur källkoden i stället för att
 * räknas upp här, så en mening som byter namn i en komponent följer med utan
 * att provet skrivs om.
 *
 * De tre filerna slår också upp nycklar de inte äger — `todo.empty.nothing`,
 * `item.index.empty` och `audit.history.empty` — och det är med flit: samma
 * ord om samma sak, och en kopia under `container.overview.*` hade varit en
 * andra sanning om vad tomt betyder på samma container.
 *
 * Bildpanelen kom med issue 173 och står i samma lista av samma skäl: den bär
 * en egen rubrik (`container.overview.images`) och lånar itemets
 * `item.attachment.file_icon` för den neutrala ytan — samma ord om samma yta
 * som på itemet, och ingen kopia under `container.overview.*`.
 */
it('hämtar containerns översiktssträngar ur ui.php', function () {
    $nycklar = [];

    foreach ([
        'js/pages/Containers/Overview.vue',
        'js/components/ContainerTasksPanel.vue',
        'js/components/ContainerDetailsPanel.vue',
        'js/components/RecentImagesPanel.vue',
    ] as $fil) {
        preg_match_all("/(?<![\w$.])t\('([a-z0-9_.]+)'/", File::get(resource_path($fil)), $träffar);

        expect($träffar[1])->not->toBeEmpty("{$fil} slår inte upp någon nyckel");

        $nycklar = [...$nycklar, ...$träffar[1]];
    }

    foreach (array_unique($nycklar) as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "ui.{$nyckel} saknas");
    }

    // Panelernas egna etiketter, och inte bara de ärvda: en nyckel som tappas
    // ur katalogen ska falla här och inte som `container.overview.details` på
    // skärmen.
    foreach (['tasks', 'costs', 'costs_total', 'activity', 'images', 'details', 'currency', 'account', 'created', 'view_all'] as $nyckel) {
        expect(Lang::get("ui.container.overview.{$nyckel}", [], 'en'))
            ->not->toBe("ui.container.overview.{$nyckel}", "container.overview.{$nyckel} saknas");
    }
});

/*
 * Containerns uppgiftsflik, se issue 174 · [[ADR-0050 Desktopdesignen]] § 16
 * och resources/js/pages/Containers/Tasks.vue.
 *
 * Samma form som proven ovanför: nycklarna läses ur källkoden i stället för
 * att räknas upp här, så en mening som byter namn i vyn följer med utan att
 * provet skrivs om — och en nyckel som glöms i katalogen faller här i stället
 * för att synas som `container.tasks.done` på skärmen.
 *
 * **Tre av de fyra kolumnrubrikerna står inte i listan och ska inte göra
 * det.** De byggs som ``t(`todo.group.${group}`)`` ur gruppens eget namn —
 * samma ord om samma grupp som på `/tasks` — och mönstret nedan fångar bara
 * ett uppslag med en skriven nyckel. Att de nycklarna finns prövas där de
 * hör hemma, i tests/Feature/Frontend/TodovyTest.php.
 */
it('hämtar uppgiftsflikens strängar ur ui.php', function () {
    $vy = File::get(resource_path('js/pages/Containers/Tasks.vue'));

    preg_match_all("/(?<![\w$.])t\\('([a-z0-9_.]+)'/", $vy, $träffar);

    expect($träffar[1])->not->toBeEmpty('Containers/Tasks.vue slår inte upp någon nyckel');

    foreach (array_unique($träffar[1]) as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "ui.{$nyckel} saknas");
    }

    // Flikens egna ord, och inte bara de ärvda: en nyckel som tappas ur
    // katalogen ska falla här och inte som `container.tasks.shortcuts` i
    // rubriken.
    foreach (['container.nav.tasks', 'container.tasks.title', 'container.tasks.heading', 'container.tasks.filter_maintenance', 'container.tasks.done', 'container.tasks.shortcuts'] as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "{$nyckel} saknas");
    }
});

/*
 * Containerns kostnadsflik, se issue 175 · [[ADR-0050 Desktopdesignen]] § 9 och
 * resources/js/pages/Containers/Costs.vue.
 *
 * Samma form som proven ovanför: nycklarna läses ur källkoden i stället för att
 * räknas upp här, så en mening som byter namn i vyn följer med utan att provet
 * skrivs om. Fem filer läses, för tabellen och Pro-delens tre delar är sina
 * egna komponenter (CostTable, CostFilterBar, CostTimeChart,
 * CostCategoryBreakdown) — och en rubrik som glöms i den ena hade blivit
 * `container.costs.supplier` på skärmen.
 *
 * **`container.costs.this_year` bär `:year`**, och det prövas inte bara att
 * nyckeln finns: etiketten säger vilket år brickan räknar, och årtalet kommer
 * ur serverns `year`-propp och aldrig ur klientens klocka. En nyckel utan
 * platshållaren hade visat *This year ()*.
 */
it('hämtar kostnadsflikens strängar ur ui.php', function () {
    $nycklar = [];

    $filer = [
        'js/pages/Containers/Costs.vue',
        'js/components/CostTable.vue',
        // Pro-delen, issue 176.
        'js/components/CostFilterBar.vue',
        'js/components/CostTimeChart.vue',
        'js/components/CostCategoryBreakdown.vue',
    ];

    foreach ($filer as $fil) {
        preg_match_all("/(?<![\w$.])t\('([a-z0-9_.]+)'/", File::get(resource_path($fil)), $träffar);

        expect($träffar[1])->not->toBeEmpty("{$fil} slår inte upp någon nyckel");

        $nycklar = [...$nycklar, ...$träffar[1]];
    }

    foreach (array_unique($nycklar) as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "ui.{$nyckel} saknas");
    }

    // Flikens egna ord, och inte bara de ärvda: en nyckel som tappas ur
    // katalogen ska falla här och inte som `container.costs.upgrade_link` i
    // uppgraderingsytan.
    foreach (['container.nav.costs', 'container.costs.title', 'container.costs.heading', 'container.costs.add', 'container.costs.add_choose_item', 'container.costs.total', 'container.costs.this_year', 'container.costs.donut', 'container.costs.empty', 'container.costs.empty_filtered', 'container.costs.date', 'container.costs.description', 'container.costs.item', 'container.costs.supplier', 'container.costs.amount', 'container.costs.previous', 'container.costs.next', 'container.costs.page', 'container.costs.upgrade', 'container.costs.upgrade_owner', 'container.costs.upgrade_link', 'container.costs.filter_aria', 'container.costs.filter_from', 'container.costs.filter_to', 'container.costs.filter_category', 'container.costs.filter_all', 'container.costs.filter_submit', 'container.costs.filter_clear', 'container.costs.chart', 'container.costs.breakdown', 'container.costs.other', 'container.costs.comparison', 'container.costs.comparison_percent'] as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "{$nyckel} saknas");
    }

    expect(Lang::get('ui.container.costs.this_year', ['year' => 2026], 'en'))->toBe('This year (2026)');

    // Pro-delens procent bär sitt tecken i VÄRDET och inte i vyn: "+12 %" och
    // "−4 %" är samma mening med olika tal, och vyn sätter aldrig ihop ett
    // plustecken och en enhet själv (issue 176).
    expect(Lang::get('ui.container.costs.comparison_percent', ['percent' => '+12'], 'en'))->toBe('+12%')
        ->and(Lang::get('ui.container.costs.comparison_percent', ['percent' => '-4'], 'en'))->toBe('-4%');
});

/*
 * Containerns dokumentflik, se issue 178 · [[ADR-0050 Desktopdesignen]]
 * § 12–15, resources/js/pages/Containers/Documents.vue och de två
 * komponenterna den monterar.
 *
 * Samma form som proven ovanför: nycklarna läses ur källkoden i stället för att
 * räknas upp här, så en mening som byter namn i vyn följer med utan att provet
 * skrivs om. Fyra filer läses, för filterfältet och lagringsstapeln är sina
 * egna komponenter — och en rubrik som glöms i den ena hade blivit
 * `container.documents.filter_uploader` på skärmen.
 *
 * **De tre typorden är `item.attachment.kind.*` och inga kopior**, och det
 * prövas särskilt: filtret och raden ska säga samma ord om samma typ. Bygger
 * någon en egen uppsättning faller raden nedan, och det är meningen.
 *
 * **`of` och `unlimited` bär `:used`**, och etiketten prövas med ett värde:
 * en nyckel utan platshållaren hade visat en tom parentes. De två är grenarna
 * för ett tak som finns och ett som inte gör det (§ 15) — ett obegränsat tak
 * ritar bara förbrukningen.
 */
it('hämtar dokumentflikens strängar ur ui.php', function () {
    $nycklar = [];

    $filer = [
        'js/pages/Containers/Documents.vue',
        'js/components/DocumentFilterBar.vue',
        'js/components/StorageBar.vue',
    ];

    foreach ($filer as $fil) {
        preg_match_all("/(?<![\w$.])t\('([a-z0-9_.]+)'/", File::get(resource_path($fil)), $träffar);

        expect($träffar[1])->not->toBeEmpty("{$fil} slår inte upp någon nyckel");

        $nycklar = [...$nycklar, ...$träffar[1]];
    }

    foreach (array_unique($nycklar) as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "ui.{$nyckel} saknas");
    }

    // Flikens egna ord, och inte bara de ärvda: en nyckel som tappas ur
    // katalogen ska falla här och inte som `container.documents.filter_from` i
    // filterfältet.
    foreach ([
        'container.nav.documents',
        'container.documents.title',
        'container.documents.heading',
        'container.documents.add',
        'container.documents.add_choose_item',
        'container.documents.empty',
        'container.documents.empty_filtered',
        'container.documents.recent',
        'container.documents.filename',
        'container.documents.type',
        'container.documents.item',
        'container.documents.date',
        'container.documents.size',
        'container.documents.download',
        'container.documents.view_label',
        'container.documents.view_list',
        'container.documents.view_grid',
        'container.documents.sort_label',
        'container.documents.sort_newest',
        'container.documents.sort_oldest',
        'container.documents.sort_name',
        'container.documents.sort_size',
        'container.documents.filter_aria',
        'container.documents.filter_item',
        'container.documents.filter_uploader',
        'container.documents.filter_from',
        'container.documents.filter_to',
        'container.documents.filter_all',
        'container.documents.filter_submit',
        'container.documents.filter_clear',
        'container.documents.previous',
        'container.documents.next',
        'container.documents.page',
        'container.documents.uploads',
        'container.documents.of',
        'container.documents.unlimited',
    ] as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "{$nyckel} saknas");
    }

    // Sorteringens fyra lägen byggs som `container.documents.sort_<värde>` ur
    // serverns `SORTS`, och typens tre som `item.attachment.kind.<värde>`: de
    // dynamiska uppslagen fångas inte av mönstret ovan, så de prövas här.
    foreach (['newest', 'oldest', 'name', 'size'] as $lage) {
        expect(Lang::get("ui.container.documents.sort_{$lage}", [], 'en'))
            ->not->toBe("ui.container.documents.sort_{$lage}", "container.documents.sort_{$lage} saknas");
    }

    foreach (['image', 'document', 'other'] as $kind) {
        expect(Lang::get("ui.item.attachment.kind.{$kind}", [], 'en'))
            ->not->toBe("ui.item.attachment.kind.{$kind}", "item.attachment.kind.{$kind} saknas");
    }

    // Lagringsstapelns två grenar bär sina tal, och ett obegränsat tak nämner
    // inget tak alls.
    expect(Lang::get('ui.container.documents.of', ['used' => '2.4 GB', 'limit' => '25 GB'], 'en'))
        ->toBe('2.4 GB of 25 GB')
        ->and(Lang::get('ui.container.documents.unlimited', ['used' => '2.4 GB'], 'en'))
        ->toBe('2.4 GB used');

    expect(Lang::get('ui.container.documents.page', ['page' => 2, 'last' => 5], 'en'))
        ->toBe('Page 2 of 5');
});

/*
 * Historikflikens strängar, se issue 179 · [[ADR-0050 Desktopdesignen]] § 17,
 * resources/js/pages/Containers/History.vue och
 * resources/js/components/HistoryFilterBar.vue.
 *
 * Samma form som proven ovanför: nycklarna läses ur källkoden i stället för att
 * räknas upp här, så en mening som byter namn i vyn följer med utan att provet
 * skrivs om. Två filer läses, för filterfältet är sin egen komponent — och en
 * rubrik som glöms i den ena hade blivit `audit.history.filter_type` på
 * skärmen.
 *
 * **Typordens tolv nycklar prövas särskilt.** Filtret bygger sin etikett som
 * `audit.subject.<subject_type>` ur ett värde servern skickar — ett dynamiskt
 * uppslag som mönstret ovan inte fångar — och en typ utan ord visar sin nyckel
 * i menyn i stället för ett ord. Listan här är de tolv domännamn
 * `RecordAuditEvent` faktiskt skriver (`subject_type` är ett öppet namnrum, så
 * en ny typ faller inte här; den syns i menyn och hittas av den som lägger
 * till den).
 */
it('hämtar historikflikens strängar ur ui.php', function () {
    $nycklar = [];

    $filer = [
        'js/pages/Containers/History.vue',
        'js/components/HistoryFilterBar.vue',
    ];

    foreach ($filer as $fil) {
        preg_match_all("/(?<![\w$.])t\('([a-z0-9_.]+)'/", File::get(resource_path($fil)), $träffar);

        expect($träffar[1])->not->toBeEmpty("{$fil} slår inte upp någon nyckel");

        $nycklar = [...$nycklar, ...$träffar[1]];
    }

    foreach (array_unique($nycklar) as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "ui.{$nyckel} saknas");
    }

    // Flikens egna ord, och inte bara de ärvda: en nyckel som tappas ur
    // katalogen ska falla här och inte som `audit.history.empty_filtered` i
    // tomtillståndet.
    foreach ([
        'audit.history.title',
        'audit.history.heading',
        'audit.history.empty',
        'audit.history.empty_filtered',
        'audit.history.day_count',
        'audit.history.filter_aria',
        'audit.history.filter_type',
        'audit.history.filter_user',
        'audit.history.filter_item',
        'audit.history.filter_from',
        'audit.history.filter_to',
        'audit.history.filter_all',
        'audit.history.filter_submit',
        'audit.history.filter_clear',
    ] as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "{$nyckel} saknas");
    }

    // Varje `subject_type` `RecordAuditEvent` skriver har ett ord, så att
    // typfiltrets meny säger *Costs* och inte `audit.subject.cost_entry`.
    foreach ([
        'attachment',
        'calendar_feed',
        'category',
        'container',
        'container_access',
        'cost_entry',
        'invitation',
        'loan',
        'ownership_transfer',
        'schedule',
        'schedule_occurrence',
        'tag',
    ] as $typ) {
        expect(Lang::get("ui.audit.subject.{$typ}", [], 'en'))
            ->not->toBe("ui.audit.subject.{$typ}", "audit.subject.{$typ} saknas");
    }

    // Dagens rubrik bär dagens tal, och de två tomma tillstånden säger olika
    // saker — det ena att ingenting hänt, det andra att inget matchar.
    expect(Lang::get('ui.audit.history.day_count', ['count' => 8], 'en'))->toBe('8 events');

    expect(Lang::get('ui.audit.history.empty', [], 'en'))
        ->not->toBe(Lang::get('ui.audit.history.empty_filtered', [], 'en'));
});

/*
 * Avbockningsknappens prick, se issue 133 och
 * resources/js/components/TodoRow.vue.
 *
 * Samma form som proven ovanför: nycklarna läses ur källkoden i stället för
 * att räknas upp här, så en mening som byter namn i komponenten följer med
 * utan att provet skrivs om. Det betyder något särskilt för just den här
 * pricken, för `sr-only`-texten är den ENDA bäraren av tillståndet för en
 * skärmläsare: `translate()` skriver nyckeln själv när uppslaget misslyckas,
 * så en glömd nyckel hörs som `todo.group.overdue` i stället för *Overdue*.
 *
 * **De två nycklarna är grupprubrikernas egna och ska förbli det** — samma ord
 * om samma sak, och en egen kopia i ui.php hade varit den andra sanningen om
 * vad gruppen heter. Byter någon mot en kopia faller raden, och det är
 * meningen. Värdena prövas också, för *Overdue* och *Upcoming* är orden
 * issuen namnger.
 */
it('hämtar prickens strängar ur ui.php', function () {
    $rad = File::get(resource_path('js/components/TodoRow.vue'));

    // Fönstret `(?<![\w$.])` är det som skiljer ett uppslag från ett anrop,
    // samma som i proven för lösenords- och e-postformuläret.
    preg_match_all("/(?<![\w$.])t\('([a-z0-9_.]+)'/", $rad, $träffar);

    expect($träffar[1])->toContain('todo.group.overdue')
        ->and($träffar[1])->toContain('todo.group.upcoming')
        ->and(Lang::get('ui.todo.group.overdue', [], 'en'))->toBe('Overdue')
        ->and(Lang::get('ui.todo.group.upcoming', [], 'en'))->toBe('Upcoming');

    foreach (array_unique($träffar[1]) as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "ui.{$nyckel} saknas");
    }
});

/*
 * Växeln för framtida uppgifter, se issue 134 och
 * resources/js/components/UpcomingTasksToggle.vue.
 *
 * Samma form som proven ovanför: nycklarna läses ur källkoden i stället för
 * att räknas upp här, så en mening som byter namn i komponenten följer med
 * utan att provet skrivs om. Det betyder något särskilt för just den här
 * växeln, för etiketten är dess ENDA text, och `translate()` skriver NYCKELN
 * SJÄLV när uppslaget misslyckas — en glömd nyckel står då som `todo.toggle`
 * i rubrikraden i stället för *Show upcoming tasks*.
 *
 * Nyckeln ligger under `todo` och inte under `dashboard`: komponenten ritas på
 * båda ytorna, och en egen kopia i `dashboard.*` hade varit den andra
 * sanningen om vad växeln heter — samma skäl som `todo.empty.*` sedan issue
 * 122.
 */
it('hämtar växelns strängar ur ui.php', function () {
    $vaxel = File::get(resource_path('js/components/UpcomingTasksToggle.vue'));

    preg_match_all("/(?<![\w$.])t\('([a-z0-9_.]+)'/", $vaxel, $träffar);

    expect($träffar[1])->toContain('todo.toggle')
        ->and(Lang::get('ui.todo.toggle', [], 'en'))->toBe('Show upcoming tasks');

    foreach (array_unique($träffar[1]) as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "ui.{$nyckel} saknas");
    }
});

/*
 * Växeln i containerns itemflik, se issue 154 ·
 * [[ADR-0046 Containerns karta]] och
 * resources/js/components/ItemViewSwitch.vue samt
 * resources/js/pages/Containers/Items/Index.vue.
 *
 * Samma form som proven ovanför: nycklarna läses ur källkoden i stället för
 * att räknas upp här. Det betyder något särskilt för de två LÄGENA, för
 * etiketten är all text de bär, och `translate()` skriver NYCKELN SJÄLV när
 * uppslaget misslyckas — en glömd nyckel står då som `item.view.tree` i växeln
 * i stället för *Tree*.
 *
 * **Komponenten bär ingen egen mening** och slår inte upp någonting själv:
 * etiketterna kommer som proppar, och det är Index.vue som äger uppslagen.
 * Provet läser därför båda filerna — den ena för formen, den andra för orden.
 *
 * **`item.view.map` kom med issue 157**, då kartan byggdes — läget fanns inte
 * förrän dess, och ett ord utan yta är ett löfte katalogen inte kan hålla. Det
 * är inte samma ord som `item.map.*`: den senare är itemets EGEN fokusgraf, en
 * annan yta med en annan fråga ([[ADR-0032 Produktens ord]]).
 */
it('hämtar itemväxelns strängar ur ui.php', function () {
    $vaxeln = File::get(resource_path('js/components/ItemViewSwitch.vue'));
    $fliken = File::get(resource_path('js/pages/Containers/Items/Index.vue'));

    expect($vaxeln)->not->toMatch("/(?<![\w$.])t\('([a-z0-9_.]+)'/");

    preg_match_all("/(?<![\w$.])t\('([a-z0-9_.]+)'/", $fliken, $träffar);

    foreach (['item.view.label', 'item.view.list', 'item.view.tree', 'item.view.map'] as $nyckel) {
        expect($träffar[1])->toContain($nyckel);
    }

    expect(Lang::get('ui.item.view.list', [], 'en'))->toBe('List')
        ->and(Lang::get('ui.item.view.tree', [], 'en'))->toBe('Tree')
        ->and(Lang::get('ui.item.view.map', [], 'en'))->toBe('Map');

    foreach (array_unique($träffar[1]) as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "ui.{$nyckel} saknas");
    }
});

/*
 * Containerkartans strängar, se issue 157 · [[M23 Mobilen och kartan]] § 157
 * och resources/js/components/ContainerMap.vue samt ContainerMapNode.vue.
 *
 * Samma form som proven ovanför: nycklarna läses ur källkoden i stället för
 * att räknas upp här, så en mening som läggs till i en komponent och glöms i
 * katalogen faller. Det betyder något särskilt för de två talen, för
 * `translate()` skriver NYCKELN SJÄLV när uppslaget misslyckas — en glömd
 * nyckel står då som `item.board.children` i en nod i stället för *3 children*.
 *
 * `status`-uppslaget är BYGGT — ``t(`item.index.status_${status}`) `` — och
 * fångas därför inte av mönstret. Det prövas i stället för sig, och det är
 * översiktens nycklar: samma regel ska heta samma sak på båda ytorna, och en
 * egen kopia här hade varit den andra sanningen om vad OK heter.
 */
it('hämtar containerkartans strängar ur ui.php', function () {
    $nycklar = [];

    foreach ([
        'js/components/ContainerMap.vue',
        'js/components/ContainerMapNode.vue',
    ] as $fil) {
        preg_match_all("/(?<![\w$.])t\('([a-z0-9_.]+)'/", File::get(resource_path($fil)), $träffar);

        expect($träffar[1])->not->toBeEmpty("{$fil} slår inte upp någon nyckel");

        $nycklar = [...$nycklar, ...$träffar[1]];
    }

    expect($nycklar)->toContain('item.board.label')
        ->toContain('item.board.path')
        ->toContain('item.board.up')
        ->toContain('item.board.children')
        ->toContain('item.board.placements');

    foreach (array_unique($nycklar) as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "ui.{$nyckel} saknas");
    }

    // Talen bär sitt värde, och förälderns knapp namnger noden den leder till.
    expect(Lang::get('ui.item.board.children', ['count' => 3], 'en'))->toBe('3 children')
        ->and(Lang::get('ui.item.board.placements', ['count' => 2], 'en'))->toBe('2 placements')
        ->and(Lang::get('ui.item.board.up', ['name' => 'Båten'], 'en'))->toBe('Up to Båten');

    // Statusordet är översiktens, och det finns bara på ett ställe.
    expect(File::get(resource_path('js/components/ContainerMapNode.vue')))
        ->toContain('item.index.status_${status}');

    expect(Lang::get('ui.item.index.status_ok', [], 'en'))->toBe('OK')
        ->and(Lang::get('ui.item.index.status_overdue', [], 'en'))->toBe('Overdue');
});

/*
 * Fällknappen i trädet, se issue 154 och
 * resources/js/components/ItemStructureTree.vue.
 *
 * Nycklarna är de ENDA två orden i komponenten, och de är åtgärden och inte
 * noden: knappen bär en ikon och inget namn av eget, så etiketten måste säga
 * vad trycket gör. `:name` fylls med nodens namn, och provet prövar att
 * parametern finns i meningen — en nyckel utan `:name` hade gett varje
 * fällknapp i trädet samma namn.
 */
it('hämtar fällknappens strängar ur ui.php', function () {
    $trädet = File::get(resource_path('js/components/ItemStructureTree.vue'));

    expect($trädet)->toContain("t('item.structure.expand', { name: node.name })")
        ->toContain("t('item.structure.collapse', { name: node.name })");

    expect(Lang::get('ui.item.structure.expand', [], 'en'))->toBe('Expand :name')
        ->and(Lang::get('ui.item.structure.collapse', [], 'en'))->toBe('Collapse :name');
});

/*
 * Fokuskartan, se issue 156 · [[M23 Mobilen och kartan]] § 156 och
 * resources/js/components/FocusMap.vue, FocusMapNode.vue och
 * ItemMapPanel.vue.
 *
 * Samma form som proven ovanför: nycklarna läses ur källkoden i stället för
 * att räknas upp här, så en mening som läggs till i en komponent och glöms i
 * katalogen faller. Det betyder något särskilt för två av dem:
 *
 *   - **Teckenförklaringen** har tre sorter, och `translate()` skriver NYCKELN
 *     SJÄLV när uppslaget misslyckas — en glömd nyckel står alltså som
 *     `item.map.kind.parent` i förklaringen i stället för *Parent*.
 *   - **Plusknappen på en nod** ritar ett plustecken och ingenting mer, så
 *     `item.map.node_menu` är det ENDA en skärmläsare hör. Den fylls med
 *     nodens namn, och provet prövar att parametern finns i meningen.
 *
 * Två av uppslagen är BYGGDA — ``t(`item.map.kind.${kind}`)`` — och fångas
 * därför inte av mönstret; de tre slagen räknas upp uttryckligen, och orden
 * prövas ordagrant, för det är de sorterna issuen namnger.
 */
it('hämtar fokuskartans strängar ur ui.php', function () {
    $nycklar = [];

    foreach ([
        'js/components/FocusMap.vue',
        'js/components/FocusMapNode.vue',
        'js/components/ItemMapPanel.vue',
        // Växelns eget namn slås upp av relationsfliken, som äger läget, och
        // lägenas etiketter av vyn, som bygger deras adresser.
        'js/components/ItemLinkSection.vue',
        'js/pages/Containers/Items/Show.vue',
    ] as $fil) {
        preg_match_all("/(?<![\w$.])t\('([a-z0-9_.]+)'/", File::get(resource_path($fil)), $träffar);

        expect($träffar[1])->not->toBeEmpty("{$fil} slår inte upp någon nyckel");

        $nycklar = [...$nycklar, ...$träffar[1]];
    }

    expect($nycklar)->toContain('item.map.heading')
        ->toContain('item.map.view.label')
        ->toContain('item.map.view.focus')
        ->toContain('item.map.view.list')
        ->toContain('item.map.node_menu')
        ->toContain('item.map.more');

    foreach (array_unique($nycklar) as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "ui.{$nyckel} saknas");
    }

    // Teckenförklaringens tre sorter, ordagrant, och växelns två lägen.
    expect(Lang::get('ui.item.map.kind.parent', [], 'en'))->toBe('Parent')
        ->and(Lang::get('ui.item.map.kind.child', [], 'en'))->toBe('Child')
        ->and(Lang::get('ui.item.map.kind.related', [], 'en'))->toBe('Related')
        ->and(Lang::get('ui.item.map.view.list', [], 'en'))->toBe('List')
        ->and(Lang::get('ui.item.map.view.focus', [], 'en'))->toBe('Focus');

    // *+N till* bär talet, och nodens knapp namnger noden.
    expect(Lang::get('ui.item.map.more', ['count' => 2], 'en'))->toBe('+2 more')
        ->and(Lang::get('ui.item.map.node_menu', ['name' => 'Motor'], 'en'))->toBe('Create in Motor');
});

/*
 * Lösenordsformuläret, se issue 129 och 140 och
 * resources/js/components/PasswordForm.vue.
 *
 * Samma form som proven ovanför: nycklarna läses ur källkoden i stället för
 * att räknas upp här, så en mening som läggs till i komponenten och glöms i
 * katalogen faller. Två av nycklarna är andras och ska förbli det: kravet på
 * lösenordet är registreringens mening (`auth.register.password_hint` —
 * [[ADR-0034 Engelska vid lansering]] ger en enda katalog, och två
 * formuleringar av samma regel glider isär) och kodfältets etikett är
 * inloggningens (`auth.code.label` — samma etikett, samma sak att skriva in).
 *
 * Flashen prövas också, och båda koderna: `flash.password-change-requested`
 * sätts av PasswordController::update() när begäran tagits emot (ingenting
 * har ändrats än — lösenordet skrivs först när länken i mejlet öppnas), och
 * `flash.password-changed` sätts av confirm() när den länken har öppnats.
 * `translate()` skriver NYCKELN SJÄLV när uppslaget misslyckas — en kod utan
 * mening syns alltså inte som ett fel i vyn, utan som
 * `flash.password-change-requested` i en grön ruta.
 */
it('hämtar lösenordsformulärets strängar ur ui.php', function () {
    $vy = File::get(resource_path('js/components/PasswordForm.vue'));

    /*
     * Fönstret `(?<![\w$.])` är det som skiljer ett uppslag från ett anrop:
     * form.reset('password_confirmation') innehåller sekvensen `t('...`, och
     * utan fönstret hade fältnamn lästs som översättningsnycklar. Syskonproven
     * ovanför behöver det inte — ingen av deras komponenter nollställer ett
     * fält — men regeln är densamma.
     */
    preg_match_all("/(?<![\w$.])t\('([a-z0-9_.]+)'/", $vy, $träffar);

    expect($träffar[1])->toContain('settings.security.password.heading')
        // De två lånade nycklarna, utskrivna: byter någon dem mot egna
        // kopior i ui.php faller raden, och det är meningen.
        ->and($träffar[1])->toContain('auth.register.password_hint')
        ->and($träffar[1])->toContain('auth.code.label');

    foreach (array_unique($träffar[1]) as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "ui.{$nyckel} saknas");
    }

    expect(Lang::get('ui.flash.password-change-requested', [], 'en'))
        ->not->toBe('ui.flash.password-change-requested')
        ->and(Lang::get('ui.flash.password-changed', [], 'en'))
        ->not->toBe('ui.flash.password-changed');
});

/*
 * E-postformuläret, se [[M20 Kontot]] § 130. Samma form som proven ovanför:
 * nycklarna läses ur källkoden i stället för att räknas upp här, så en mening
 * som läggs till i komponenten och glöms i katalogen faller. En av nycklarna
 * är en annans och ska förbli det: kodfältets etikett är inloggningens
 * (`auth.code.label` — samma etikett, samma sak att skriva in).
 *
 * `password_first` är både en text i komponenten och serverns
 * valideringsfel (App\Http\Requests\Settings\RequestEmailChangeRequest::
 * messages()) — samma nyckel på båda ställena, så de två inte kan glida
 * isär. Flashen prövas också: `flash.email-change-requested` och
 * `flash.email-changed` sätts av EmailChangeController, och en kod utan
 * mening syns i vyn som den råa nyckeln.
 */
it('hämtar e-postformulärets strängar ur ui.php', function () {
    $vy = File::get(resource_path('js/components/EmailChangeForm.vue'));

    // Fönstret `(?<![\w$.])`: form.reset('new_email') innehåller sekvensen
    // `t('...`, och utan det hade fältnamn lästs som översättningsnycklar.
    preg_match_all("/(?<![\w$.])t\('([a-z0-9_.]+)'/", $vy, $träffar);

    expect($träffar[1])->toContain('settings.profile.email_change.heading')
        ->and($träffar[1])->toContain('settings.profile.email_change.password_first')
        // Den lånade nyckeln, utskriven: byter någon den mot en egen kopia i
        // ui.php faller raden, och det är meningen.
        ->and($träffar[1])->toContain('auth.code.label');

    foreach (array_unique($träffar[1]) as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "ui.{$nyckel} saknas");
    }

    foreach (['ui.flash.email-change-requested', 'ui.flash.email-changed'] as $nyckel) {
        expect(Lang::get($nyckel, [], 'en'))->not->toBe($nyckel);
    }
});

/*
 * Raderingsformuläret, se [[M22 Redo för testare]] § 145 och
 * resources/js/components/UserDeletionForm.vue.
 *
 * Samma form som proven ovanför: nycklarna läses ur källkoden i stället för
 * att räknas upp här, så en mening som läggs till i komponenten och glöms i
 * katalogen faller. En av nycklarna är en annans och ska förbli det:
 * kodfältets etikett är inloggningens (`auth.code.label` — samma etikett,
 * samma sak att skriva in).
 *
 * **Spärrarnas meningar nås med en dynamisk nyckel** —
 * ``t(`…blocker.${blocker.code.split('.').pop()}`)`` — och fångas därför inte
 * av mönstret som läser de literala uppslagen. De tre leden räknas upp
 * uttryckligen här, och de är desamma som App\Support\User\DeletionBlocker
 * stavar: en spärr utan mening syns i vyn som den råa nyckeln.
 *
 * **Ordet prövas.** Kontot heter *account* i gränssnittet och är något annat
 * än personen (ett konto med andra medlemmar lämnas), så copyn får inte säga
 * "Delete account" — den ska säga vad som raderas.
 */
it('hämtar raderingsformulärets strängar ur ui.php', function () {
    $vy = File::get(resource_path('js/components/UserDeletionForm.vue'));

    // Fönstret `(?<![\w$.])`: form.reset('code') och
    // blocker.code.split('.') innehåller sekvensen `t('…`, och utan det hade
    // fältnamn lästs som översättningsnycklar.
    preg_match_all("/(?<![\w$.])t\('([a-z0-9_.]+)'/", $vy, $träffar);

    expect($träffar[1])->toContain('settings.security.deletion.heading')
        ->and($träffar[1])->toContain('settings.security.deletion.blocked')
        // Den lånade nyckeln, utskriven: byter någon den mot en egen kopia i
        // ui.php faller raden, och det är meningen.
        ->and($träffar[1])->toContain('auth.code.label');

    foreach (array_unique($träffar[1]) as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "ui.{$nyckel} saknas");
    }

    foreach (['sole_owner', 'shared_container', 'legal_hold'] as $led) {
        $nyckel = "ui.settings.security.deletion.blocker.{$led}";

        expect(Lang::get($nyckel, [], 'en'))->not->toBe($nyckel);
    }

    foreach ([
        'ui.flash.user-deletion-requested',
        'ui.flash.user-deletion-blocked',
        'ui.flash.user-deleted',
    ] as $nyckel) {
        expect(Lang::get($nyckel, [], 'en'))->not->toBe($nyckel);
    }

    foreach (['heading', 'intro', 'submit', 'blocked', 'blocked_button'] as $nyckel) {
        expect(Lang::get("ui.settings.security.deletion.{$nyckel}", [], 'en'))
            ->not->toContain('Delete account');
    }
});

/*
 * [[ADR-0033 Produktens omfång]] § Beslut: containern är ett sammanhang för
 * allt man äger, använder eller arbetar med — inte ett fordon eller ett
 * fritidshus. Det generiska svaret issue 81 lämnade efter sig är
 * `common.tagline`, och den prövas ordagrant: den är den första meningen en
 * ny användare möter.
 *
 * Fordonsorden prövas mot varje VÄRDE i filen, inte mot råtexten. Kommentarer
 * får nämna vad som helst (och gör det — "carries" står i ett tjugotal rader),
 * men copyn får bära ett fordon bara som etikett för en containertyp:
 * `container.kind.*` är datamodellens namn och ingen mening användaren möts
 * av. Ett tomt tillstånd som ber om en båt är felet ADR-0033 § Beslut finns
 * för att förhindra, och det är svårast att upptäcka i efterhand.
 */
it('beskriver produkten generiskt, utan fordon i copyn', function () {
    $ui = sprakLov(sprakFil('en'));

    expect($ui['common.tagline'])->toBe('The place for everything you own, use or work with.');

    foreach ($ui as $nyckel => $varde) {
        if (preg_match('/\b(boat|car|caravan|vessel|vehicle)\b/i', $varde) !== 1) {
            continue;
        }

        expect($nyckel)->toStartWith('container.kind.', "ui.{$nyckel} namnger ett fordon: {$varde}");
    }
});

/*
 * `lang/en/ui.php` sade *binder* i ett tjugotal kommentarer efter issue 77b,
 * som tog prosan i koden men missade den filen. Städningen är gjord — det här
 * är regeln som håller den kvar, och den läser råtexten just därför att
 * kommentarerna är osynliga för sprakLov(): en kommentar som smyger tillbaka
 * hade annars passerat obesedd.
 *
 * `pdf_binder` är undantaget och det enda — [[ADR-0032 Produktens ord]]
 * § Konsekvenser håller namnet tills PDF-pärmen byggs. Raden bär ordet i både
 * nyckeln och värdet ('PDF binder'), och det är samma undantag.
 */
it('säger inte binder någon annanstans än i pdf_binder', function () {
    $rader = preg_split('/\R/', File::get(lang_path('en/ui.php'))) ?: [];

    $fel = [];

    foreach ($rader as $nummer => $rad) {
        if (stripos($rad, 'binder') !== false && ! str_contains($rad, 'pdf_binder')) {
            $fel[] = sprintf('rad %d: %s', $nummer + 1, trim($rad));
        }
    }

    expect($fel)->toBe([]);
});

it('har inga användarvända strängar kvar i Vue-komponenterna', function () {
    $filer = File::allFiles(resource_path('js'));

    expect($filer)->not->toBeEmpty();

    foreach ($filer as $fil) {
        if (! in_array($fil->getExtension(), ['js', 'vue'], true)) {
            continue;
        }

        // `data/` är undantaget, och det är det ENDA undantaget: de färdiga
        // kategoriuppsättningarna (issue 56b) är användarvänd text som med
        // flit bor i Vue-lagret i stället för i `lang/` — se [[ADR-0004 Fria
        // taggar och kategorier]] § Konsekvenser och [[ADR-0021
        // Frontendteknik]] § Konsekvenser ("som data i Vue-lagret, seedad per
        // språk och containertyp"). Regeln nedan gäller fortfarande varje
        // komponent och varje sidmodul; att katalogen inte får växa vaktas av
        // testet strax nedanför.
        if (str_starts_with($fil->getRelativePath(), 'data')) {
            continue;
        }

        // Kommentarer är svenska med flit (AGENTS.md § Språk i koden). Kvar
        // efter städningen finns bara kod och markup, och där får ingen
        // svensk text finnas alls — varken som strängliteral eller som text
        // mellan två taggar. En rubrik skriven rakt i en mall är samma fel
        // som en sträng i en konstant.
        $kod = $fil->getContents();
        $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
        $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);
        $kod = (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);

        $rader = preg_split('/\R/', $kod) ?: [];

        foreach ($rader as $nummer => $rad) {
            expect($rad)->not->toMatch('/[åäöÅÄÖ]/u', sprintf(
                'svensk text utanför kommentar i %s:%d: %s',
                $fil->getFilename(),
                $nummer + 1,
                trim($rad),
            ));
        }
    }
});

/*
 * Undantaget för `data/` i testet ovan är inte en fribiljett, och det är det
 * här testet som håller det i schack.
 *
 * Katalogen rymmer exakt EN fil: de färdiga kategoriuppsättningarna (issue 56b
 * § Beslut 1 och 2). Att orden bor där i stället för i `lang/` är hela
 * arkitekturen — servern får aldrig veta vad de betyder ([[ADR-0004 Fria
 * taggar och kategorier]] § Konsekvenser) — men undantaget betyder att
 * katalogen inte längre granskas av regeln ovan. Därför prövas katalogens
 * omfång i stället: hamnar det en andra fil dit är det en komponent med
 * användarvänd text som gömmer sig undan `lang/`-regeln, och då ska det här
 * testet falla.
 *
 * Att filen finns och bär en uppsättning per containertyp prövas i
 * tests/Feature/Frontend/KategoriuppsattningTest.php, mot samma modul klienten
 * importerar. Här prövas bara att undantaget inte har vidgats.
 */
/*
 * Issue 425 · Primitiverna, issue 426 · Ytorna och issue 100 · Flikraden.
 * Testet ovan fångar svensk text i en komponent; det här fångar den engelska,
 * och det är den som är lätt att skriva utan att tänka. Knappen och de fyra
 * kontrollerna är rena former: etiketten kommer ur FormField, knappens ord ur
 * anroparen och vänteläget ur `common.pending.*`. De fem ytorna är rena former
 * på samma sätt — kortets rubrik, brickans ord, radens titel och talets
 * etikett kommer alla ur anroparens slots och `t()`-uppslag. Flikraden bär
 * varken etikett eller radnamn själv: fliktexten kommer som prop och slås upp
 * ur `lang/` av anroparen, precis som `container.nav.<key>` gör i dag. En
 * literal sträng i en `Ui*.vue` blir därför alltid fel — den finns inte i
 * `lang/`, och `en` är den enda katalogen som levereras
 * ([[ADR-0034 Engelska vid lansering]]).
 *
 * Textnoder i mallen är allt mellan två taggar som inte är en interpolation.
 * Attributvärdena tas bort först: ett `>` inuti ett värde är inget slut på en
 * tagg, och utan det steget hade `v-if="a > b"` fällt provet på fel sak.
 */
it('har ingen hårdkodad text i Ui-komponenterna', function () {
    $filer = File::glob(resource_path('js/components/Ui*.vue'));

    // Tolv: de fem primitiverna (issue 425), de fem ytorna (issue 426),
    // flikraden (issue 100) och arket (issue 152). Räkningen är en spärr och
    // inte en bekvämlighet — en trettonde `Ui*.vue` är en komponent någon
    // byggt utan att en issue bad om den, och den ska mötas av det här provet
    // och inte av tystnad.
    expect($filer)->toHaveCount(12);

    foreach ($filer as $fil) {
        $kod = (string) preg_replace('#/\*.*?\*/#s', '', File::get($fil));
        $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

        preg_match('#<template>(.*)</template>#s', $kod, $träff);
        $mall = $träff[1] ?? '';

        expect(trim($mall))->not->toBe('', basename($fil).' har ingen mall');

        $text = (string) preg_replace('/"[^"]*"/', '""', $mall);
        $text = (string) preg_replace('/<[^>]*>/', '', $text);
        $text = (string) preg_replace('/\{\{.*?\}\}/s', '', $text);

        expect(trim($text))->toBe('', sprintf(
            'hårdkodad text i %s: %s — den hör i lang/en/ui.php',
            basename($fil),
            trim($text),
        ));
    }
});

it('har bara kategoriuppsättningarna i js/data/', function () {
    $katalog = resource_path('js/data');

    expect(File::isDirectory($katalog))->toBeTrue(
        'resources/js/data saknas — undantaget i testet ovan undantar en katalog som inte finns',
    );

    expect(array_map(fn ($fil): string => $fil->getRelativePathname(), File::allFiles($katalog)))
        ->toBe(['categoryPresets.js']);
});

/*
 * Avanmälningssidan är serverrenderad för en känd mottagare, och
 * UnsubscribeController sätter sin locale EFTER middlewaren. SetLocale får
 * alltså inte köra över kontrollerns egen App::setLocale() — se
 * tests/Feature/Notis/AvregistreringsTest.php, som äger själva avanmälan.
 */
it('låter kontrollerns egen App::setLocale() vinna över middlewaren', function () {
    [, $mottagare] = sprakKontext('sv_SE', 'sv_SE');

    $url = app(UnsubscribeLink::class)->for($mottagare, Notification::TYPE_TASK_DUE);

    $rubrik = trans('notiser.unsubscribe.confirm_heading', [
        'type' => trans('notiser.unsubscribe.types.task_due', [], 'en'),
    ], 'en');

    get($url)->assertOk()->assertSee($rubrik, false);
});
