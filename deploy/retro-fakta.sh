#!/usr/bin/env bash
#
# Läser av serverns faktiska tillstånd och skriver ut det. Skriver ingenting,
# rör ingen release, och kan inte startas med argument som styr vad den gör mer
# än att välja miljö.
#
# Installeras som ~/bin/retro-fakta, chmod 700, och låses till en nyckel i
# authorized_keys:
#
#   command="/home/s174280/bin/retro-fakta",restrict ssh-ed25519 AAAA... claude-retro
#
# Anropas då som:  ssh -4 -p 2020 -i ~/.ssh/claude-retro s174280@prime5.inleed.net production
#
# Motivering, de två villkoren och vad som INTE får läggas till här:
# docs/ADR/ADR-0029 Agentens läsåtkomst till servern.md
#
# Två regler gäller varje framtida tillägg:
#   1. Inga hemliga värden i utdatan. shared/.env dumpas aldrig - nyckelnamn
#      listas, värden skrivs bara ut för OFARLIGA nedan.
#   2. Laravel bootas inte. Ren shell plus mysql-klienten. Skriptet måste
#      fungera när appen inte gör det; en halv utrullning är precis det läge
#      man vill kunna läsa av, och php artisan svarar då inte.
#
# set -e är med flit INTE satt. Varje avsnitt ska kunna misslyckas utan att ta
# resten med sig - en tom jobs-tabell och en trasig databas ser annars likadana
# ut, nämligen som ingen utskrift alls.
set -uo pipefail

VERSION="1"   # höjs vid varje ändring, så att glapp mot repot syns i utdatan

# Miljön kommer från klientens kommando, som med command= i authorized_keys
# hamnar i SSH_ORIGINAL_COMMAND i stället för att köras. Den valideras mot
# exakt två värden - allt annat avvisas. Det här är skriptets enda indata.
VAL="${1:-${SSH_ORIGINAL_COMMAND:-}}"
VAL="${VAL%% *}"

case "$VAL" in
  production|"") MILJO="production"; APP="$HOME/mimers" ;;
  staging)       MILJO="staging";    APP="$HOME/mimers-staging" ;;
  *)
    echo "Okänd miljö: '$VAL'. Giltiga värden: production, staging." >&2
    exit 64
    ;;
esac

ENVFIL="$APP/shared/.env"

# Nycklar vars värden är ofarliga att skriva ut. Allt utanför listan redovisas
# som satt/saknas. Lägg aldrig till en nyckel här utan att först fråga vad ett
# läckage av den skulle kosta - se ADR-0029.
OFARLIGA="APP_ENV APP_DEBUG APP_URL QUEUE_CONNECTION MAIL_MAILER MAILGUN_DOMAIN MAILGUN_ENDPOINT DB_CONNECTION DB_HOST DB_DATABASE FILESYSTEM_DISK SESSION_DRIVER CACHE_STORE LOG_CHANNEL"

rubrik() { printf '\n== %s ==\n' "$1"; }

# Läser en nyckel ur .env utan att exponera resten av filen. Sista
# förekomsten vinner, som i Laravels egen läsning; citattecken och
# radslutskommentarer skalas av.
envvarde() {
  [ -r "$ENVFIL" ] || return 1
  local rad
  rad=$(grep -E "^[[:space:]]*${1}=" "$ENVFIL" 2>/dev/null | tail -1) || return 1
  [ -n "$rad" ] || return 1
  rad="${rad#*=}"
  rad="${rad%%#*}"
  rad="$(printf '%s' "$rad" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/")"
  printf '%s' "$rad"
}

printf 'retro-fakta v%s · %s · %s\n' "$VERSION" "$MILJO" "$(date -u '+%Y-%m-%d %H:%M:%SZ')"
printf 'värd: %s\n' "$(hostname 2>/dev/null || echo okänd)"

# ---------------------------------------------------------------------------
rubrik "Release"
# readlink är frågan "vad servar just nu", som är något annat än vad senaste
# taggen säger. Skiljer de två åt är utrullningen halv eller current flippad
# till fel katalog - se Pipeline.md § Rollback.
if [ -L "$APP/current" ]; then
  printf 'current -> %s\n' "$(readlink "$APP/current")"
elif [ -e "$APP/current" ]; then
  echo 'current finns men är INTE en symlänk - det är fel form, se Pipeline.md § Kataloglayout.'
