<?php

use Illuminate\Support\Facades\File;

/*
 * M24 · issue 231 (GitHub #729) — grupprubrikerna med antal och ihopfällning,
 * se resources/js/components/TaskGroup.vue, de tre ytorna och lang/en/ui.php.
 * Förlagan är docs/Design/tasks-container.png.
 *
 * Filen är ett källkodsprov och inte ett sidprov: knappen, `aria-expanded`,
 * ihopfällningens frånvaro av lagring och antalets nycklar går att avgöra ur
 * markupen och katalogen. Det som kräver en webbläsare — att chevronen roterar,
 * att träffytan känns rätt i handen — står i PR-kroppen. Samma grepp som
 * GenomgangTest och UppgiftsradTest.
 *
 * Rubriken ritas på tre ytor (`/tasks`, containerns flik och itemets flik), och
 * alla läser SAMMA komponent — därför prövas formen en gång, i `TaskGroup.vue`,
 * och varje yta prövas dessutom för att den VERKLIGEN ritar den.
 *
 * Hjälparna har prefixet `uppgiftsgrupp` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * En källfil med kommentarer borta. Samma tre slag som GenomgangTest rensar —
 * blockkommentarer, HTML-kommentarer och radkommentarer — så att docblocken
 * (svenska med flit, AGENTS.md § Språk i koden) varken kan läsas som markup
 * eller råka bära en klass.
 */
function uppgiftsgruppKod(string $sokvag): string
{
    $kod = File::get(resource_path($sokvag));

    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * De tre ytorna som ritar grupprubriken.
 *
 * @return list<string>
 */
function uppgiftsgruppYtor(): array
{
    return [
        'js/pages/Containers/Tasks.vue',
        'js/components/ScheduleListSection.vue',
        'js/pages/Tasks/Index.vue',
    ];
}

/*
 * Klart när: rubriken är en knapp med aria-expanded.
 *
 * Beslut 1. Hela rubriken — chevronen, namnet och antalet — är EN knapp, och
 * `aria-expanded` annonserar läget för en skärmläsare. Rader under rubriken
 * fälls med `v-show` och inte `v-if`: en ihopfälld grupp finns kvar i
 * dokumentet, och en försenad uppgift som fällts ihop försvinner inte ur
 * läsordningen för den som använder skärmläsare.
 */
it('rubriken är en knapp med aria-expanded', function () {
    $grupp = uppgiftsgruppKod('js/components/TaskGroup.vue');

    expect($grupp)->toContain('<button type="button"')
        ->toContain(':aria-expanded="open"')
        ->toContain('@click="open = !open"')
        ->toContain('v-show="open"')
        ->toContain('<slot');
});

/*
 * Klart när: ihopfällningen sparas inte.
 *
 * Beslut 2. `open` börjar öppet och nollställs vid sidladdning — en sparad
 * ihopfällning hade gömt en försenad uppgift för den som glömt att hon fällt
 * ihop gruppen. Provet fäster båda halvorna: att startläget är öppet, och att
 * ingen lagring eller navigering finns i filen.
 */
it('ihopfällningen sparas inte', function () {
    $grupp = uppgiftsgruppKod('js/components/TaskGroup.vue');

    expect($grupp)->toContain('const open = ref(true)');

    expect($grupp)->not->toContain('localStorage');
    expect($grupp)->not->toContain('sessionStorage');
    expect($grupp)->not->toContain('router.');
});

/*
 * Klart när: antalet har nycklar för ental och flertal.
 *
 * Beslut 3. `t()` kan inte pluralisera, så ental har sin egen mening — samma
 * mönster som `date.overdue_one`/`date.overdue`. Talet kommer ur listans längd
 * och inte ur en egen räkning: `count === 1` väljer nyckeln, och annars bär
 * `:count` talet.
 */
it('antalet har nycklar för ental och flertal', function () {
    expect(trans('ui.todo.group_count_one', [], 'en'))->toBe('1 task')
        ->and(trans('ui.todo.group_count', ['count' => 3], 'en'))->toBe('3 tasks');

    expect(uppgiftsgruppKod('js/components/TaskGroup.vue'))
        ->toContain("t('todo.group_count_one')")
        ->toContain("t('todo.group_count', { count })")
        ->toContain('count === 1');
});

/*
 * Klart när: de tre ytorna ritar TaskGroup.
 *
 * Beslut 4. Rubriken ritas på ETT ställe, och varje yta skickar in gruppens
 * namn, antalet och tonen. Den gamla grupprubriken — en rå `<h2>` med
 * `todo.group.*` — är borta ur alla tre. Även *Done* ritas av komponenten, på
 * de två ytor som har en sådan grupp, med `container.tasks.done` som rubrik.
 *
 * **Kontrollen av `<h2>` gäller grupprubriken, inte varje `<h2>` i filen.**
 * Containerns flik bär en egen sektionsrubrik för snabblänkarna
 * (`container.tasks.shortcuts`) med samma klass; den är inte en grupp och rörs
 * inte av issuen. Mönstret kräver därför att rubriktexten är gruppens uppslag
 * (`t(\`todo.group.${group}\`)`), vilket bara den gamla grupprubriken var.
 */
it('de tre ytorna ritar TaskGroup', function () {
    foreach (uppgiftsgruppYtor() as $fil) {
        $kod = uppgiftsgruppKod($fil);

        expect($kod)->toContain('<TaskGroup')
            ->toContain(':heading="t(`todo.group.${group}`)"')
            ->toContain(':count="entries.length"');

        expect($kod)->not->toMatch('/<h2[^>]*>\s*\{\{\s*t\(`todo\.group/');
    }

    // *Done* ritas av samma komponent, med sitt eget antal.
    foreach (['js/pages/Containers/Tasks.vue', 'js/components/ScheduleListSection.vue'] as $fil) {
        expect(uppgiftsgruppKod($fil))
            ->toMatch('/<TaskGroup[\s\S]*?t\(\'container\.tasks\.done\'\)/')
            ->toContain(':count="completed.length"');
    }
});

/*
 * Klart när: försenat har tonen danger.
 *
 * Beslut 4: `tone` är `'danger'` för `overdue` och annars null. Gruppen jämförs
 * mot sitt EGET namn — serverns nyckel — och aldrig mot ett datum (issue 64
 * § Beslut 3). Komponenten målar bara den tonen rött, och namnet i övrigt med
 * textens egen roll.
 */
it('försenat har tonen danger', function () {
    foreach (uppgiftsgruppYtor() as $fil) {
        expect(uppgiftsgruppKod($fil))
            ->toContain(":tone=\"group === 'overdue' ? 'danger' : null\"");
    }

    expect(uppgiftsgruppKod('js/components/TaskGroup.vue'))
        ->toContain("tone === 'danger' ? 'text-danger' : 'text-ink'");
});
