#!/usr/bin/env bash
#
# Hämtar hem Mimers backup från produktionsservern hos inleed — 42b, andra
# halvan av issue 42. Riktningen är hela poängen: backupmålet hämtar,
# produktionsservern skickar inte. Inga uppgifter till backupen finns hos
# inleed — nyckel, restic-lösenord och repo bor på utvecklings-VPS:en.
# Se [[ADR-0015 Backup]] § Konsekvenser ("Dra, skicka inte").
#
# Tre verb, tre cron-rader i runbooken (issue 44):
#   hamta-backup.sh dump    dagligen — databasdump över ssh, in i restic
#   hamta-backup.sh filer   veckovis — rsync av filerna till FILES_DIR
#   hamta-backup.sh arkiv   månadsvis — restic-snapshot av FILES_DIR
#
# Efter ett fullständigt lyckat verb skrivs $STATE_DIR/<verb>.ok med en rad
# UTC i ISO-8601. Dead man's switchen i issue 43 läser stämpelfilerna; en
# misslyckad körning rör aldrig sin stämpel.
#
# Konfigurationen läses från $BACKUP_ENV (default
# ~/.config/mimers-backup/backup.env), installerad med läge 600. Vilka nycklar
# som är obligatoriska står i issue 42b § Beslut 2. restic-lösenordet skapas
# av installatören i filen RESTIC_PASSWORD_FILE — skriptet läser det aldrig
# själv, ekar det inte och skickar det inte i något larm.
#
# Installeras på VPS:en, t.ex. som ~/bin/hamta-backup.sh med chmod 700.
# Cron-radena installeras inte här; de står i runbooken, issue 44. Verben går
# att köra för hand:
#
#   deploy/drift/hamta-backup.sh dump
#   deploy/drift/hamta-backup.sh filer
#   deploy/drift/hamta-backup.sh arkiv
set -euo pipefail

VERB="${1:-}"

anvandning() {
  echo "Användning: hamta-backup.sh dump|filer|arkiv" >&2
  echo "  dump   daglig databasdump: ssh med verbet dump, in i restic" >&2
  echo "  filer  veckovis filsynk mot FILES_DIR (rsync, ingen raderingsflagga)" >&2
  echo "  arkiv  månatlig restic-snapshot av FILES_DIR" >&2
  echo "Konfigurationen läses från BACKUP_ENV (default ~/.config/mimers-backup/backup.env)." >&2
}

case "$#" in
  1) ;;
  *) anvandning; exit 1 ;;
esac

case "$VERB" in
  dump|filer|arkiv) ;;
  *) anvandning; exit 1 ;;
esac

# --- Konfiguration ---------------------------------------------------------
BACKUP_ENV="${BACKUP_ENV:-$HOME/.config/mimers-backup/backup.env}"
if [ ! -r "$BACKUP_ENV" ]; then
  echo "hamta-backup: kan inte läsa konfigurationen $BACKUP_ENV" >&2
  exit 1
fi
# shellcheck source=/dev/null
. "$BACKUP_ENV"

fel() {
  # $1 = exit-kod, resten = meddelande på en rad. Meddelandet skrivs på stderr
  # och skickas till NOTIFY_CMD — finns kommandot inte har stderr-raden redan
  # skrivits, och körningen förblir icke-noll. Meddelandet innehåller aldrig
  # ett lösenord eller ett värde ur backup.env: bara verbet, vad som gick fel
  # och exit-koden. Issue 42b § Beslut 10.
  local rc="${1:-1}"
  shift
  echo "hamta-backup: $*" >&2
  if [ -x "${NOTIFY_CMD:-}" ]; then
    "$NOTIFY_CMD" "$*" "Mimers backup" >/dev/null 2>&1 || true
  fi
  exit "$rc"
}

