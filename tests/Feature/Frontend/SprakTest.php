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
    foreach (['js/layouts/AppLayout.vue', 'js/components/NotificationBell.vue'] as $fil) {
        preg_match_all("/t\('([a-z0-9_.]+)'/", File::get(resource_path($fil)), $träffar);

        $nycklar = [...$nycklar, ...$träffar[1]];
    }

    // Favoritlistans rubrik kom med issue 106 och är skalets enda nya nyckel.
    expect($nycklar)->toContain('nav.favorites');

    expect($nycklar)->toContain('inbox.label');
    expect($nycklar)->toContain('inbox.empty');

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
 * **Inbjudningarna undantas med flit.** `invitation.received` finns som
 * konstant men skrivs av ingen kod: `CreateInvitation` skickar mejlet direkt
 * med `InvitationNotification`. Klockan visar de sex typer som faktiskt
 * skrivs, och den sjunde är issue 131 ([[M20 Kontot]]). Undantaget står här
 * som en rad och inte som en tystnad: börjar någon skriva typen faller provet
 * till dess att meningen finns.
 */
it('har en mening åt varje notistyp klockan visar', function () {
    $lov = sprakLov(sprakFil('en'));

    $typer = [];

    foreach ((new ReflectionClass(Notification::class))->getConstants() as $namn => $varde) {
        if (str_starts_with($namn, 'TYPE_') && is_string($varde)) {
            $typer[$namn] = $varde;
        }
    }

    unset($typer['TYPE_INVITATION_RECEIVED']);

    expect($typer)->toHaveCount(6);

    foreach ($typer as $namn => $typ) {
        $mening = $lov["inbox.{$typ}"] ?? '';

        expect($mening)->not->toBe('', "ui.inbox.{$typ} saknas ({$namn})");
    }
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
 * Lösenordsformuläret, se issue 129 och
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
 * Flashen prövas också: `flash.password-changed` sätts av
 * PasswordController, och `translate()` skriver NYCKELN SJÄLV när uppslaget
 * misslyckas — en kod utan mening syns alltså inte som ett fel i vyn, utan
 * som `flash.password-changed` i en grön ruta.
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

    expect(Lang::get('ui.flash.password-changed', [], 'en'))
        ->not->toBe('ui.flash.password-changed');
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

    // Elva: de fem primitiverna (issue 425), de fem ytorna (issue 426) och
    // flikraden (issue 100). Räkningen är en spärr och inte en bekvämlighet —
    // en tolfte `Ui*.vue` är en komponent någon byggt utan att en issue bad om
    // den, och den ska mötas av det här provet och inte av tystnad.
    expect($filer)->toHaveCount(11);

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
