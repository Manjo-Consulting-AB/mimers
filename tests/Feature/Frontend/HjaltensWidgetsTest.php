<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * M84 · issue #1651 · Hjältens widgetar står bredvid brickorna och är lika
 * höga, se resources/js/components/AppHero.vue,
 * resources/js/components/ContainerHero.vue,
 * resources/js/components/DashboardStats.vue,
 * resources/js/components/UiStat.vue, resources/js/components/HeroCosts.vue,
 * resources/js/components/HeroStorage.vue och [[ADR-0090 Dashboardens hjälte]]
 * § Tillägg 2026-11-24 · [[ADR-0042 Designsystemet]] § Beslut.
 *
 * Filen bevisar *Klart när*:
 *
 * 1. `AppHero` har varken `md:flex-1` på `DashboardStats` eller skelettet, och
 *    sifferraden har `gap-4` i stället för `gap-8`.
 * 2. Ordningen står kvar: brickorna, `HeroCosts`, `HeroStorage`, och skelettet
 *    står på brickornas och kostnadernas plats.
 * 3. Sifferraden sträcker sina barn (`items-stretch`) och `md:min-h-[102px]`
 *    är ett golv, aldrig ett tak.
 * 4. `HeroCosts`, `HeroStorage` och båda brickorna fyller radens höjd; länken
 *    och `UiStat` bär höjden genom `class`.
 * 5. `UiStat` har en standardslot under etiketten och ingen ny propp; utan
 *    slotinnehåll är markupen oförändrad.
 * 6. *Overdue* ritas inuti uppgiftsbrickan, inuti länken till `/tasks`, med
 *    ikonen `overdue` i 16 px och `text-danger` — och ingen rad alls vid noll.
 * 7. Två valutor ger två rader i `HeroCosts` och radens höjd kapas inte.
 * 8. `ContainerHero` följer samma höjdregel med oförändrad ordning och länkar.
 * 9. Skelettet följer samma regler och uppgiftsbrickans skelett bär den tredje
 *    raden inuti rutan.
 *
 * Källkodsproven läser formen, som grannfilerna: komponenten ritas i
 * webbläsaren och går inte att rendera här. Kommentarerna rensas bort först, så
 * en mening i ett docblock inte kan nöja ett prov.
 *
 * Hjälparna har prefixet `hjaltwidget` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Källkoden med kommentarer borta — samma tre slag som GenomgangTest rensar.
 */
