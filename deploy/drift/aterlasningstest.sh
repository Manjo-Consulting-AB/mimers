#!/usr/bin/env bash
#
# Återläsningstestet — issue 44, sista delen av driftkedjan. Körs kvartalsvis
# på utvecklings-VPS:en och bevisar att backupen går att läsa tillbaka innan
# någon behöver den ([[ADR-0015 Backup]] § Konsekvenser: "Testad återläsning
# kvartalsvis"). En backup som aldrig återlästs är en förhoppning.
#
# Till skillnad från hamta-backup.sh och vakt.sh pushar det här skriptet ALLTID
# — vid framgång och vid fel (issue 44 § Beslut 9). En kvartalsvis kontroll vars
# hela värde ligger i att en människa vet att den gjordes ska höras av, och den
# kommer fyra gånger om året, inte varje timme.
#
# Ordningen (Beslut 6):
#   1. senaste dumpen ur restic, till en temporär fil
#   2. skräpdatabasen skapas på VPS:ens lokala MariaDB och dumpen läses in
#   3. antalet tabeller räknas och radantalen i user, account, container och
#      item mäts
#   4. restic check --read-data-subset=5% mot hela repot
#   5. skräpdatabasen släpps — i en EXIT-trap, så att den försvinner även när
#      något går fel
#   6. $STATE_DIR/aterlasning.ok skrivs och resultatet pushas
#
# Skräpdatabasens namn måste matcha ^mimers_restore_test (Beslut 7): ett test
# som pekas mot produktionsdatabasen skriver över den, och den enda garantin
# som håller klockan två på natten är en som skriptet självt bär. Fel namn ger
# exit 64, före varje anrop.
#
# Radantalen skrivs till $STATE_DIR/aterlasning.counts och jämförs med förra
# kvartalets. Ett tal som sjunkit mer än 10 % är ett larm — antingen är dumpen
# trasig eller så har något raderats uppströms. Ett tal som är noll är däremot
# inget fel: systemet är i förproduktion och har inga kunder (Beslut 8).
#
# Konfigurationen läses från $BACKUP_ENV (default
# ~/.config/mimers-backup/backup.env), samma fil som hamta-backup.sh och
# vakt.sh läser. Obligatoriska nycklar: RESTIC_REPOSITORY, RESTIC_PASSWORD_FILE,
# STATE_DIR, NOTIFY_CMD. Valfria nycklar: RESTORE_TEST_DB (förval
# mimers_restore_test), MIN_TABLES (förval 25), RESTIC och MYSQL (förval restic
# och mysql — krokarna som låter tester byta kommando), MYSQL_EXTRA
# (ytterligare argument till mysql, t.ex. en --defaults-extra-file när den
# användare cron kör som saknar eget konto i VPS:ens MariaDB).
#
# Installeras på VPS:en, t.ex. som ~/bin/aterlasningstest.sh med chmod 700.
# Cron-raden installeras inte här; den står i runbooken (issue 44):
#
#   0 5 1 1,4,7,10 *  $HOME/bin/aterlasningstest.sh
set -euo pipefail

BACKUP_ENV="${BACKUP_ENV:-$HOME/.config/mimers-backup/backup.env}"
if [ ! -r "$BACKUP_ENV" ]; then
  echo "aterlasningstest: kan inte läsa konfigurationen $BACKUP_ENV" >&2
  exit 1
fi
# shellcheck source=/dev/null
. "$BACKUP_ENV"

RESTIC="${RESTIC:-restic}"
MYSQL="${MYSQL:-mysql}"
MIN_TABLES="${MIN_TABLES:-25}"
RESTORE_TEST_DB="${RESTORE_TEST_DB:-mimers_restore_test}"

# Extra argument till mysql-klienten, vanligen tomt. Uppsättningen ovan — ett
# naket `mysql` över unix-socket som den användare cron kör som — är förvalet;
# MYSQL_EXTRA låter en installation välja ett särskilt konto utan att koden
# ändras. read-raden tål en tom sträng (read ger 1 på tom indata).
read -r -a MYSQL_EXTRA_ARGS <<< "${MYSQL_EXTRA:-}" || true

