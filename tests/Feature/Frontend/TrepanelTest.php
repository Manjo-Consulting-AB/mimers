<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 103 · Trepanelslayouten, och issue 633 · förekomstpanelen. Se
 * resources/js/pages/Containers/Items/Show.vue,
 * resources/js/components/ItemStructurePanel.vue,
 * resources/js/components/ItemStructureTree.vue,
 * resources/js/components/ItemMapPanel.vue,
 * resources/js/components/ItemPlacementsPanel.vue,
 * [[ADR-0042 Designsystemet]] § Beslut och § Konsekvenser,
 * [[M17 Designsystemet]] § 103 och
 * `docs/Design/struktur - item.jpeg` (panelen under kartan).
 *
 * **Filen prövar en layout och två upplösningar som redan finns.**
 * Strukturen är issue 94:s träd och förekomsterna issue 95:s vägar; ingenting
 * av det byggs på nytt här, och proven nedan faller om en panel börjar ställa
 * en egen fråga — det är [[ADR-0041 Itemets vy]] § Beslut, som byggde en
 * gemensam rotregel just för att två formuleringar av samma graf glider isär.
 *
 * **Issue 633 flyttade förekomsterna till en egen panel.** De var en lista
 * mitt i mittenkolumnen, ovanför flikraden, och tryckte ned flikarna för ett
 * item som finns på flera platser; nu är de ItemPlacementsPanel.vue i
 * högerkolumnen under kartan över `md:` och samma panel i mittenkolumnen under
 * — samma yta två gånger, som kartan. Villkoret (mer än en förekomst) och
 * rubrikens id bor i panelen, inte i vyn.
 *
 * **Kartans panel är tom med flit**, och provet är därför negativt: det som
 * prövas är att ingenting står där som säger att något saknas. En tom panel
 * som väntar är inte samma sak som en trasig yta.
 *
 * **Det som INTE prövas här** är det som kräver en webbläsare: att panelerna
 * står sida vid sida över `md:`, att `<details>` fäller ihop trädet på en
 * telefon, och att träffytorna känns rätt i en hand. Det som går att läsa är
 * formen på källkoden och svaret från servern.
 *
 * Hjälparna har prefixet `trepanel` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem, och en container med ett item under kontot.
 *
 * @return array{0: Container, 1: User}
 */
function trepanelKonto(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'member']);

    return [Container::factory()->for($konto, 'account')->create(), $anvandare];
}

function trepanelItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create(['name' => $namn]);
}

/**
 * En `parent`-kant skriven direkt i tabellen, förbi App\Actions\Item\LinkItems
 * — samma linje och samma skäl som StrukturuplosningTest: Actionen är garanten
 * mot cykler, och garanten ska inte kunna maskera ett fel i vandringen.
 */
