<?php

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Den globala historikens form — M30 · testarnas fynd 2026-10-09 · issue 272
 * (#822), se resources/js/pages/History/Index.vue,
 * resources/js/components/HistoryFilterBar.vue,
 * resources/js/components/HistoryRow.vue,
 * resources/js/components/ActiveItemsPanel.vue och
 * resources/js/components/ShellSections.vue.
 *
 * **Källkodsprov och inte sidprov.** Skalet och sidan ritas i klienten:
 * Inertia renderar mallen i webbläsaren, och en svarskropp i en testsvit bär
 * bara rotvyn. Det som går att pröva på serversidan är därför formen på
 * källkoden — vilken rad, vilken nyckel, vilken länk — samma form som
 * GlobalaDokumentvyTest och MobilskalTest. Sidans SVAR — rader, läsregel,
 * filter och containerval — ägs av tests/Feature/Revision/GlobalHistorikTest.php
 * (issue 271).
 *
 * **Syskonproven är orörda med flit.** HistorikflikTest prövar att flikarna
 * tiger om containern och att raden blir en mening; den här filen prövar att
 * den GLOBALA sidan gör det motsatta — säger vilken plats raden gäller, och
 * att en kontorad heter *Account* — utan att röra flikens kontrakt.
 *
 * **Nycklarnas värden prövas där de bär en mening.** `nav.history` fästs vid
 * ordet, så en rad som tappar sin etikett faller i stället för att visa sin
 * egen nyckel på skärmen (SprakTest håller att nyckeln finns).
 *
 * Hjälparna har prefixet `globalHistorikvy` — Pest lägger alla testfiler i
 * samma namnrymd när hela sviten körs.
 */

/**
 * Källkoden med kommentarer borta. Samma tre slag som GenomgangTest och
 * HistorikflikTest rensar: blockkommentarer, HTML-kommentarer och
 * radkommentarer — docblocken är svenska med flit (AGENTS.md § Språk i
 * koden), och en regel som letar efter en tagg eller en nyckel ska inte kunna
 * nöjas av en mening i ett docblock.
 */
function globalHistorikvyKod(string $sokvag): string
{
    $kod = File::get(resource_path($sokvag));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/*
 * Klart när: `sidopanelen har History mellan Containers och Search` — i både
 * `allSections` och `partKeys.rows` (Beslut 1).
 *
 * Ordningen läses ur listen i skalet — samma grepp som MobilskalTest och
 * GlobalaDokumentvyTest — och *mellan* prövas och inte bara *med*: en rad på
 * fel plats är samma fel som en saknad rad.
 *
 * Listan är bara sanningen om vad en rad ÄR. Vilka rader som ritas står i
 * `partKeys`: en rad som ligger i `allSections` men saknas i `rows` syns inte
 * i sidopanelen eller i sidomenyn. Provet läser därför båda och kräver att
 * `containers`, `history` och `search` ligger i den ordningen på båda
 * ställena.
 */
it('sidopanelen har History mellan Containers och Search', function () {
    $skal = File::get(resource_path('js/components/ShellSections.vue'));

    preg_match_all("/key: '([a-z0-9_]+)', href: '([^']+)'/", $skal, $träffar, PREG_SET_ORDER);

    $nycklar = array_column($träffar, 1);

    expect($nycklar)->toContain('containers')->toContain('history')->toContain('search');

    $containers = array_search('containers', $nycklar, true);
    $history = array_search('history', $nycklar, true);
    $search = array_search('search', $nycklar, true);

    expect($history)->toBe($containers + 1)
        ->and($search)->toBe($history + 1);

    // Raderna som RITAS: `rows` ur `partKeys`, i den ordning de staplas.
    preg_match("/'rows': \[([^\]]+)\]/", $skal, $rad);
    preg_match_all("/'([a-z0-9_]+)'/", $rad[1], $ritade);

    $rader = $ritade[1];

    expect($rader)->toContain('containers')->toContain('history')->toContain('search');

    $ritadContainers = array_search('containers', $rader, true);
    $ritadHistory = array_search('history', $rader, true);
    $ritadSearch = array_search('search', $rader, true);

    expect($ritadHistory)->toBe($ritadContainers + 1)
        ->and($ritadSearch)->toBe($ritadHistory + 1);

    // Etiketten slås upp i katalogen och är sidans eget ord; en rad utan
    // översättning renderas som sin egen nyckel, `nav.history`.
    expect(Lang::get('ui.nav.history', [], 'en'))->toBe('History');
});

/*
 * Klart när: `sidan ritar listan, filtret och de tre diagrammen` (Beslut 2).
 *
 * Sidan är containerns historikflik över hela kontot, och ritas med flikens
 * delar: filterfältet, raderna och de tre panelerna. Ramen är den enda
 * skillnaden — `AppLayout` och ingen `ContainerLayout`: historiken är ingen
 * container, och en hjälte hade påstått att den var det (hjälten kom med
 * issue 170 och bär containerns bild, art, namn och beskrivning).
 *
 * Kommentarerna är borta innan regeln läser, så docblocken som BESKRIVER
 * skillnaden kan inte nöja den.
 */
it('sidan ritar listan, filtret och de tre diagrammen', function () {
    $sidan = globalHistorikvyKod('js/pages/History/Index.vue');

    expect($sidan)->toContain('<HistoryFilterBar')
        ->toContain('<HistoryRow')
        ->toContain('<ActivityTimeChart')
        ->toContain('<ActivityTypeChart')
        ->toContain('<ActiveItemsPanel');

    // Ingen containerhjälte, och ingen layout som kräver en container-propp:
    // fliken ligger i ContainerLayout, den globala sidan gör det inte.
    expect($sidan)->not->toContain('ContainerLayout');
});

/*
 * Klart när: `raderna visar sin container` — `History/Index.vue` skickar
 * `show-container` (Beslut 2 och 3).
 *
 * Raderna kommer från flera containrar här — och från ingen alls — så sidan
 * ber om platsen, precis som dashboardens händelsepanel (issue 126). Flikarna
 * ber inte om den: där är containern given av sidan man står på.
 */
it('raderna visar sin container', function () {
    $sidan = globalHistorikvyKod('js/pages/History/Index.vue');

    expect($sidan)->toContain('<HistoryRow')
        ->toContain('show-container');
});

/*
 * Klart när: `en kontohändelse heter Account` — `HistoryRow.vue` läser
 * `audit.history.account_place` (Beslut 3).
 *
 * En rad med `container: null` hör till kontot och inte till någon container
 * (led 3 i ListAuditEvents). Platsen där containernamnet annars står säger
 * *Account*: en tom plats hade lästs som ett ritfel, och
 * `audit.fallback.container` hade varit osann — containern är inte gallrad,
 * den har aldrig funnits. Dashboardens händelsepanel ritar samma ord, och det
 * är avsiktligt.
 *
 * Provet fäster källan OCH katalogen: nyckeln ska finnas, och raden ska läsa
 * den bara när containern saknas — en rad som alltid skrev *Account* hade
 * tystat varje containernamn.
 */
it('en kontohändelse heter Account', function () {
    $raden = globalHistorikvyKod('js/components/HistoryRow.vue');

    expect($raden)->toContain("t('audit.history.account_place')")
        ->toContain('props.row.container === null');

    expect(Lang::get('ui.audit.history.account_place', [], 'en'))->toBe('Account');
});

/*
 * Klart när: `filterfältet har containerval med Account activity` —
 * `HistoryFilterBar.vue` läser `audit.history.filter_container_all` och
 * `audit.history.filter_account` och skickar `container` (Beslut 4 och 6).
 *
 * Väljaren ritas ur serverns `options.containers` — den finns på den globala
 * historiken och saknas på fliken — och bär tre slags val: *All containers*
 * (tomt värde), *Account activity* (ordet `account`, kontohändelserna utan
 * container) och containrarna själva. En container med `inbox: true` heter
 * *Inbox* ur `nav.inbox`, samma ord som `/documents` ger en inboxfil.
 *
 * `container` följer med i parametrarna till servern, precis som de fem
 * ärvda fälten.
 */
it('filterfältet har containerval med Account activity', function () {
    $fältet = globalHistorikvyKod('js/components/HistoryFilterBar.vue');

    expect($fältet)->toContain("t('audit.history.filter_container')")
        ->toContain("t('audit.history.filter_container_all')")
        ->toContain("t('audit.history.filter_account')")
        ->toContain('value="account"')
        ->toContain('params.container')
        // Inboxen heter Inbox och inte sitt eget namn (Beslut 6).
        ->toContain("t('nav.inbox')");

    expect(Lang::get('ui.audit.history.filter_container_all', [], 'en'))->toBe('All containers');
    expect(Lang::get('ui.audit.history.filter_account', [], 'en'))->toBe('Account activity');
    expect(Lang::get('ui.audit.history.filter_container', [], 'en'))->toBe('Container');
});

/*
 * Klart när: `filterfältet navigerar till url när den är satt` —
 * `History/Index.vue` skickar `url="/history"`, och `HistoryFilterBar.vue`
 * faller tillbaka på containerns adress (Beslut 4).
 *
 * Fältet känner inte sin egen rutt: fliken stavar adressen ur `containerUlid`,
 * den globala sidan har ingen container och skickar sin egen i `url`. De två
 * anropen skiljer sig därför i ett värde och inte i en gren — och containerns
 * flik är oförändrad.
 */
it('filterfältet navigerar till url när den är satt', function () {
    $sidan = globalHistorikvyKod('js/pages/History/Index.vue');
    $fältet = globalHistorikvyKod('js/components/HistoryFilterBar.vue');

    expect($sidan)->toContain('url="/history"');

    // Fallbacken: containerns adress när `url` inte är satt, alltså på fliken.
    expect($fältet)->toContain('props.url ??')
        ->toContain('/containers/${props.containerUlid}/history');
});

/*
 * Klart när: `topplistan länkar genom itemets container` —
 * `ActiveItemsPanel.vue` läser `container_ulid` (Beslut 5).
 *
 * Posterna kommer från flera containrar på den globala historiken, och varje
 * post bär sin egen `container_ulid` (issue 271 § Beslut 4): raden länkar till
 * itemets sida inne i ITEMETS container och inte i en container panelen
 * gissar. Fliken fyller i sin egen ur `containerUlid` och är oförändrad.
 *
 * Länken *View all* ritas bara när panelen vet vilken lista den är vägen
 * vidare till: utan en container finns ingen itemlista att länka till.
 */
it('topplistan länkar genom itemets container', function () {
    $panelen = globalHistorikvyKod('js/components/ActiveItemsPanel.vue');

    expect($panelen)->toContain('item.container_ulid')
        ->toContain('props.containerUlid');

    // *View all* är villkorad av att containern är känd.
    expect($panelen)->toContain('v-if="containerUlid !== null"');
});

/*
 * Klart när: `sidan svarar med History/Index` — `GET /history` som inloggad
 * ger 200 och komponenten `History/Index`.
 *
 * Rutten kom med issue 271 och är oförändrad; det här provet fäster att
 * adressen fortfarande pekar på SAMMA sida efter att formen byggdes i 272 —
 * en nyckel som byter namn i kontrollern hade gett en vit skärm utan att
 * något källkodsprov märkte det.
 *
 * Propparna sidan ritar ur prövas till sin form: dagarna, talen, filtret (med
 * `container`, som bara finns här) och valen (med `containers`, som bara
 * finns här). Innehållet i dem ägs av tests/Feature/Revision/
 * GlobalHistorikTest.php.
 */
it('sidan svarar med History/Index', function () {
    withoutVite();

    actingAs(User::factory()->create())->get('/history')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('History/Index')
            ->has('days')
            ->has('stats.perDay')
            ->has('stats.perType')
            ->has('stats.topItems')
            ->has('filter.container')
            ->has('options.containers')
            ->has('options.types')
            ->has('options.users')
            ->has('options.items')
        );
});

/*
 * Klart när: sidans titel och rubrik är sina egna nycklar (Beslut 6).
 *
 * `history.title` och `history.heading` är sidans ord och inte flikens
 * (`audit.history.title`): fliken och den globala sidan är två ytor, och den
 * dag en av dem behöver säga något annat än den andra är nycklarna redan
 * åtskilda — samma uppdelning som `documents.title` mot containerns
 * dokumentflik.
 */
it('sidans titel och rubrik är sina egna nycklar', function () {
    $sidan = globalHistorikvyKod('js/pages/History/Index.vue');

    expect($sidan)->toContain("t('history.title')")
        ->toContain("t('history.heading')");

    expect(Lang::get('ui.history.title', [], 'en'))->toBe('History');
    expect(Lang::get('ui.history.heading', [], 'en'))->toBe('History');
});
