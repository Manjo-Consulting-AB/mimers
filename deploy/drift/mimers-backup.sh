#!/usr/bin/env bash
#
# Lämnar ut en konsistent databasdump och användarfilerna, för backup på en egen
# server. Hämtaren körs på den egna servern (42b); det här skriptet skriver
# aldrig något till disk hos inleed utöver en temporär autentiseringsfil under
# ${TMPDIR:-/tmp}. Produktionsservern får aldrig veta vart backupen går, se
# docs/ADR/ADR-0015 Backup.md § Konsekvenser.
#
# Installeras som ~/bin/mimers-backup, chmod 700, och låses till en EGEN nyckel
# i authorized_keys — utöka aldrig retro-fakta med en dump, den nyckeln har en
# helt annan motivering:
#
#   command="/home/s174280/bin/mimers-backup",restrict ssh-ed25519 AAAA... mimers-backup
#
# Klientens kommando hamnar i SSH_ORIGINAL_COMMAND och är skriptets enda indata:
#   dump                        → databasdumpen på stdout, okomprimerad
#   rsync --server --sender …   → den läsande rsync klienten begärt
#   allt annat, inklusive tomt  → exit 64, en rad på stderr, ingenting på stdout
#
# Exit-koder: 64 betyder uteslutande "begäran avvisad" — okänt verb, eller en
# rsync-argv som inte klarar valideringen. Det är ett kontraktsbrott mellan 42a
# och 42b som en människa måste titta på. Allt annat som går fel — saknad .env,
# mktemp som faller, en dump som avbryts — avslutar med 1 eller mariadb-dumps
# egen kod, så att 42b kan skilja "backupen misslyckades" från "nyckeln blev
# ombedd något den inte gör".
#
# Formen — command=-låsningen, den egna nyckeln, att skriptet bor i ~/bin och
# inte installeras av utrullningen — är ADR-0029:s:
# docs/ADR/ADR-0029 Agentens läsåtkomst till servern.md. Installationsstegen
# och runbooken hör hemma i issue 44.
#
# Reglerna, från issue 42:
#   * stdout bär bara nyttolast. All diagnostik går till stderr, hela vägen.
#   * dumpen streamas okomprimerad: restic på hämtarsidan deduplicerar och
#     komprimerar själv, och en gzipad dump skiljer sig i hela sin längd från
#     gårdagens även när databasen knappt ändrats.
#   * lösenordet står aldrig på en kommandorad: det läses ur shared/.env och
#     når mariadb-dump genom en --defaults-extra-file, skapad under umask 077.
#     Den tas bort av en EXIT-trap; HUP/INT/TERM/PIPE fångas för att tvinga
#     fram exit även när hämtaren avbryter mitt i en dump, så att filen med
#     produktionslösenordet inte blir kvar i /tmp på en delad maskin.
#   * Laravel bootas inte. Ren shell plus mariadb-dump — en halv utrullning är
#     precis det läge man vill kunna ta en backup i, och appens artisan-svar
#     finns då inte.
#   * rsync-klienten i 42b kör med --no-protect-args: sökvägen ska ligga i argv
#     som sista token, inte i protokollströmmen, för att kunna valideras här.
#
# set -euo pipefail, till skillnad från retro-fakta.sh: där ska ett avsnitt
# kunna falla utan att ta resten med sig, här är en avbruten dump som avslutar
# 0 det värsta utfallet i hela kedjan. Hämtaren i 42b litar på exit-koden.
set -euo pipefail

VERSION="4"   # höjs vid varje ändring, så att glapp mot repot syns på stderr

# Bara produktion, med flit (Beslut 5). Staging är engångsdata som återskapas
# av en utrullning, och en miljöväljare vore ytterligare en indata på en nyckel
# som redan lämnar ut hela databasen.
APP="$HOME/mimers"
ENVFIL="$APP/shared/.env"
FILKATALOG="$APP/shared/storage/files"

AUTHFIL=""   # sätts i dump-grenen; EXIT-trappen städar den

# Läser en nyckel ur shared/.env utan att exponera resten av filen. Sista
# förekomsten vinner, som i Laravels egen läsning; citattecken skalas av och
# en radslutskommentar tas bort. Värden skrivs aldrig ut härifrån.
envvarde() {
  [ -r "$ENVFIL" ] || return 1
  local rad
  rad=$(grep -E "^[[:space:]]*${1}=" "$ENVFIL" 2>/dev/null | tail -n 1) || return 1
  [ -n "$rad" ] || return 1
  rad="${rad#*=}"

  # Citerade värden först: en # inuti citattecken är data — ett lösenord kan
  # innehålla ett — och får inte klippas av. I ett ociterat värde tar en
  # kommentar vid vid första #, som i Laravels egen läsning (phpdotenv).
  rad="$(printf '%s' "$rad" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
  case "$rad" in
    \'*)
      rad="${rad#\'}"
      rad="${rad%%\'*}"
      ;;
    \"*)
      rad="${rad#\"}"
      rad="${rad%%\"*}"
      ;;
    *)
      rad="${rad%%#*}"
      rad="$(printf '%s' "$rad" | sed -e 's/[[:space:]]*$//')"
      ;;
  esac
  printf '%s' "$rad"
}