function trepanelKant(Item $förälder, Item $barn): void
{
    ItemLink::query()->insert([
        'from_item_id' => $förälder->id,
        'to_item_id' => $barn->id,
        'relation' => 'parent',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Itemets egen URL, med en väg i querysträngen när en sådan anges — samma form
 * som ForekomstvagTest bygger (issue 95).
 */
function trepanelUrl(Container $container, Item $item, ?string $väg = null): string
{
    $url = "/containers/{$container->ulid}/items/{$item->ulid}";

    return $väg === null ? $url : $url.'?path='.$väg;
}

/**
 * En väg som strängen den står i querysträngen: ledens ULID:ar, punkt mellan.
 *
 * @param  list<array{ulid: string, name: string}>  $led
 */
function trepanelSträng(array $led): string
{
    return implode('.', array_column($led, 'ulid'));
}

/**
 * Källkoden med kommentarer borta. Samma tre slag som GenomgangTest och
 * SprakTest rensar: blockkommentarer, HTML-kommentarer och radkommentarer —
 * docblocken är svenska med flit, och en regel som letar efter en markup ska
 * inte kunna nöjas av en mening i en docblock.
 */
function trepanelKod(string $sökväg): string
{
    $kod = File::get(resource_path("js/{$sökväg}"));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Proppnamnen i en panels `defineProps`, i källordning.
 *
 * Läses ur källkoden och inte ur en rendering: det som prövas är att panelen
 * inte fick en NY propp, och en propp som ingen skickar syns inte i svaret.
 *
 * @return list<string>
 */
function trepanelProps(string $sökväg): array
{
    $kod = trepanelKod($sökväg);

    preg_match('/defineProps\(\{([\s\S]*?)\n\}\)/', $kod, $block);

    expect($block)->not->toBeEmpty("{$sökväg} deklarerar inga proppar");

    preg_match_all('/^\s*([A-Za-z_$][\w$]*)\s*:\s*\{/m', $block[1], $namn);

    return $namn[1];
}

/**
 * Antalet frågor itemets sida ställer, mätt efter ett omätt anrop.
 *
 * Omfånget kommer ur ResolveItemScope, som är `scoped` och memoiserar per
 * `{user, container}`: utan ett värmande anrop först hade mätningen burit
 * ÅTKOMSTENS frågor i stället för sidans egna. Samma mönster som trädFrågor i
 * StrukturuplosningTest.
 */
function trepanelFragor(Container $container, Item $item, User $anvandare): int
{
    app()->forgetScopedInstances();

    actingAs($anvandare)->get(trepanelUrl($container, $item))->assertOk();

    $antal = 0;

    DB::listen(function ($query) use (&$antal): void {
        if (! str_contains($query->sql, 'last_active_at')) {
            $antal++;
        }
    });

    $antal = 0;
    actingAs($anvandare)->get(trepanelUrl($container, $item))->assertOk();

    return $antal;
}

/*
 * Klart när: de tre panelerna renderas inuti containerns ram.
 *
 * Strukturen till vänster, itemet i mitten, kartans plats till höger — och
 * alla tre innanför `ContainerLayout`, för itemet bor i containern
 * ([[ADR-0041 Itemets vy]] § Beslut). Provet läser ordningen i källkoden: en
 * panel som hamnat i en egen layout, eller utanför ramen, är den globala
 * navigering som är avvisad två gånger.
 */
it('renderar de tre panelerna inuti containerns ram', function () {
    withoutVite();

    [$container, $anvandare] = trepanelKonto();
    $motorn = trepanelItem($container, 'Motorn');

    $vy = trepanelKod('pages/Containers/Items/Show.vue');

    $start = strpos($vy, '<ContainerLayout');
    $slut = strpos($vy, '</ContainerLayout>');

    expect($start)->not->toBeFalse('vyn ritar ingen ContainerLayout');
    expect($slut)->not->toBeFalse('ContainerLayout stängs aldrig');

    $ram = substr($vy, (int) $start, (int) $slut - (int) $start);

    $strukturen = strpos($ram, '<ItemStructurePanel');
    $itemet = strpos($ram, '<UiTabs');
    $kartan = strpos($ram, '<ItemMapPanel');

    expect($strukturen)->not->toBeFalse('vänsterpanelen saknas i ramen');
    expect($itemet)->not->toBeFalse('mittenpanelen saknas i ramen');
    expect($kartan)->not->toBeFalse('högerpanelen saknas i ramen');

    // I ritad ordning: strukturen, itemet, kartan.
    expect((int) $strukturen)->toBeLessThan((int) $itemet);
    expect((int) $itemet)->toBeLessThan((int) $kartan);

    // Rutan runt dem: en kolumn på en telefon, fyra över `md:` — mittkolumnen
    // är tre av dem, och strukturen en.
    expect($ram)->toContain('grid grid-cols-1 gap-6 md:grid-cols-4')
        ->toContain('md:col-span-3');

    // Skalet deklareras inte om här: containerns ram bär det. Och
    // navigeringen i vyn är fortfarande brödsmulan och ingenting annat.
    expect($vy)->not->toContain('AppLayout');
    expect(preg_match_all('/<nav\b/', $vy))->toBe(1);

    // Strukturen kommer med som prop, ur samma svar som resten av sidan.
    actingAs($anvandare)->get(trepanelUrl($container, $motorn))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Show')
            ->has('structure', 1)
            ->where('structure.0.ulid', $motorn->ulid)
            ->where('structure.0.name', 'Motorn')
    );
});

/*
 * Klart när: strukturpanelen visar trädet ur issue 94 — och frågeräkningen är
 * oförändrad.
 *
 * Trädet är App\Actions\Item\ResolveItemTree, uppenbarat oförändrat: samma
 * nodform (`{ulid, name, children}`), namnet stigande på varje nivå, och ett
 * item med två föräldrar på båda ställena. Provet jämför mot svaret och inte
 * mot en avskrift av vyn.
 *
 * **Frågeräkningen** mäts på SAMMA container före och efter att trädet växer
 * från ett item till tretti — samma sida, samma frågor, en större skog. Det
 * är så "inga nya frågor ställs" går att bevisa utan en webbläsare: panelen
 * ritar en propp och ställer ingenting själv, och upplösningens två frågor
 * (itemen, kanterna) är desamma oavsett hur många noder de bär. Källkodsprovet
 * nedanför stänger den andra halvan: komponenterna ber inte servern om något.
 */
it('visar trädet ur issue 94 utan att ställa en egen fråga', function () {
    withoutVite();

    [$container, $anvandare] = trepanelKonto();

    $båten = trepanelItem($container, 'Båten');
    $motorn = trepanelItem($container, 'Motorn');
    $masten = trepanelItem($container, 'Masten');
    $impellern = trepanelItem($container, 'Impellern');

    trepanelKant($båten, $motorn);
    trepanelKant($båten, $masten);
    trepanelKant($motorn, $impellern);

    // Motorn är den sida som visas, och trädet är containerhelheten — inte
    // bara itemets egen gren.
    actingAs($anvandare)->get(trepanelUrl($container, $motorn))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('structure', [
            [
                'ulid' => $båten->ulid,
                'name' => 'Båten',
                'children' => [
                    ['ulid' => $masten->ulid, 'name' => 'Masten', 'children' => []],
                    [
                        'ulid' => $motorn->ulid,
                        'name' => 'Motorn',
                        'children' => [
                            ['ulid' => $impellern->ulid, 'name' => 'Impellern', 'children' => []],
                        ],
                    ],
                ],
            ],
        ])
    );

    // Ett item med två föräldrar förekommer på båda ställena — som två noder
    // och inte som en sammanslagen rad.
    trepanelKant($masten, $impellern);

    $svar = actingAs($anvandare)->get(trepanelUrl($container, $motorn))->assertOk();

    $namn = [];

    foreach ($svar->viewData('page')['props']['structure'] as $rot) {
        foreach ($rot['children'] as $gren) {
            foreach ($gren['children'] as $löv) {
                $namn[] = $löv['name'];
            }
        }
    }

    expect($namn)->toBe(['Impellern', 'Impellern']);

    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80, 477).
    Carbon::setTestNow(now());

    // Sedan växer skogen: tretti items under båten, och samma sida igen.
    $medEtt = trepanelFragor($container, $motorn, $anvandare);

    foreach (range(1, 30) as $i) {
        $barn = trepanelItem($container, sprintf('Del %02d', $i));
        trepanelKant($båten, $barn);
    }

    expect(trepanelFragor($container, $motorn, $anvandare))->toBe(
        $medEtt,
        'trädet kostar fler frågor när det växer — panelen ställer en egen',
    );

    // Och den andra halvan: komponenterna ber aldrig servern om något. Ingen
    // av dem läser adressen, hämtar något eller navigerar — allt de ritar
    // kommer i propparna, och markeringen ur `paths` (issue 95).
    $panelen = trepanelKod('components/ItemStructurePanel.vue');
    $trädet = trepanelKod('components/ItemStructureTree.vue');

    foreach ([$panelen, $trädet] as $kod) {
        foreach (['usePage', 'fetch(', 'axios', 'router.'] as $hämtning) {
            expect($kod)->not->toContain($hämtning);
        }
    }

    // Panelen ritar proppen den får, och vyn ger den strukturen — inte en
    // lista den byggt själv, och inte en fråga per nod.
    expect($panelen)->toContain(':nodes="nodes"')
        ->toContain('<ItemStructureTree');

    expect(trepanelKod('pages/Containers/Items/Show.vue'))
        ->toContain(':nodes="structure"')
        ->toContain(':active-trail="activeTrail"');

    // **Fällningen är trädLÄGETS och inte panelens** (issue 154). Panelen
    // ritar hela trädet öppet — den är vänsterpanelen i trepanelslayouten,
    // och en hopfälld gren där hade gömt strukturen på den breda skärmen —
    // och väljaren i issue 153 ritar det av samma skäl öppet. Flaggan är
    // därför opt-in med standarden `false`, och de två ytorna lämnar den
    // orörd: hade de ärvt trädlägets fällning hade itemets vänsterpanel tyst
    // blivit en annan yta.
    expect($panelen)->not->toContain('collapsible');

    expect(trepanelKod('components/ParentPicker.vue'))->not->toContain('collapsible');

    expect($trädet)->toContain('collapsible: { type: Boolean, default: false }');

    Carbon::setTestNow();
});

/*
 * Klart när: den aktuella förekomsten markeras ur querysträngen — och en väg
 * som inte längre finns ger den första i ordningen, aldrig ett fel.
 *
 * `?path=` är issue 95:s, och servern har redan avgjort saken när vyn får
 * svaret: en väg som inte längre finns är utbytt mot den första i ordningen,
 * utan rad och utan felkod. Det provet ägs av
 * tests/Feature/Item/ForekomstvagTest.php, och det som prövas HÄR är att
 * panelen följer samma svar: markeringen kommer ur `paths` och aldrig ur en
 * egen läsning av adressen, så panelen och förekomstlistan inte kan markera
 * olika förekomster.
 */
it('markerar den aktuella förekomsten ur querysträngen', function () {
    withoutVite();

    [$container, $anvandare] = trepanelKonto();

    $båten = trepanelItem($container, 'Båten');
    $motorn = trepanelItem($container, 'Motorn');
    $masten = trepanelItem($container, 'Masten');
    $impellern = trepanelItem($container, 'Impellern');

    trepanelKant($båten, $motorn);
    trepanelKant($motorn, $impellern);
    trepanelKant($masten, $impellern);

    // Utan querysträng: den första i ordningen är den aktuella.
    $svar = actingAs($anvandare)->get(trepanelUrl($container, $impellern))->assertOk();

    $vägar = $svar->viewData('page')['props']['paths'];

    expect($vägar)->toHaveCount(2);
    expect($vägar[0]['current'])->toBeTrue();
    expect($vägar[1]['current'])->toBeFalse();

    // Och med den ANDRA vägen i querysträngen flyttar markeringen med — den
    // är hela ledet från roten, punkt mellan leden.
    actingAs($anvandare)
        ->get(trepanelUrl($container, $impellern, trepanelSträng($vägar[1]['nodes'])))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('paths.0.current', false)
            ->where('paths.1.current', true)
        );

    // En väg som inte längre finns: masten hänger inte längre under impellern.
    // Svaret är sidan med den första förekomsten — 200, ingen rad om varför,
    // och ingen markering av att något fallit bort.
    $gammal = trepanelSträng($vägar[1]['nodes']);

    ItemLink::query()
        ->where('from_item_id', $masten->id)
        ->where('to_item_id', $impellern->id)
        ->delete();

    actingAs($anvandare)
        ->get(trepanelUrl($container, $impellern, $gammal))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('paths', 1)
            ->where('paths.0.current', true)
        );

    // Formen: vyn räknar ut ledet en gång ur `paths` och skickar det vidare,
    // och trädet läser det — inte adressen. En andra jämförelse i komponenten
    // hade varit en andra regel om samma sak.
    expect(trepanelKod('pages/Containers/Items/Show.vue'))
        ->toContain('props.paths.find((path) => path.current)')
        ->toContain(':active-trail="activeTrail"');

    expect(trepanelKod('components/ItemStructureTree.vue'))
        ->toContain('activeTrail')
        ->toContain(':aria-current=')
        ->toContain("'true'");

    expect(trepanelKod('components/ItemStructurePanel.vue'))
        ->toContain(':active-trail="activeTrail"');
});

