<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\withHeaders;
use function Pest\Laravel\withoutVite;

/*
 * Issue 104 · Datumregeln, se [[ADR-0042 Designsystemet]] § Konsekvenser,
 * [[M10 Webbfrontend]] § 52 och [[ADR-0013 Språk och i18n]].
 *
 * Bilderna blandar två format i samma lista — *Om 24 dagar* bredvid *14 okt
 * 2026* — och regeln som avgör vilket som visas bor i
 * resources/js/composables/useRelativeDate.js. Den är ren presentation: inget
 * schema ändras och ingen fråga ställs om, så den prövas mot modulen i node
 * och mot källkoden — inte genom att räkna om ett svar ur databasen.
 *
 * `t()` stubbas i node till att skriva ut nyckeln och sitt `:days`. Testet ser
 * därmed VILKEN mening regeln valde och vilket tal den skickade med, och
 * nycklarna prövas mot lang/en/ui.php i SprakTest.
 */

/**
 * Kör en snutt mot datumregeln i node och returnerar det den skriver ut.
 */
function datumregelKör(string $skript): string
{
    $kod = implode("\n", [
        "const { pathToFileURL } = await import('node:url');",
        'const m = await import(pathToFileURL('
            .json_encode(resource_path('js/composables/useRelativeDate.js'), JSON_UNESCAPED_SLASHES).').href);',
        'const t = (key, params = {}) => key + (params.days === undefined ? "" : ":" + params.days);',
        $skript,
    ]);

    $rader = [];
    $status = 0;

    exec('node --input-type=module -e '.escapeshellarg($kod).' 2>&1', $rader, $status);

    expect($status)->toBe(0, implode("\n", $rader));

    return implode("\n", $rader);
}

/**
 * Vad regeln svarar för ett datum, som en array — så att varje fält kan
 * prövas för sig i stället för mot en hel sträng.
 *
 * @return array{text: ?string, state: string, relative: bool, days: ?int}
 */
function datumregel(string $värde, string $idag, bool $försenad = false, string $locale = 'sv-SE'): array
{
    $json = datumregelKör(sprintf(
        'process.stdout.write(JSON.stringify(m.formatDueDate(%s, { t, locale: %s, today: %s, overdue: %s })));',
        json_encode($värde),
        json_encode($locale),
        json_encode($idag),
        $försenad ? 'true' : 'false',
    ));

    /** @var array{text: ?string, state: string, relative: bool, days: ?int} $svar */
    $svar = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    return $svar;
}

/** Källkoden till komposabeln, som den står. */
function datumregelKälla(): string
{
    return File::get(resource_path('js/composables/useRelativeDate.js'));
}

/** Gränsen i dagar, läst ur källkoden — det enda stället talet står. */
function datumregelGräns(): int
{
    preg_match('/^export const RELATIVE_DAYS = (\d+);$/m', datumregelKälla(), $träff);

    expect($träff)->not->toBeEmpty('RELATIVE_DAYS saknas i useRelativeDate.js');

    return (int) $träff[1];
}

// --- gränsen ---------------------------------------------------------------

/*
 * Klart när: en skriven gräns avgör relativt eller absolut, och den prövas på
 * båda sidor om sig.
 *
 * Talet läses ur konstanten och jämförs med docblocken: ändrar någon gränsen
 * utan att skriva om meningen som förklarar den faller det här provet. Att
 * bara pröva `formatDueDate()` mot ett hårdkodat tal hade inte fångat det —
 * då hade docblocken kunnat säga tjugo medan koden sade trettio.
 */
