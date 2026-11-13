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
 * Kontrollerar också exec-biten på de skalskript som installeras och körs på
 * plats. Arbetsträdet ligger på en NFS-share som rapporterar varje fil som
 * rwxrwxrwx, så core.fileMode=false i .git/config är nödvändig och permanent -
 * utan den vore varje fil i repot ständigt ändrad. Följden är att chmod +x
 * aldrig syns i indexet: skriptet läggs till som 100644 och faller först i
 * testsviten, på en assertion långt inne i 1200 test. Issue 42a, 42b och 43
 * betalade den upptäckten var för sig, och två av dem först efter att
 * granskningen godkänt PR:en - se docs/Process/Lärdomar.md.
 *
 * Samma skäl som ovan: deterministiskt villkor, känt kommando, alltså ett
 * skript. Skriptet lagar ingenting självt - en tyst `git update-index` hade
 * dolt att läget aldrig sattes - men det skriver ut exakt kommandot.
 *
 * Två skalskript är undantagna med flit: de piped:as till `bash -s` över ssh av
 * utrullningsworkflowarna och körs aldrig på plats, så 100644 är rätt för dem.
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
} else {
    // Ett manifest som finns men är äldre än källorna är samma fel som ett
    // som saknas: TypsnittTest föll på en kopierad public/build utan
    // InterVariable.woff2 (M50-M52). is_file och filemtime följer länkar, så
    // en symlänkad public/build kontrolleras likadant.
    $manifest_tid = filemtime("$rot/public/build/manifest.json");
    $nyaste = 0;
    foreach (['resources/js', 'resources/css'] as $katalog) {
        if (! is_dir("$rot/$katalog")) {
            continue;
        }
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator("$rot/$katalog", FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS)
        );
        foreach ($iter as $fil) {
            if ($fil->isFile()) {
                $nyaste = max($nyaste, $fil->getMTime());
            }
        }
    }
    if ($nyaste > $manifest_tid) {
        $saknas[] = 'public/build är äldre än resources/ — kör npm run build';
    }
}

// node_modules mot package-lock.json. npm skriver node_modules/.package-lock.json
// vid varje installation; dess packages-nycklar ska vara de som låsfilen
// listar (utom rotposten). Valfria paket som inte passar plattformen saknas
// legitimt i node_modules och får också finnas där utan att vara fel.
// En länkad node_modules följs av file_get_contents.
$las_paket = static function (string $fil): ?array {
    if (! is_file($fil)) {
        return null;
    }
    $json = json_decode((string) file_get_contents($fil), true);
    if (! is_array($json) || ! is_array($json['packages'] ?? null)) {
        return null;
    }
    unset($json['packages']['']);

    return $json['packages'];
};

$last = $las_paket("$rot/package-lock.json");
if ($last !== null) {
    $installerat = $las_paket("$rot/node_modules/.package-lock.json");
    $fel = $installerat === null;
    if (! $fel) {
        foreach ($last as $nyckel => $paket) {
            if (empty($paket['optional']) && ! array_key_exists($nyckel, $installerat)) {
                $fel = true;
            }
        }
        foreach ($installerat as $nyckel => $paket) {
            if (empty($paket['optional']) && ! array_key_exists($nyckel, $last)) {
                $fel = true;
            }
        }
    }
    if ($fel) {
        $saknas[] = 'node_modules matchar inte package-lock.json — kör npm ci';
    }
}

// Skalskript som piped:as till `bash -s` över ssh och aldrig körs på plats.
// Se production.yml och staging.yml respektive ADR-0029.
const UTAN_EXECBIT = [
    'deploy/env-uppdatera.sh',
    'deploy/retro-fakta.sh',
];

$utan_lage = [];
exec('git -C ' . escapeshellarg($rot) . " ls-files -s -- '*.sh'", $rader, $kod);
if ($kod !== 0) {
    // Ingen git, ingen kontroll. Testsviten ska fortfarande kunna köras ur en
    // tarball; exec-biten är då redan avgjord av den som packade den.
    $rader = [];
}
foreach ($rader as $rad) {
    // <läge> <blob> <stage>\t<sökväg>
    if (! preg_match('/^(\d{6}) \S+ \d+\t(.+)$/', $rad, $m)) {
        continue;
    }
    [, $lage, $fil] = $m;
    if ($lage === '100755' || in_array($fil, UTAN_EXECBIT, true)) {
        continue;
    }
    $utan_lage[] = $fil;
}

if ($utan_lage !== []) {
    fwrite(STDERR, "Skalskript utan exec-bit i git-indexet:\n");
    foreach ($utan_lage as $fil) {
        fwrite(STDERR, "  - $fil (läge 100644)\n");
    }
    fwrite(STDERR, "\nArbetsträdet har core.fileMode=false, så `chmod +x` syns inte i indexet.\n");
    fwrite(STDERR, "Sätt läget i indexet i stället:\n\n");
    foreach ($utan_lage as $fil) {
        fwrite(STDERR, "  git update-index --chmod=+x " . escapeshellarg($fil) . "\n");
    }
    fwrite(STDERR, "\nSka skriptet aldrig köras på plats - det piped:as till `bash -s` - lägg\n");
    fwrite(STDERR, "det i UTAN_EXECBIT i " . basename(__FILE__) . " med en rad om varför.\n");

    exit(1);
}

if ($saknas === []) {
    exit(0);
}

fwrite(STDERR, "Testmiljön är inte uppsatt:\n");
foreach ($saknas as $rad) {
    fwrite(STDERR, "  - $rad\n");
}
fwrite(STDERR, "\nKör `composer setup` först. En fräsch worktree behöver det en gång.\n");
fwrite(STDERR, "Gäller raden node_modules eller public/build räcker kommandot på raden.\n");

exit(1);