/*
 * Klart när: "ritar förekomsterna i en egen panel under kartan".
 *
 * Issue 633 · `docs/Design/struktur - item.jpeg` ritar *Förekomster i
 * struktur* som en panel under kartan i högerkolumnen. Fram till issue 633
 * låg vägarna som en lista mitt i mittenkolumnen, ovanför flikraden, och
 * tryckte ned flikarna för ett item som finns på flera platser — en yta ingen
 * mockup bad om.
 *
 * Panelen står EFTER kartan och inuti ramen: högerkolumnen är kartans plats,
 * och förekomsterna hör till samma kolumn. Att den ligger i kartans eget
 * omslutande element är vad som gör dem till en kolumn och inte två ytor som
 * råkade hamna under varandra i källan.
 */
it('ritar förekomsterna i en egen panel under kartan', function () {
    withoutVite();

    [$container, $anvandare] = trepanelKonto();

    $båten = trepanelItem($container, 'Båten');
    $masten = trepanelItem($container, 'Masten');
    $impellern = trepanelItem($container, 'Impellern');

    trepanelKant($båten, $impellern);
    trepanelKant($masten, $impellern);

    $vy = trepanelKod('pages/Containers/Items/Show.vue');

    // Två instanser: högerkolumnen över `md:` och mittenkolumnen under.
    expect(substr_count($vy, '<ItemPlacementsPanel'))->toBe(2, 'förekomstpanelen ritas inte två gånger');

    $kartan = strpos($vy, '<ItemMapPanel');
    $panelen = strrpos($vy, '<ItemPlacementsPanel');
    $ramen = strpos($vy, '</ContainerLayout>');

    expect($kartan)->not->toBeFalse('kartans panel saknas')
        ->and($panelen)->not->toBeFalse('förekomstpanelen saknas')
        ->and($ramen)->not->toBeFalse('ContainerLayout stängs aldrig');

    // Efter kartan och före ramens slut.
    expect((int) $kartan)->toBeLessThan((int) $panelen)
        ->and((int) $panelen)->toBeLessThan((int) $ramen);

    // ...och inuti kartans EGET omslutande element — samma kolumn.
    preg_match('#<div class="[^"]*md:col-start-2[^"]*">(.*?)</div>#s', $vy, $träff);

    expect($träff[1] ?? '')->toContain('<ItemMapPanel')
        ->toContain('<ItemPlacementsPanel')
        ->toContain(':paths="paths"')
        ->toContain(':path-href="pathHref"');

    // Panelen ritar itemets vägar ur samma svar som brödsmulan: ett item med
    // två föräldrar har två vägar, och båda kommer med.
    $vägar = actingAs($anvandare)
        ->get(trepanelUrl($container, $impellern))
        ->assertOk()
        ->viewData('page')['props']['paths'];

    expect($vägar)->toHaveCount(2);
});