else
  echo 'current saknas.'
fi
if [ -d "$APP/releases" ]; then
  printf 'releaser på disk (nyast först): %s\n' "$(ls -1t "$APP/releases" 2>/dev/null | head -5 | tr '\n' ' ')"
  printf 'antal releaser: %s\n' "$(ls -1 "$APP/releases" 2>/dev/null | wc -l | tr -d ' ')"
fi
[ -f "$APP/current/public/index.php" ] && echo 'public/index.php: finns' || echo 'public/index.php: SAKNAS'

# ---------------------------------------------------------------------------
rubrik "Miljö (shared/.env)"
if [ ! -r "$ENVFIL" ]; then
  echo "$ENVFIL går inte att läsa."
else
  printf 'rättigheter: %s\n' "$(ls -l "$ENVFIL" | awk '{print $1, $3, $4}')"
  for nyckel in $OFARLIGA; do
    if varde=$(envvarde "$nyckel"); then
      printf '  %-20s %s\n' "$nyckel" "${varde:-(tomt)}"
    fi
  done
  echo '  --- övriga nycklar, bara namn och om de är satta ---'
  grep -E '^[[:space:]]*[A-Z_][A-Z0-9_]*=' "$ENVFIL" 2>/dev/null \
    | sed -E 's/^[[:space:]]*([A-Z_][A-Z0-9_]*)=(.*)$/\1\t\2/' \
    | while IFS=$'\t' read -r namn varde; do
        case " $OFARLIGA " in *" $namn "*) continue ;; esac
        varde="${varde%%#*}"
        varde="$(printf '%s' "$varde" | tr -d '"'"'"' \t')"
        if [ -n "$varde" ]; then
          # Bara "satt", aldrig längden: teckenantalet på en hemlighet är
          # också en upplysning, och det säger inget diagnostiskt.
          printf '  %-28s satt\n' "$namn"
        else
          printf '  %-28s TOM\n' "$namn"
        fi
      done
fi

# ---------------------------------------------------------------------------
rubrik "Kön"
# Frågan ADR-0029 skrevs för. Två köade jobb är utrullade och ingenting i
# pipelinen startar en arbetare - se issue 235. Raderna nedan skiljer
# "aldrig köad" från "köad och aldrig körd", vilket .env-värdet ensamt inte gör.
KO=$(envvarde QUEUE_CONNECTION || echo '(oläst)')
printf 'QUEUE_CONNECTION: %s\n' "$KO"
DB_H=$(envvarde DB_HOST || echo 127.0.0.1)
DB_N=$(envvarde DB_DATABASE || echo '')
DB_U=$(envvarde DB_USERNAME || echo '')
DB_P=$(envvarde DB_PASSWORD || echo '')
if ! command -v mysql >/dev/null 2>&1; then
  echo 'mysql-klienten saknas i PATH - kan inte läsa jobs/failed_jobs.'
elif [ -z "$DB_N" ] || [ -z "$DB_U" ]; then
  echo 'DB_DATABASE eller DB_USERNAME saknas i .env - hoppar över databasen.'
else
  # Lösenordet går via MYSQL_PWD och inte som argument: argument syns i ps för
  # alla på en delad maskin. Det skrivs aldrig ut.
  fraga() { MYSQL_PWD="$DB_P" mysql -N -B -h "$DB_H" -u "$DB_U" "$DB_N" -e "$1" 2>&1; }
  # Prova anslutningen en gång. Utan det upprepas samma felrad sex gånger, och
  # "databasen svarar inte" ser ut som sex olika problem.
  if ! prov=$(fraga "select 1;") || [ "$prov" != "1" ]; then
    printf 'databasen svarar inte: %s\n' "$(printf '%s' "$prov" | head -1)"
    DB_N=""
  fi
