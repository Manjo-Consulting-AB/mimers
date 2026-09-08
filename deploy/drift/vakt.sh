#!/usr/bin/env bash
#
# Dead man's switch-vakten — 43, tredje delen. Körs varje timme på
# utvecklings-VPS:en och larmar till telefonen när något blivit tyst:
# schemaposterna i produktionen (via ytan GET /drift/heartbeat) och
# backupjobben (via stämpelfilerna som hamta-backup.sh skriver). Riktningen
# är dragning, inte sändning, av samma skäl som i backupkedjan: vakten
# hämtar, produktionen pingar inte utåt. Se [[ADR-0015 Backup]] § Konsekvenser
# ("Dead man's switch") och issue 43 § Beslut 1.
#
# Ett larm går när NÅGOT av det här gäller (issue 43 § Beslut 9):
#   - en backupstämpel saknas eller är äldre än sin maxålder i BACKUP_MAXAGE
#   - curl mot DRIFT_URL misslyckas, tar timeout (--max-time) eller svarar
#     något annat än 200 — inklusive 404, som betyder fel token eller ingen
#     token i produktionen
#   - svaret går inte att tolka som JSON
#   - ett namn i DRIFT_JOBS saknas i svaret eller har en last_success_at
#     äldre än sin maxålder
# Tystnad är alltså också ett fel: en switch som bara larmar när den får ett
# felmeddelande upptäcker inte maskinen som slutat prata.
#
# Larmdämpning per nyckel (Beslut 10): en push per nyckel (backup:dump,
# jobb:deliver-notifications, yta) skickas högst en gång per ALARM_COOLDOWN_MIN.
# Tillståndet ligger som en fil per nyckel under $STATE_DIR/larm/. Går en
# nyckel som larmat tillbaka till grönt skickas ETT meddelande om det och
# filen tas bort.
#
# Veckopulsen (Beslut 11): är allt grönt och $STATE_DIR/puls.ok äldre än
# PULSE_DAYS skickas en kort "allt grönt"-push och filen uppdateras. Det är
# motmedlet mot den självhostade vaktens enda verkliga svaghet — dör VPS:en
# larmar ingen — och kostar en push i veckan.
#
# Meddelandena bär jobbnamn, ålder och HTTP-status. Aldrig hemligheter
# (Beslut 12): inte token, inte URL med token, inte innehåll ur backup.env.
#
# Konfigurationen läses från $BACKUP_ENV (default
# ~/.config/mimers-backup/backup.env), samma fil som hamta-backup.sh läser.
# Nycklarna som hör till vakten: DRIFT_URL, DRIFT_TOKEN, DRIFT_JOBS
# (namn:maxålder i minuter, mellanslagsseparerat), BACKUP_MAXAGE
# (verb:maxålder), ALARM_COOLDOWN_MIN, PULSE_DAYS, STATE_DIR, NOTIFY_CMD.
# Tabellen är data, inte kod: en schemapost till att bevaka är en token till
# i DRIFT_JOBS, aldrig en ny release.
set -euo pipefail

BACKUP_ENV="${BACKUP_ENV:-$HOME/.config/mimers-backup/backup.env}"
if [ ! -r "$BACKUP_ENV" ]; then
  echo "vakt: kan inte läsa konfigurationen $BACKUP_ENV" >&2
  exit 1
fi
# shellcheck source=/dev/null
. "$BACKUP_ENV"

CURL="${CURL:-curl}"

fel() {
  # $1 = exit-kod, resten = meddelande. Används för fel som gör att vakten
  # inte kan köra alls (trasig konfiguration), inte för själva larmen.
  local rc="${1:-1}"
  shift
  echo "vakt: $*" >&2
  if [ -x "${NOTIFY_CMD:-}" ]; then
    "$NOTIFY_CMD" "$*" "Mimers drift" >/dev/null 2>&1 || true
  fi
  exit "$rc"
}

OBLIGATORISKA="DRIFT_URL DRIFT_TOKEN DRIFT_JOBS BACKUP_MAXAGE ALARM_COOLDOWN_MIN PULSE_DAYS STATE_DIR NOTIFY_CMD"
for nyckel in $OBLIGATORISKA; do
  if [ -z "${!nyckel:-}" ]; then
    fel 1 "konfigurationen saknar obligatorisk nyckel: $nyckel"
  fi
