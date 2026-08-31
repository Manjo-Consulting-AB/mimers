<?php

/**
 * Kontrollera att arbetsträdet är bootstrappat innan testsviten körs.
 *
 * En fräsch git-worktree har varken .env eller public/build. `composer test`
 * föll då på två ställen som inte har med testet att göra: TOTP-testerna kastar
 * MissingAppKeyException, och UtrullningsartefaktTest läser
 * public/build/manifest.json. Issue 14 och 15a betalade den uppstarten var för
 * sig och rapporterade den oberoende av varandra i sina processnoteringar - se
 * docs/Process/Lärdomar.md.
 *
 * Villkoret är deterministiskt och kommandot är känt, alltså ett skript och inte
 * en rad i AGENTS.md som någon ska minnas. Skriptet lagar ingenting självt:
 * `composer setup` finns redan och gör precis det som behövs, och en tyst
 * autofix inne i `composer test` hade dolt att worktreen aldrig sattes upp.
 *
 * PHP och inte bash, eftersom composer garanterar en php-binär men inte ett
 * skal - skriptet ska kunna köras varhelst `composer test` kan det.
 *
 * Avslutar 0 när allt finns, 1 med en åtgärdbar rad när något saknas.
 */
$rot = dirname(__DIR__, 2);

$saknas = [];

if (! is_file("$rot/.env")) {
    $saknas[] = '.env saknas';
} elseif (! preg_match('/^APP_KEY=.+$/m', (string) file_get_contents("$rot/.env"))) {
    // Tom APP_KEY är samma fel som ingen .env: nyckeln finns i .env.example som
    // en tom rad, så filen kan existera och ändå fälla TOTP-testerna.
    $saknas[] = 'APP_KEY är tom i .env';
}

if (! is_file("$rot/public/build/manifest.json")) {
    $saknas[] = 'public/build/manifest.json saknas (frontend inte byggd)';
}

if ($saknas === []) {
    exit(0);
}

fwrite(STDERR, "Testmiljön är inte uppsatt:\n");
foreach ($saknas as $rad) {
    fwrite(STDERR, "  - $rad\n");
}
fwrite(STDERR, "\nKör `composer setup` först. En fräsch worktree behöver det en gång.\n");

exit(1);