fel() {
  # $1 = exit-kod, resten = meddelande på en rad. Meddelandet skrivs på stderr
  # och pushas till NOTIFY_CMD. Det innehåller aldrig ett lösenord eller ett
  # värde ur backup.env: bara vad som gick fel och mätvärden. Till skillnad
  # från hamta-backup.sh och vakt.sh pushar också felvägen (Beslut 9).
  local rc="${1:-1}"
  shift
  echo "aterlasningstest: $*" >&2
  if [ -x "${NOTIFY_CMD:-}" ]; then
    "$NOTIFY_CMD" "$*" "Mimers återläsning" >/dev/null 2>&1 || true
  fi
  exit "$rc"
}

# Namnskyddet före allt annat, innan något anrop görs (Beslut 7): ett
# RESTORE_TEST_DB som inte börjar på mimers_restore_test är sannolikt en
# produktionsdatabas. Hela namnet måste dessutom bestå av [A-Za-z0-9_] så att
# det är säkert att baka in i SQL längre ner.
case "$RESTORE_TEST_DB" in
  mimers_restore_test*)
    case "$RESTORE_TEST_DB" in
      *[!A-Za-z0-9_]*) fel 64 "RESTORE_TEST_DB får bara innehålla bokstäver, siffror och understreck" ;;
    esac
    ;;
  *) fel 64 "RESTORE_TEST_DB matchar inte ^mimers_restore_test — vägrar röra en databas med ett annat namn" ;;
esac

OBLIGATORISKA="RESTIC_REPOSITORY RESTIC_PASSWORD_FILE STATE_DIR NOTIFY_CMD"
for nyckel in $OBLIGATORISKA; do
  if [ -z "${!nyckel:-}" ]; then
    fel 1 "konfigurationen saknar obligatorisk nyckel: $nyckel"
  fi
done

case "$MIN_TABLES" in
  ''|*[!0-9]*) fel 1 "MIN_TABLES måste vara ett heltal" ;;
esac

export RESTIC_REPOSITORY RESTIC_PASSWORD_FILE

mkdir -p "$STATE_DIR" \
  || fel 1 "kunde inte skapa tillståndskatalogen"

TMP=""
DB_SKAPAD=0

stada() {
  # Körs vid varje utväg: den temporära dumpen tas bort och skräpdatabasen
  # släpps — även när inläsningen misslyckades (Beslut 6). Städningen sker i en
  # EXIT-trap så att databasen aldrig blir kvar på VPS:en. DROP-raden dämpar
  # sina egna fel: städningen ska inte kunna ändra skriptets exit-kod.
  if [ -n "$TMP" ]; then
    rm -f "$TMP"
  fi
  if [ "$DB_SKAPAD" -eq 1 ]; then
    "$MYSQL" "${MYSQL_EXTRA_ARGS[@]}" -e "DROP DATABASE IF EXISTS \`$RESTORE_TEST_DB\`" >/dev/null 2>&1 || true
  fi
}
trap stada EXIT

TMP="$(mktemp "${TMPDIR:-/tmp}/mimers-aterlasning.XXXXXX")" \
  || fel 1 "kunde inte skapa temporär fil"

# Senaste dump-snapshoten. --tag dump väljer den senaste snapshoten med taggen
# dump: en filer- eller arkiv-snapshot som råkar vara nyast än gårdagens dump
# innehåller ingen /mimers-production.sql. Filnamnet är det --stdin-filename
# som hamta-backup.sh satte (Beslut 6).
"$RESTIC" dump --tag dump latest /mimers-production.sql > "$TMP" \
  || fel $? "restic dump avslutade med kod $?"

"$MYSQL" "${MYSQL_EXTRA_ARGS[@]}" -e \
    "CREATE DATABASE IF NOT EXISTS \`$RESTORE_TEST_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" \
  || fel $? "kunde inte skapa skräpdatabasen"
DB_SKAPAD=1

"$MYSQL" "${MYSQL_EXTRA_ARGS[@]}" "$RESTORE_TEST_DB" < "$TMP" \
  || fel $? "inläsningen av dumpen avslutade med kod $?"