done

case "$ALARM_COOLDOWN_MIN" in
  ''|*[!0-9]*) fel 1 "ALARM_COOLDOWN_MIN måste vara ett heltal" ;;
esac
case "$PULSE_DAYS" in
  ''|*[!0-9]*) fel 1 "PULSE_DAYS måste vara ett heltal" ;;
esac

STATE_DIR="$(echo "$STATE_DIR" | sed 's:/*$::')"
LARM_KAT="$STATE_DIR/larm"
CURL_MAX_TIME="${CURL_MAX_TIME:-30}"

mkdir -p "$LARM_KAT" \
  || fel 1 "kunde inte skapa tillståndskatalogen $LARM_KAT"

RODA_DIR=""
KROPP=""
stada() {
  if [ -n "$KROPP" ]; then
    rm -f "$KROPP"
  fi
  if [ -n "$RODA_DIR" ]; then
    rm -rf "$RODA_DIR"
  fi
}
trap stada EXIT
RODA_DIR="$(mktemp -d "${TMPDIR:-/tmp}/mimers-vakt-rod.XXXXXX")"
KROPP="$(mktemp "${TMPDIR:-/tmp}/mimers-vakt-kropp.XXXXXX")"

shopt -s nullglob

larma() {
  # $1 = nyckel (backup:dump, jobb:deliver-notifications, yta), $2 = meddelande.
  # Markerar nyckeln som röd den här körningen och pushar — men aldrig oftare
  # än en gång per ALARM_COOLDOWN_MIN per nyckel (Beslut 10). Filens mtime är
  # senaste push; rör den inte när cooldownen är aktiv, så att nästa push kan
  # gå när fönstret löpt ut.
  local nyckel="$1" meddelande="$2" fil="$LARM_KAT/$1"
  touch "$RODA_DIR/$nyckel"
  if [ -f "$fil" ] && [ -n "$(find "$fil" -mmin "-$ALARM_COOLDOWN_MIN" 2>/dev/null)" ]; then
    return 0
  fi
  if [ -x "$NOTIFY_CMD" ]; then
    "$NOTIFY_CMD" "$meddelande" "Mimers drift" >/dev/null 2>&1 || true
  fi
  printf '%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" > "$fil" 2>/dev/null || true
}

epok() {
  # ISO-8601 (med eller utan Z) till epok. Tom eller otolkbar inmatning ger
  # tom utdata — GNU date tolkar annars en tom sträng som "nu", vilket skulle
  # få en trasig kropp att se färsk ut i stället för att larma.
  if [ -z "$1" ]; then
    return 0
  fi
  date -u -d "$1" +%s 2>/dev/null || true
}

backup_stamplar() {
  local post verb maxalder fil stampel ts_epok alder_min
  for post in $BACKUP_MAXAGE; do
    verb="${post%%:*}"
    maxalder="${post##*:}"
    fil="$STATE_DIR/$verb.ok"
    if [ ! -f "$fil" ]; then
      larma "backup:$verb" "$verb: stämpelfilen saknas — backupen har inte kört"
      continue
    fi
    stampel="$(cat "$fil")"
    ts_epok="$(epok "$stampel")"
    if [ -z "$ts_epok" ]; then
      larma "backup:$verb" "$verb: stämpelfilen går inte att tolka"
      continue
    fi
    alder_min=$(( (NU_EPOK - ts_epok) / 60 ))
    if [ "$alder_min" -gt "$maxalder" ]; then
      larma "backup:$verb" "$verb: senaste körningen är $alder_min minuter gammal (gräns $maxalder minuter)"
    fi
  done
}

