#!/usr/bin/env bash
#
# Bevisar filleveransens skydd mot en utrullad miljö, issue 19b § Beslut 5:
#   1. GET /_protected/          → 403
#   2. GET /_protected/ab/cd/…   → 403
#   3. GET /files/{ulid}         → 200, Content-Disposition: attachment,
#                                  Content-Type som inte är text/html
#
# De två första anropen kräver ingen inloggning. Det tredje behöver en bilaga
# som kontot får läsa och ett sanctum personal access token. Scriptet avslutar
# med kod 1 så fort något av de tre avviker.
#
#   deploy/verifiera-filleverans.sh <bas-url> <ulid> <token>
set -euo pipefail

BAS_URL="${1:-}"
ULID="${2:-}"
TOKEN="${3:-}"

if [ -z "$BAS_URL" ] || [ -z "$ULID" ] || [ -z "$TOKEN" ]; then
  echo "Användning: verifiera-filleverans.sh <bas-url> <ulid> <token>" >&2
  echo "  <bas-url>  t.ex. https://staging.mimers.app" >&2
  echo "  <ulid>     en bilaga som kontot får läsa" >&2
  echo "  <token>    sanctum personal access token (Authorization: Bearer)" >&2
  exit 1
fi

BAS_URL="${BAS_URL%/}"

# De två första punkterna behöver bara HTTP-statusen. Vid en transportmiss
# hamnar curls felmeddelande i stället i variabeln och grenen nedan får namnge
# felet i stället för att set -e avbryter tyst.
httpkod() {
  curl -sS -o /dev/null -w '%{http_code}' "$1" 2>&1
}

fel=0

# 1. Katalogroten får inte vara läsbar utifrån.
if kod=$(httpkod "$BAS_URL/_protected/"); then
  if [ "$kod" = 403 ]; then
    echo "OK   1/3  GET /_protected/          → $kod"
  else
    echo "FEL  1/3  GET /_protected/          → $kod (väntat 403)" >&2
    fel=1
  fi
else
  echo "FEL  1/3  kunde inte nå $BAS_URL/_protected/: $kod" >&2
  fel=1
fi

# 2. Inte heller en sökväg i lagringsformatet. 64 hex-siffror som härmar en
# riktig content hash: regeln nekar på hela prefixet, så även en sökväg utan
# existerande fil ska ge 403. Ger den 404 i stället är .htaccess-regeln borta.
KAND_HASH="ab/cd/0000000000000000000000000000000000000000000000000000000000000000"
if kod=$(httpkod "$BAS_URL/_protected/$KAND_HASH"); then
  if [ "$kod" = 403 ]; then
    echo "OK   2/3  GET /_protected/ab/cd/…   → $kod"
  else
    echo "FEL  2/3  GET /_protected/ab/cd/…   → $kod (väntat 403)" >&2
    fel=1
  fi
else
  echo "FEL  2/3  kunde inte nå $BAS_URL/_protected/: $kod" >&2
  fel=1
fi

# 3. Samma byten ska levereras genom appens rutt med rätt svarsheadrar. I
# internal-redirect-läget skickar PHP noll bytes och LiteSpeed ersätter kroppen;
# headrarna här är de PHP satte i svaret.
if headers=$(curl -sS -D - -o /dev/null -H "Authorization: Bearer $TOKEN" "$BAS_URL/files/$ULID" 2>&1); then
  status=$(printf '%s\n' "$headers" | awk 'NR == 1 { for (i = 1; i <= NF; i++) if ($i ~ /^[0-9]{3}$/) { print $i; exit } }')
  disposition=$(printf '%s\n' "$headers" | awk -F': ' 'tolower($1) == "content-disposition" { gsub("\r", "", $2); print $2; exit }')
  typ=$(printf '%s\n' "$headers" | awk -F': ' 'tolower($1) == "content-type" { gsub("\r", "", $2); print $2; exit }')

  if [ "$status" = 200 ] \
    && [ -n "$typ" ] \
    && printf '%s\n' "$disposition" | grep -qi 'attachment' \
    && ! printf '%s\n' "$typ" | grep -qi 'text/html'; then
    echo "OK   3/3  GET /files/$ULID           → $status, $disposition, Content-Type: $typ"
  else
    echo "FEL  3/3  GET /files/$ULID           → status $status, disposition ”$disposition”, Content-Type ”$typ” (väntat 200, attachment, inte text/html)" >&2
    fel=1
  fi
else
  echo "FEL  3/3  kunde inte nå $BAS_URL/files/$ULID: $headers" >&2
  fel=1
fi

exit "$fel"
