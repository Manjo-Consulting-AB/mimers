<?php

use Illuminate\Support\Facades\Lang;

/*
 * Itemets ord, se issue 329 · [[M45 Itemets sida]] § 329 och [[ADR-0068
 * Itemets sida och skapandet]] § 1. Nycklarna i lang/en/ui.php står still och
 * byter bara värde ([[ADR-0032 Produktens ord]]).
 */

/**
 * En språkgren som en platt lista punktnycklar → sträng. Eget prefix på
 * namnet: Pest lägger alla testfiler i samma namnrymd.
 *
 * @param  array<mixed>  $gren
 * @return array<string, string>
 */
function itemetsOrdStrangar(array $gren, string $prefix = ''): array
{
    $ut = [];

    foreach ($gren as $nyckel => $varde) {
        if (is_array($varde)) {
            $ut += itemetsOrdStrangar($varde, $prefix.$nyckel.'.');
        } elseif (is_string($varde)) {
            $ut[$prefix.$nyckel] = $varde;
        }
    }

    return $ut;
}

it('ger varje nyckel i ordtabellen sitt nya värde', function () {
    $forvantat = [
        'item.form.location' => 'Placement',
        'item.show.placements' => 'Placements',
        'item.show.position_note' => 'Storage location',
        'item.form.position_note' => 'Storage location',
        'item.form.purchased_at' => 'Purchase date',
        'item.attachment.heading' => 'Documents',
        'create.rows.attachment' => 'Document',
        'item.schedule.heading' => 'To do',
    ];

    foreach ($forvantat as $nyckel => $varde) {
        // `trans()` skriver nyckeln själv om den saknas, så `toBe` binder både
        // närvaron och ordalydelsen.
        expect(trans("ui.{$nyckel}", [], 'en'))->toBe($varde, $nyckel);
    }
});

it('har inga av de utgångna orden under item.* eller create.rows', function () {
    $strangar = itemetsOrdStrangar(Lang::get('ui', [], 'en'));
    $utgangna = ['Where it is', 'Location', 'Attachments', 'Image or document', 'Purchased', 'Placements in the structure'];

    $granskade = 0;
    $traffar = [];

    foreach ($strangar as $nyckel => $varde) {
        if (! str_starts_with($nyckel, 'item.') && ! str_starts_with($nyckel, 'create.rows.')) {
            continue;
        }

        $granskade++;

        // Hela strängen: *Purchase date* och *Storage location* ska inte
        // fällas av att de innehåller ett orddelsliknande ord.
        if (in_array($varde, $utgangna, true)) {
            $traffar[] = "{$nyckel} = {$varde}";
        }
    }

    expect($granskade)->toBeGreaterThan(100);
    expect($traffar)->toBe([]);
});

it('kallar itemets uppgiftsflik To do', function () {
    expect(trans('ui.item.schedule.heading', [], 'en'))->toBe('To do');
});

it('har kvar nycklarna i ordtabellen och plusmenyns övriga rader', function () {
    $strangar = itemetsOrdStrangar(Lang::get('ui', [], 'en'));

    foreach ([
        'item.form.location',
        'item.show.placements',
        'item.show.position_note',
        'item.form.position_note',
        'item.form.purchased_at',
        'item.attachment.heading',
        'create.rows.attachment',
        'item.schedule.heading',
        'create.rows.relation',
        'create.rows.schedule',
        'create.rows.cost',
    ] as $nyckel) {
        expect($strangar)->toHaveKey($nyckel);
    }

    // Plusmenyns rader är singular.
    expect($strangar['create.rows.relation'])->toBe('Relation');
    expect($strangar['create.rows.schedule'])->toBe('Task');
    expect($strangar['create.rows.cost'])->toBe('Cost');
});

it('rör inte trädtabellens kolumner, lagringsrubriken eller historikens ämnen', function () {
    expect(trans('ui.item.tree.documents', [], 'en'))->toBe('Documents');
    expect(trans('ui.item.tree.tasks', [], 'en'))->toBe('Tasks');
    expect(trans('ui.storage.list_heading', [], 'en'))->toBe('Attachments');
    expect(trans('ui.audit.subject.attachment', [], 'en'))->toBe('Files');
    expect(trans('ui.audit.subject.schedule', [], 'en'))->toBe('Tasks');
});