/*
 * Klart när: "ritar förekomstpanelen i mittenkolumnen under md:".
 *
 * Under `md:` är högerkolumnen dold, och panelen ritas därför en gång till i
 * mittenkolumnen där den gamla listan stod — samma mönster och samma skäl som
 * kartan, som står i högerpanelen över `md:` och i relationsfliken under
 * (FokuskartaTest). Kopian i mitten bär `md:hidden`; högerkolumnens omslag är
 * redan `hidden md:block` och behöver ingen egen brytpunkt.
 *
 * **Villkoret och rubrikens id bor i panelen.** `paths.length > 1` och
 * `id="item-placements-heading"` fanns i vyn fram till issue 633; nu ritar vyn
 * samma panel två gånger och ska inte upprepa samma regel på två ställen.
 */
it('ritar förekomstpanelen i mittenkolumnen under md:', function () {
    $vy = trepanelKod('pages/Containers/Items/Show.vue');

    expect(substr_count($vy, '<ItemPlacementsPanel'))->toBe(2);

    $mitten = strpos($vy, '<ItemPlacementsPanel');
    $flikraden = strpos($vy, '<UiTabs');

    expect($mitten)->not->toBeFalse('förekomstpanelen saknas i mittenkolumnen')
        ->and($flikraden)->not->toBeFalse('flikraden saknas');

    // Kopian i mitten står där den gamla listan stod: före flikraden.
    expect((int) $mitten)->toBeLessThan((int) $flikraden);

    // ...och det är den som bär brytpunkten.
    preg_match('/<ItemPlacementsPanel\b[^>]*>/s', $vy, $först);

    expect($först[0] ?? '')->toContain('md:hidden');

    // Regeln och id:t flyttade in i panelen. Två `expect` och inte en kedja:
    // ett andra `not` mitt i en kedja fäller phpstan (samma fälla som
    // GenomgangTest beskriver).
    expect($vy)->not->toContain('paths.length > 1');
    expect($vy)->not->toContain('item-placements-heading');
});

