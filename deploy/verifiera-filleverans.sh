#!/usr/bin/env bash
#
# Bevisar filleveransens skydd mot en utrullad miljö. Fallen 1–3 är 19b:s och
# prövar .htaccess-regeln på appdomänen; resten kom med issue 61a § Beslut 8
# och prövar filoriginet och den signerade länken — sådant bara en riktig
# server kan svara på:
#
#   1. GET <app>/_protected/                  → 403
#   2. GET <app>/_protected/ab/cd/…           → 403
#   3. GET <app>//_protected/ab/cd/…          → 403 (kringgångsform)
#   4. GET <app>/files/{ulid-bild} med token  → 302 till filoriginet
#   5. GET <filorigin>/_protected/ab/cd/…     → 403 (samma tillåt-lista där)
#   6. GET <filorigin>/login                  → 404 (middlewaren, Beslut 3)
#   7. den signerade länken (bild)            → 200, rätt Content-Type,
#      nosniff och inline — utan session och utan token
#   8. den signerade länken (svg)             → 200, attachment och inte inline
#   9. samma länk med ett förflutet expires   → 403
#
# Fallen 1–3 kräver ingen inloggning. Fall 4 behöver en bilaga som kontot får
# läsa och ett sanctum personal access token; fallen 5–9 följer ur den länken.
# <ulid-bild> ska vara en bild vars lagrade typ står i tillåt-listan
# (image/jpeg, image/png, image/gif, image/webp eller application/pdf) och
# <ulid-svg> en SVG — den senare prövar att en SVG INTE får inline, eftersom
# den kan bära skript (issue 61a § Beslut 5).
#
# **Fall 9 vrids fram ur den giltiga länken** genom att `expires` sätts till ett
# förflutet värde. Signaturen täcker querysträngen, så den manipulerade länken
# är ogiltig på två sätt samtidigt: det som bevisas är att ingen länk går att
# förlänga för hand och att en död länk inte levererar. Att sitta och vänta ut
# de femton minuterna är inte ett alternativ i ett skript, och den här körs för
# hand — se [[ADR-0019 Filleverans]] § Uppföljning 2026-09-15.
#
# Skriptet avslutar med kod 1 så fort något avviker.
#
# Token läses från FILES_TOKEN eller som femte argument. Ett argument syns i
# ps och hamnar i skalhistoriken på den delade servern, så miljövariabeln är
# förstahandsvalet:
#   FILES_TOKEN=… deploy/verifiera-filleverans.sh <app-url> <filorigin-url> \
#       <ulid-bild> <ulid-svg>
#
# Körs för hand mot staging EFTER att filoriginet slagits på — symlänken per
# miljö och FILES_URL i shared/.env, se [[Pipeline]] § Engångsuppsättning.
# Utan FILES_URL svarar fall 4 200 med bytena i stället för 302, och skriptet
# faller: det är avsikten, det är det läget som ska ha lämnats.
set -euo pipefail

APP_URL="${1:-}"
FILORIGIN_URL="${2:-}"
ULID_BILD="${3:-}"
ULID_SVG="${4:-}"
TOKEN="${FILES_TOKEN:-${5:-}}"

if [ -z "$APP_URL" ] || [ -z "$FILORIGIN_URL" ] || [ -z "$ULID_BILD" ] || [ -z "$ULID_SVG" ] || [ -z "$TOKEN" ]; then
  echo "Användning: verifiera-filleverans.sh <app-url> <filorigin-url> <ulid-bild> <ulid-svg> [token]" >&2
  echo "  <app-url>       t.ex. https://staging.mimers.app" >&2
  echo "  <filorigin-url> t.ex. https://files.staging.mimers.app" >&2
  echo "  <ulid-bild>     en bilaga vars typ får visas inline, t.ex. en jpg" >&2
  echo "  <ulid-svg>      en svg-bilaga — den ska INTE visas inline" >&2
  echo "  token           sanctum personal access token (Authorization: Bearer)" >&2
  echo "                  sätt hellre FILES_TOKEN: ett argument syns i ps och" >&2
  echo "                  hamnar i skalhistoriken" >&2
  exit 1
fi

APP_URL="${APP_URL%/}"
FILORIGIN_URL="${FILORIGIN_URL%/}"

# De anrop som bara behöver HTTP-statusen. Vid en transportmiss hamnar curls
# felmeddelande i variabeln i stället, och grenen nedan får namnge felet i
# stället för att set -e avbryter tyst.
httpkod() {
  curl -sS -o /dev/null -w '%{http_code}' "$1" 2>&1
}