function hjaltwidgetUtanKommentarer(string $kod): string
{
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * En komponent i `resources/js/`, med kommentarer borta.
 */
function hjaltwidgetKod(string $sokvag): string
{
    return hjaltwidgetUtanKommentarer(File::get(resource_path("js/{$sokvag}")));
}

/**
 * Klasserna på den `<div>` ett element står direkt i, eller null när elementet
 * inte står direkt i en div. `$element` är markupen som inleder elementet.
 *
 * `<` sätts utanför `preg_quote`: PCRE läser `\<` som en ordgräns och inte som
 * ett mindre-än, så en quoted `<` matchar aldrig.
 */
function hjaltwidgetRadKlasser(string $markup, string $element): ?string
{
    $namn = ltrim($element, '<');

    if (preg_match('/<div class="([^"]*)"[^>]*>\s*<'.preg_quote($namn, '/').'/s', $markup, $träff) !== 1) {
        return null;
    }

    return $träff[1];
}

/**
 * Ett ägarkonto med en medlem i, i UTC så att månaden är entydig.
 *
 * @return array{0: Account, 1: User}
 */
function hjaltwidgetKonto(): array
{
    $konto = Account::factory()->create(['timezone' => 'UTC']);
    $anvandare = User::factory()->create(['timezone' => 'UTC']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$konto, $anvandare];
}

// --- platsen ---------------------------------------------------------------

/*
 * Klart när: "I `AppHero.vue` har varken `DashboardStats` eller skelettet
 * `md:flex-1`, och sifferraden har `gap-4` i stället för `gap-8`."
 *
 * Utan `md:flex-1` växer ingen av brickorna i sidled, så *Costs* och *Storage*
 * står direkt efter dem — samma form som `ContainerHero.vue`, där ingenting
 * växer. `gap-4` är samma avstånd som containerns hjälte bär.
 */
it('ställer widgetarna direkt efter brickorna utan att någon växer i sidled', function () {
    $hjalten = hjaltwidgetKod('components/AppHero.vue');

    expect($hjalten)->not->toContain('md:flex-1');

    $raden = hjaltwidgetRadKlasser($hjalten, '<DashboardStats');

    expect($raden)->not->toBeNull('brickorna står inte i en egen rad')
        ->and($raden)->toContain('gap-4')
        ->and($raden)->not->toContain('gap-8')
        ->and($raden)->not->toContain('items-start');

    // Skelettets block har samma avstånd och växer inte heller.
    expect($hjalten)->toMatch('/<div v-if="!loaded" class="([^"]*)" aria-hidden="true">/s');

    preg_match('/<div v-if="!loaded" class="([^"]*)" aria-hidden="true">/s', $hjalten, $skelett);

    expect($skelett[1])->toContain('flex-wrap')
        ->and($skelett[1])->toContain('items-stretch')
        ->and($skelett[1])->toContain('gap-4')
        ->and($skelett[1])->not->toContain('md:flex-1')
        ->and($skelett[1])->not->toContain('items-start');
});

/*
 * Klart när: "Ordningen i sifferraden är oförändrad: brickorna, `HeroCosts`,
 * `HeroStorage`, och skelettet står på brickornas och kostnadernas plats
 * medan `heroFigures` hämtas."
 */
it('behåller ordningen brickorna, Costs, Storage och låter skelettet stå på deras plats', function () {
    $hjalten = hjaltwidgetKod('components/AppHero.vue');

    $brickorna = strpos($hjalten, '<DashboardStats');
    $kostnaderna = strpos($hjalten, '<HeroCosts');
    $skelettet = strpos($hjalten, 'v-if="!loaded"');
    $lagringen = strpos($hjalten, '<HeroStorage');

    expect($brickorna)->not->toBeFalse()
        ->and($kostnaderna)->not->toBeFalse()
        ->and($skelettet)->not->toBeFalse()
        ->and($lagringen)->not->toBeFalse();

    expect($brickorna)->toBeLessThan($kostnaderna)
        ->and($kostnaderna)->toBeLessThan($skelettet)
        ->and($skelettet)->toBeLessThan($lagringen);
});

/*
 * Klart när: "Sifferraden i `AppHero.vue` sträcker sina barn till radens höjd
 * (inte `items-start`), och `md:min-h-[102px]` står kvar på raden som golv,
 * inte som tak."
 *
 * `items-stretch` är radens regel; golvet håller raden minst så hög som
 * brickorna och begränsar den aldrig uppåt. Därför finns inget `max-h` på
 * raden eller på `HeroCosts`.
 */
it('sträcker radens barn och behåller golvet som ett minimum', function () {
    $hjalten = hjaltwidgetKod('components/AppHero.vue');

    $raden = hjaltwidgetRadKlasser($hjalten, '<DashboardStats');

    expect($raden)->toContain('items-stretch')
        ->and($raden)->toContain('md:min-h-[102px]')
        ->and($raden)->not->toContain('max-h');

    $summeringen = hjaltwidgetKod('components/HeroCosts.vue');

    expect($summeringen)->not->toContain('max-h')
        ->and($summeringen)->not->toMatch('/\bmin-h-/');

    // Rader ritas per valuta och staplas på höjden — ingen sidled och ingen
    // fast höjd.
    expect($summeringen)->toContain('v-for="total in props.totals"')
        ->and($summeringen)->toContain('flex-col');
});

// --- höjden ----------------------------------------------------------------

/*
 * Klart när: "`HeroCosts`, `HeroStorage` och båda brickorna i `DashboardStats`
 * fyller radens höjd; länkarna och `UiStat` bär höjden genom `class`."
 *
 * Raden sträcker länkarna till sin höjd, och `UiStat` fyller länken med
 * `h-full` — `class` faller igenom till taltutans rot. `HeroCosts` och
 * `HeroStorage` är radens egna barn och sträcks av `items-stretch`.
 */
it('fyller radens höjd genom länkarna och UiStat', function () {
    $brickorna = hjaltwidgetKod('components/DashboardStats.vue');

    // Båda taltutorna fyller sin länk.
    expect(substr_count($brickorna, 'class="h-full"'))->toBe(2);

    preg_match_all('#<Link\b[^>]*>\s*<UiStat\b([^>]*)>#s', $brickorna, $träffar, PREG_SET_ORDER);

    expect($träffar)->toHaveCount(2, 'brickorna omsluts inte var för sig av en <Link>');

    foreach ($träffar as $träff) {
        expect($träff[1])->toContain('class="h-full"');
    }

    // `HeroCosts` och `HeroStorage` står i raden och sträcks med den.
    $hjalten = hjaltwidgetKod('components/AppHero.vue');
    $raden = hjaltwidgetRadKlasser($hjalten, '<DashboardStats');

    expect($raden)->toContain('items-stretch');

    expect($hjalten)->toContain('<HeroCosts')
        ->and($hjalten)->toContain('<HeroStorage');
});

// --- UiStat och Overdue ----------------------------------------------------

/*
 * Klart när: "`UiStat` har en standardslot under etiketten och ingen ny prop.
 * Utan slotinnehåll är dess markup oförändrad."
 *
 * Sloten är en plats att rita en underrad på, inte en propp: den som inte
 * fyller den får samma markup som förut. Propplistan är därför orörd.
 */
it('har en standardslot under etiketten och ingen ny propp', function () {
    $tutan = hjaltwidgetKod('components/UiStat.vue');

    $etikett = strpos($tutan, '{{ label }}');
    $slot = strpos($tutan, '<slot />');

    expect($etikett)->not->toBeFalse('UiStat ritar ingen etikett')
        ->and($slot)->not->toBeFalse('UiStat har ingen standardslot');

    expect($slot)->toBeGreaterThan($etikett);

    // Propparna är de fyra som fanns: value, label, icon, tone. Ingen slot-propp.
    expect($tutan)->toContain('value: { type: [Number, String], required: true }')
        ->and($tutan)->toContain('label: { type: String, required: true }')
        ->and($tutan)->toContain('icon: { type: String, default: null }')
        ->and($tutan)->toContain('tone: { type: String, default: null }');

    expect(substr_count($tutan, 'defineProps'))->toBe(1);

    // Utan slotinnehåll ritas talet och etiketten exakt som förut.
    expect($tutan)->toMatch(
        '/<span v-else class="text-heading font-semibold" :class="toneClasses\.value">\{\{ value \}\}<\/span>/',
        'UiStat utan ikon ritar inte värdet som förut',
    );
});

/*
 * Klart när: "Uppgiftsbrickan ritar *Overdue* inuti `UiStat`-rutan, inuti
 * länken till `/tasks`, med ikonen `overdue` och `text-danger`. Ingen
 * *Overdue*-rad ritas utanför rutan."
 *
 * Raden är en underrad till talet, inte en egen yta: den ligger i samma
 * glasruta, inuti samma länk som hela brickan.
 */
it('ritar Overdue inuti uppgiftsbrickan och inuti länken till /tasks', function () {
    $brickorna = hjaltwidgetKod('components/DashboardStats.vue');

    preg_match('#<Link\s+href="/tasks"[^>]*>\s*<UiStat\b[^>]*>(.*?)</UiStat>\s*</Link>#s', $brickorna, $träff);

    expect($träff)->toHaveCount(2, 'uppgiftsbrickan är ingen <Link> till /tasks med en <UiStat> i');

    $inuti = $träff[1];

    expect($inuti)->toContain('v-if="props.stats.overdue > 0"')
        ->and($inuti)->toContain('text-danger')
        ->and($inuti)->toContain('<UiIcon name="overdue" :size="16" />')
        ->and($inuti)->toContain("t('dashboard.stats.overdue', { count: props.stats.overdue })");

    // Bara EN förekomst, och den står inuti rutan — ingen lös rad under den.
    expect(substr_count($brickorna, 'dashboard.stats.overdue'))->toBe(1);

    // Ingen egen yta: raden är ett `<p>` i sloten, inte en länk eller knapp.
    expect($inuti)->not->toContain('<Link')
        ->and($inuti)->not->toContain('<a ')
        ->and($inuti)->not->toContain('<button');
});

/*
 * Klart när: "Med `stats.overdue` lika med 0 ritas ingen *Overdue*-rad och
 * ingen ikon; med 1 eller fler ritas den oförändrade texten
 * `dashboard.stats.overdue` (`Overdue: :count`)."
 *
 * Villkoret är `> 0`, alltså ritas varken en tom rad eller en med noll. Texten
 * och nyckeln är oförändrade — `UppgiftsetiketterTest` läser dem.
 */
it('ritar ingen Overdue-rad vid noll och den oförändrade texten vid ett eller fler', function () {
    $brickorna = hjaltwidgetKod('components/DashboardStats.vue');

    expect($brickorna)->toContain('v-if="props.stats.overdue > 0"')
        ->and($brickorna)->toContain("t('dashboard.stats.overdue', { count: props.stats.overdue })");

    // Nyckeln och meningen är desamma som före issue #1651.
    expect(trans('ui.dashboard.stats.overdue', ['count' => 2], 'en'))->toBe('Overdue: 2');
});

// --- valutorna -------------------------------------------------------------

/*
 * Klart när: "Med två valutor i `costs.totals` staplas båda raderna i
 * `HeroCosts`, och sifferradens höjd följer den högsta widgeten i stället för
 * att kapas av golvet."
 *
 * Två kostnadsrader i olika valutor ger två totaler ur samma action, och
 * `HeroCosts` ritar en rad per total. Raden har ett golv men inget tak, så
 * hjälten växer med den högsta widgeten.
 */
it('staplar två valutor i HeroCosts och låter radens höjd följa den högsta', function () {
    withoutVite();

    Carbon::setTestNow(Carbon::parse('2026-11-24 12:00:00', 'UTC'));

    [$konto, $anvandare] = hjaltwidgetKonto();

    $container = Container::factory()->for($konto, 'account')->create();

    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    foreach ([['SEK', 1000], ['EUR', 2000]] as [$valuta, $belopp]) {
        ItemTransaction::factory()->for($item, 'item')->create([
            'amount' => $belopp,
            'currency' => $valuta,
            'incurred_on' => '2026-11-24',
            'created_by_user_id' => $anvandare->id,
            'created_by_account_id' => $konto->id,
        ]);
    }

    /** @var array{totals: list<array{currency: string, amount: int, count: int}>} $kostnader */
    $kostnader = actingAs($anvandare)->get('/dashboard')->assertOk()->inertiaProps()['costs'];

    expect($kostnader['totals'])->toHaveCount(2)
        ->and(array_column($kostnader['totals'], 'currency'))->toContain('SEK')
        ->and(array_column($kostnader['totals'], 'currency'))->toContain('EUR');

    $summeringen = hjaltwidgetKod('components/HeroCosts.vue');

    expect($summeringen)->toContain('v-for="total in props.totals"')
        ->and($summeringen)->not->toContain('max-h');

    $hjalten = hjaltwidgetKod('components/AppHero.vue');

    expect($hjalten)->not->toContain('max-h');

    Carbon::setTestNow();
});

// --- containerns hjälte ----------------------------------------------------

/*
 * Klart när: "`ContainerHero.vue` ritar *Items*, *Open tasks*, `HeroCosts` och
 * `HeroStorage` med samma höjdregel, och ordningen och länkarna är
 * oförändrade."
 *
 * Platsen var redan rätt här — ingenting växer i sidled — så bara höjdregeln
 * tillkommer. *Open tasks* har ingen *Overdue*-rad.
 */
it('ger containerns hjälte samma höjdregel med oförändrad ordning och länkar', function () {
    $hjalten = hjaltwidgetKod('components/ContainerHero.vue');

    // Raden bär `v-if="counts"` före `class`, så klasserna läses ur taggen.
    preg_match('#<div\b([^>]*v-if="counts"[^>]*)>#s', $hjalten, $rad);
    preg_match('/class="([^"]*)"/', $rad[1] ?? '', $klass);

    $raden = $klass[1] ?? null;

    expect($raden)->not->toBeNull('talen står inte i en egen rad')
        ->and($raden)->toContain('items-stretch')
        ->and($raden)->toContain('gap-4');

    // Båda taltutorna fyller sin länk.
    expect(substr_count($hjalten, 'class="h-full"'))->toBe(2);

    // Ordningen: Items, Open tasks, Costs, Storage.
    $items = strpos($hjalten, ':value="counts.items"');
    $uppgifter = strpos($hjalten, ':value="counts.todos"');
    $kostnader = strpos($hjalten, '<HeroCosts');
    $lagring = strpos($hjalten, '<HeroStorage');

    expect($items)->not->toBeFalse()
        ->and($uppgifter)->not->toBeFalse()
        ->and($kostnader)->not->toBeFalse()
        ->and($lagring)->not->toBeFalse();

    expect($items)->toBeLessThan($uppgifter)
        ->and($uppgifter)->toBeLessThan($kostnader)
        ->and($kostnader)->toBeLessThan($lagring);

    // Länkarna är oförändrade.
    expect($hjalten)->toContain('`/containers/${container.ulid}/items`')
        ->and($hjalten)->toContain('`/containers/${container.ulid}/tasks`');

    // *Open tasks* har ingen underrad.
    expect($hjalten)->not->toContain('overdue');
});

// --- skelettet -------------------------------------------------------------

/*
 * Klart när: "Skelettets block i `AppHero.vue` följer samma höjdregel, och
 * uppgiftsbrickans skelett har den tredje raden inuti rutan."
 *
 * Den gamla formen lade *Overdue* som en `<p>` under rutan. Nu bär
 * uppgiftsbrickan tre rader inuti samma ruta, så ramen står stilla när talen
 * kommer — och den första taltutan bär fortfarande två.
 */
it('följer samma höjdregel i skelettet med tredje raden inuti rutan', function () {
    $hjalten = hjaltwidgetKod('components/AppHero.vue');

    // Ingen lös rad under rutan längre.
    expect($hjalten)->not->toContain('<div class="flex flex-col gap-1">');

    // Uppgiftsbrickans skelett: tre rader inuti samma ruta.
    expect($hjalten)->toMatch(
        '#<div class="flex min-w-32 flex-col rounded-card border border-glass-border bg-glass px-4 py-3">\s*<span class="block text-heading font-semibold">&nbsp;</span>\s*<span class="block text-meta">&nbsp;</span>\s*<span class="block text-meta">&nbsp;</span>\s*</div>#s',
        'uppgiftsbrickans skelett bär ingen tredje rad inuti rutan',
    );

    // Den första taltutan bär fortfarande två rader.
    expect($hjalten)->toMatch(
        '#<div class="flex min-w-32 flex-col rounded-card border border-glass-border bg-glass px-4 py-3">\s*<span class="block text-heading font-semibold">&nbsp;</span>\s*<span class="block text-meta">&nbsp;</span>\s*</div>#s',
        'containerbrickans skelett bär inte två rader',
    );

    // Skelettet är dolt för skärmläsare och följer radens höjdregel.
    expect($hjalten)->toMatch('/<div v-if="!loaded" class="flex flex-wrap items-stretch gap-4" aria-hidden="true">/');
});
