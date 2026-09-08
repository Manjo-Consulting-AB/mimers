#!/usr/bin/env bash
#
# Lämnar ut en konsistent databasdump och användarfilerna, för backup på en egen
# server. Hämtaren körs på den egna servern (42b); det här skriptet skriver
# aldrig något till disk hos inleed utöver en temporär autentiseringsfil under
# /tmp. Produktionsservern får aldrig veta vart backupen går, se
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
#   * Laravel bootas inte. Ren shell plus mariadb-dump — en halv utrullning är
#     precis det läge man vill kunna ta en backup i, och appens artisan-svar
#     finns då inte.
#
# set -euo pipefail, till skillnad från retro-fakta.sh: där ska ett avsnitt
# kunna falla utan att ta resten med sig, här är en avbruten dump som avslutar
# 0 det värsta utfallet i hela kedjan. Hämtaren i 42b litar på exit-koden.
set -euo pipefail

VERSION="1"   # höjs vid varje ändring, så att glapp mot repot syns på stderr

# Bara produktion, med flit (Beslut 5). Staging är engångsdata som återskapas
# av en utrullning, och en miljöväljare vore ytterligare en indata på en nyckel
# som redan lämnar ut hela databasen.
APP="$HOME/mimers"
ENVFIL="$APP/shared/.env"
FILKATALOG="$APP/shared/storage/files"

# Läser en nyckel ur shared/.env utan att exponera resten av filen. Sista
# förekomsten vinner, som i Laravels egen läsning; citattecken och
# radslutskommentarer skalas av. Värden skrivs aldrig ut härifrån.
envvarde() {
  [ -r "$ENVFIL" ] || return 1
  local rad
  rad=$(grep -E "^[[:space:]]*${1}=" "$ENVFIL" 2>/dev/null | tail -n 1) || return 1
  [ -n "$rad" ] || return 1
  rad="${rad#*=}"
  rad="${rad%%#*}"
  rad="$(printf '%s' "$rad" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/")"
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
  skapa_authfil
  trap 'rm -f "$AUTHFIL"' EXIT
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

# Validerar rsync-kommandot token för token och kör det sedan. Hela poängen med
# command= är att en läckt nyckel inte ska ge ett skal — därför delas strängen
# med read och körs aldrig som kod, och varje token måste ligga i en tillåten
# teckenmängd. Det stänger ;, backticks, $(, > och allt annat som gör en sträng
# till kod.
rsync_gren() {
  local argv=() token sender=0 pat
  read -r -a argv <<<"$KOM" || true

  if [ "${argv[0]:-}" != rsync ] || [ "${argv[1]:-}" != --server ]; then
    echo "mimers-backup: rsync-anropet måste börja med 'rsync --server'" >&2
    exit 64
  fi

  for token in "${argv[@]}"; do
    if [[ ! "$token" =~ ^[-A-Za-z0-9._/=:,+@]+$ ]]; then
      echo "mimers-backup: otillåten token i rsync-kommandot" >&2
      exit 64
    fi
    [ "$token" = --sender ] && sender=1
    case "$token" in
      --delete*)             echo "mimers-backup: rsync får inte radera (hittade '$token')" >&2; exit 64 ;;
      --remove-source-files) echo "mimers-backup: rsync får inte ta bort källfiler" >&2; exit 64 ;;
    esac
  done

  if [ "$sender" -ne 1 ]; then
    echo "mimers-backup: --sender saknas — utan den är anropet en skrivning" >&2
    exit 64
  fi

  # Sista token är sökvägen. Normalisera bort en avslutande /, avvisa allt som
  # innehåller .., och kräv att sökvägen hamnar i filkatalogen. sshd startar
  # forced command i hemkatalogen, så en relativ sökväg räknas därifrån.
  pat="${argv[${#argv[@]}-1]}"
  pat="${pat%/}"
  case "$pat" in
    *..*) echo "mimers-backup: sökvägen får inte innehålla '..'" >&2; exit 64 ;;
  esac
  [[ "$pat" == /* ]] || pat="$HOME/$pat"
  if [ "$pat" != "$FILKATALOG" ] && [ "${pat#"$FILKATALOG"/}" = "$pat" ]; then
    echo "mimers-backup: sökvägen ligger utanför $FILKATALOG" >&2
    exit 64
  fi

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