# Alla nycklar krävs för alla verb: samma konfigurationsfil ligger på en
# maskin och varje verb kan köras därifrån. Valideringen sker före något
# annat, så att ett fel namnger nyckeln utan att blanda in värden.
OBLIGATORISKA="SSH_HOST SSH_PORT SSH_USER SSH_KEY SSH_KNOWN_HOSTS REMOTE_FILES RESTIC_REPOSITORY RESTIC_PASSWORD_FILE FILES_DIR STATE_DIR MIN_FREE_MB NOTIFY_CMD"
for nyckel in $OBLIGATORISKA; do
  if [ -z "${!nyckel:-}" ]; then
    fel 1 "$VERB: konfigurationen saknar obligatorisk nyckel: $nyckel"
  fi
done

# De externa kommandona läses ur variabler så att hela skriptet går att köra
# mot stubbar i stället för mot produktionen — issue 42b § Beslut 3. `df` finns
# inte med i Beslut 3 men behöver samma krok: diskvakten (Beslut 8) ska vara
# deterministiskt testbar.
SSH="${SSH:-ssh}"
RESTIC="${RESTIC:-restic}"
RSYNC="${RSYNC:-rsync}"
DF="${DF:-df}"

# restic läser repo och lösenord ur miljön, aldrig från kommandoraden.
export RESTIC_REPOSITORY RESTIC_PASSWORD_FILE

# ssh-raden till rsyncs -e. Samma flaggor som dump-anropet nedan: -4 eftersom
# VPS:en saknar IPv6-route ([[Deploy/Pipeline]] § Kör ssh med -4), BatchMode
# så att saknat nyckelmaterial blir ett fel i stället för en prompt som hänger
# i en cron, och pinnad known_hosts så att backupen aldrig tyst accepterar en
# ny värdnyckel.
RSYNC_SSH="$SSH -4 -p $SSH_PORT -i $SSH_KEY -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=$SSH_KNOWN_HOSTS"

stampel() {
  # En rad UTC i ISO-8601 med sekundupplösning, ingenting annat. Kontraktet
  # mot dead man's switchen i issue 43.
  local verb="$1"
  if ! printf '%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" > "$STATE_DIR/$verb.ok"; then
    fel 1 "$verb: kunde inte skriva stämpelfilen"
  fi
}

fritt_mb() {
  # Ledigt utrymme i MB på filsystemet som innehåller $1. Finns sökvägen inte
  # ännu vandrar vi upp till närmaste existerande katalog.
  local sokvag="$1"
  local katalog="$sokvag"
  while [ ! -e "$katalog" ] && [ "$katalog" != "/" ]; do
    katalog="$(dirname "$katalog")"
  done
  "$DF" -Pk "$katalog" 2>/dev/null | awk 'NR == 2 { print int($4 / 1024) }'
}

diskvakt() {
  # Körs före varje verb, innan något hämtas. VPS:en har ~9,8 GB disk och kör
  # dessutom agentkön; en synk som fyller disken tar ner både backupen och
  # kön. Att fylla disken halvvägs genom en synk är sämre än att inte börja.
  # Issue 42b § Beslut 8.
  local verb="$1"
  local sokvag ledigt_mb lagst_mb=0
  for sokvag in "$RESTIC_REPOSITORY" "$FILES_DIR"; do
    if ! ledigt_mb="$(fritt_mb "$sokvag")"; then
      fel 1 "$verb: kunde inte avläsa ledigt diskutrymme"
    fi
    case "$ledigt_mb" in
      ''|*[!0-9]*) fel 1 "$verb: kunde inte avläsa ledigt diskutrymme" ;;
    esac
    if [ "$lagst_mb" -eq 0 ] || [ "$ledigt_mb" -lt "$lagst_mb" ]; then
      lagst_mb="$ledigt_mb"
    fi
  done
  if [ "$lagst_mb" -lt "$MIN_FREE_MB" ]; then
    fel 1 "$verb: för lite fritt diskutrymme — avbryter före hämtning"
  fi
}

