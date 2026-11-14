<?php

use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * M58 · issue 1212 · Itemets plusmeny och snabbknappar landar på fliken med
 * registreringen öppen, se [[ADR-0074 Registrering i vyn]] (tillägget
 * 2026-11-13: itemets flikar).
 *
 * Adresserna prövas mot servern. Vad vyn gör med `create=1` prövas på
 * källkoden med kommentarerna borta, eftersom sviten saknar webbläsare —
 * samma sorts prov som ItemflikTest och ItemoversiktTest gör på samma filer.
 * Fokus och utfällning i en riktig webbläsare står i PR-kroppen.
 *
 * Varför menyvalet öppnar formuläret (issue 377/PR #1219, följd i issue 377:s
 * spår): raden är en `<Link>` utan `preserve-state`, alltså ett vanligt
 * GET-besök, och `@inertiajs/vue3` ger då sidkomponenten en ny nyckel — sidan
 * och sektionerna monteras om, och det är `immediate`/`onMounted` som öppnar.
 * Provet om adaptern nedan låser det sista ledet; kommentarerna i sektionerna
 * säger samma sak.
 *
 * Hjälparna har prefixet `itemPlusmeny` — Pest lägger alla testfiler i samma
 * namnrymd. Kontexthjälparna `plusknappKontext()` och `plusknappItem()` bor i
 * PlusknappTest.
 */

function itemPlusmenyKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

it('ger flikadresser med create=1 och ingen rad till schedules/create', function (?string $path) {
    withoutVite();

    [, $anvandare, $container] = plusknappKontext();
    $item = plusknappItem($container);

    $bas = "/containers/{$container->ulid}/items/{$item->ulid}";
    $prefix = $path === null ? '?' : '?path='.urlencode($path).'&';

    actingAs($anvandare)->get($bas.($path === null ? '' : '?path='.urlencode($path)))->assertInertia(
        function (AssertableInertia $page) use ($bas, $prefix) {
            $rader = collect($page->toArray()['props']['create']['rows'])->keyBy('key');

            expect($rader['attachment']['href'])->toBe("{$bas}{$prefix}tab=attachments&create=1")
                ->and($rader['schedule']['href'])->toBe("{$bas}{$prefix}tab=schedules&create=1")
                ->and($rader['cost']['href'])->toBe("{$bas}{$prefix}tab=costs&create=1")
                ->and($rader->pluck('href')->implode(' '))->not->toContain('/schedules/create');
        },
    );
})->with([
    'utan path' => [null],
    'med path' => ['01HZZZZZZZZZZZZZZZZZZZZZZZ'],
]);

it('skickar create=1 vidare som start-open till de tre sektionerna', function () {
    $vy = itemPlusmenyKod('pages/Containers/Items/Show.vue');

    expect($vy)->toContain("get('create') === '1'");

    foreach ([
        'ItemAttachmentSection' => 'attachments',
        'ScheduleListSection' => 'schedules',
        'ItemCostSection' => 'costs',
    ] as $sektion => $flik) {
        $start = strpos($vy, "<{$sektion}\n                v-if=\"activeTab === '{$flik}'\"");
        expect($start)->not->toBeFalse();

        $tagg = substr($vy, (int) $start, (int) strpos($vy, '/>', (int) $start) - (int) $start);
        expect($tagg)->toContain(':start-open="startOpen"');
    }
});

it('låter bilage- och kostnadssektionen fälla ut InlineCreate när startOpen sätts', function (string $fil) {
    $kod = itemPlusmenyKod("components/{$fil}");

    expect($kod)->toContain('startOpen: { type: Boolean, default: false }')
        ->and($kod)->toMatch('/watch\(\s*\(\) => props\.startOpen,.*?createOpen\.value = true;.*?immediate: true/s')
        ->and($kod)->toContain('<InlineCreate');
})->with(['ItemAttachmentSection.vue', 'ItemCostSection.vue']);

