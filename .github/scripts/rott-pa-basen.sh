#!/usr/bin/env bash
# Kontrollera att varje nytt test i PR:en är RÖTT utan PR:ens implementation.
#
# AGENTS.md kräver ett test per "Klart när"-punkt, men ingenting har kontrollerat
# att testet faktiskt fångar något. Ett test som är grönt redan innan koden skrevs
# bevisar ingenting, och CI blir grön i båda fallen - det är den enda vägen förbi
# acceptanskriterierna som inte syns vid granskning.
#
# Metoden: exportera baskommitens träd till en egen katalog, lägg in PR:ens
# testfiler men ingen av dess källkod, och kör testerna där. Varje testfil ska
# misslyckas. En fil som går igenom är fyndet.
#
# Trädet exporteras med `git archive` och inte som en worktree, eftersom en
# worktree delar objektdatabas med repot - då ligger lösningen kvar inom räckhåll.
#
# Vad kontrollen INTE gör: den säger att testet reagerar på implementationen, inte
# att testet är bra. Ett test som faller på ett fatalt fel för att en klass saknas
# räknas som rött, vilket är rätt utfall men ett svagt bevis.
#
# Läser BASE_SHA ur miljön. Avslutar 0 om alla nya tester är röda på basen eller om
# PR:en inte lägger till några tester, 1 om något test går igenom utan koden.

set -euo pipefail

BASE_SHA="${BASE_SHA:?BASE_SHA måste vara satt}"
ROT="$(git rev-parse --show-toplevel)"

# --diff-filter=AM: tillagda och ändrade. Raderade tester finns inte att köra.
mapfile -t TESTER < <(git diff --name-only --diff-filter=AM "$BASE_SHA...HEAD" -- 'tests/*.php')

if [ ${#TESTER[@]} -eq 0 ]; then
    echo "::notice::PR:en rör inga testfiler - kontrollen hoppas över."
    exit 0
fi

echo "Nya eller ändrade testfiler: ${#TESTER[@]}"
printf '  %s\n' "${TESTER[@]}"

ARBETE="$(mktemp -d)"
trap 'rm -rf "$ARBETE"' EXIT

# Basträdet, utan historik och utan PR:ens källkod.
git archive "$BASE_SHA" | tar -x -C "$ARBETE"

# PR:ens tester läggs ovanpå. Bara dessa - resten av grenen ska inte med.
for fil in "${TESTER[@]}"; do
    mkdir -p "$ARBETE/$(dirname "$fil")"
    git show "HEAD:$fil" > "$ARBETE/$fil"
done

cd "$ARBETE"

# Beroenden: återanvänd rotens vendor när låsfilen är oförändrad mellan bas och
# HEAD. En andra composer install per PR kostar mer än hela kontrollen är värd.
# --no-scripts: post-autoload-dump kör artisan package:discover, som behöver en
# bootad app. Vi är bara ute efter klasskartan; paketmanifestet byggs vid behov
# när testet körs.
export COMPOSER_ALLOW_SUPERUSER=1
if git -C "$ROT" diff --quiet "$BASE_SHA" HEAD -- composer.lock && [ -d "$ROT/vendor" ]; then
    cp -r "$ROT/vendor" "$ARBETE/vendor"
    composer dump-autoload --quiet --no-interaction --no-scripts
else
    composer install --prefer-dist --no-interaction --no-progress --quiet --no-scripts
fi

cp .env.example .env
php artisan key:generate --quiet

# Frontend byggs inte här. Ett test som läser public/build/manifest.json faller
# därför på basen, vilket räknas som rött - och rött är det förväntade utfallet,
# så ett falskt rött kan aldrig fälla den här kontrollen. Motsatsen, ett grönt
# test som borde varit rött, är den enda signal vi letar efter.
GRONA=()
for fil in "${TESTER[@]}"; do
    echo "--- $fil"
    if php artisan test "$fil" > "$ARBETE/utfall.log" 2>&1; then
        GRONA+=("$fil")
        echo "::error file=$fil::testet går igenom utan PR:ens implementation - det bevisar inget om acceptanskriteriet."
        tail -n 20 "$ARBETE/utfall.log"
    else
        echo "rött på basen, som det ska"
    fi
done

if [ ${#GRONA[@]} -gt 0 ]; then
    echo "::error::${#GRONA[@]} testfil(er) är gröna redan på baskommiten. Varje \"Klart när\"-punkt ska motsvaras av ett test som faller utan koden."
    exit 1
fi

echo "Alla ${#TESTER[@]} testfiler är röda på basen."