/*
 * Klart när: "märker den aktuella förekomsten med synlig text och länkar de
 * andra".
 *
 * Raden är mockupens: vägen som en brödsmula, och radens åtgärd till höger.
 * Den aktuella raden bär ordet *Current* som SYNLIG text bredvid
 * `aria-current` — markeringen får aldrig vara en färg allena (issue 68b
 * § Beslut 7) — och de andra raderna en länk *Go to* mot samma adress som
 * brödsmulan bygger, `pathHref(occurrence.nodes)`.
 *
 * Panelen ställer ingen egen fråga och räknar ingenting själv: vägarna kommer
 * i `paths`, adressen i `pathHref`, och båda är vyns. Propplistan prövas för
 * att en panel som plötsligt frågade efter något eget — en egen läsning av
 * adressen, en egen väg — hade varit den andra regeln om samma sak.
 */
it('märker den aktuella förekomsten med synlig text och länkar de andra', function () {
    $panelen = trepanelKod('components/ItemPlacementsPanel.vue');

    expect($panelen)->toContain('aria-current')
        ->toContain("t('item.show.placement_current')")
        ->toContain("t('item.show.placement_go')")
        ->toContain('UiBadge')
        // Den aktuella raden bär ordet, de andra länken.
        ->toContain('v-if="occurrence.current"')
        ->toContain('v-else')
        ->toContain('pathHref(occurrence.nodes)')
        ->toContain('aria-labelledby="item-placements-heading"');

    // Ingen egen fråga och ingen egen navigering: allt kommer i propparna.
    foreach (['usePage', 'fetch(', 'axios', 'router.'] as $hämtning) {
        expect($panelen)->not->toContain($hämtning);
    }

    // Propparna, i källordning: vägarna och vyns adressfunktion.
    expect(trepanelProps('components/ItemPlacementsPanel.vue'))->toBe(['paths', 'pathHref']);

    // ...och ordet finns i katalogen, som alla andra ord på ytan.
    expect(Lang::has('ui.item.show.placement_go'))->toBeTrue();
    expect(Lang::get('ui.item.show.placement_go', [], 'en'))->toBe('Go to');

    // Vyn skickar båda vidare, på båda ställena.
    expect(trepanelKod('pages/Containers/Items/Show.vue'))
        ->toContain(':paths="paths"')
        ->toContain(':path-href="pathHref"');
});

