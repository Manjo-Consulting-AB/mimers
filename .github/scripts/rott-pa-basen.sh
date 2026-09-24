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
# Undantag: en testfixar utan kodändring (t.ex. issue 80 - tiden fryst runt en
# redan existerande mätning för att ta bort ett race) kan aldrig bli röd på basen,
# eftersom bas och head då delar samma applikationskod. En fil som har
# `// rott-pa-basen: <motivering>` som första icke-tomma rad efter `<?php` hoppar
# över körningen mot basen för just den filen - se issue 83. Markören tar bort en
# maskinell kontroll, inte granskarens läsning av diffen: den litar på att den som
# satte den har rätt, precis som issuens egna axlar litar på den som skriver dem.
# Granulariteten är per fil - en fil som blandar en genuint ny acceptanstest med
# en flakighetsfix ska inte ha markören.
#
# En ÄNDRAD testfil prövas bara om den lägger till ett testfall - ett `it('…')`
# eller `test('…')` vars namn inte finns i basens version av filen. Utan nytt
# namn är ändringen en följdändring: en ny konstruktorsignatur, en hjälpare som
# byter anrop. Sådana filer beter sig likadant på basen och kan aldrig bli röda
# där, och det var så issue 109 (#450) fastnade i fyra försök - fem befintliga
# tester som bara bytte `new StoreAttachment` mot en injicerad variant. Ett
# "Klart när"-kriterium namnger alltid ett testfall, så ett nytt kriterium ger
# alltid ett nytt namn. Det kontrollen släpper igenom är en ändrad assertion i
# ett befintligt testfall med oförändrat namn; den läses av granskaren.
# Tillagda filer prövas som förut.
#
# Läser BASE_SHA ur miljön. Avslutar 0 om alla nya tester är röda på basen (eller
# undantagna) eller om PR:en inte lägger till några tester, 1 om något
# icke-undantaget test går igenom utan koden.

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

# Testfallens namn i en fil på stdin, ett per rad. Repots tester skriver alla
# namnet med enkelfnutt direkt efter parentesen; ett namn med escapad fnutt
# kapas på samma ställe i bas och HEAD, så jämförelsen håller ändå.
testnamn() {
    grep -oE "^[[:space:]]*(it|test)\([[:space:]]*'[^']*'" \
        | sed -E "s/^[[:space:]]*(it|test)\([[:space:]]*//" \
        | sort -u || true
}

# Sant om filen lägger till minst ett testfall jämfört med baskommiten. En fil
# som inte fanns på basen räknas som ny.
har_nya_testfall() {
    local fil="$1"
    git cat-file -e "$BASE_SHA:$fil" 2>/dev/null || return 0
    [ -n "$(comm -13 <(git show "$BASE_SHA:$fil" | testnamn) <(git show "HEAD:$fil" | testnamn))" ]
}

# Följdändringar sorteras bort innan något byggs - består PR:en bara av sådana
# behövs inget basträd alls.
PROVAS=()
for fil in "${TESTER[@]}"; do
    if har_nya_testfall "$fil"; then
        PROVAS+=("$fil")
    else
        echo "::notice file=$fil::ändrad utan nytt testfall - en följdändring, prövas inte mot basen."
    fi
done

if [ ${#PROVAS[@]} -eq 0 ]; then
    echo "Inga nya testfall att pröva - alla ${#TESTER[@]} ändrade testfiler är följdändringar."
    exit 0
fi
TESTER=("${PROVAS[@]}")

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

    # Undantagsmarkören, se filhuvudet. Sökt i PR:ens version av filen, som
    # redan ligger i $ARBETE/$fil (kopierad ovan) - inte i basens.
    UNDANTAG="$(head -n 5 "$fil" | grep -m1 '^// rott-pa-basen: ' || true)"
    if [ -n "$UNDANTAG" ]; then
        MOTIVERING="${UNDANTAG#"// rott-pa-basen: "}"
        echo "::notice file=$fil::undantagen från röd-på-bas-kontrollen: $MOTIVERING"
        continue
    fi

    if php artisan test "$fil" > "$ARBETE/utfall.log" 2>&1; then
        GRONA+=("$fil")
        echo "::error file=$fil::testet går igenom utan PR:ens implementation - det bevisar inget om acceptanskriteriet."
        tail -n 20 "$ARBETE/utfall.log"
    else
        echo "rött på basen, som det ska"
    fi
done

if [ ${#GRONA[@]} -gt 0 ]; then
    # Sammanfattningen skrivs sist med flit: den som bara läser svansen av
    # utdatan - en agent vars felutskrift kapats - ska ändå få veta vad som
    # fälldes och vad som går att göra åt det.
    echo "::error::${#GRONA[@]} testfil(er) är gröna redan på baskommiten. Varje \"Klart när\"-punkt ska motsvaras av ett test som faller utan koden."
    echo "Fällda filer:"
    printf '  %s\n' "${GRONA[@]}"
    cat <<'RAD'
Vad du kan göra, per fil:
  - Nytt testfall som ska bevisa ett kriterium: skärp assertionen så att den
    faller utan din implementation. Byt den mot det nya beteendet, stryk den inte.
  - Ett nytt testfall som av goda skäl är grönt på basen (en ren testfix, en
    flytt): sätt `// rott-pa-basen: <motivering>` som första rad efter `<?php`.
  - En befintlig fil du bara behövde ändra för en ny signatur fälls inte - lägg
    inte till testfall i den som inte hör till issuen.
RAD
    exit 1
fi

echo "Alla ${#TESTER[@]} prövade testfiler är röda på basen eller undantagna."