it('skriver gränsen i komposabelns docblock och prövar båda sidor om den', function () {
    $källa = datumregelKälla();
    $gräns = datumregelGräns();

    preg_match('#/\*(.*?)\*/#s', $källa, $docblock);

    expect($docblock[1] ?? '')->toContain("Gränsen är {$gräns} dagar")
        ->and($källa)->toContain('RELATIVE_DAYS');

    $idag = Carbon::parse('2027-05-01');

    $innanför = $idag->copy()->addDays($gräns)->toDateString();
    $bortom = $idag->copy()->addDays($gräns + 1)->toDateString();

    $relativ = datumregel($innanför, '2027-05-01');
    $absolut = datumregel($bortom, '2027-05-01');

    // På gränsen är datumet relativt, en dag bortom den absolut — och den
    // absoluta grenen är ett datum, inte en mening.
    expect($relativ['relative'])->toBeTrue()
        ->and($relativ['text'])->toBe("date.in_days:{$gräns}")
        ->and($relativ['days'])->toBe($gräns)
        ->and($absolut['relative'])->toBeFalse()
        ->and($absolut['text'])->toBe($bortom);

    // Och de relativa meningarna finns i lang/, som allt annat användaren läser.
    foreach (['today', 'tomorrow', 'in_days', 'overdue', 'overdue_one'] as $nyckel) {
        expect(Lang::get("ui.date.{$nyckel}", [], 'en'))
            ->not->toBe("ui.date.{$nyckel}", "ui.date.{$nyckel} saknas");
    }

    expect(Lang::get('ui.date.in_days', [], 'en'))->toContain(':days');
    expect(Lang::get('ui.date.overdue', [], 'en'))->toContain(':days');
});

// --- förfallet -------------------------------------------------------------

/*
 * Klart när: ett förfallet datum är alltid relativt och markerat som fara.
 *
 * *3 dagar försenad* är den upplysning en förfallodag är till för; *14 okt
 * 2026* säger ingenting om att den är sen. Övriga tillstånd bär `warning` när
 * datumet närmar sig och `neutral` när det ligger bortom gränsen — ADR-0042:s
 * egna färgroller, och brickans fyra tillstånd (UiBadge, issue 99).
 */
it('gör ett förfallet datum relativt och markerat som fara', function () {
    $försenad = datumregel('2027-04-28', '2027-05-01', true);

    expect($försenad['relative'])->toBeTrue()
        ->and($försenad['state'])->toBe('danger')
        ->and($försenad['text'])->toBe('date.overdue:3');

    // Även utan serverns flagga: ett datum bakåt i tiden ÄR förfallet.
    expect(datumregel('2027-04-28', '2027-05-01')['state'])->toBe('danger');

    // En dag sen har sin egen mening — `t()` pluraliserar inte (issue 52
    // § Beslut 4), och "1 dagar försenad" är fel.
    expect(datumregel('2027-04-30', '2027-05-01', true)['text'])->toBe('date.overdue_one');

    // Relativt hur länge sedan det än är: en uppgift från i fjol tappar inte
    // ordet som säger att den är sen.
    $gammal = datumregel('2026-05-01', '2027-05-01');

    expect($gammal['relative'])->toBeTrue()
        ->and($gammal['text'])->toBe('date.overdue:365');

    /*
     * Serverns flagga vinner över klockan. En klient som ligger efter får
     * inte skriva "0 dagar sen": tillståndet kommer ur `overdue`
     * ([[ADR-0005 Schema och förekomst]]), och klockan får bara räkna hur
     * många dagar — aldrig om.
     */
    $efter = datumregel('2027-05-01', '2027-05-01', true);

    expect($efter['state'])->toBe('danger')
        ->and($efter['text'])->toBe('date.overdue_one');

    // Och flaggan är serverns i båda panelerna som ritar ett förfallodatum.
    expect(File::get(resource_path('js/components/TodoRow.vue')))->toContain('entry.overdue');
    expect(File::get(resource_path('js/components/OpenOccurrence.vue')))
        ->toContain('dueDate(props.occurrence.due_at, props.occurrence.overdue)');
});

// --- DATE-kontraktet -------------------------------------------------------

/*
 * Klart när: regeln tar DATE-strängar och allt annat ger `null`.
 *
 * `due_at` och `visible_from` är DATE-kolumner, och de kommer som `Y-m-d`.
 * Tidsstämpelformen — *Idag 10:24* ur aktivitetslistan — hör till en yta som
 * ännu inte ritas ([[ADR-0042 Designsystemet]] § Konsekvenser), och den byggs
 * här när ytan finns. Tills dess är svaret `null`, och det är ett PRÖVAT
 * beslut och inte en odokumenterad bugg: en tyst `null` är en fälla som
 * väntar, en prövad `null` är ett kontrakt.
 */
it('svarar null för allt som inte är ett DATE', function () {
    // En tidsstämpel, inte ett DATE.
    $tidsstämpel = datumregel('2027-05-05T10:24:00Z', '2027-05-01');

    expect($tidsstämpel['text'])->toBeNull()
        ->and($tidsstämpel['relative'])->toBeFalse()
        ->and($tidsstämpel['days'])->toBeNull()
        ->and($tidsstämpel['state'])->toBe('neutral');

    // Ett tomt värde likaså: ingen dag att skriva ut.
    expect(datumregel('', '2027-05-01')['text'])->toBeNull();
});