/*
 * Klart när: kartans panel visar fokuskartan — och den är ett LÄGE i
 * relationsfliken under `md:` (issue 156 · [[M23 Mobilen och kartan]] § 156).
 *
 * **Provet var negativt med flit från issue 103 till issue 156.** Panelen var
 * en plats som väntade — fokuskartan behövde en layout, och den är eget arbete
 * som inte var beslutat — och det som föll då var varje ord som sade att något
 * var fel eller på väg. Nu är kartan byggd, och det som prövas är i stället att
 * panelen ritar den ur `map`-proppen och fortfarande inte ställer en egen
 * fråga: noderna kommer ur samma svar som resten av sidan.
 *
 * Att panelen är dold UNDER `md:` är samma beslut: där är kartan ett läge i
 * itemets relationsflik, och samma karta två gånger på samma skärm hade varit
 * samma nod två gånger. Formen prövas i tests/Feature/Frontend/FokuskartaTest.
 */
it('fyller kartans panel med fokuskartan ur samma svar som resten av sidan', function () {
    withoutVite();

    [$container, $anvandare] = trepanelKonto();
    $motorn = trepanelItem($container, 'Motorn');
    $impellern = trepanelItem($container, 'Impellern');

    trepanelKant($motorn, $impellern);

    $panelen = trepanelKod('components/ItemMapPanel.vue');

    expect($panelen)->toContain("t('item.map.heading')")
        ->toContain("import FocusMap from './FocusMap.vue'")
        ->toContain('<FocusMap')
        ->toContain(':map="map"')
        ->toContain(':overflow-href="overflowHref"');

    // Panelen ställer ingen egen fråga: den läser inte adressen, hämtar
    // ingenting och navigerar ingenstans. Allt den ritar kommer i propparna.
    foreach (['usePage', 'fetch(', 'axios', 'router.'] as $hämtning) {
        expect($panelen)->not->toContain($hämtning);
    }

    // Och vyn skickar kartan vidare — panelen bygger ingen själv.
    expect(trepanelKod('pages/Containers/Items/Show.vue'))
        ->toContain('<ItemMapPanel')
        ->toContain(':map="map"')
        ->toContain(':overflow-href="relationsHref"');

    expect(Lang::get('ui.item.map.heading', [], 'en'))->toBe('Map');

    // Noderna kommer ur samma svar som relationerna, och kostar ingen egen
    // fråga: de ÄR App\Actions\Item\ListItemLinks.
    actingAs($anvandare)->get(trepanelUrl($container, $motorn))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Show')
            ->where('map.self.name', 'Motorn')
            ->where('map.child.0.name', 'Impellern')
    );
});

/*
 * Klart när: panelerna staplas på smal skärm — `md:` är enda brytpunkten, och
 * strukturen blir en utfällbar yta och inte en egen sida.
 *
 * `md:` är enda brytpunkten uppåt (issue 68a § Beslut 2), och GenomgangTest
 * vaktar den regeln över hela resources/js; här prövas vad staplingen BETYDER
 * för strukturen. På en telefon ligger panelerna under varandra, och trädet
 * fälls ihop med en `<details>`-yta — samma val och samma skäl som
 * AccessLevelField: en `<summary>` är tabbbar av sig själv, och en yta man
 * fäller är inte en sida man lämnar.
 *
 * "Inte en egen sida" prövas på adresserna: panelen navigerar bara till items
 * i containern, och ingen av dem lämnar containerns ram.
 */
