<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 102 · Itemets flikrad. Se
 * resources/js/pages/Containers/Items/Show.vue,
 * resources/js/components/UiTabs.vue, resources/js/components/itemPresentation.js,
 * [[ADR-0041 Itemets vy]] § Beslut, [[ADR-0042 Designsystemet]] § Beslut och
 * [[M17 Designsystemet]] § 102.
 *
 * **Filen prövar en omfördelning och ett krav.** Omfördelningen är att de sex
 * propar som i dag renderas på en enda lång sida — fälten, relationerna,
 * bilagorna, schemana, utlåningen och taggarna — nu ligger i var sin flik, och
 * att anteckningen och beskrivningen står på översiktsfliken. Kravet är att
 * **utlåningen får en flik trots att bilden inte ritar någon**: en yta ingen
 * hittar är samma sak som en yta som inte finns, och det är 62a:s och 67c:s
 * egen motivering.
 *
 * **Ingen ny ändpunkt och ingen ny prop.** Provet begär varje fliks adress som
 * inloggad medlem: svarar den inte är fliken en död länk, och en flik som
 * kräver en ny kontrollermetod är ett fynd i PR:ens `## Frågor och antaganden`
 * och ingen ändpunkt i smyg.
 *
 * **Det som INTE prövas här** är det som kräver en webbläsare: att raden ser ut
 * som `struktur - item.jpeg`, att den aktiva fliken lyser, och att
 * tangentbordet tar sig genom den. Formen på `UiTabs` prövas i FlikradTest,
 * träffytan i GenomgangTest, och handprovet står i PR-kroppen.
 *
 * **Fliklistan läses i källkoden.** Den bor i vyns egen `computed` och inte i
 * en modul: containern delar sin rad mellan layouten och inställningssidan och
 * behövde därför `containerSections.js`, men itemets rad har en enda anropare.
 * Samma sorts källkodsprov som ItemrelationvyTest och ForekomstvagTest gör på
 * samma fil — en mall går inte att köra, och det som går att läsa är formen.
 *
 * Hjälparna har prefixet `itemflik` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem som äger en container.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function itemflikKontext(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $anvandare, $container];
}

/**
 * Ett item i containern med sammanhängande `created_by_*`.
 *
 * @param  array<string, mixed>  $attribut
 */
function itemflikItem(Container $container, string $namn, array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create(array_merge([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ], $attribut));
}

/**
 * Itemets detaljvy som URL.
 */
function itemflikUrl(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}";
}

/**
 * Källkoden med kommentarer borta. Samma tre slag som GenomgangTest rensar:
 * blockkommentarer, HTML-kommentarer och radkommentarer — docblocken är
 * svenska med flit (AGENTS.md § Språk i koden), och en regel som letar efter en
 * markup ska inte kunna nöjas av en mening i ett docblock.
 */
function itemflikKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Fliklistan: stycket från `const tabs = computed(` till radens `});`.
 *
 * Raden är den ENDA listan över itemets flikar, och proven nedan läser den
 * i stället för en avskrift: en avskrift hade bevisat att testet och koden
 * säger samma sak, inte att vyn gör det.
 */
function itemflikTabbar(string $vy): string
{
    $start = strpos($vy, 'const tabs = computed(');

    expect($start)->not->toBeFalse('vyn bygger ingen flikrad');

    $slut = strpos($vy, '});', (int) $start);

    expect($slut)->not->toBeFalse('flikraden stänger aldrig');

    return substr($vy, (int) $start, (int) $slut - (int) $start);
}

/**
 * Nycklarna i fliklistan, i ritad ordning.
 *
 * @return list<string>
 */
function itemflikNycklar(string $vy): array
{
    preg_match_all("/key: '(\w+)'/", itemflikTabbar($vy), $träffar);

    return $träffar[1];
}

/**
 * Etikettnycklarna i fliklistan, i ritad ordning — de nycklar `t()` slår upp.
 *
 * @return list<string>
 */
function itemflikEtiketter(string $vy): array
{
    preg_match_all("/label: t\('([\w.]+)'\)/", itemflikTabbar($vy), $träffar);

    return $träffar[1];
}

