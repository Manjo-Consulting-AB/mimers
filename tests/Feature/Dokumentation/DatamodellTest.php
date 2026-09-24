<?php

/*
 * Issue 118. Åtta av appens tabeller nämndes ingenstans under
 * docs/Datamodell/, och CLAUDE.md skickar varje fråga om en tabell dit.
 * Provet gör luckan omöjlig att öppna igen: varje tabell en migrering
 * skapar med Schema::create() måste nämnas som ett helt ord i någon fil
 * under docs/Datamodell/.
 *
 * Två listor står uttryckligen här, var och en med sitt skäl:
 * ramverkets egna tabeller, och de tabeller som en senare migrering tar
 * bort eller som aldrig var domän. Kontrollen är en egen funktion så att
 * det andra provet kan pröva den med en påhittad lista, utan att en
 * migrering läggs till.
 */

/** Ramverkets egna tabeller — Laravel sköter dem, datamodellen beskriver appen. */
function ramverketsTabeller(): array
{
    return [
        'password_reset_tokens',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'personal_access_tokens',
    ];
}

/** Tabeller som en senare migrering tar bort, eller som aldrig var domän. */
function borttagnaTabeller(): array
{
    return [
        // Borttagen i 2026_08_24_115145_drop_users_table.php.
        'users',
        // Konventionsexemplet ur issue 2.
        'examples',
    ];
}

/** Datamodellens samlade text — tabellnamnen ska stå som hela ord i den. */
function datamodellensText(): string
{
    $text = '';

    foreach (glob(base_path('docs/Datamodell').'/*.md') ?: [] as $fil) {
        $innehåll = file_get_contents($fil);

        if ($innehåll === false) {
            throw new RuntimeException("Kunde inte läsa {$fil}.");
        }

        $text .= $innehåll."\n";
    }

    return $text;
}

/** Varje tabellnamn en migrering skapar med Schema::create(). */
function migreradeTabeller(): array
{
    $tabeller = [];

    foreach (glob(base_path('database/migrations').'/*.php') ?: [] as $migrering) {
        $text = file_get_contents($migrering);

        if ($text === false) {
            throw new RuntimeException("Kunde inte läsa {$migrering}.");
        }

        preg_match_all("/Schema::create\(\s*['\"]([a-z0-9_]+)['\"]/", $text, $träffar);

        foreach ($träffar[1] as $tabell) {
            $tabeller[$tabell] = true;
        }
    }

    return array_keys($tabeller);
}

/** De av tabellerna som inte nämns som ett helt ord i datamodellen. */
function saknadeITabellistan(array $tabeller): array
{
    $text = datamodellensText();

    return array_values(array_filter(
        $tabeller,
        fn (string $tabell) => preg_match('/\b'.preg_quote($tabell, '/').'\b/', $text) !== 1
    ));
}

/** Fäller med namnet på varje tabell som saknas i datamodellen. */
function krävTabellerIDatamodellen(array $tabeller): void
{
    $saknade = saknadeITabellistan($tabeller);

    if ($saknade !== []) {
        throw new RuntimeException(
            'Tabeller som saknas i datamodellen: '.implode(', ', $saknade)
        );
    }
}

it('har ett avsnitt för varje tabell en migrering skapar', function () {
    $undantag = array_merge(ramverketsTabeller(), borttagnaTabeller());
    $granskade = array_values(array_diff(migreradeTabeller(), $undantag));

    expect($granskade)->not->toBeEmpty();

    krävTabellerIDatamodellen($granskade);
});

it('fäller en tabell som saknas i datamodellen, med sitt namn', function () {
    expect(fn () => krävTabellerIDatamodellen(['magic_link_token', 'finns_inte_i_dokumenten']))
        ->toThrow(RuntimeException::class, 'finns_inte_i_dokumenten');
});

it('bär ramverkets tabeller och de borttagna tabellerna som två uttalade listor', function () {
    expect(ramverketsTabeller())->toBe([
        'password_reset_tokens',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'personal_access_tokens',
    ]);

    expect(borttagnaTabeller())->toBe(['users', 'examples']);
});