it('staplar panelerna på smal skärm med strukturen utfällbar', function () {
    [$container, $anvandare] = trepanelKonto();

    $panelen = trepanelKod('components/ItemStructurePanel.vue');

    // Staplad i källan och sida vid sida över `md:` — en kolumn först, fyra
    // sedan, och mittkolumnen tre av dem.
    $vy = trepanelKod('pages/Containers/Items/Show.vue');

    expect($vy)->toContain('grid-cols-1')
        ->toContain('md:grid-cols-4')
        ->toContain('md:col-span-3');

    // Utfällbar: `<details>` med en `<summary>`, och öppen i källan så att den
    // breda skärmen visar trädet som bilden gör.
    expect($panelen)->toContain('<details open>')
        ->toContain('<summary')
        ->toContain('<ItemStructureTree');

    // Och taket: en öppen `<details>` utan höjdtak är i praktiken den egna sida
    // staplingen inte får vara — hela containerns träd står då mellan användaren
    // och itemet vid varje sidladdning. Trädet skrollar i höjdled under `md:`
    // och är fritt däröver; `md:` är fortfarande enda brytpunkten.
    expect($panelen)->toContain('max-h-80')
        ->toContain('overflow-y-auto')
        ->toContain('md:max-h-none')
        ->toContain('md:overflow-visible');

    // Trädet står INUTI den utfällbara ytan — en `<details>` runt en rubrik
    // hade varit en fällning utan innehåll.
    expect(strpos($panelen, '<ItemStructureTree'))->toBeGreaterThan((int) strpos($panelen, '<summary'));

    // Strukturen är en yta i containern och inte en egen sida: varje adress
    // panelen bygger går till ett item i containern.
    $trädet = trepanelKod('components/ItemStructureTree.vue');

    preg_match_all('/[`\'"](\/[^`\'"]*)[`\'"]/', $trädet, $träffar);

    expect($träffar[1])->not->toBeEmpty();

    foreach ($träffar[1] as $adress) {
        expect($adress)->toStartWith('/containers/', "trädet pekar utanför containern: {$adress}");
    }

    expect($trädet)->not->toContain('/structure');

    // Och panelen själv navigerar ingenstans: det enda klickbara i den är
    // trädets noder.
    expect($panelen)->not->toContain('<Link');

    actingAs($anvandare)->get(trepanelUrl($container, trepanelItem($container, 'Motorn')))->assertOk();
});

/*
 * Klart när: över `lg:` står tre kolumner med fasta gränser för sidopanelerna.
 *
 * Skalet från issue 169 släppte innehållsytan från `max-w-3xl`, och här får
 * trepanelen sin fulla bredd (issue 181 · `docs/Design/struktur - item.jpeg`).
 * Formen är tre spår: strukturen, itemet, kartan — och sidopanelerna har ett
 * GOLV och ett TAK i `rem` i stället för en andel. Det är hela poängen: en
 * andel hade krympt strukturträdet under läsbarhet på en smalare skärm och
 * låtit itemet svälla på en bredare. Mitten är `1fr` och tar resten.
 *
 * Provet läser rutnätet ur källkoden — det som kräver en webbläsare är att
 * spåren faktiskt hamnar sida vid sida, och det står i filhuvudet.
 */
it('över lg står tre kolumner med fasta gränser för sidopanelerna', function () {
    $vy = trepanelKod('pages/Containers/Items/Show.vue');

    expect($vy)->toContain(
        'lg:grid-cols-[minmax(16rem,20rem)_minmax(0,1fr)_minmax(18rem,24rem)]',
    );

    preg_match('/lg:grid-cols-\[([^\]]+)\]/', $vy, $träff);

    expect($träff)->not->toBeEmpty('vyn sätter ingen lg:grid-cols-');

    $spår = explode('_', $träff[1]);

    expect($spår)->toHaveCount(3, 'rutnätet över lg: har inte tre spår');

    // Fasta gränser på båda sidorna, resten i mitten.
    expect($spår[0])->toStartWith('minmax(')->toEndWith('rem)')
        ->and($spår[2])->toStartWith('minmax(')->toEndWith('rem)')
        ->and($spår[1])->toContain('1fr');
});