it('låter uppgiftssektionen ge snabbraden fokus via exponerad focus()', function () {
    $sektion = itemPlusmenyKod('components/ScheduleListSection.vue');
    $snabb = itemPlusmenyKod('components/ContainerTaskQuickCreate.vue');

    expect($sektion)->toContain('startOpen: { type: Boolean, default: false }')
        ->and($sektion)->toContain('ref="quick"')
        ->and($sektion)->toContain('quick.value?.focus()')
        ->and($sektion)->toMatch('/onMounted\(\(\) => \{\s*if \(props\.startOpen/')
        ->and($sektion)->toMatch('/watch\(\s*\(\) => props\.startOpen/')
        ->and($snabb)->toContain('defineExpose({ focus: () => quick.value?.focus() })');
});

it('lämnar flikarna stängda utan create=1', function () {
    $vy = itemPlusmenyKod('pages/Containers/Items/Show.vue');

    // Proppen är sann bara för exakt `1`, och sektionernas förval är falskt.
    expect($vy)->toContain("get('create') === '1'");

    foreach (['ItemAttachmentSection', 'ScheduleListSection', 'ItemCostSection'] as $sektion) {
        expect(itemPlusmenyKod("components/{$sektion}.vue"))
            ->toContain('startOpen: { type: Boolean, default: false }');
    }

    // Utan create=1 är formuläret stängt från start.
    expect(itemPlusmenyKod('components/ItemAttachmentSection.vue'))->toContain('const createOpen = ref(false)')
        ->and(itemPlusmenyKod('components/ItemCostSection.vue'))->toContain('const createOpen = ref(false)');
});

/*
 * Det som gör att ett val i menyn faktiskt öppnar formuläret: raden är en
 * Inertia-`<Link>` utan `preserve-state`. Ett sådant besök bevarar inte
 * tillståndet, så adaptern ger sidan en ny nyckel (provet nedan) och
 * sektionernas montering sköter öppningen. Får raden `preserve-state` blir
 * sidan kvar i stället, och då är det bevakningen som öppnar — kommentarerna
 * i sektionerna och `Show.vue` måste i så fall skrivas om.
 */
it('låter menyraderna vara Link utan preserve-state', function (string $fil) {
    $kod = itemPlusmenyKod("components/{$fil}");

    expect($kod)->toContain('<Link')
        ->and($kod)->toContain(':href=')
        ->and($kod)->not->toContain('preserve-state')
        ->and($kod)->not->toContain('preserveState');
})->with(['CreateMenu.vue', 'ItemQuickActions.vue']);

/*
 * Öppningen hör till monteringen: `immediate` i bilage- och kostnadssektionen
 * och `onMounted` i uppgiftssektionen. Uppgiftssektionens bevakning saknar
 * `immediate` med flit — den gäller det besök som BEVARAR tillståndet och får
 * inte ersätta monteringsvägen.
 */
it('öppnar formuläret vid monteringen och låter bevakningen gälla ett besök som bevarar tillståndet', function () {
    foreach (['ItemAttachmentSection.vue', 'ItemCostSection.vue'] as $fil) {
        expect(itemPlusmenyKod("components/{$fil}"))
            ->toMatch('/watch\(\s*\(\) => props\.startOpen,.*?immediate: true/s');
    }

    expect(itemPlusmenyKod('components/ScheduleListSection.vue'))
        ->toMatch('/onMounted\(\(\) => \{\s*if \(props\.startOpen && props\.can\.create\) \{\s*focusQuick\(\);/s');
});

/*
 * Antagandet som kommentarerna vilar på, läst ur adaptern själv: nyckeln byts
 * när `preserveState` är falskt, alltså monteras sidan om vid ett vanligt
 * GET-besök. Filen kommer med `npm ci` i CI (samma förutsättning som
 * TypsnittTest ställer på `public/build`), och dist-filen är paketerad men
 * läsbar. Skulle en Inertia-uppgradering ändra raden ska det här provet följa
 * med i uppgraderingen — det är det som gör antagandet till ett löfte.
 */
it('byter sidkomponentens nyckel när preserveState är falskt', function () {
    $dist = base_path('node_modules/@inertiajs/vue3/dist/index.js');

    expect(file_exists($dist))->toBeTrue(
        'node_modules/@inertiajs/vue3 finns inte — kör "npm ci" innan testsviten.',
    );

    expect(File::get($dist))
        ->toMatch('/key\.value = options\.preserveState \? key\.value : Date\.now\(\);/');
});