/**
 * Fliklistan rad för rad: nyckel → radens egen källkod.
 *
 * Raden delas vid `{ key: '`, så provet kan läsa vad EN flik gör utan att
 * pinnas vid ordningen mellan radens fält — den som byter plats på `href` och
 * `count` ska inte fälla ett prov om räknaren.
 *
 * @return array<string, string>
 */
function itemflikRader(string $vy): array
{
    $rader = [];

    foreach (explode("{ key: '", itemflikTabbar($vy)) as $bit) {
        if (preg_match("/^(\w+)'/", $bit, $namn) !== 1) {
            continue;
        }

        $rader[$namn[1]] = $bit;
    }

    return $rader;
}

/**
 * Panelerna: flikens nyckel → källkoden för panelen, från taggen grinden står
 * i till taggen nästa fliks grind står i.
 *
 * Varje panel har EXAKT en grind och den står först i sin tagg — det är formen
 * `v-if="activeTab === '<nyckel>'"`, och den är bindande för vyn: en panel som
 * grindas på något annat sätt går inte att skilja från en granne. Fragmentet
 * börjar därför i taggens `<`, så att panelens eget namn — `<ItemLoanSection`
 * — står i dess egen text och inte i grannens.
 *
 * @return array<string, string>
 */
function itemflikPaneler(string $vy): array
{
    preg_match_all(
        '/v-if="activeTab === \'(\w+)\'"/',
        $vy,
        $träffar,
        PREG_OFFSET_CAPTURE,
    );

    $kanter = array_map(
        fn (array $träff): int => (int) strrpos(substr($vy, 0, $träff[1]), '<'),
        $träffar[0],
    );

    $paneler = [];

    foreach ($träffar[1] as $index => $nyckel) {
        $start = $kanter[$index];
        $slut = $kanter[$index + 1] ?? strlen($vy);

        $paneler[$nyckel[0]] = substr($vy, $start, $slut - $start);
    }

    return $paneler;
}

/**
 * Kör en snutt mot resources/js/components/itemPresentation.js i node, samma
 * väg som ItemdetaljTest och av samma skäl: modulen går att köra, och en mall
 * gör det inte.
 */
function itemflikKor(string $skript): string
{
    $kod = implode("\n", [
        "const { pathToFileURL } = await import('node:url');",
        'const m = await import(pathToFileURL('
            .json_encode(resource_path('js/components/itemPresentation.js'), JSON_UNESCAPED_SLASHES).').href);',
        $skript,
    ]);

    $rader = [];
    $status = 0;

    exec('node --input-type=module -e '.escapeshellarg($kod).' 2>&1', $rader, $status);

    expect($status)->toBe(0, implode("\n", $rader));

    return implode("\n", $rader);
}

/*
 * Klart när: itemets vy har en flikrad byggd av UiTabs.
 *
 * Raden är komponenten från issue 100 och inte en egen rad markup: formen,
 * tangentbordet och den aktiva fliken bor där, och två flikrader glider isär
 * inom samma milstolpe. Provet fäster raden vid itemets namn, läser flikarnas
 * ordning ur vyns egen lista, och begär varje adress — en flik som inte svarar
 * är en död länk.
 */