json_varde() {
  # $1 = nyckel. Skriver strängvärdet ur JSON-kroppen på stdout; tomt om
  # nyckeln saknas eller kroppen inte är JSON. En nyckel per rad räcker:
  # svaret är en rad per post, och den enda kollisionen (ett jobb som heter
  # "now") finns inte.
  #
  # `|| true` — under pipefail ger grep utan träff (eller med stängd pipe)
  # en icke-noll status, och utan utfallet skulle varje saknad nyckel få hela
  # vakten att dö under set -e i stället för att larma.
  grep -o "\"$1\"[[:space:]]*:[[:space:]]*\"[^\"]*\"" "$KROPP" \
    | head -n 1 \
    | sed -E 's/^.*:[[:space:]]*"([^"]*)".*$/\1/' \
    || true
}

kolla_jobb() {
  # $1 = ytans now som epok. Alla åldrar räknas mot produktionens egen klocka,
  # inte VPS:ens — klockskev mellan maskinerna ska inte kunna larma.
  local post namn maxalder ts ts_epok alder_min
  for post in $DRIFT_JOBS; do
    namn="${post%%:*}"
    maxalder="${post##*:}"
    ts="$(json_varde "$namn")"
    if [ -z "$ts" ]; then
      larma "jobb:$namn" "$namn: saknas i svaret från ytan"
      continue
    fi
    ts_epok="$(epok "$ts")"
    if [ -z "$ts_epok" ]; then
      larma "jobb:$namn" "$namn: tidsstämpeln går inte att tolka"
      continue
    fi
    alder_min=$(( (YTA_NOW_EPOK - ts_epok) / 60 ))
    if [ "$alder_min" -gt "$maxalder" ]; then
      larma "jobb:$namn" "$namn: senaste lyckade körningen är $alder_min minuter gammal (gräns $maxalder minuter)"
    fi
  done
}

# --- Kontroller ------------------------------------------------------------
NU_EPOK="$(date -u +%s)"

backup_stamplar

YTA_NOW_EPOK=""
HTTP_KOD=""
curl_rc=0
HTTP_KOD="$("$CURL" -sS --max-time "$CURL_MAX_TIME" -o "$KROPP" \
    -w '%{http_code}' -H "X-Drift-Token: $DRIFT_TOKEN" "$DRIFT_URL" 2>/dev/null)" \
  || curl_rc=$?
if [ "$curl_rc" -ne 0 ]; then
  larma yta "ytan gick inte att nå: curl avslutade med kod $curl_rc"
elif [ "$HTTP_KOD" != "200" ]; then
  larma yta "ytan svarade HTTP $HTTP_KOD"
else
  YTA_NOW="$(json_varde now)"
  YTA_NOW_EPOK="$(epok "$YTA_NOW")"
  if [ -z "$YTA_NOW_EPOK" ]; then
    larma yta "svaret från ytan går inte att tolka som JSON"
  else
    kolla_jobb
  fi
fi

# --- Återställningar och veckopuls -----------------------------------------
# En nyckel som larmat en tidigare körning men är grön nu får ETT
# återställningsmeddelande, och larmfilen tas bort (Beslut 10).
for fil in "$LARM_KAT"/*; do
  nyckel="$(basename "$fil")"
  if [ ! -f "$RODA_DIR/$nyckel" ]; then
    if [ -x "$NOTIFY_CMD" ]; then
      "$NOTIFY_CMD" "$nyckel är grönt igen" "Mimers drift" >/dev/null 2>&1 || true
    fi
    rm -f "$fil"
  fi
done

# Veckopulsen går bara när ingenting är rött (Beslut 11).
if [ -z "$(ls -A "$LARM_KAT")" ]; then
  if [ ! -f "$STATE_DIR/puls.ok" ] \
    || [ -z "$(find "$STATE_DIR/puls.ok" -mmin "-$((PULSE_DAYS * 1440))" 2>/dev/null)" ]; then
    if [ -x "$NOTIFY_CMD" ]; then
      "$NOTIFY_CMD" "allt grönt: schemaposter och backup svarar" "Mimers drift" >/dev/null 2>&1 || true
    fi
    touch "$STATE_DIR/puls.ok"
  fi
fi

# Exit-koden speglar om något fortfarande är rött — även en push som
# cooldownen dämpat lämnar kvar sin larmfil.
if [ -n "$(ls -A "$LARM_KAT")" ]; then
  exit 1
fi
exit 0
