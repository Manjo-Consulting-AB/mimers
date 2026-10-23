<?php

use Illuminate\Support\Facades\File;

/*
 * Issue 334 · De tomma flikarna. Se
 * [[ADR-0068 Itemets sida och skapandet]] § 7, resources/js/components/
 * UiEmptyState.vue och flikarnas sektioner under resources/js/components/.
 *
 * Varje flik på itemets sida som kan vara tom säger en mening och erbjuder en
 * åtgärd, och åtgärden visas bara när användaren får utföra den. Proven läser
 * källan, som resten av ytans prov: Vue-sektionerna renderas i webbläsaren och
 * har ingen PHP-sida att fråga.
 */

function tommaFlikarKod(string $fil): string
{
    return File::get(resource_path("js/{$fil}"));
}

/**
 * Blocket för ett tomt läge: från `<UiEmptyState` med det villkoret till
 * närmaste `</UiEmptyState>`.
 */
function tommaFlikarBlock(string $kod, string $start): string
{
    $fran = strpos($kod, $start);

    expect($fran)->not->toBeFalse("{$start} saknas");

    $till = strpos($kod, '</UiEmptyState>', (int) $fran);

    expect($till)->not->toBeFalse('</UiEmptyState> saknas');

    return substr($kod, (int) $fran, (int) $till - (int) $fran);
}

/** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}> */
function tommaFlikarFlikar(): array
{
    // fil, början av blocket, meningens nyckel, åtgärdens ord, rättigheten, formulärets första fält
    return [
        'Relations' => ['components/ItemLinkSection.vue', '<UiEmptyState v-else', 'item.links.empty', 'item.show.add_relation', 'can.update && counterparts.length > 0', 'item'],
        'Documents' => ['components/ItemAttachmentSection.vue', '<UiEmptyState v-if="rows.length === 0"', 'item.attachment.empty', 'item.show.add_document', 'can.create', 'attachment-file'],
        'Costs' => ['components/ItemCostSection.vue', '<UiEmptyState v-if="costs.length === 0"', 'item.cost.empty', 'item.show.add_cost', 'can.create', 'cost-incurred-on'],
        'Loans' => ['components/ItemLoanSection.vue', '<UiEmptyState v-else', 'item.loan.not_lent', 'item.loan.form_heading', 'can.create', 'borrower_name'],
    ];
}

it('ritar en mening med UiEmptyState i varje tom flik', function () {
    foreach (tommaFlikarFlikar() as [$fil, $start, $mening]) {
        $kod = tommaFlikarKod($fil);
        $block = tommaFlikarBlock($kod, $start);

        expect($kod)->toContain("import UiEmptyState from './UiEmptyState.vue'");
        expect($block)->toContain("t('{$mening}')");
    }

    $uppgifter = tommaFlikarKod('components/ScheduleListSection.vue');
    $block = tommaFlikarBlock($uppgifter, '<UiEmptyState v-if="!hasAnyTask"');

    expect($uppgifter)->toContain("import UiEmptyState from './UiEmptyState.vue'");
    expect($block)->toContain("t('item.schedule.empty')");
});

it('visar åtgärden bara med rättigheten som formuläret redan kräver', function () {
    foreach (tommaFlikarFlikar() as [$fil, $start, , $ord, $ratt]) {
        $block = tommaFlikarBlock(tommaFlikarKod($fil), $start);

        expect($block)->toContain("<template v-if=\"{$ratt}\" #action>");
        expect($block)->toContain("t('{$ord}')");
    }

    $block = tommaFlikarBlock(tommaFlikarKod('components/ScheduleListSection.vue'), '<UiEmptyState v-if="!hasAnyTask"');

    expect($block)->toContain('<template v-if="can.create" #action>');
    expect($block)->toContain("t('item.show.add_task')");
});

it('låter åtgärden nå flikens formulär', function () {
    foreach (tommaFlikarFlikar() as [$fil, $start, , , , $falt]) {
        $kod = tommaFlikarKod($fil);
        $block = tommaFlikarBlock($kod, $start);

        expect($block)->toContain('@click="focusForm"');
        expect($kod)->toContain("document.getElementById('{$falt}')?.focus()");
        // Fältet finns i samma fil, så fokus har något att landa på.
        expect($kod)->toContain("id=\"{$falt}\"");
    }

    // Uppgifterna har sitt formulär på en egen sida: åtgärden är samma
    // länk som *New task* i rubrikkortet.
    $kod = tommaFlikarKod('components/ScheduleListSection.vue');
    $block = tommaFlikarBlock($kod, '<UiEmptyState v-if="!hasAnyTask"');

    expect($block)->toContain(':href="createUrl"');
});

it('låter History säga en mening utan åtgärd', function () {
    $kod = tommaFlikarKod('pages/Containers/Items/Show.vue');
    $block = tommaFlikarBlock($kod, '<UiEmptyState v-if="history.length === 0"');

    expect($block)->toContain("t('audit.history.empty')");
    expect($block)->not->toContain('#action');
    expect($block)->not->toContain('<Link');
    expect($block)->not->toContain('<UiButton');
});

it('ritar inget tomt läge i en flik med rader', function () {
    // Det tomma läget är grenen före `v-else`-listan, och listan ritas
    // ur samma villkor — en flik med rader når aldrig `UiEmptyState`.
    $bilagor = tommaFlikarKod('components/ItemAttachmentSection.vue');
    $kostnader = tommaFlikarKod('components/ItemCostSection.vue');
    $historik = tommaFlikarKod('pages/Containers/Items/Show.vue');
    $uppgifter = tommaFlikarKod('components/ScheduleListSection.vue');
    $relationer = tommaFlikarKod('components/ItemLinkSection.vue');
    $utlaning = tommaFlikarKod('components/ItemLoanSection.vue');

    expect($bilagor)->toMatch('~</UiEmptyState>\s*<ul v-else~');
    expect($kostnader)->toMatch('~</UiEmptyState>\s*<ul v-else~');
    expect($historik)->toMatch('~</UiEmptyState>\s*<ul v-else~');
    expect($uppgifter)->toMatch('~</UiEmptyState>.*?<div v-else class="mt-6~s');
    expect($relationer)->toMatch('~<template v-if="hasAny">.*?</template>\s*<UiEmptyState v-else~s');
    expect($utlaning)->toMatch('~<div v-if="openLoan".*?<UiEmptyState v-else~s');
});
