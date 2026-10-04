<?php

use Illuminate\Support\Facades\File;

/*
 * M24 · issue 230 — uppgiftsraden enligt mockupen, se
 * resources/js/components/TodoRow.vue, resources/js/pages/Containers/Tasks.vue,
 * resources/js/components/ScheduleListSection.vue och [[ADR-0042
 * Designsystemet]]. Förlagan är docs/Design/tasks-container.png.
 *
 * Filen är ett källkodsprov och inte ett sidprov: formen — den runda bocken,
 * cirkelns kant, den röda tonen, miniatyren och *Done*-raden — går att avgöra
 * ur markupen, och det som kräver en webbläsare (att cirkeln sitter till
 * vänster, att raden bryter under `md:`) står i PR-kroppen. Samma grepp som
 * GenomgangTest och de andra formproven i sviten.
 *
 * Raden ritas på fem ytor (`/tasks`, dashboarden, containerns översikt,
 * containerns flik och itemets flik), och alla läser SAMMA komponent — därför
 * prövas formen en gång, i `TodoRow.vue`, och *Done* dessutom på de två ytor
 * som ritade den med en egen rad.
 *
 * Hjälparna har prefixet `uppgiftsrad` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * En källfil med kommentarer borta. Samma tre slag som GenomgangTest rensar —
 * blockkommentarer, HTML-kommentarer och radkommentarer — så att docblocken
 * (svenska med flit, AGENTS.md § Språk i koden) varken kan läsas som markup
 * eller råka bära en färgklass.
 */
function uppgiftsradKod(string $sokvag): string
{
    $kod = File::get(resource_path($sokvag));

    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/*
 * Klart när: avbockningen är en rund knapp, 44 × 44 px, med sin text i
 * `aria-label` och ingen synlig knapptext.
 */
it('avbockningen är en rund knapp med aria-label', function () {
    $rad = uppgiftsradKod('js/components/TodoRow.vue');

    // Den runda formen och träffytan (M24 · issue 230, Beslut 1): `min-w-11`
    // är 44 px bredvid `min-h-11`, så knappen är kvadratisk och inte bara hög.
    expect($rad)->toContain('rounded-full')
        ->toContain('min-h-11')
        ->toContain('min-w-11')
        ->toContain(':aria-label');

    // Ingen synlig knapptext kvar: *Check off* bor i namnet och ingen
    // annanstans.
    expect($rad)->not->toContain("t('todo.complete') }}");
});

/*
 * Klart när: cirkelns kant följer serverns fält — `danger` försenat,
 * `warning` idag, `subtle` kommande — och raden jämför inga datum själv.
 */
it('cirkelns kant följer serverns fält', function () {
    $rad = uppgiftsradKod('js/components/TodoRow.vue');

    expect($rad)->toContain('border-danger')
        ->toContain('border-warning')
        ->toContain('border-ink-subtle')
        ->toContain('entry.overdue')
        ->toContain('entry.upcoming');

    // Inget datum jämförs i raden (issue 64 § Beslut 3): `\bDate\b` fångar
    // `Date` som eget ord och inte `dueDate`/`eventDate`, så ett `new Date`
    // eller `Date.now` fäller provet medan regeln får bo i komposabeln.
    expect($rad)->not->toMatch('/\bDate\b/');
});

/*
 * Klart när: en försenad rad har bakgrunden `bg-danger/5`.
 */
it('försenad rad har röd ton', function () {
    $rad = uppgiftsradKod('js/components/TodoRow.vue');

    expect($rad)->toContain('bg-danger/5')
        ->toContain('entry.overdue');
});

/*
 * Klart när: raden ritar itemets miniatyr när derivatet finns och lådikonen
 * annars — samma val som itemlistan gör.
 */
it('ritar miniatyren eller lådikonen', function () {
    $rad = uppgiftsradKod('js/components/TodoRow.vue');

    expect($rad)->toContain('entry.cover?.hasThumb')
        ->toContain('?variant=thumb');
});

/*
 * Klart när: en avbockad rad ritas av `TodoRow` — `entry.completed_at` väljer
 * fylld bock och `eventDate()` — och varken containerns flik eller itemets
 * flik bär `UiListRow` kvar.
 */
it('Done ritas med TodoRow', function () {
    $rad = uppgiftsradKod('js/components/TodoRow.vue');

    expect($rad)->toContain('entry.completed_at')
        ->toContain('eventDate');

    foreach (['js/pages/Containers/Tasks.vue', 'js/components/ScheduleListSection.vue'] as $fil) {
        expect(uppgiftsradKod($fil))->not->toContain('UiListRow');
    }
});

/*
 * Klart när: inga råa palettfärger i raden — varje roll kommer ur `@theme`
 * ([[ADR-0042 Designsystemet]] § Beslut).
 */
it('inga råa palettfärger i raden', function () {
    $rad = uppgiftsradKod('js/components/TodoRow.vue');

    expect($rad)->not->toMatch('/\b(red|slate|blue)-\d{2,3}\b/');
});