it('itemets vy har en flikrad byggd av UiTabs', function () {
    withoutVite();

    [, $anvandare, $container] = itemflikKontext();
    $motorn = itemflikItem($container, 'Motorn');

    $vy = itemflikKod('pages/Containers/Items/Show.vue');

    expect($vy)->toContain("import UiTabs from '../../../components/UiTabs.vue'")
        ->toContain('<UiTabs')
        ->toContain(':tabs="tabs"')
        // `label` är tablistens tillgängliga namn och är itemets namn — samma
        // namn rubriken bär, och det som säger vilket item raden hör till.
        ->toContain(':label="item.name"');

    // Bildens fem, och de två rader bilden inte har (utlåningen och taggarna)
    // sist. Historiken och kostnaden har ingen flik: den ena väntar på
    // instrumenteringen och den andra på trepanelslayouten (issue 103).
    expect(itemflikNycklar($vy))->toBe([
        'overview',
        'details',
        'relations',
        'attachments',
        'schedules',
        'loans',
        'tags',
    ]);

    // Etiketten slås upp och skrivs aldrig i filen: `t()` hade skrivit nyckeln
    // själv på skärmen om den saknades. Fem av dem är sektionens eget ord —
    // fliken och rubriken strax under den ska inte kunna säga olika saker.
    expect(itemflikEtiketter($vy))->toBe([
        'item.show.overview',
        'item.show.details',
        'item.links.heading',
        'item.attachment.heading',
        'item.schedule.heading',
        'item.loan.heading',
        'item.show.tags',
    ]);

    foreach (itemflikEtiketter($vy) as $nyckel) {
        $mening = trans("ui.{$nyckel}", [], 'en');

        expect($mening)->not->toBe("ui.{$nyckel}", "{$nyckel} saknas");
        expect(trim($mening))->not->toBe('');
    }

    // Adressen: itemets egen sökväg, och `?tab=` för varje flik utom
    // vilotillståndet — översikten skrivs utan querysträng (issue 100).
    $bar = itemflikTabbar($vy);

    expect($bar)->toContain('`/containers/${props.container.ulid}/items/${props.item.ulid}`');

    preg_match_all('/\?tab=(\w+)/', $bar, $träffar);

    expect($träffar[1])->toBe(['details', 'relations', 'attachments', 'schedules', 'loans', 'tags']);

    // Och varje flik svarar. Ingen ny ändpunkt: adresserna är itemets egen
    // rutt med en querysträng på, och kontrollern är orörd av issuen.
    foreach (itemflikNycklar($vy) as $nyckel) {
        $adress = itemflikUrl($container, $motorn).($nyckel === 'overview' ? '' : "?tab={$nyckel}");

        actingAs($anvandare)->get($adress)->assertOk();
    }
});

/*
 * Klart när: alla sex befintliga propar når sin flik.
 *
 * Fälten, relationerna, bilagorna, schemana, utlåningen och taggarna ritas
 * alla i dag, och omfördelningen ska inte tappa en enda av dem. Provet fäster
 * var och en vid sin panel och prövar att ingen av dem ritas i en annan: en
 * prop som ritas på två flikar är samma yta två gånger, och den som försvinner
 * i en omfördelning är den här filens hela ärende.
 */
it('alla sex befintliga propar når sin flik', function () {
    $vy = itemflikKod('pages/Containers/Items/Show.vue');

    // Panel för panel: markören är den prop eller det anrop som bär ytan.
    $ytor = [
        'details' => ['v-for="field in fields"', 'field.value'],
        'relations' => ['<ItemLinkSection', ':links="links"', ':counterparts="counterparts"'],
        'attachments' => ['<ItemAttachmentSection', ':attachments="attachments"', ':variants="variants"'],
        'schedules' => ['<ScheduleListSection', ':schedules="schedules"', ':open-occurrences="openOccurrences"'],
        'loans' => ['<ItemLoanSection', ':open-loan="openLoan"', ':loan-history="loanHistory"'],
        'tags' => ['<ItemTagList', ':tags="item.tags"'],
    ];

    $paneler = itemflikPaneler($vy);

    foreach ($ytor as $nyckel => $markörer) {
        expect(array_key_exists($nyckel, $paneler))->toBeTrue("fliken {$nyckel} finns inte");

        foreach ($markörer as $markör) {
            expect(str_contains($paneler[$nyckel], $markör))->toBeTrue(
                "{$markör} når inte fliken {$nyckel}",
            );
        }
    }

    // Och bara där. Varje ytas första markör är dess namn, och den får bara
    // stå i sin egen panel.
    foreach ($ytor as $nyckel => $markörer) {
        foreach (array_keys($ytor) as $annan) {
            if ($annan === $nyckel) {
                continue;
            }

            expect(str_contains($paneler[$annan], $markörer[0]))->toBeFalse(
                "{$markörer[0]} ritas både på {$nyckel} och på {$annan}",
            );
        }
    }

    // Och de sex proparna är desamma som förut: ingen ny ändpunkt, ingen ny
    // fråga. Fälten kommer ur `itemFields`, de fem listorna ur detaljvyns
    // props — se ItemController::show().
    expect($vy)->toContain("import { itemFields } from '../../../components/itemPresentation.js'")
        ->toContain('itemFields(props.item, locale.value)');
});

