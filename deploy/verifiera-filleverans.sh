#!/usr/bin/env bash
#
# Bevisar filleveransens skydd mot en utrullad miljö, issue 19b § Beslut 5:
#   1. GET /_protected/                     → 403
#   2. GET /_protected/ab/cd/…              → 403
#   3. GET //_protected/ab/cd/…             → 403 (kringgångsform)
#   4. GET /files/{ulid} med giltig token   → 200, icke-tom kropp,
#      Content-Disposition: attachment, X-Content-Type-Options: nosniff,
#      Content-Type som inte är text/html
#
# De tre första anropen kräver ingen inloggning. Det fjärde behöver en bilaga
# som kontot får läsa och ett sanctum personal access token. Bilagan ska inte
# vara HTML: text/html vore ett korrekt svar för en .html-bilaga men kan inte
# skiljas från appens standardsvar, medan en annan typ bevisar att den
# explicita Content-Type:n överlevde den interna omdirigeringen. Scriptet
# avslutar med kod 1 så fort något avviker.
#
# Token läses från FILES_TOKEN eller som tredje argument. Ett argument syns i
# ps och hamnar i skalhistoriken på den delade servern, så miljövariabeln är
# förstahandsvalet:
#   FILES_TOKEN=… deploy/verifiera-filleverans.sh <bas-url> <ulid>
set -euo pipefail

BAS_URL="${1:-}"
ULID="${2:-}"
TOKEN="${FILES_TOKEN:-${3:-}}"

if [ -z "$BAS_URL" ] || [ -z "$ULID" ] || [ -z "$TOKEN" ]; then
  echo "Användning: verifiera-filleverans.sh <bas-url> <ulid> [token]" >&2
  echo "  <bas-url>  t.ex. https://staging.mimers.app" >&2
  echo "  <ulid>     en bilaga som kontot får läsa — inte HTML" >&2
  echo "  token      sanctum personal access token (Authorization: Bearer)" >&2
  echo "             sätt hellre FILES_TOKEN: ett argument syns i ps och" >&2
  echo "             hamnar i skalhistoriken" >&2
  exit 1
fi

BAS_URL="${BAS_URL%/}"

# De tre första punkterna behöver bara HTTP-statusen. Vid en transportmiss
# hamnar curls felmeddelande i stället i variabeln och grenen nedan får namnge
# felet i stället för att set -e avbryter tyst.
httpkod() {
  curl -sS -o /dev/null -w '%{http_code}' "$1" 2>&1
}

fel=0

# 1. Katalogroten får inte vara läsbar utifrån.
if kod=$(httpkod "$BAS_URL/_protected/"); then
  if [ "$kod" = 403 ]; then
    echo "OK   1/4  GET /_protected/            → $kod"
  else
    echo "FEL  1/4  GET /_protected/            → $kod (väntat 403)" >&2
    fel=1
  fi
else
  echo "FEL  1/4  kunde inte nå $BAS_URL/_protected/: $kod" >&2
  fel=1
fi

# 2. Inte heller en sökväg i lagringsformatet. 64 hex-siffror som härmar en
# riktig content hash: regeln prövas på URI:n innan filen slås upp, så även en
# sökväg utan existerande fil ska ge 403. Ger den 404 i stället är
# .htaccess-regeln borta.
KAND_HASH="ab/cd/0000000000000000000000000000000000000000000000000000000000000000"
if kod=$(httpkod "$BAS_URL/_protected/$KAND_HASH"); then
  if [ "$kod" = 403 ]; then
    echo "OK   2/4  GET /_protected/ab/cd/…     → $kod"
  else
    echo "FEL  2/4  GET /_protected/ab/cd/…     → $kod (väntat 403)" >&2
    fel=1
  fi
else
  echo "FEL  2/4  kunde inte nå $BAS_URL/_protected/$KAND_HASH: $kod" >&2
  fel=1
fi

# 3. En kringgångsform av samma anrop. //_protected/… normaliseras av servern
# till samma fil men inleds inte med strängen /_protected/ — en neka-lista på
# textform missar den. Regeln är en tillåt-lista (deploy/protected.htaccess),
# så den nekar allt som inte redan har formen /files/{ulid}.
if kod=$(httpkod "$BAS_URL//_protected/$KAND_HASH"); then
  if [ "$kod" = 403 ]; then
    echo "OK   3/4  GET //_protected/ab/cd/…    → $kod"
  else
    echo "FEL  3/4  GET //_protected/ab/cd/…    → $kod (väntat 403)" >&2
    fel=1
  fi
else
  echo "FEL  3/4  kunde inte nå $BAS_URL//_protected/$KAND_HASH: $kod" >&2
  fel=1
fi

# 4. Samma byten ska levereras genom appens rutt med rätt svarsheadrar. I
# internal-redirect-läget skickar PHP noll bytes och LiteSpeed ersätter
# kroppen — status och kroppsstorlek tas därför från -w, medan headrarna är de
# PHP satte i svaret. En tom kropp (0 bytes) betyder att symlänken eller
# läsningen gick sönder: 200 med noll bytes är en trasig nedladdning, inte en
# lyckad. nosniff är det som gör leveransen på appdomänen säker
# ([[ADR-0019 Filleverans]] § Uppföljning 2026-08-31) och måste överleva den
# interna omdirigeringen.
if utdata=$(curl -sS -D - -o /dev/null \
    -w $'\n@@ %{http_code} %{size_download}\n' \
    -H "Authorization: Bearer $TOKEN" "$BAS_URL/files/$ULID" 2>&1); then
  status=$(printf '%s\n' "$utdata" | sed -n 's/^@@ \([0-9][0-9][0-9]\) .*/\1/p')
  storlek=$(printf '%s\n' "$utdata" | sed -n 's/^@@ [0-9][0-9][0-9] \([0-9][0-9]*\)$/\1/p')
  storlek="${storlek:-0}"
  disposition=$(printf '%s\n' "$utdata" | awk -F': ' 'tolower($1) == "content-disposition" { gsub("\r", "", $2); print $2; exit }')
  typ=$(printf '%s\n' "$utdata" | awk -F': ' 'tolower($1) == "content-type" { gsub("\r", "", $2); print $2; exit }')
  nosniff=$(printf '%s\n' "$utdata" | awk -F': ' 'tolower($1) == "x-content-type-options" { gsub("\r", "", $2); print $2; exit }')

  if [ "$status" = 200 ] \
    && [ "$storlek" -gt 0 ] \
    && printf '%s\n' "$disposition" | grep -qi 'attachment' \
    && printf '%s\n' "$nosniff" | grep -qi 'nosniff' \
    && [ -n "$typ" ] \
    && ! printf '%s\n' "$typ" | grep -qi 'text/html'; then
    echo "OK   4/4  GET /files/$ULID            → $status, $storlek bytes, $disposition, Content-Type: $typ, nosniff"
  else
    echo "FEL  4/4  GET /files/$ULID            → status $status, $storlek bytes, disposition ”$disposition”, Content-Type ”$typ”, nosniff ”$nosniff” (väntat 200, icke-tom kropp, attachment, nosniff, inte text/html)" >&2
    fel=1
  fi
else
  echo "FEL  4/4  kunde inte nå $BAS_URL/files/$ULID: $utdata" >&2
  fel=1
fi

exit "$fel"