fi
if [ -n "${DB_N:-}" ] && command -v mysql >/dev/null 2>&1; then
  for tabell in jobs failed_jobs; do
    svar=$(fraga "select count(*) from \`$tabell\`;")
    printf '%-12s %s\n' "$tabell" "$svar"
  done
  svar=$(fraga "select coalesce(min(from_unixtime(available_at)), 'inga rader') from jobs;")
  printf 'äldsta jobbet: %s\n' "$svar"
  svar=$(fraga "select coalesce(group_concat(distinct queue), '-') from jobs;")
  printf 'köer med rader: %s\n' "$svar"
  # Migreringsläget utan att boota Laravel: batch-numret räcker för att se om
  # utrullningen kom hela vägen igenom.
  svar=$(fraga "select count(*), max(batch) from migrations;")
  printf 'migreringar (antal, senaste batch): %s\n' "$svar"
  svar=$(fraga "select migration from migrations order by id desc limit 3;" | tr '\n' ' ')
  printf 'tre senaste: %s\n' "$svar"
fi

# ---------------------------------------------------------------------------
rubrik "Schemaläggning"
# Minutcronen är det som kör Schedule::call-jobben. Faller raden bort slutar
# notiser, gallring och kvotavstämning tyst - inget test ser det.
if command -v crontab >/dev/null 2>&1; then
  crontab -l 2>/dev/null | grep -F "$APP" || echo "ingen cron-rad nämner $APP"
else
  echo 'crontab-kommandot saknas.'
fi

# ---------------------------------------------------------------------------
rubrik "Filleverans"
# Symlänken och .htaccess är ADR-0019:s två förutsättningar. Båda läggs av
# deploy.sh vid varje utrullning, alltså är ett bortfall här ett tecken på en
# halv utrullning - inte på en handpåläggning som glömts.
if [ -L "$APP/current/public/_protected" ]; then
  printf '_protected -> %s\n' "$(readlink "$APP/current/public/_protected")"
else
  echo '_protected: SAKNAS eller är ingen symlänk'
fi
HT="$APP/shared/storage/files/.htaccess"
if [ -r "$HT" ]; then
  printf '.htaccess: %s rader, %s\n' "$(wc -l < "$HT" | tr -d ' ')" "$(ls -l "$HT" | awk '{print $1}')"
  # Tillåt-listan är den rad som avgör vilka URI:er som får levereras internt.
  # Exportnedladdningen (41b) ligger utanför den i skrivande stund.
  grep -n 'ORG_REQ_URI' "$HT" 2>/dev/null | head -3
else
  echo '.htaccess: saknas i shared/storage/files/'
fi
if [ -d "$APP/shared/storage/files" ]; then
  printf 'storage/files: %s\n' "$(du -sh "$APP/shared/storage/files" 2>/dev/null | awk '{print $1}')"
  printf 'exports/: %s\n' "$(du -sh "$APP/shared/storage/files/exports" 2>/dev/null | awk '{print $1}' || echo 'finns inte')"
fi

# ---------------------------------------------------------------------------
rubrik "Åtkomst"
# Deployraderna är utrullningens enda väg in, och ett bortfall märks först vid
# nästa release - se Pipeline.md § En röjd nyckel. Bara kommentaren i slutet av
# varje rad skrivs ut; aldrig nyckelmaterial.
AK="$HOME/.ssh/authorized_keys"
if [ -r "$AK" ]; then
  printf '%s rader:\n' "$AK"
  awk '!/^#/ && NF { print "  " $NF }' "$AK"
else
  echo 'authorized_keys går inte att läsa.'
fi

# ---------------------------------------------------------------------------
rubrik "Diskutrymme"
df -h "$HOME" 2>/dev/null | tail -1

# ---------------------------------------------------------------------------
rubrik "Loggens svans"
# Sista raderna räcker för att se om något återkommer. Hela loggen hämtas inte
# hit - den kan innehålla nyttolaster.
LOGG="$APP/shared/storage/logs/laravel.log"
if [ -r "$LOGG" ]; then
  printf '%s, %s, senast ändrad %s\n' "$LOGG" \
    "$(du -sh "$LOGG" 2>/dev/null | awk '{print $1}')" \
    "$(date -u -r "$LOGG" '+%Y-%m-%d %H:%M:%SZ' 2>/dev/null || echo okänt)"
  grep -c 'production.ERROR' "$LOGG" 2>/dev/null | sed 's/^/rader med production.ERROR: /'
  echo '--- tre senaste ERROR-rubrikerna ---'
  grep 'production.ERROR' "$LOGG" 2>/dev/null | tail -3 | cut -c1-200
else
  echo 'ingen laravel.log i shared/storage/logs/'
fi

echo
echo 'Slut. Skriptet skriver inget, ändrar inget och kan inte rulla ut något.'