# Värdnamnet ur en URL, för jämförelsen i fall 4.
vardnamn() {
  printf '%s' "$1" | sed -E 's#^[a-zA-Z]+://##; s#/.*$##'
}

# Ett anrop vars enda krav är statuskoden.
kontroll() {
  local nr="$1" beskrivning="$2" url="$3" vantad="$4" kod

  if kod=$(httpkod "$url"); then
    if [ "$kod" = "$vantad" ]; then
      printf 'OK   %s  %-34s → %s\n' "$nr" "$beskrivning" "$kod"
      return 0
    fi

    printf 'FEL  %s  %-34s → %s (väntat %s)\n' "$nr" "$beskrivning" "$kod" "$vantad" >&2
    fel=1
    return 0
  fi

  printf 'FEL  %s  kunde inte nå %s: %s\n' "$nr" "$url" "$kod" >&2
  fel=1
  return 0
}

fel=0

# 1. Katalogroten får inte vara läsbar utifrån.
kontroll 1/9 "GET /_protected/" "$APP_URL/_protected/" 403

# 2. Inte heller en sökväg i lagringsformatet. 64 hex-siffror som härmar en
# riktig content hash: regeln prövas på URI:n innan filen slås upp, så även en
# sökväg utan existerande fil ska ge 403. Ger den 404 i stället är
# .htaccess-regeln borta.
KAND_HASH="ab/cd/0000000000000000000000000000000000000000000000000000000000000000"
kontroll 2/9 "GET /_protected/ab/cd/…" "$APP_URL/_protected/$KAND_HASH" 403

# 3. En kringgångsform av samma anrop. //_protected/… normaliseras av servern
# till samma fil men inleds inte med strängen /_protected/ — en neka-lista på
# textform missar den. Regeln är en tillåt-lista (deploy/protected.htaccess),
# så den nekar allt som inte redan har formen /files/{ulid}.
kontroll 3/9 "GET //_protected/ab/cd/…" "$APP_URL//_protected/$KAND_HASH" 403

# 4. Appdomänens rutt präglar en länk i stället för att leverera. Den ska
# svara 302 till filoriginets värdnamn, efter behörighetsprövning — ett 200
# här betyder att FILES_URL inte är satt och att originet inte är påslaget.
LANK_BILD=""
LANK_SVG=""

signerad_lank() {
  # $1 = ulid, $2 = beskrivande namn. Skriver länken på stdout och lämnar
  # 0/1 — anroparen sätter fel-flaggan, eftersom en variabeltilldelning ur en
  # kommandosubstitution sker i en egen process och inte når hit.
  local ulid="$1" namn="$2" utdata status lank

  if utdata=$(curl -sS -D - -o /dev/null -H "Authorization: Bearer $TOKEN" \
      -w $'\n@@ %{http_code}\n' "$APP_URL/files/$ulid" 2>&1); then
    status=$(printf '%s\n' "$utdata" | sed -n 's/^@@ \([0-9][0-9][0-9]\)$/\1/p')
    lank=$(printf '%s\n' "$utdata" | awk -F': ' 'tolower($1) == "location" { gsub("\r", "", $2); print $2; exit }')

    if [ "$status" != 302 ]; then
      printf 'FEL  4/9  %s: väntat 302, fick %s (är FILES_URL satt på servern?)\n' "$namn" "$status" >&2
      return 1
    fi

    if [ "$(vardnamn "$lank")" != "$(vardnamn "$FILORIGIN_URL")" ]; then
      printf 'FEL  4/9  %s: länken pekar på %s, inte på filoriginet %s\n' "$namn" "$lank" "$FILORIGIN_URL" >&2
      return 1
    fi

    printf '%s\n' "$lank"
    return 0
  fi

  printf 'FEL  4/9  %s: kunde inte nå %s/files/%s: %s\n' "$namn" "$APP_URL" "$ulid" "$utdata" >&2
  return 1
}

LANK_BILD=$(signerad_lank "$ULID_BILD" "bilden") || fel=1
LANK_SVG=$(signerad_lank "$ULID_SVG" "svg:en") || fel=1

if [ -n "$LANK_BILD" ]; then
  printf 'OK   4/9  %-34s → 302 till %s\n' "GET /files/{ulid-bild} med token" "$(vardnamn "$LANK_BILD")"
fi