/*
 * Räknaren på en flik (issue 99 · [[ADR-0042 Designsystemet]] § Beslut).
 *
 * Talet är antalet rader fliken ritar — motparterna i sina tre grupper,
 * bilagorna, schemana, utlåningarna, taggarna — och det kommer ur en prop som
 * redan finns. Översikten och detaljerna ritar ingen lista och bär `null`:
 * `UiTabs` skiljer `null` från en nolla, och en nolla är ett påstående
 * anroparen HAR gjort (issue 100).
 */
it('räknar raderna på fliken och bär inget tal där ingen lista finns', function () {
    $vy = itemflikKod('pages/Containers/Items/Show.vue');

    $rader = itemflikRader($vy);

    expect(array_keys($rader))->toBe(itemflikNycklar($vy));

    expect($rader['overview'])->toContain('count: null');
    expect($rader['details'])->toContain('count: null');

    $listor = [
        'relations' => ['props.links'],
        'attachments' => ['props.attachments'],
        'schedules' => ['props.schedules'],
        'loans' => ['props.loanHistory', 'props.openLoan'],
        'tags' => ['props.item.tags'],
    ];

    foreach ($listor as $nyckel => $proppar) {
        expect($rader[$nyckel])->not->toContain('count: null');

        foreach ($proppar as $propp) {
            expect(str_contains($rader[$nyckel], $propp))->toBeTrue(
                "räknaren på {$nyckel} räknar inte sin egen lista",
            );
        }
    }
});

/*
 * Klart när: utlåningen har en egen flik.
 *
 * Utlåningen har ingen rad i bilden, och kravet är att den ändå får en plats:
 * en yta ingen hittar är samma sak som en yta som inte finns (62a och 67c).
 * Provet faller om fliken saknas, om sektionen flyttar in i en annan flik, och
 * om adressen inte svarar.
 *
 * Itemets enda status en annan medlem behöver se på en sekund är just den
 * öppna utlåningen — därför står en riktig utlåning i provet och inte ett tomt
 * item: räknaren ska räkna den öppna som en rad, och fliken ska visa den.
 */
it('utlåningen har en egen flik', function () {
    withoutVite();

    [, $anvandare, $container] = itemflikKontext();
    $motorn = itemflikItem($container, 'Motorn');

    $oppen = Loan::factory()->for($motorn, 'item')->create([
        'borrower_name' => 'Grannen',
        'lent_at' => '2026-09-01',
        'returned_at' => null,
    ]);

    $vy = itemflikKod('pages/Containers/Items/Show.vue');

    expect(itemflikNycklar($vy))->toContain('loans');
    expect(itemflikEtiketter($vy))->toContain('item.loan.heading');

    // Sektionen ritas i utlåningsfliken och i ingen annan.
    $paneler = itemflikPaneler($vy);

    expect($paneler['loans'])->toContain('<ItemLoanSection');

    foreach (array_keys($paneler) as $nyckel) {
        if ($nyckel !== 'loans') {
            expect($paneler[$nyckel])->not->toContain('<ItemLoanSection');
        }
    }

    // Och adressen svarar, med samma propar som förut: den öppna utlåningen
    // och historiken kommer ur detaljvyns props, inte ur en ny fråga.
    actingAs($anvandare)->get(itemflikUrl($container, $motorn).'?tab=loans')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Show')
            ->where('openLoan.ulid', $oppen->ulid)
            ->where('openLoan.borrower_name', 'Grannen')
            ->has('loanHistory', 0)
        );
});

/*
 * Klart när: flikraden ligger inuti containerns ram.
 *
 * **Itemet bor i containern** ([[ADR-0041 Itemets vy]] § Beslut), och
 * [[ADR-0042 Designsystemet]] § Beslut står fast vid det. Bilden ritar en
 * global vänstermeny med egna rader för Struktur, Karta, Uppgifter, Dokument
 * och Kostnader; den tas inte in, och det är avgjort två gånger. Ramen är
 * `ContainerLayout` — containerns namn och flikrad ur issue 101 — och itemets
 * flikrad står i dess slot.
 *
 * Provet faller på tre sätt en global navigering hade kunnat smyga in: en egen
 * layout i vyn, en andra `<nav>`, eller en adress som lämnar containern. Det
 * fjärde är fliklistan själv: ingen av bildens fem globala rader får finnas som
 * flik.
 */
