<?php

use App\Models\Account;
use App\Models\Notification;
use App\Models\User;
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
 * Filen bevisar de tre reglerna i ADR-0013 § Konsekvenser: användarens
 * `locale` åsidosätter kontots, språket kommer aldrig ur requestens
 * `Accept-Language`, och frontenden får sin text ur `lang/` — bara ur
 * ui.php, aldrig ur notiser.php eller export.php.
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
 * En språkfil som en platt lista punktnycklar → värde, så att två språk kan
 * jämföras nyckel för nyckel.
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
            'nav' => ['dashboard' => 'Översikt'],
            'error' => ['title' => 'Fel :status'],
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
            ->where('locale', 'sv')
            ->has('translations.nav')
        );
    }
});

it('ger en engelsktalande medlem i ett svenskt konto engelska vyer', function () {
    withoutVite();

    [, $medlem] = sprakKontext('sv_SE', 'en_GB');

    actingAs($medlem)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('locale', 'en')
        ->where('translations.nav.dashboard', 'Dashboard')
    );
});

it('ger svenska när användaren saknar egen locale och kontot är svenskt', function () {
    withoutVite();

    [, $medlem] = sprakKontext('sv_SE', null);

    actingAs($medlem)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('locale', 'sv')
        ->where('translations.nav.dashboard', 'Översikt')
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

it('läser aldrig requestens Accept-Language', function () {
    withoutVite();

    [, $medlem] = sprakKontext('sv_SE', null);

    withHeaders(['Accept-Language' => 'en-US,en;q=0.9'])
        ->actingAs($medlem)
        ->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('locale', 'sv')
            ->where('translations.nav.dashboard', 'Översikt')
        );
});

it('sätter html-attributet lang efter den valda localen', function () {
    withoutVite();

    [, $svensk] = sprakKontext('sv_SE', 'sv_SE');
    [, $engelsk] = sprakKontext('sv_SE', 'en_GB');

    actingAs($svensk)->get('/dashboard')->assertSee('lang="sv"', false);
    actingAs($engelsk)->get('/dashboard')->assertSee('lang="en"', false);
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
    expect(korTranslate("'error.title', { status: 404 }"))->toBe('Fel 404');
});

it('resolvar auth.totp_required och auth.totp_invalid på båda språken', function () {
    foreach (['sv', 'en'] as $locale) {
        foreach (['totp_required', 'totp_invalid'] as $nyckel) {
            $mening = trans("auth.{$nyckel}", [], $locale);

            expect($mening)->not->toBe("auth.{$nyckel}", "auth.{$nyckel} saknas på {$locale}");
            expect($mening)->not->toBe('');
        }
    }

    expect(trans('auth.totp_invalid', [], 'sv'))->not->toBe(trans('auth.totp_invalid', [], 'en'));
});

it('renderar valideringsfel på användarens språk', function () {
    withoutVite();

    Route::middleware('web')->post('/test-validering', function (Request $request): void {
        $request->validate(['email' => ['required']]);
    });

    [, $svensk] = sprakKontext('sv_SE', 'sv_SE');
    [, $engelsk] = sprakKontext('sv_SE', 'en_GB');

    actingAs($svensk)->post('/test-validering', ['email' => ''])->assertSessionHasErrors('email');
    expect(session('errors')->get('email')[0])->toContain('obligatoriskt');

    actingAs($engelsk)->post('/test-validering', ['email' => ''])->assertSessionHasErrors('email');
    expect(session('errors')->get('email')[0])->toContain('required');
});

/*
 * Inloggningens POST sker av en GÄST — anroparen är inte autentiserad, så
 * SetLocale har ingen användare att läsa locale ur och språket blir appens
 * standard (`config('app.locale')`). Det är samma regel som för ett
 * gästanrop till `/`, och den motsäger "svenska för en svensk användare" i
 * issuens kriterielista — se PR:ens `## Frågor och antaganden`.
 */
it('renderar gästens POST /login-validering på appens standardspråk', function () {
    withoutVite();

    from('/login')->post('/login', ['email' => '', 'password' => ''])->assertSessionHasErrors('email');

    expect(session('errors')->get('email')[0])
        ->toBe(trans('validation.required', ['attribute' => 'email'], config('app.locale')));
});

it('ger olika text på svenska och engelska — minst tre nycklar', function () {
    withoutVite();

    [, $svensk] = sprakKontext('sv_SE', 'sv_SE');
    [, $engelsk] = sprakKontext('sv_SE', 'en_GB');

    $sv = sprakLov(sprakEgenskaper($svensk)['translations']);
    $en = sprakLov(sprakEgenskaper($engelsk)['translations']);

    expect(array_keys($sv))->toBe(array_keys($en));

    $olika = array_keys(array_filter(
        $sv,
        fn (string $varde, string $nyckel): bool => ($en[$nyckel] ?? null) !== $varde,
        ARRAY_FILTER_USE_BOTH,
    ));

    expect(count($olika))->toBeGreaterThanOrEqual(3);
});

it('har samma nycklar på båda språken och inga tomma strängar', function () {
    $sv = sprakLov(sprakFil('sv'));
    $en = sprakLov(sprakFil('en'));

    expect(array_keys($en))->toBe(array_keys($sv));

    foreach (['sv' => $sv, 'en' => $en] as $locale => $lov) {
        foreach ($lov as $nyckel => $varde) {
            expect(trim($varde))->not->toBe('', "ui.{$nyckel} är tom på {$locale}");
        }
    }
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
 * användarvänd svensk text som gömmer sig undan `lang/`-regeln, och då ska
 * det här testet falla.
 *
 * Att filen finns och bär tio uppsättningar prövas i
 * tests/Feature/Frontend/KategoriuppsattningTest.php, mot samma modul klienten
 * importerar. Här prövas bara att undantaget inte har vidgats.
 */
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
        'type' => trans('notiser.unsubscribe.types.task_due', [], 'sv'),
    ], 'sv');

    get($url)->assertOk()->assertSee($rubrik, false);
});
