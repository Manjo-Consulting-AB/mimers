<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

/*
 * Inkorgsöversiktens form, se M28 · issue 776 och
 * `docs/Design/findings_261007a/inkorgsöversikt.png`. Sidan gör samma saker
 * som förut — fångar, bearbetar, flyttar och (efter issue 775) raderar — men
 * med mockupens uppställning: rubriken med underraden, fångstkortet med två
 * halvor, de tre brickorna och de två listkorten.
 *
 * **Källkodsprov och inte sidprov.** Det som prövas här är ritningen — vilken
 * nyckel, vilken rutt, vilket antal — och den går inte att läsa ur ett
 * HTTP-svar. Filerna läses med `File::get`, samma form som proven i
 * DokumentflikDesignTest och RaderaIInboxenTest. Sidans svar — rader, omfång,
 * öppningar — ägs av RaderaIInboxenTest och av sviten i övrigt.
 *
 * **Nycklarnas VÄRDEN prövas också, inte bara att de slås upp.** SprakTest
 * håller att varje nyckel finns i katalogen; här fästs de nya orden vid sin
 * plats, och den tredje brickan prövas för det den INTE får säga: *items* är
 * ett begrepp i Mimers, och en obearbetad post i inboxen är inte ett item
 * (Tonys beslut 2026-10-07).
 *
 * Ett prov per punkt i "Klart när". Den sista punkten — hela testsviten är
 * grön — är CI:s uppgift och ingen egen rad.
 *
 * **Ett prov kommer ur granskningen 2026-10-08 och inte ur "Klart när".**
 * Cirkeln på uppgiftsraden var dekor — en kryssruta som inte gjorde något —
 * och provet fäster den vid `complete`-rutten. En död kontroll är värre än
 * ingen kontroll, och den som klickar på den ska få något att hända.
 */

it('sidan har rubriken och underraden', function () {
    $vy = File::get(resource_path('js/pages/Inbox/Index.vue'));

    // Rubriken är sidans egen, och underraden står direkt under den (Beslut 1).
    expect($vy)->toContain("t('inbox.page.heading')")
        ->toContain("t('inbox.page.tagline')");

    expect(Lang::get('ui.inbox.page.tagline', [], 'en'))->toBe('Capture now. Organize later.');
});

it('fångstkortet har New task och Add files', function () {
    $vy = File::get(resource_path('js/pages/Inbox/Index.vue'));

    // Samma två formulär, samma två rutter och samma fält som i dag (Beslut
    // 2): formen är ny, vägen in är den gamla.
    expect($vy)->toContain("t('inbox.page.capture.task_label')")
        ->toContain("t('inbox.page.capture.files_label')")
        ->toContain('@submit.prevent="captureTask"')
        ->toContain('@submit.prevent="captureFiles"')
        ->toContain("post('/inbox/tasks'")
        ->toContain("post('/inbox/attachments'");

    // Släppytan matar SAMMA fält och samma POST: ingen ny uppladdningsväg.
    expect($vy)->toContain('@drop.prevent="onFilesDropped"')
        ->toContain("t('inbox.page.capture.files_drop')")
        ->toContain("t('inbox.page.capture.files_browse')");

    expect(Lang::get('ui.inbox.page.capture.task_label', [], 'en'))->toBe('New task');
    expect(Lang::get('ui.inbox.page.capture.files_label', [], 'en'))->toBe('Add files');
});

it('den tredje brickan säger to process och inte items', function () {
    $vy = File::get(resource_path('js/pages/Inbox/Index.vue'));

    // Tre brickor, och talen räknas ur propparnas längder i vyn (Beslut 3).
    expect(substr_count($vy, '<UiStat'))->toBe(3);

    expect($vy)->toContain("'inbox.page.stats.tasks'")
        ->toContain("'inbox.page.stats.files'")
        ->toContain("'inbox.page.stats.total'")
        ->toContain("t('inbox.page.stats.to_process')");

    // Nycklarna finns, och ingen av dem säger *items*: ett item är ett begrepp
    // i Mimers, och inboxens poster är inte items.
    foreach (['tasks', 'files', 'total'] as $bricka) {
        $nyckel = "ui.inbox.page.stats.{$bricka}";
        $varde = (string) Lang::get($nyckel, [], 'en');

        expect(trim($varde))->not->toBe('', "{$nyckel} saknas i lang/en/ui.php");
        expect(stripos($varde, 'item'))->toBeFalse("{$nyckel} säger items: {$varde}");
    }
});

it('varje rad har Process och en radmeny', function () {
    $vy = File::get(resource_path('js/pages/Inbox/Index.vue'));

    // *Process…* står på båda radslagen — uppgiften öppnar steget med listan,
    // filen går samma väg som satsen — och ⋯-menyn ritas en gång per lista
    // (Beslut 4, issue 775 · Beslut 3).
    // `<summary` och inte `<details`: docblocken ovanför menyerna NAMNGER
    // taggen, och en räkning på `<details` hade räknat kommentarerna med.
    expect($vy)->toContain("t('inbox.page.process')")
        ->toContain("t('inbox.page.row_menu')")
        ->and(substr_count($vy, '<summary'))->toBe(2);

    expect(Lang::get('ui.inbox.page.process', [], 'en'))->toBe('Process…');
});

/*
 * Granskningsfynd 2026-10-08: cirkeln är ingen dekor. Den postar till 63b:s
 * `complete`-rutt genom samma hjälpare som TodoRow på `/tasks` — en
 * inboxuppgift är en vanlig förekomst ([[ADR-0054 Inboxen]] § 5) — och den
 * avbockade raden lämnar listan, för `list: inbox` visar bara aktiva
 * förekomster. Rutten hade ingen egen grind mot inboxen: samma `update` på
 * itemet som `can.update` räknas med.
 */
it('cirkeln på uppgiftsraden är en avbockning mot complete-rutten', function () {
    $vy = File::get(resource_path('js/pages/Inbox/Index.vue'));

    // Samma rutt och samma anrop som TodoRow: `occurrenceActionUrl` med
    // `complete`, och `account` med i kroppen (CompleteOccurrenceRequest
    // fordrar den).
    expect($vy)->toContain('occurrenceActionUrl(')
        ->toContain("'complete'")
        ->toContain('@submit.prevent="completeTask(')
        ->toContain('account: task.account');

    // Samma väntetext och samma tillgängliga namn som på `/tasks`, och samma
    // 44 px-träffyta — cirkeln är en riktig kontroll, inte en ritad prick.
    expect($vy)->toContain("t('common.pending.complete')")
        ->toContain("t('todo.complete')")
        ->toContain('min-w-11');
});

it('filkortet har Move selected och Delete selected', function () {
    $vy = File::get(resource_path('js/pages/Inbox/Index.vue'));

    // De två satsåtgärderna står i kortets rubrikrad (Beslut 4), och båda är
    // inaktiva när ingen fil är markerad.
    expect($vy)->toContain("t('inbox.page.move_selected')")
        ->toContain("t('inbox.page.delete_selected')")
        ->toContain(':disabled="selected.length === 0"');
});
