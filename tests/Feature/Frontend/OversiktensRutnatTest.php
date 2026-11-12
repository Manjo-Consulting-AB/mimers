<?php

use App\Actions\User\ReadOverviewPanels;
use App\Models\Account;
use App\Models\Container;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 1113 · översikten ritar panelerna i personens ordning och bredd, se
 * resources/js/pages/Containers/Overview.vue och
 * [[ADR-0073 Översiktens ordning]] § 2–4. Provet ersätter reglerna från
 * issue 288 och 321 (rutnätet utan hål), som ADR:en avskaffade.
 *
 * **Rutnätet ritas i webbläsaren och går inte att rendera här.** Servern
 * provas på proppen `panels`; formen på vyn provas som källa, med
 * kommentarerna borta (som i GenomgangTest): filerna är fulla av svensk prosa
 * som förklarar varför, och en mening om `lg:order-4` ska inte kunna fälla ett
 * prov som letar efter klassen.
 *
 * Hjälparna har prefixet `rutnat` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Källkoden med kommentarer borta: block-, HTML- och radkommentarer.
 */
function rutnatUtanKommentarer(string $kod): string
{
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Översikten med kommentarer borta.
 */
function rutnatVy(): string
{
    return rutnatUtanKommentarer(File::get(resource_path('js/pages/Containers/Overview.vue')));
}

/**
 * En fil ur resources/js, relativt katalogen, med kommentarer borta.
 */
function rutnatKod(string $sokvag): string
{
    return rutnatUtanKommentarer(File::get(resource_path("js/{$sokvag}")));
}

/**
 * En container med en ägare, för de tester som ber servern om översikten.
 *
 * @return array{0: User, 1: Container}
 */
function rutnatContainer(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $person = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($person, ['role' => 'owner']);

    return [$person, Container::factory()->for($konto, 'account')->create()];
}

/*
 * Klart när: översiktens svar har proppen `panels` lika med
 * `ReadOverviewPanels` för den inloggade personen.
 */
it('skickar proppen panels lika med ReadOverviewPanels för personen', function () {
    withoutVite();

    [$person, $container] = rutnatContainer();

    $props = actingAs($person)->get("/containers/{$container->ulid}")->assertOk()->inertiaProps();

    expect($props['panels'])->toBe(app(ReadOverviewPanels::class)->handle($person))
        ->and(array_column($props['panels'], 'key'))->toBe(['tasks', 'costs', 'details', 'items', 'activity']);
});

/*
 * Klart när: för en person som sparat `activity` först står `activity` först
 * i proppen `panels`, med den sparade bredden.
 */
it('ger activity först i panels för en person som sparat den först', function () {
    withoutVite();

    [$person, $container] = rutnatContainer();

    foreach (['activity' => 'wide', 'tasks' => 'narrow', 'costs' => 'narrow', 'details' => 'narrow', 'items' => 'wide'] as $nyckel => $bredd) {
        DB::table('overview_panel')->insert([
            'user_id' => $person->id,
            'panel_key' => $nyckel,
            'position' => DB::table('overview_panel')->where('user_id', $person->id)->count(),
            'width' => $bredd,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $panels = actingAs($person)->get("/containers/{$container->ulid}")->assertOk()->inertiaProps()['panels'];

    expect($panels[0])->toBe(['key' => 'activity', 'width' => 'wide'])
        ->and(array_column($panels, 'key'))->toBe(['activity', 'tasks', 'costs', 'details', 'items']);
});

/*
 * Klart när: `Overview.vue` ritar panelerna i en loop över `props.panels`,
 * och ingen panel bär `lg:order-*`. Rutnätet behåller sin form.
 */
it('ritar panelerna i en loop över props.panels utan lg:order', function () {
    $vy = rutnatVy();

    expect($vy)->toContain('v-for="(panel, index) in shownPanels"')
        ->toContain('lg:grid lg:grid-flow-dense lg:grid-cols-3');
    expect($vy)->not->toMatch('/order-\d/', 'en panel bär fortfarande en ordningsklass');
    expect($vy)->not->toMatch('/lg:row-span-\d/', 'omslaget spänner fortfarande rader');
    expect($vy)->not->toMatch('/lg:row-start-\d/');
    expect($vy)->not->toMatch('/lg:col-start-\d/');

    foreach (['tasks', 'costs', 'details', 'items', 'activity'] as $nyckel) {
        expect($vy)->toContain("panel.key === '{$nyckel}'");
    }
});

/*
 * Klart när: en panel med `wide` bär `lg:col-span-2`, och ingen panel får ett
 * spann ur `costs.totals.length` eller `recentImages.length`.
 */
it('ger wide lg:col-span-2 och spänner inte ur innehållet', function () {
    $vy = rutnatVy();

    expect($vy)->toContain("panel.width === 'wide' ? 'lg:col-span-2' : ''");
    expect($vy)->not->toMatch('/(costs\.totals|recentImages)\.length\s*\?/', 'ett spann beror på innehållet');
    expect($vy)->not->toContain('lg:col-span-3');

    // Det enda spannet i filen är det som `wide` ger.
    expect(substr_count($vy, 'col-span'))->toBe(1);
});

/*
 * Klart när: Costs ritas utan kostnadsrader, med `UiEmptyState` och
 * `container.overview.costs_empty`. Panelen har ingen `v-if` som döljer den.
 */
it('ritar Costs med tomt läge utan kostnadsrader', function () {
    withoutVite();

    [$person, $container] = rutnatContainer();

    $props = actingAs($person)->get("/containers/{$container->ulid}")->assertOk()->inertiaProps();

    expect($props['costs'])->toBe(['totals' => [], 'breakdown' => []]);

    $vy = rutnatVy();

    expect($vy)->toMatch(
        "~<UiEmptyState v-if=\"props\\.costs\\.totals\\.length === 0\">\\s*\\{\\{ t\\('container\\.overview\\.costs_empty'\\) \\}\\}\\s*</UiEmptyState>~s",
    )
        ->toMatch('~<CostDonut\s+v-else\b~s');
    expect($vy)->not->toContain('v-if="props.costs.totals.length"');

    expect(Lang::get('ui.container.overview.costs_empty', [], 'en'))->toBe('No costs recorded yet.');
});

/*
 * Klart när: Recent images inuti `items` ritas inte utan bilder (ADR-0073 § 4)
 * och står under Items-kortet i samma panel.
 */
it('ritar Recent images under Items och bara när bilder finns', function () {
    $vy = rutnatVy();

    expect(preg_match(
        "~panel\\.key === 'items'\">(.*?)panel\\.key === 'activity'~s",
        $vy,
        $träff,
    ))->toBe(1);

    expect($träff[1])->toContain('<RecentImagesPanel')
        ->and(strpos($träff[1], '<RecentImagesPanel'))->toBeGreaterThan((int) strpos($träff[1], '<ul v-else'))
        ->and($träff[1])->toContain('v-if="props.recentImages.length"');
});

/*
 * Klart när: informationsytan står sist, efter loopen, och ingen annan
 * markör står efter den. Under `lg:` är källordningen panelordningen.
 */
it('ställer informationsytan sist, efter loopen', function () {
    $vy = rutnatVy();

    $loop = (int) strpos($vy, 'v-for="(panel, index) in shownPanels"');
    $info = strpos($vy, '<InfoPanel');

    expect($info)->not->toBeFalse()
        ->and($info)->toBeGreaterThan($loop)
        ->and(substr_count($vy, '<InfoPanel'))->toBe(1);

    // Efter informationsytan följer bara stängande taggar.
    expect(trim((string) preg_replace('~<InfoPanel[^>]*/>~', '', substr($vy, (int) $info))))
        ->toMatch('~^</div>\s*</div>\s*</template>$~');

    // Huvudets definitionslista för mobilen står kvar före loopen.
    expect(strpos($vy, 'md:hidden'))->toBeLessThan($loop);
});

/*
 * Klart när: varje panelrubrik på översikten bär en `UiIcon` ur listan.
 *
 * Översiktens egna paneler — kostnader, items och aktiviteter — ritar ikonen
 * i `heading`-slotten; de tre egna panelkomponenterna gör det samma.
 * Informationsytan får sin roll av anroparen: den ritas också på dashboarden,
 * som inte har fått sina ikoner än ([[M32 Formspråket]]).
 */
it('ger varje panelrubrik en ikon ur listan', function () {
    $vy = rutnatVy();

    expect($vy)->toMatch('~<template #heading>\s*<UiIcon name="cost" />~s')
        ->toMatch('~<template #heading>\s*<UiIcon name="item" />~s')
        ->toMatch('~<template #heading>\s*<UiIcon name="history" />~s');

    $ikoner = [
        'components/ContainerTasksPanel.vue' => 'tasks',
        'components/ContainerDetailsPanel.vue' => 'container',
        'components/RecentImagesPanel.vue' => 'image',
    ];

    foreach ($ikoner as $fil => $roll) {
        expect(rutnatKod($fil))->toMatch(
            '~<template #heading>\s*<UiIcon\b[^>]*name="'.$roll.'"[^>]*/>~s',
            "{$fil} saknar {$roll} i rubriken",
        );
    }

    // Informationsytan tar rollen som propp och ritar ingenting utan den.
    expect($vy)->toMatch('~<InfoPanel\b[^>]*\bicon="tip"~s')
        ->and(rutnatKod('components/InfoPanel.vue'))
        ->toMatch('~<UiIcon v-if="props\.icon" :name="props\.icon" />~')
        ->and(rutnatKod('components/InfoPanel.vue'))
        ->toContain('icon: { type: String, default: null }');
});

/*
 * Klart när: inget tomt läge på översikten är ett bart `<p>`; alla ritas av
 * `UiEmptyState`.
 *
 * De tre tomma lägena — uppgifterna, itemen och aktiviteterna — lånar samma
 * nycklar som förut. Bara formen byts: `UiEmptyState` äger ramen, och en bar
 * `<p>` var en yta utan den.
 */
it('ritar varje tomt läge med UiEmptyState', function () {
    $vy = rutnatVy();

    expect($vy)->toContain("import UiEmptyState from '../../components/UiEmptyState.vue'")
        ->toMatch("~<UiEmptyState v-if=\"props\\.items\\.length === 0\">\\s*\\{\\{ t\\('item\\.index\\.empty'\\) \\}\\}\\s*</UiEmptyState>~s")
        ->toMatch("~<UiEmptyState v-if=\"props\\.events\\.length === 0\">\\s*\\{\\{ t\\('audit\\.history\\.empty'\\) \\}\\}\\s*</UiEmptyState>~s");

    expect($vy)->not->toMatch('~<p[^>]*v-if="props\\.(items|events)\\.length === 0"~s');

    $uppgifter = rutnatKod('components/ContainerTasksPanel.vue');

    expect($uppgifter)->toContain("import UiEmptyState from './UiEmptyState.vue'")
        ->toMatch("~<UiEmptyState v-if=\"props\\.tasks\\.length === 0\">\\s*\\{\\{ t\\('todo\\.empty\\.nothing'\\) \\}\\}\\s*</UiEmptyState>~s");

    expect($uppgifter)->not->toMatch('~<p[^>]*v-if="props\\.tasks\\.length === 0"~s');
});