# Antalet tabeller. En avhuggen eller stubblad dump ska synas här, inte vid
# första riktiga återläsningen (Beslut 8). mysql:s stderr dämpas — felet som
# betyder något är att antalet saknas eller understiger MIN_TABLES.
ANTAL_TABELLER="$("$MYSQL" "${MYSQL_EXTRA_ARGS[@]}" -N -B -e \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$RESTORE_TEST_DB'" 2>/dev/null)" \
  || fel $? "kunde inte räkna tabellerna i skräpdatabasen"
case "$ANTAL_TABELLER" in
  ''|*[!0-9]*) fel 1 "tabellantalet gick inte att tolka" ;;
esac
if [ "$ANTAL_TABELLER" -lt "$MIN_TABLES" ]; then
  fel 1 "dumpen gav bara $ANTAL_TABELLER tabeller (gräns $MIN_TABLES) — något är fel"
fi

# Radantalen i de fyra tabeller som bär verksamheten. `user` är reserverat i
# SQL och backtickas därför. Ett tal som är noll är inget fel i sig — systemet
# har inga kunder — men jämförelsen mot förra kvartalet ligger nedan.
METRIKER=(user account container item)
declare -A ANTAL
for tabell in "${METRIKER[@]}"; do
  ANTAL[$tabell]="$("$MYSQL" "${MYSQL_EXTRA_ARGS[@]}" -N -B "$RESTORE_TEST_DB" -e \
      "SELECT COUNT(*) FROM \`$tabell\`" 2>/dev/null)" \
    || fel $? "$tabell: kunde inte räkna raderna i skräpdatabasen"
  case "${ANTAL[$tabell]}" in
    ''|*[!0-9]*) fel 1 "$tabell: radantalet gick inte att tolka" ;;
  esac
done

# Jämförelsen mot förra kvartalets mätning. En minskning på mer än 10 % är ett
# larm: antingen är dumpen trasig eller så har något raderats uppströms. En
# saknad counts-fil är första körningen — då finns inget att jämföra mot, och
# nolltal larmar inte. Minskningen räknas på heltal: ny*10 < forn*9 betyder mer
# än 10 % färre rader.
if [ -r "$STATE_DIR/aterlasning.counts" ]; then
  for tabell in "${METRIKER[@]}"; do
    ny="${ANTAL[$tabell]}"
    forn="$(sed -nE "s/^$tabell=([0-9]+)$/\1/p" "$STATE_DIR/aterlasning.counts" | head -n 1)"
    if [ -n "$forn" ] && [ "$forn" -gt 0 ] && [ $((ny * 10)) -lt $((forn * 9)) ]; then
      fel 1 "$tabell: $ny rader mot $forn förra kvartalet — mer än 10 % färre"
    fi
  done
fi

# Integritetskontrollen mot hela repot: läser tillbaka 5 % av datan. En rimlig
# stickprovskontroll för ett test som går fyra gånger om året (Beslut 6).
"$RESTIC" check --read-data-subset=5% \
  || fel $? "restic check avslutade med kod $?"

# Allt grönt: skriv stämpeln och counts-filen, och pusha. Stämpeln skrivs
# först efter alla kontroller, så en misslyckad körning rör den aldrig.
printf '%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" > "$STATE_DIR/aterlasning.ok" \
  || fel 1 "kunde inte skriva stämpelfilen"
{
  printf 'user=%s\n' "${ANTAL[user]}"
  printf 'account=%s\n' "${ANTAL[account]}"
  printf 'container=%s\n' "${ANTAL[container]}"
  printf 'item=%s\n' "${ANTAL[item]}"
} > "$STATE_DIR/aterlasning.counts" \
  || fel 1 "kunde inte skriva counts-filen"

if [ -x "$NOTIFY_CMD" ]; then
  "$NOTIFY_CMD" \
    "återläsningstest OK: $ANTAL_TABELLER tabeller, user=${ANTAL[user]} account=${ANTAL[account]} container=${ANTAL[container]} item=${ANTAL[item]}" \
    "Mimers återläsning" >/dev/null 2>&1 || true
fi

exit 0
