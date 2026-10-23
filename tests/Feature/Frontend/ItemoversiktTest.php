<?php

use App\Http\Controllers\ContainerController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

/*
 * M45 · issue 333 · Itemets översikt, se
 * resources/js/pages/Containers/Items/Show.vue,
 * resources/js/components/ItemFieldGrid.vue,
 * resources/js/components/ItemQuickActions.vue och
 * [[ADR-0068 Itemets sida och skapandet]] § 5 och 7.
 *
 * **Det som prövas är formen, inte webbläsaren.** Sviten har ingen browser,
 * så proven läser källkoden med kommentarerna borta — samma sorts prov som
 * AnteckningsrutaTest och ItemflikTest gör på samma filer. Vad som kräver en
 * webbläsare står i PR-kroppen.
 *
 * Hjälparna har prefixet `itemoversikt` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Källkoden med kommentarer borta.
 */
function itemoversiktKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * Översiktens sektion i Show.vue, från dess öppning till nästa flik.
 */
function itemoversiktSektion(): string
{
    $vy = itemoversiktKod('pages/Containers/Items/Show.vue');

    $start = strpos($vy, "<section v-if=\"activeTab === 'overview'\"");
    $slut = strpos($vy, '</section>', (int) $start);

    expect($start)->not->toBeFalse('översiktens sektion finns inte');

    return substr($vy, (int) $start, (int) $slut - (int) $start);
}

it('ritar varje ifyllt fält i rutnätet och inget tomt fält', function () {
    $rutnat = itemoversiktKod('components/ItemFieldGrid.vue');

    expect($rutnat)->toContain("import { itemFields } from './itemPresentation.js'")
        ->toContain('itemFields(props.item, props.locale)')
        ->toContain("key: 'category'")
        ->toContain('v-for="field in fields"')
        ->toContain('{{ field.value }}')
        ->toContain('{{ field.label }}')
        ->toContain('v-if="fields.length > 0"')
        ->toContain('t(`item.show.${field.key}`)');

    // Kategorin ritas bara med ett namn, och rubriken Quick facts finns inte.
    expect($rutnat)->toContain('props.categoryName !== null')
        ->and($rutnat)->not->toContain('quick_facts');

    expect(itemoversiktSektion())->toContain('<ItemFieldGrid :item="item" :locale="locale" :category-name="categoryName" />');

    foreach (['category', 'manufacturer', 'model', 'serial_number', 'purchased_at', 'warranty_until', 'position_note'] as $nyckel) {
        expect(Lang::has("ui.item.show.{$nyckel}", 'en'))->toBeTrue("etiketten item.show.{$nyckel} saknas");
    }
});

it('ger rutnätet två kolumner från md', function () {
    $rutnat = itemoversiktKod('components/ItemFieldGrid.vue');

    expect($rutnat)->toContain('grid grid-cols-1 gap-4 md:grid-cols-2');
});

it('ställer beskrivningen under fälten', function () {
    $sektion = itemoversiktSektion();

    $rutnat = strpos($sektion, '<ItemFieldGrid');
    $beskrivning = strpos($sektion, '{{ item.description }}');

    expect($rutnat)->not->toBeFalse()
        ->and($beskrivning)->not->toBeFalse()
        ->and($rutnat)->toBeLessThan($beskrivning);
});

it('ritar snabbåtgärderna i ordningen document, task, cost, relation ur create-proppen', function () {
    $atgarder = itemoversiktKod('components/ItemQuickActions.vue');

    $nycklar = [];

    foreach (['attachment', 'schedule', 'cost', 'relation'] as $nyckel) {
        $nycklar[$nyckel] = strpos($atgarder, "{ key: '{$nyckel}', label: 'item.show.add_");
        expect($nycklar[$nyckel])->not->toBeFalse("åtgärden {$nyckel} finns inte");
    }

    expect($nycklar['attachment'])->toBeLessThan($nycklar['schedule'])
        ->and($nycklar['schedule'])->toBeLessThan($nycklar['cost'])
        ->and($nycklar['cost'])->toBeLessThan($nycklar['relation']);

    // Adressen är radens egen — samma som plusmenyns — och ingen byggs här.
    expect($atgarder)->toContain(':href="action.href"')
        ->and($atgarder)->toContain('props.create?.rows')
        ->and($atgarder)->not->toContain('/containers/');

    expect(Lang::get('ui.item.show.add_document', [], 'en'))->toBe('Add document')
        ->and(Lang::get('ui.item.show.add_task', [], 'en'))->toBe('Add task')
        ->and(Lang::get('ui.item.show.add_cost', [], 'en'))->toBe('Add cost')
        ->and(Lang::get('ui.item.show.add_relation', [], 'en'))->toBe('Add relation');

    expect(itemoversiktSektion())->toContain('<ItemQuickActions :create="create" />');
});

