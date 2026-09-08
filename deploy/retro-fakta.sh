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

VERSION="4"   # höjs vid varje ändring, så att glapp mot repot syns i utdatan

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
OFARLIGA="APP_ENV APP_DEBUG APP_URL QUEUE_CONNECTION MAIL_MAILER MAIL_FROM_ADDRESS MAILGUN_DOMAIN MAILGUN_ENDPOINT DB_CONNECTION DB_HOST DB_DATABASE FILESYSTEM_DISK FILES_INTERNAL_REDIRECT EXPORT_RETENTION_DAYS TRASH_RETENTION_DAYS SESSION_DRIVER CACHE_STORE LOG_CHANNEL"

rubrik() { printf '\n== %s ==\n' "$1"; }

# Crontabben läses en gång, före första avsnittet som behöver den. Utdata och
# felkod hålls isär: "raden saknas" och "gick inte att läsa" är olika svar.
CRON_UT=""
CRON_RC=0
if command -v crontab >/dev/null 2>&1; then
  CRON_UT=$(crontab -l 2>&1)
  CRON_RC=$?
fi

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
# `mysql` finns kvar som alias men skriver en deprecationsvarning på stderr:
# "Deprecated program name. It will be removed in a future release, use
# '/usr/bin/mariadb' instead". Med 2>&1 hamnade den i svaret och gjorde varje
# fråga till ett fel - v1 rapporterade "databasen svarar inte" mot en databas
# som svarade utmärkt. Därav både klientvalet och att stderr hålls isär från
# svaret nedan.
KLIENT="$(command -v mariadb || command -v mysql || true)"
if [ -z "$KLIENT" ]; then
  echo 'varken mariadb- eller mysql-klienten finns i PATH - kan inte läsa jobs/failed_jobs.'
elif [ -z "$DB_N" ] || [ -z "$DB_U" ]; then
  echo 'DB_DATABASE eller DB_USERNAME saknas i .env - hoppar över databasen.'
else
  # Lösenordet går via MYSQL_PWD och inte som argument: argument syns i ps för
  # alla på en delad maskin. Det skrivs aldrig ut.
  fraga() { MYSQL_PWD="$DB_P" "$KLIENT" -N -B -h "$DB_H" -u "$DB_U" "$DB_N" -e "$1" 2>/dev/null; }
  fragefel() { MYSQL_PWD="$DB_P" "$KLIENT" -N -B -h "$DB_H" -u "$DB_U" "$DB_N" -e "$1" 2>&1 >/dev/null; }
  # Prova anslutningen en gång. Utan det upprepas samma felrad sex gånger, och
  # "databasen svarar inte" ser ut som sex olika problem.
  prov=$(fraga "select 1;")
  if [ "$prov" != "1" ]; then
    printf 'databasen svarar inte: %s\n' "$(fragefel "select 1;" | head -1)"
    DB_N=""
  fi
fi
if [ -n "${DB_N:-}" ] && [ -n "${KLIENT:-}" ]; then
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
# Noll rader i `jobs` betyder INTE att kön töms - det kan lika gärna betyda att
# inget någonsin köats. Frågan "finns det en arbetare" har ett eget svar, och
# utan den raden är radantalet ovan omöjligt att tolka. Se issue 235.
#
# Ordningen är medveten: repot först, cron sist. Arbetaren BOR i
# routes/console.php sedan ADR-0031, och crontabben går inte alltid att läsa
# under den låsta nyckeln - se CRON_UT nedan. En kontroll som frågar cron
# först svarar därför "ingen arbetare" om en läsning misslyckas.
if [ -n "${APP:-}" ]; then
  if [ -r "$APP/current/routes/console.php" ] && grep -q 'queue:work\|queue:listen' "$APP/current/routes/console.php" 2>/dev/null; then
    echo 'köarbetare: schemalagd i routes/console.php'
  elif printf '%s' "${CRON_UT:-}" | grep -F "$APP" | grep -q 'queue:work'; then
    echo 'köarbetare: schemalagd i cron'
  elif pgrep -u "$(id -un)" -f 'queue:work' >/dev/null 2>&1; then
    echo 'köarbetare: en process kör just nu'
  elif [ "${CRON_RC:-1}" -ne 0 ]; then
    echo 'köarbetare: ingen i routes/console.php, och crontabben gick inte att läsa - OBESVARAT'
  else
    echo 'köarbetare: INGEN hittad (varken routes/console.php, cron eller en levande process)'
  fi
fi

# ---------------------------------------------------------------------------
rubrik "Schemaläggning"
# Minutcronen är det som kör Schedule::call-jobben. Faller raden bort slutar
# notiser, gallring och kvotavstämning tyst - inget test ser det.
# `crontab -l` svarar inte likadant i alla sessioner: under nyckeln med
# command= i authorized_keys gav den tom utdata 2026-09-08 medan samma skript
# över ett vanligt skal skrev ut båda raderna. v3 slog ihop det med "ingen
# cron-rad finns", vilket är fel svar på en fråga som inte gick att ställa -
# och den sortens tystnad är precis vad skriptet finns för att undvika. Utdata
# och felkod fångas därför var för sig, och rc != 0 eller tom utdata redovisas
# som obesvarat.
if command -v crontab >/dev/null 2>&1; then
  if [ "$CRON_RC" -ne 0 ]; then
    printf 'crontab -l misslyckades (rc=%s): %s\n' "$CRON_RC" "$(printf '%s' "$CRON_UT" | head -2)"
  elif [ -z "$CRON_UT" ]; then
    echo 'crontab -l gav tom utdata - OBESVARAT, inte samma sak som att raden saknas.'
    echo 'Kör skriptet över ett vanligt skal för att avgöra vilket det är.'
  else
    printf '%s' "$CRON_UT" | grep -F "$APP" || echo "ingen cron-rad nämner $APP"
  fi
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
