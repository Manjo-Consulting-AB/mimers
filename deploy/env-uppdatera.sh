#!/usr/bin/env bash
#
# Körs på servern, matad över SSH från staging.yml och production.yml:
#   ssh HOST "bash -s -- /home/s174280/mimers" < deploy/env-uppdatera.sh
#
# Skriver in de nycklar som miljöns GitHub-secrets bär i shared/.env, och rör
# ingen annan rad i filen. Fragmentet har lagts där av steget före, som
# `shared/.env.inkommande` - över stdin, aldrig som argument, eftersom
# argument syns i `ps` för varje annan kund på den delade maskinen.
#
# Motivering: docs/ADR/ADR-0030 Miljövariabler ur GitHubs secrets.md
#
# Varför en upsert och inte en renderad fil: shared/.env bär också APP_KEY och
# databasuppgifterna, som finns BARA på servern och aldrig har passerat GitHub.
# Renderades hela filen ur secrets skulle de behöva flyttas dit först, och en
# felaktig rendering skulle ta produktionens nyckel med sig. Upserten lämnar
# allt den inte känner till i fred, och är därför den enda formen som är säker
# att införa mitt i ett projekt.
set -euo pipefail

APP="$1"                       # t.ex. /home/s174280/mimers
ENVFIL="$APP/shared/.env"
INKOMMANDE="$APP/shared/.env.inkommande"

# Fragmentet bär MAILGUN_SECRET i klartext och får inte överleva körningen -
# inte heller en som avbryts. Trappen ligger före den första kontrollen just
# därför: varje väg ut härifrån ska ta filen med sig.
trap 'rm -f "$INKOMMANDE"' EXIT

# Fail-closed på båda: en .env som inte finns ska skapas för hand, inte av en
# utrullning som bara känner sju nycklar. Och saknas fragmentet är steget före
# trasigt - då är rätt utfall att stanna, inte att rulla ut mot en gammal env.
[ -r "$ENVFIL" ] || { echo "$ENVFIL saknas eller går inte att läsa. Skapa den för hand, se Pipeline.md § Engångsuppsättning."; exit 1; }
[ -r "$INKOMMANDE" ] || { echo "$INKOMMANDE saknas - steget som skickar secrets kördes inte."; exit 1; }

# Alla rader valideras innan en enda skrivs. En trasig rad ska falla här, med
# .env orörd, och inte halvvägs genom en skrivning.
NYCKLAR=()
VARDEN=()
RAD=0
while IFS= read -r rad || [ -n "$rad" ]; do
  RAD=$((RAD + 1))
  rad="${rad%$'\r'}"
  [ -z "$rad" ] && continue
  case "$rad" in \#*) continue ;; esac
  if ! printf '%s' "$rad" | grep -qE '^[A-Z_][A-Z0-9_]*='; then
    echo "Rad $RAD i fragmentet är inte NYCKEL=värde. Avbryter utan att röra $ENVFIL."
    exit 1
  fi
  namn="${rad%%=*}"
  varde="${rad#*=}"
  # En tom secret betyder oftast att den inte är satt i miljön, inte att värdet
  # ska bli tomt. Att blanka MAILGUN_SECRET i produktion är värre än att låta
  # den gamla raden stå kvar, så tomma värden hoppas över med en notis.
  if [ -z "$varde" ]; then
    echo "  $namn: tom i secrets - hoppas över, raden i .env lämnas som den är."
    continue
  fi
  NYCKLAR+=("$namn")
  VARDEN+=("$varde")
done < "$INKOMMANDE"

if [ "${#NYCKLAR[@]}" -eq 0 ]; then
  echo "Inga nycklar att skriva. $ENVFIL orörd."
  exit 0
fi

# Backup före varje skrivning. .env är den enda filen på servern som inte finns
# någon annanstans - APP_KEY går inte att generera om utan att varje krypterad
# kolumn och varje signerad länk blir obrukbar.
STAMPEL="$(date -u '+%Y%m%d%H%M%S')"
cp -p "$ENVFIL" "$ENVFIL.bak.$STAMPEL"
ls -1t "$APP/shared/".env.bak.* 2>/dev/null | tail -n +6 | xargs -r rm -f

TMP="$(mktemp "$APP/shared/.env.ny.XXXXXX")"
chmod 600 "$TMP"
cp -p "$ENVFIL" "$TMP"

for i in "${!NYCKLAR[@]}"; do
  namn="${NYCKLAR[$i]}"
  varde="${VARDEN[$i]}"
  # Värdet går via miljön och aldrig genom sed-uttrycket: ett lösenord kan
  # innehålla &, / och \, som alla betyder något i en sed-ersättning. awk läser
  # det ur ENVIRON och kopierar det tecken för tecken.
  if grep -qE "^[[:space:]]*${namn}=" "$TMP"; then
    NAMN="$namn" VARDE="$varde" awk '
      BEGIN { namn = ENVIRON["NAMN"]; varde = ENVIRON["VARDE"] }
      $0 ~ "^[[:space:]]*" namn "=" { print namn "=" varde; next }
      { print }
    ' "$TMP" > "$TMP.2" && mv "$TMP.2" "$TMP"
    echo "  $namn: uppdaterad"
  else
    printf '%s=%s\n' "$namn" "$varde" >> "$TMP"
    echo "  $namn: tillagd"
  fi
done

# Sista rimlighetskontrollen innan filen byter plats: en .env utan APP_KEY är
# en app som inte startar, och det ska inte gå att åstadkomma härifrån.
if ! grep -qE '^[[:space:]]*APP_KEY=.+' "$TMP"; then
  echo "Resultatet saknar APP_KEY. Skriver INTE. Backupen ligger kvar som $ENVFIL.bak.$STAMPEL."
  rm -f "$TMP"
  exit 1
fi

# mv är en rename inom samma filsystem och därmed atomiskt: appen läser antingen
# den gamla filen eller den nya, aldrig en halvskriven. Samma resonemang som
# .htaccess-bytet i deploy.sh.
chmod 600 "$TMP"
mv -f "$TMP" "$ENVFIL"

echo "$ENVFIL uppdaterad ur miljöns secrets. Backup: $ENVFIL.bak.$STAMPEL"