# 5. Samma tillåt-lista gäller på filoriginet. Katalogen ligger under samma
# webbrot där — symlänken i releasen och .htaccess-regeln är gemensamma för
# båda värdnamnen (Beslut 3), så skyddet ska vara identiskt.
kontroll 5/9 "GET <filorigin>/_protected/ab/cd/…" "$FILORIGIN_URL/_protected/$KAND_HASH" 403

# 6. Middlewaren: filoriginet bär leveransen och ingenting annat. Inloggningen
# får inte finnas där, och svaret ska vara 404 — inte en omdirigering till
# appdomänen, som hade läckt att rutten finns.
kontroll 6/9 "GET <filorigin>/login" "$FILORIGIN_URL/login" 404

# 7 och 8. Den signerade länken, hämtad UTAN token och utan session — på det
# värdnamnet är signaturen hela autentiseringen. Bilden ska komma inline (dess
# typ står i tillåt-listan), svg:en som attachment. Båda ska bära nosniff.
#
# I internal-redirect-läget skickar PHP noll bytes och LiteSpeed ersätter
# kroppen, så status och kroppsstorlek tas från -w medan headrarna är de PHP
# satte i svaret. En tom kropp (0 bytes) betyder att symlänken eller läsningen
# gick sönder: 200 med noll bytes är en trasig nedladdning, inte en lyckad.
lank_kontroll() {
  local nr="$1" beskrivning="$2" lank="$3" vantad="$4" utdata status storlek
  local disposition typ nosniff

  if [ -z "$lank" ]; then
    return 0
  fi

  if utdata=$(curl -sS -D - -o /dev/null \
      -w $'\n@@ %{http_code} %{size_download}\n' "$lank" 2>&1); then
    status=$(printf '%s\n' "$utdata" | sed -n 's/^@@ \([0-9][0-9][0-9]\) .*/\1/p')
    storlek=$(printf '%s\n' "$utdata" | sed -n 's/^@@ [0-9][0-9][0-9] \([0-9][0-9]*\)$/\1/p')
    storlek="${storlek:-0}"
    disposition=$(printf '%s\n' "$utdata" | awk -F': ' 'tolower($1) == "content-disposition" { gsub("\r", "", $2); print $2; exit }')
    typ=$(printf '%s\n' "$utdata" | awk -F': ' 'tolower($1) == "content-type" { gsub("\r", "", $2); print $2; exit }')
    nosniff=$(printf '%s\n' "$utdata" | awk -F': ' 'tolower($1) == "x-content-type-options" { gsub("\r", "", $2); print $2; exit }')

    if [ "$status" = 200 ] \
      && [ "$storlek" -gt 0 ] \
      && printf '%s\n' "$disposition" | grep -qi "$vantad" \
      && printf '%s\n' "$nosniff" | grep -qi 'nosniff' \
      && [ -n "$typ" ] \
      && ! printf '%s\n' "$typ" | grep -qi 'text/html'; then
      printf 'OK   %s  %-34s → %s, %s bytes, %s, Content-Type: %s, nosniff\n' \
        "$nr" "$beskrivning" "$status" "$storlek" "$disposition" "$typ"
      return 0
    fi

    printf 'FEL  %s  %-34s → status %s, %s bytes, disposition ”%s”, Content-Type ”%s”, nosniff ”%s” (väntat 200, icke-tom kropp, %s, nosniff, inte text/html)\n' \
      "$nr" "$beskrivning" "$status" "$storlek" "$disposition" "$typ" "$nosniff" "$vantad" >&2
    fel=1
    return 0
  fi

  printf 'FEL  %s  kunde inte nå den signerade länken: %s\n' "$nr" "$utdata" >&2
  fel=1
  return 0
}

lank_kontroll 7/9 "signerad länk, bild" "$LANK_BILD" 'inline'
lank_kontroll 8/9 "signerad länk, svg" "$LANK_SVG" 'attachment'

# 9. Samma länk med ett förflutet expires. Signaturen täcker querysträngen, så
# den manipulerade länken är ogiltig både som förlängd och som utgången — det
# skriptet bevisar är att ingen länk går att förlänga för hand.
if [ -n "$LANK_SVG" ]; then
  UTGANGEN=$(printf '%s' "$LANK_SVG" | sed -E 's/expires=[0-9]+/expires=1/')
  kontroll 9/9 "samma länk efter utgången" "$UTGANGEN" 403
fi

exit "$fel"