it('flikraden ligger inuti containerns ram', function () {
    $vy = itemflikKod('pages/Containers/Items/Show.vue');

    $start = strpos($vy, '<ContainerLayout');
    $slut = strpos($vy, '</ContainerLayout>');

    expect($start)->not->toBeFalse('vyn ritar ingen ContainerLayout');
    expect($slut)->not->toBeFalse('ContainerLayout stängs aldrig');

    // Flikraden står INUTI ramen — mellan öppningen och stängningen — och
    // alltså i layoutens slot, jämte brödsmulan och namnet.
    expect(substr($vy, (int) $start, (int) $slut - (int) $start))->toContain('<UiTabs');

    // Skalet deklareras inte om här: containerns ram bär det.
    expect($vy)->not->toContain('AppLayout');

    // EN navigering i vyn, och det är brödsmulan. En global meny hade varit
    // en <nav> till — eller en sidopanel, som bilden ritar den.
    expect(preg_match_all('/<nav\b/', $vy))->toBe(1, 'vyn ritar en navigering utöver brödsmulan');
    expect($vy)->toContain(':aria-label="t(\'item.show.breadcrumb\')"');
    expect($vy)->not->toContain('<aside');

    // Och varje adress vyn bygger stannar i containern. En global rad hade
    // pekat någon annanstans — /structure, /map eller vad den nu hette.
    preg_match_all('/[`\'"](\/[^`\'"]*)[`\'"]/', $vy, $träffar);

    expect($träffar[1])->not->toBeEmpty();

    foreach ($träffar[1] as $adress) {
        expect($adress)->toStartWith('/containers/', "vyn pekar utanför containern: {$adress}");
    }

    // Ingen av bildens fem globala rader finns som flik: de är ytor i
    // containerns ram i den här produkten, inte egna sidor.
    $flikar = itemflikNycklar($vy);

    foreach (['structure', 'map', 'documents', 'costs', 'history'] as $global) {
        expect(in_array($global, $flikar, true))->toBeFalse(
            "bildens globala rad {$global} har blivit en flik",
        );
    }
});

/*
 * Klart när: anteckningen och beskrivningen står på översiktsfliken.
 *
 * De är två fält sedan issue 96 — beskrivningen säger vad itemet ÄR,
 * anteckningen vad användaren VET om det — och [[ADR-0041 Itemets vy]]
 * § Beslut säger att de visas som vyns ledande fält i stället för som en rad
 * bland tillverkare och modell. Provet skiljer dem åt på tre ställen: de två
 * fälten på översikten, deras frånvaro i detaljlistan, och modulen som bygger
 * listan — `itemFields` lämnar dem ifrån sig, och det prövas på den modul
 * klienten importerar.
 */
it('står anteckningen och beskrivningen på översiktsfliken', function () {
    $vy = itemflikKod('pages/Containers/Items/Show.vue');

    $paneler = itemflikPaneler($vy);

    // Två fält med var sin etikett, aldrig en sammanslagen text.
    expect($paneler['overview'])->toContain('item.description')
        ->toContain("t('item.show.description')")
        ->toContain('item.notes')
        ->toContain("t('item.show.notes')");

    // Och ingen av dem är en rad bland tillverkare och modell: detaljlistan
    // ritar `fields` och kategorin, och ingenting annat.
    expect($paneler['details'])->toContain('v-for="field in fields"');

    foreach (['item.description', 'item.notes'] as $falt) {
        expect(str_contains($paneler['details'], $falt))->toBeFalse("{$falt} står i detaljlistan");
    }

    // Regeln bor i modulen. Ett item med både beskrivning och anteckning ger
    // därför EN rad i detaljlistan — tillverkaren — och de två fälten står
    // kvar orörda på översikten.
    $item = json_encode([
        'description' => 'En bronsimpeller till sjövattenpumpen.',
        'notes' => 'Bytte impeller 2024.',
        'manufacturer' => 'Yanmar',
        'model' => null,
        'serial_number' => null,
        'purchased_at' => null,
        'warranty_until' => null,
        'position_note' => null,
    ], JSON_UNESCAPED_UNICODE);

    $rader = itemflikKor("process.stdout.write(JSON.stringify(m.itemFields({$item}, 'sv-SE').map((rad) => rad.key)));");

    expect($rader)->toBe('["manufacturer"]');
});