// --- språket ---------------------------------------------------------------

/*
 * Klart när: formatet följer användarens `locale` och inte `Accept-Language`.
 *
 * Två led. Formen kommer ur locale — samma datum skrivs olika för en svensk
 * och en engelsk användare — och locale är användarens egen, ur den delade
 * propen, aldrig ur requestens rubrik (issue 52, [[ADR-0013 Språk och i18n]]).
 *
 * Sedan [[ADR-0034 Engelska vid lansering]] är `en` den enda katalogen, så en
 * svensk locale möts av engelska; det är katalogen som avgör orden. Datumets
 * FORM följer locale-kolumnen ändå, och det är den regeln prövas här.
 */
it('formaterar efter användarens locale och aldrig efter Accept-Language', function () {
    $svenskt = datumregel('2027-06-01', '2027-05-01', false, 'sv-SE');
    $engelskt = datumregel('2027-06-01', '2027-05-01', false, 'en-US');

    expect($svenskt['text'])->toBe('2027-06-01')
        ->and($engelskt['text'])->not->toBe($svenskt['text']);

    // Den relativa grenen är locale-oberoende med flit: meningen kommer ur
    // lang/ och inte ur `Intl`.
    expect(datumregel('2027-05-25', '2027-05-01', false, 'en-US')['text'])
        ->toBe(datumregel('2027-05-25', '2027-05-01', false, 'sv-SE')['text']);

    // Locale läses ur den delade propen — ingen rubrik någonstans i ledet.
    expect(datumregelKälla())->toContain('usePage().props.locale');

    withoutVite();

    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'member']);

    withHeaders(['Accept-Language' => 'sv-SE,sv;q=0.9'])
        ->actingAs($anvandare)
        ->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('locale', 'en'));
});

// --- en formatering, inte fem ----------------------------------------------

/*
 * Klart när: ingen panel formaterar datum själv.
 *
 * Regeln gäller på minst fem ställen — containerns underhållspanel,
 * dashboardens upptäcktslista, itemets paneler, aktivitetslistan och todo-vyn
 * — och skrivs den i varje panel blir den fem regler som glider isär. Provet
 * är därför ett källkodsprov och inte ett vyprov: det faller på
 * `toLocaleDateString` i varje fil utom komposabeln, alltså även i en panel
 * som ännu inte finns.
 *
 * itemPresentation.js och accessPresentation.js skriver ut datum utan att
 * välja form och lånar därför `formatLocaleDate()` — den som lägger en egen
 * `Intl`-formatering i en sjätte modul faller här.
 *
 * Kommentarsrader hoppas över: `toLocaleDateString` står med flit i
 * docblocken som förklarar fällan, och en rad som bara BESKRIVER anropet är
 * inte anropet (AGENTS.md § Språk i koden — prosan är svensk och fri).
 */
it('låter ingen panel formatera ett datum själv', function () {
    $komposabel = resource_path('js/composables/useRelativeDate.js');
    $syndare = [];

    foreach (File::allFiles(resource_path('js')) as $fil) {
        if (! in_array($fil->getExtension(), ['js', 'vue'], true) || $fil->getPathname() === $komposabel) {
            continue;
        }

        foreach (preg_split('/\R/', $fil->getContents()) ?: [] as $nummer => $rad) {
            if (preg_match('#^\s*(/\*|\*|//|<!--)#', $rad) === 1) {
                continue;
            }

            if (str_contains($rad, 'toLocaleDateString') || str_contains($rad, 'toLocaleTimeString')) {
                $syndare[] = sprintf('%s:%d', $fil->getRelativePathname(), $nummer + 1);
            }
        }
    }

    expect($syndare)->toBe([]);

    // Och panelerna räknar ingen tid själva: `månader` och `dagar` kommer ur
    // regeln, och klockan bor i komposabeln.
    foreach (['components/TodoRow.vue', 'components/OpenOccurrence.vue'] as $fil) {
        $kod = File::get(resource_path("js/{$fil}"));

        expect($kod)->not->toContain('new Date')
            ->and($kod)->not->toContain('Date.now')
            ->and($kod)->not->toContain('toISOString')
            ->and($kod)->toContain('useRelativeDate');
    }
});