# Läser de fyra uppgifterna dumpen behöver. Saknas en av dem avslutar skriptet.
# Ett felmeddelande som upprepar ett läst värde vore en hemlighet i onödan, så
# det gör vi aldrig (ADR-0029 regel 1).
las_db_uppgifter() {
  DB_DATABASE=$(envvarde DB_DATABASE) || {
    echo "mimers-backup: DB_DATABASE saknas i $ENVFIL" >&2
    exit 1
  }
  DB_USERNAME=$(envvarde DB_USERNAME) || {
    echo "mimers-backup: DB_USERNAME saknas i $ENVFIL" >&2
    exit 1
  }
  DB_PASSWORD=$(envvarde DB_PASSWORD) || {
    echo "mimers-backup: DB_PASSWORD saknas i $ENVFIL" >&2
    exit 1
  }
  DB_HOST=$(envvarde DB_HOST) || {
    echo "mimers-backup: DB_HOST saknas i $ENVFIL" >&2
    exit 1
  }
}

# Skriver uppgifterna till en temporär autentiseringsfil och lägger sökvägen i
# AUTHFIL. Lösenordet får aldrig stå på en kommandorad: ps läses av andra på en
# delad maskin. Filen tas bort av en trap när skriptet avslutas.
skapa_authfil() {
  umask 077
  local fil
  fil=$(mktemp "${TMPDIR:-/tmp}/mimers-backup.XXXXXX")
  chmod 600 "$fil"
  {
    printf '[client]\n'
    printf 'user=%s\n' "$DB_USERNAME"
    printf 'password=%s\n' "$DB_PASSWORD"
    printf 'host=%s\n' "$DB_HOST"
  } >"$fil"
  AUTHFIL="$fil"
}

dumpa() {
  # Trapporna sätts före authfilen skapas. En bar EXIT-trap räcker inte: dör
  # bash av en otrappad signal — SIGHUP när ssh-sessionen faller, SIGPIPE när
  # hämtaren avbryter mitt i en dump — körs EXIT-trappen aldrig och filen med
  # produktionslösenordet blir kvar i /tmp. Signaltrappen tvingar bara fram
  # exit, som i sin tur kör EXIT-trappen.
  trap 'rm -f "$AUTHFIL"' EXIT
  trap 'exit 1' HUP INT TERM PIPE
  skapa_authfil
  # --single-transaction ger ett konsistent InnoDB-läge utan att låsa, --quick
  # buffrar inte en hel tabell i minnet på en delad maskin, och --no-tablespaces
  # för att kontot saknar PROCESS-privilegiet.
  if mariadb-dump --defaults-extra-file="$AUTHFIL" --single-transaction --quick \
      --routines --events --triggers --no-tablespaces \
      --default-character-set=utf8mb4 "$DB_DATABASE"; then
    :
  else
    rc=$?
    echo "mimers-backup: mariadb-dump avslutade med kod $rc" >&2
    exit "$rc"
  fi
}

# Rensar en sökväg till kanonisk form: dubbla snedstreck och ./-komponenter tas
# bort. ..-sekvenser har redan avvisats av valideringen. Syftet är att det som
# jämförs mot filkatalogen är exakt det som sedan körs.
rensa_sökväg() {
  local s="$1" n
  while :; do
    n="$(printf '%s\n' "$s" | sed -e 's#//\{1,\}#/#g' -e 's#/\./#/#g' -e 's#^\./##')"
    [ "$n" = "$s" ] && break
    s="$n"
  done
  printf '%s' "$s"
}

