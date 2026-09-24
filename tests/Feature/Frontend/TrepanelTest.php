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
 * Issue 103 · Trepanelslayouten. Se
 * resources/js/pages/Containers/Items/Show.vue,
 * resources/js/components/ItemStructurePanel.vue,
 * resources/js/components/ItemStructureTree.vue,
 * resources/js/components/ItemMapPanel.vue,
 * [[ADR-0042 Designsystemet]] § Beslut och § Konsekvenser och
 * [[M17 Designsystemet]] § 103.
 *
 * **Filen prövar en layout och två upplösningar som redan finns.**
 * Strukturen är issue 94:s träd och förekomsterna issue 95:s vägar; ingenting
 * av det byggs på nytt här, och proven nedan faller om en panel börjar ställa
 * en egen fråga — det är [[ADR-0041 Itemets vy]] § Beslut, som byggde en
 * gemensam rotregel just för att två formuleringar av samma graf glider isär.
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
    // är två av dem, och sidopanelerna en var.
    expect($ram)->toContain('grid grid-cols-1 gap-6 md:grid-cols-4')
        ->toContain('md:col-span-2');

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
 * Klart när: kartans panel är tom och annonserar sig inte som trasig.
 *
 * Fokuskartan är inte beslutad, och panelen är därför en plats som väntar —
 * inte en yta som saknas. Provet är negativt med flit: det som fälls är varje
 * ord som säger att något är fel eller på väg. Ingen tom-tillståndsruta
 * (`UiEmptyState` skiljer *inget alls* från *inget som matchar*, och båda vore
 * ett påstående om innehållet), ingen länk, ingen knapp, ingen ikon och ingen
 * räknare. Rubriken är panelens enda ord, och den namnger platsen.
 */
it('lämnar kartans panel tom utan att annonsera att något är trasigt', function () {
    withoutVite();

    [$container, $anvandare] = trepanelKonto();
    $motorn = trepanelItem($container, 'Motorn');

    $kartan = trepanelKod('components/ItemMapPanel.vue');

    foreach (['<UiEmptyState', '<Link', '<button', 'role=', 'alert'] as $innehåll) {
        expect($kartan)->not->toContain($innehåll);
    }

    // Ett enda ord: rubriken. Ingen andra `t()`-nyckel finns i filen, och
    // alltså ingen mening om att kartan saknas eller kommer.
    preg_match_all("/t\('([\w.]+)'\)/", $kartan, $träffar);

    expect($träffar[1])->toBe(['item.map.heading']);

    // Panelen tar ingen propp och ställer ingen fråga: den har ingenting att
    // rita, och därför ingenting att hämta.
    expect($kartan)->not->toContain('defineProps');

    // Den ritar en yta med höjd, så att kolumnen håller ihop i layouten, och
    // ytan är tom och dold för skärmläsaren.
    expect($kartan)->toContain('min-h-')
        ->toContain('aria-hidden="true"');

    expect(Lang::get('ui.item.map.heading', [], 'en'))->toBe('Map');

    // Och vyn skickar ingenting till den — ingen prop, ingen fråga.
    expect(trepanelKod('pages/Containers/Items/Show.vue'))->toContain('<ItemMapPanel />');

    actingAs($anvandare)->get(trepanelUrl($container, $motorn))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Show')
            ->missing('map')
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
    // sedan, och mittkolumnen två av dem.
    $vy = trepanelKod('pages/Containers/Items/Show.vue');

    expect($vy)->toContain('grid-cols-1')
        ->toContain('md:grid-cols-4')
        ->toContain('md:col-span-2');

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