/*
 * Klart när: mellan `md:` och `lg:` står kartan under itemet.
 *
 * Under `lg:` ryms inte tre kolumner, och kartan faller då ned i itemets
 * kolumn i stället för att bli en tredje spalt. Rutnätet är fyra spår — det är
 * `md:`-formen från issue 103, och endast mittkolumnens bredd ändras — och
 * kartan tar samma tre spår som mittkolumnen. Att den börjar i spår 2 är det
 * som ger en EGEN rad: spår 1 är strukturens, och itemet fyller resten så att
 * ingen rad står tom.
 *
 * Ordningen i markupen är oförändrad ([[ADR-0041 Itemets vy]] § Beslut):
 * placeringen görs med klasserna, aldrig genom att flytta elementen.
 */
it('mellan md och lg står kartan under itemet', function () {
    $vy = trepanelKod('pages/Containers/Items/Show.vue');

    // Mittkolumnen är tre av fyra spår över `md:` — itemets kolumn.
    expect($vy)->toContain('min-w-0 md:col-span-3');

    // Kartans omslutande element, alltså rutnätets barn — spåren sätts där
    // och inte på panelen, vars proppar och innehåll är orörda (Beslut 3).
    preg_match('#<div class="([^"]*)">\s*<ItemMapPanel\b#', $vy, $träff);

    expect($träff)->not->toBeEmpty('kartan har inget omslutande rutnätselement');

    $kartan = $träff[1];

    // Samma startspår och samma bredd som itemet, alltså raden under det.
    expect($kartan)->toContain('md:col-start-2')
        ->toContain('md:col-span-3');

    // Och över `lg:` tar kartan det tredje spåret i stället — den har inte
    // kvar mittkolumnens tre.
    expect($kartan)->toContain('lg:col-start-3')
        ->toContain('lg:col-span-1');
});

/*
 * Klart när: panelernas ordning i markupen är struktur, item, karta.
 *
 * Beslut 2 i issue 181: ordningen ändras inte, och tangentbordsordningen
 * följer markupen. Placeringen görs uteslutande med grid-klasser, så en
 * framtida bredd kan läggas till utan att någon panel flyttar sig i källan —
 * och därmed utan att tabbordningen tyst blir en annan.
 */
it('panelernas ordning i markupen är struktur, item, karta', function () {
    $vy = trepanelKod('pages/Containers/Items/Show.vue');

    $strukturen = strpos($vy, '<ItemStructurePanel');
    $itemet = strpos($vy, '<UiTabs');
    $kartan = strpos($vy, '<ItemMapPanel');

    expect($strukturen)->not->toBeFalse('vänsterpanelen saknas')
        ->and($itemet)->not->toBeFalse('mittenpanelen saknas')
        ->and($kartan)->not->toBeFalse('högerpanelen saknas');

    expect((int) $strukturen)->toBeLessThan((int) $itemet)
        ->and((int) $itemet)->toBeLessThan((int) $kartan);

    // Alla tre ligger i SAMMA rutnät: ordningen är rutnätets och inte tre
    // ytor som råkar stå under varandra i källan.
    expect((int) strpos($vy, 'grid-cols-1'))->toBeLessThan((int) $strukturen)
        ->and((int) $kartan)->toBeLessThan((int) strpos($vy, '</ContainerLayout>'));
});

/*
 * Klart när: `ItemStructurePanel` och `ItemMapPanel` har samma props som förut.
 *
 * Beslut 3 i issue 181: ingen panel ändrar innehåll. Den nya bredden är en
 * layoutfråga, och en panel som plötsligt frågade efter något — en höjd, en
 * brytpunkt, ett läge — hade flyttat layouten in i panelen och gjort trepanelen
 * till tre ytor som var för sig vet vilken skärm de står på. Panelen ritar
 * proppen den får; `Show.vue` äger placeringen.
 *
 * Provet läser deklarationen och inte en rendering: en propp som ingen skickar
 * syns inte i svaret, och det är just den som vore felet.
 */
it('ItemStructurePanel och ItemMapPanel har samma props som förut', function () {
    expect(trepanelProps('components/ItemStructurePanel.vue'))
        ->toBe(['nodes', 'containerUlid', 'activeTrail']);

    expect(trepanelProps('components/ItemMapPanel.vue'))
        ->toBe(['map', 'overflowHref']);
});