verb_dump() {
  diskvakt dump

  # Dumpen går via en temporär fil, inte rakt in i restic: ett rör döljer sitt
  # fel, och en avhuggen dump skulle annars sparas som en lyckad snapshot.
  # EXIT-trappen längre ner tar bort filen, även vid avbrott.
  TMP="$(mktemp "${TMPDIR:-/tmp}/mimers-dump.XXXXXX")" \
    || fel 1 "dump: kunde inte skapa temporär fil"

  "$SSH" -4 -p "$SSH_PORT" -i "$SSH_KEY" \
    -o BatchMode=yes \
    -o StrictHostKeyChecking=yes \
    -o UserKnownHostsFile="$SSH_KNOWN_HOSTS" \
    "$SSH_USER@$SSH_HOST" dump > "$TMP" \
    || fel $? "dump: ssh mot servern avslutade med kod $?"

  # mariadb-dumps egen slutmarkör är den sista raden i en hel dump. Saknas den
  # är filen avhuggen oavsett vad ssh:s exit-kod sade — och en avhuggen dump
  # får inte sparas som en lyckad snapshot.
  if ! tail -n 5 "$TMP" | grep -q -- '-- Dump completed'; then
    fel 1 "dump: dumpen saknar slutmarkören — avbryter, restic anropas inte"
  fi

  "$RESTIC" backup --stdin --stdin-filename mimers-production.sql --tag dump < "$TMP" \
    || fel $? "dump: restic backup avslutade med kod $?"

  "$RESTIC" forget --tag dump --keep-daily 30 --keep-weekly 8 --keep-monthly 12 --prune \
    || fel $? "dump: restic forget avslutade med kod $?"

  stampel dump
}

verb_filer() {
  diskvakt filer

  # rsync, med flit utan raderingsflagga: poängen med backupen är skyddet mot
  # buggen som raderar saker i produktion, och en sådan radering skulle
  # annars nå backupen inom ett dygn. Låt backupen växa — filerna är
  # innehållsadresserade och oföränderliga, så en inkrementell synk kostar
  # bara det som tillkommit. Issue 42b § Beslut 6.
  "$RSYNC" -a --partial --human-readable \
    -e "$RSYNC_SSH" \
    "$SSH_USER@$SSH_HOST:$REMOTE_FILES" "$FILES_DIR/" \
    || fel $? "filer: rsync avslutade med kod $?"

  stampel filer
}

verb_arkiv() {
  diskvakt arkiv

  # Månatlig restic-snapshot av det synkade filträdet. Den ger filerna
  # kryptering, integritetskontroll och en tidsaxel som rsync-katalogen inte
  # har. Att den ligger på samma maskin som allt annat är känt — arkivnivån
  # mot en tredje leverantör byggs inte här. Issue 42b § Beslut 7.
  "$RESTIC" backup "$FILES_DIR" --tag arkiv \
    || fel $? "arkiv: restic backup avslutade med kod $?"
  "$RESTIC" forget --tag arkiv --keep-monthly 12 --prune \
    || fel $? "arkiv: restic forget avslutade med kod $?"

  stampel arkiv
}

# --- Lås och körning -------------------------------------------------------
mkdir -p "$STATE_DIR" \
  || fel 1 "$VERB: kunde inte skapa tillståndskatalogen"

TMP=""
LOCK_DIR=""

stada() {
  if [ -n "$TMP" ]; then
    rm -f "$TMP"
  fi
  if [ -n "$LOCK_DIR" ]; then
    rmdir "$LOCK_DIR" 2>/dev/null || true
  fi
}
trap stada EXIT

# Ett lås per skript, inte per verb. Startar veckosynken medan den dagliga
# dumpen fortfarande hämtar ska den andra avsluta 0 med en rad på stderr —
# inte larma, inte köa. Issue 42b § Beslut 11.
if ! mkdir "$STATE_DIR/lock" 2>/dev/null; then
  echo "hamta-backup: $VERB: ett annat anrop pågår ($STATE_DIR/lock finns) — avslutar utan åtgärd." >&2
  exit 0
fi
LOCK_DIR="$STATE_DIR/lock"

case "$VERB" in
  dump)  verb_dump ;;
  filer) verb_filer ;;
  arkiv) verb_arkiv ;;
esac