# Validerar rsync-kommandot token för token och kör det sedan. Hela poängen med
# command= är att en läckt nyckel inte ska ge ett skal — därför delas strängen
# med read och körs aldrig som kod, och varje token måste ligga i en tillåten
# teckenmängd. Det stänger ;, backticks, $(, > och allt annat som gör en sträng
# till kod.
rsync_gren() {
  local argv=() token sender=0 pat sista avslutande_snedstreck=0
  local tillatna='logDtpre.iLsfxCIvu' b rest
  read -r -a argv <<<"$KOM" || true

  if [ "${argv[0]:-}" != rsync ] || [ "${argv[1]:-}" != --server ]; then
    echo "mimers-backup: rsync-anropet måste börja med 'rsync --server'" >&2
    exit 64
  fi

  # Allowlist, inte blocklist: en okänd flagga är en rsync-funktion med
  # sidoeffekt utanför filkatalogen — --delete* och --remove-source-files
  # skriver, --log-file=… skriver dit klienten pekar, --files-from=… läser
  # godtyckliga sökvägar — så allt utom det kända avvisas här i stället för
  # att jaga flaggor en och en. --server sitter redan i argv[1] (kontrollerad
  # ovan) och --sender är den enda andra långa flaggan en läsande begäran har.
  # Korta flaggor måste vara en enda bokstavsbunt vars tecken finns i den mängd
  # den riktiga rsync-klienten skickar för en läsande överföring; 42b kör med
  # --no-protect-args, så sökvägen ligger i argv som sista token och valideras
  # nedan. -s/--protect-args/--secluded-args kräver en egen gren: de flyttar
  # sökvägen från argv till protokollströmmen och då finns inget kvar att
  # validera — -s är dessutom en teckenbunt som annars skulle passera allow-
  # listen, för s ingår i den mängd den riktiga klienten skickar.
  for token in "${argv[@]:2}"; do
    if [[ ! "$token" =~ ^[-A-Za-z0-9._/=:,+@]+$ ]]; then
      echo "mimers-backup: otillåten token i rsync-kommandot" >&2
      exit 64
    fi
    if [ "$token" = --sender ]; then
      sender=1
      continue
    fi
    case "$token" in
      -s|--protect-args|--secluded-args)
        echo "mimers-backup: rsync får inte köras med skyddade argument ('$token') — klienten måste skicka sökvägen i argv, med --no-protect-args" >&2
        exit 64
        ;;
    esac
    if [[ "$token" == --* ]]; then
      echo "mimers-backup: rsync får inte köras med flaggan '$token' — enda tillåtna långa flaggorna är --server och --sender" >&2
      exit 64
    fi
    if [[ "$token" == -* ]]; then
      b="${token#-}"
      rest="${b//[$tillatna]/}"
      if [ -n "$rest" ]; then
        echo "mimers-backup: rsync får inte köras med flaggan '$token' — varje tecken i en kort flaggbunt måste finnas i $tillatna" >&2
        exit 64
      fi
    fi
  done

  if [ "$sender" -ne 1 ]; then
    echo "mimers-backup: --sender saknas — utan den är anropet en skrivning" >&2
    exit 64
  fi

  # Kontraktet med 42b är en enda källa: rsync --server --sender <flaggor> . <sökväg>.
  # Allt som inte börjar på - är ett positionsargument, och det får finnas exakt
  # två: käll-markören "." och sökvägen. Fler positionsargument vore ytterligare
  # käll-sökvägar — rsync läser dem allihop — och då kunde en läckt nyckel läsa
  # godtyckliga filer på servern, inte bara filkatalogen. Att bara validera sista
  # token räcker därför inte: även en andra markör (". X . <sökväg>") vore en
  # extra källa.
  local positionella=()
  for token in "${argv[@]:2}"; do
    [[ "$token" == -* ]] || positionella+=("$token")
  done
  if [ "${#positionella[@]}" -ne 2 ] || [ "${positionella[0]}" != . ]; then
    echo "mimers-backup: rsync-anropet får inte ha fler än en källsökväg — formatet är 'rsync --server --sender <flaggor> . <sökväg>'" >&2
    exit 64
  fi

  # Sista token är sökvägen (kontraktet med 42b: klienten kör --no-protect-args).
  # sshd startar forced command i hemkatalogen, men skriptet litar inte på cwd:
  # sökvägen görs absolut och kanonisk och skrivs tillbaka i argv före exec, så
  # att det som validerades är exakt det som körs. En avslutande / bevaras —
  # --sender behandlar files/ (katalogens innehåll) och files (katalogen som en
  # nivå hos mottagaren) olika, och skriptet ska inte tyst byta trädets form.
  sista="${argv[${#argv[@]}-1]}"
  if [[ "$sista" == */ ]]; then
    avslutande_snedstreck=1
    while [[ "$sista" == */ ]]; do sista="${sista%/}"; done
  fi
  pat="$sista"
  case "$pat" in
    *..*) echo "mimers-backup: sökvägen får inte innehålla '..'" >&2; exit 64 ;;
  esac
  [[ "$pat" == /* ]] || pat="$HOME/$pat"
  pat="$(rensa_sökväg "$pat")"
  if [ "$pat" != "$FILKATALOG" ] && [ "${pat#"$FILKATALOG"/}" = "$pat" ]; then
    echo "mimers-backup: sökvägen ligger utanför $FILKATALOG" >&2
    exit 64
  fi
  [ "$avslutande_snedstreck" -eq 1 ] && pat="$pat/"
  argv[${#argv[@]}-1]="$pat"

  exec rsync "${argv[@]:1}"
}

KOM="${SSH_ORIGINAL_COMMAND:-}"

case "$KOM" in
  dump)
    las_db_uppgifter
    dumpa
    ;;
  rsync\ *)
    rsync_gren
    ;;
  *)
    printf 'mimers-backup v%s\n' "$VERSION" >&2
    echo "mimers-backup: okänt kommando. Tillåtna: dump, rsync --server --sender …" >&2
    exit 64
    ;;
esac