it('ritar ingen snabbåtgärd utan motsvarande rad och ingen rad utan åtgärder', function () {
    $atgarder = itemoversiktKod('components/ItemQuickActions.vue');

    // En åtgärd utan rad hoppas över, och listan har en egen grind.
    expect($atgarder)->toContain('rows.find((candidate) => candidate.key === action.key)')
        ->toContain('row ? [{')
        ->toContain(': []')
        ->toContain('v-if="actions.length > 0"');

    // Itemrader hör till plusmenyn och är ingen snabbåtgärd.
    expect($atgarder)->not->toContain("key: 'item'");
});

it('ritar den pågående utlåningen bara när openLoan finns', function () {
    $sektion = itemoversiktSektion();

    $tagg = strpos($sektion, 'v-if="openLoan"');

    expect($tagg)->not->toBeFalse();

    expect($sektion)->toContain("t('item.loan.borrowed_by', { name: openLoan.borrower_name })")
        ->and($sektion)->toContain("t('item.loan.due_at', { date: formatDateOnly(openLoan.due_at, locale) })")
        ->and($sektion)->toContain('v-if="openLoanOverdue"');

    // Raden står efter snabbåtgärderna och före anteckningen.
    expect(strpos($sektion, '<ItemQuickActions'))->toBeLessThan($tagg)
        ->and($tagg)->toBeLessThan(strpos($sektion, '<ItemNotes'));
});

it('ritar anteckningarna och aktiviteten som förut med länk till History', function () {
    $sektion = itemoversiktSektion();

    expect($sektion)->toContain('<ItemNotes')
        ->and($sektion)->toContain('<ItemActivityPanel v-if="events !== null" :events="events" />')
        ->and($sektion)->toContain("t('item.show.activity_all')")
        ->and($sektion)->toContain(':href="historyHref"');

    $vy = itemoversiktKod('pages/Containers/Items/Show.vue');

    expect($vy)->toContain("tab.key === 'history'");

    expect(strpos($sektion, '<ItemActivityPanel'))->toBeLessThan(strpos($sektion, "t('item.show.activity_all')"));

    // Taket på fem händelser sätts av servern, och länken är vägen till resten.
    expect(ContainerController::ACTIVITY_LIMIT)->toBe(5);
    expect(Lang::get('ui.item.show.activity_all', [], 'en'))->toBe('All history');
});

it('visar meningen i det tomma läget och länken Edit bara med can.update', function () {
    $sektion = itemoversiktSektion();

    $rad = strpos($sektion, '<p v-if="overviewEmpty"');

    expect($rad)->not->toBeFalse();

    $stycke = substr($sektion, (int) $rad, (int) strpos($sektion, '</p>', (int) $rad) - (int) $rad);

    expect($stycke)->toContain("t('item.show.overview_empty')")
        ->and($stycke)->toContain('v-if="can.update"')
        ->and($stycke)->toContain(':href="editUrl"')
        ->and($stycke)->toContain("t('item.edit.action')");

    // Tomt betyder också ingen aktivitet.
    $vy = itemoversiktKod('pages/Containers/Items/Show.vue');

    expect($vy)->toContain('props.events === null || props.events.length === 0');
});

it('har tagit bort ItemQuickFacts och itemQuickFacts', function () {
    expect(File::exists(resource_path('js/components/ItemQuickFacts.vue')))->toBeFalse();

    $modul = itemoversiktKod('components/itemPresentation.js');

    expect($modul)->not->toContain('itemQuickFacts')
        ->and($modul)->not->toContain('QUICK_FACT_KEYS');

    $vy = itemoversiktKod('pages/Containers/Items/Show.vue');

    expect($vy)->not->toContain('ItemQuickFacts');

    expect(Lang::has('ui.item.show.quick_facts', 'en'))->toBeFalse();
});
