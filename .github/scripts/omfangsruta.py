#!/usr/bin/env python3
"""Kontrollera att PR:ens diff håller sig inom issuens omfångsruta.

Omfångsrutan är bindande enligt AGENTS.md, men fram tills nu har ingen kontrollerat
den. Globbarna i `.github/ISSUE_TEMPLATE/agent_task.yml` skrivs maskinläsbart just
för det här - den kontrollen är det som gör en billigare modell säker att köra,
eftersom den fångar den dyraste feltypen (ändringar utanför rutan) utan att någon
behöver läsa diffen rad för rad.

Deterministiskt villkor, alltså ett skript - inte en modell. Samma resonemang som
ordbudgeten i ci.yml.

Issuenumret läses ur PR-kroppens stängningsnyckelord. GitHub känner bara igen de
engelska (`Closes`, `Fixes`, `Resolves`) - det svenska `Stänger` stänger ingenting,
och mellan 2026-08-30 och 2026-09-01 letade det här skriptet efter just det ordet
medan PR-mallen bad om `Closes`. Kontrollen hoppades därför över på varje PR som
följde mallen, tyst och med exit 0. Se docs/Process/Lärdomar.md.

Därför två saker: nyckelorden matchas som GitHub matchar dem, och en PR som saknar
referens hoppas inte längre över utan vidare. Grennamnet avgör vilket som gäller -
`issue-*` är en implementations-PR och måste referera sin issue (annars fel), medan
process-, retro- och teknisk skuld-grenar får passera med en varning. Grenens eget
nummer duger inte som referens: det är backlognumret, inte GitHub-numret
(`issue-15b` stänger `#72`).

Läser:
    PR_BODY            PR-beskrivningen, för att hitta stängningsnyckelordet
    HEAD_REF           PR:ens grennamn, för att avgöra om referensen är obligatorisk
    GITHUB_REPOSITORY  ägare/repo
    GITHUB_TOKEN       för att hämta issuen, och för GraphQL-anropet nedan
    BASE_SHA           commit att diffa mot när HEAD inte är en merge-ref; på en
                        merge-ref (det actions/checkout ger vid pull_request) går
                        diffen mot HEAD^1 i stället, se main()
    PR_CREATED_AT      PR:ens skapelsetid, för att avgöra vilka redigeringar av
                        issuekroppen som hann ske innan PR:en öppnades
    GITHUB_EVENT_PATH  sätts av Actions själv i varje steg; ger PR-numret som
                        behövs för att läsa beviljade undantag ur arkitektsvaret

Rutan går att flytta i efterhand utan att röra en diff, och det gjorde den. Under
M5 fällde den här kontrollen fem gånger, och bara **en** löstes som processen
föreskriver: issue 184 (PR #207) lät filerna utanför rutan ligga kvar som en
överträdelse, reverterade raderna och facit står i commit `8ac8479`. Issue 172
(PR #187) var en ren mallmiss - PR-kroppen saknade `Closes #NN`. De tre
återstående fälldes av grinden och mergades ändå gröna, för att issuekroppen
redigerades medan PR:en var öppen:

  - Issue 175 (PR #192): kroppen redigerades fyra gånger medan PR:en var öppen
    (09:33:34, 11:52:02, 11:54:31, 11:58:03 den 2026-09-05). Grinden var röd
    11:52:36 och 11:58:27. `In scope` innehåller i dag
    `app/Support/Notification/EmailChannel.php` med exakt det skäl PR-kroppen
    samtidigt angav för att filen låg **utanför** rutan.
  - Issue 183 (PR #206): kroppen redigerades 18:47:16, mellan röd grind
    18:38:28 och merge 18:52:00. De två fällda filerna (`app/Models/Account.php`,
    `app/Support/Notification/UnsafeUrlException.php`) står i dag i `In scope`.
  - Issue 173 (PR #189): kroppen rördes aldrig - PR:en mergades i stället med
    steget rött. `process` står `fail` på körning 33954480512 än i dag.

Sedan M6 finns en väg som varken flyttar linjalen eller kräver en revert:
ett arkitektsvar kan bevilja ett undantag med markören `Beviljat undantag från
omfångsrutan:` följt av ett kodblock med sökvägar. Se `beviljade_undantag()` för
varför just arkitektsvaret är platsen, och för issue 223 (PR #231) som mergades
röd på exakt de två filer arkitekten hade beordrat.

Grindens egen felutskrift säger ordagrant: "Ligger en fil utanför rutan med
avsikt: skriv vilken och varför i PR:en och vänta på svar - vidga inte rutan i
efterhand." Två av tre gjorde precis tvärtom: rutan skrevs om till att omsluta
diffen i stället. Följden är att metriken "omfångsdrift i mergat läge = 0" inte
mäter något - den är noll för att rutan i två fall skrevs om till att omsluta
diffen, inte för att diffen höll sig innanför den. Se docs/Process/Lärdomar.md
§ Observerat.

Kontrollen nedan (`linjalen_flyttad` och det som anropar den i `main`) läser
issuens redigeringshistorik via GraphQL - REST-svaret `hamta_issue` ger bara
den kropp som gäller just nu, vilket är precis det en omskriven ruta gör sig
osynlig för. Den skiljer på att flytta linjalen (`In scope`/`Out of scope`
ändras efter att PR:en öppnades - fel) och att rätta en stavning (kroppen
ändras någon annanstans - varning, för det är en legitim rättelse). Kan
anropet inte göras - saknad GraphQL-behörighet, trasigt svar - varnar
kontrollen och fortsätter i stället för att rapportera grönt: en grind som
inte kan skilja "inget att göra" från "jag tittade åt fel håll" får inte
tiga om skillnaden.

Rutan läses i båda de former som finns i repot: issue-formulärets `### In scope`
och den handskrivna `**In scope**` med en punktlista där sökvägen står i
bakåtcitat. Hela M2 (#91-#100) skrevs i den senare formen, och skriptet svarade
"ingen ifylld ruta" och exit 0 på var enda en - åtta filer utanför rutan mergades.
Därför felar kontrollen numera stängt: en implementations-PR vars ruta inte går
att läsa underkänns i stället för att hoppas över.

Avslutar 0 om allt ligger innanför rutan, eller om kontrollen inte är tillämplig
(process-PR utan issuereferens eller utan ruta). 1 vid överträdelse, när en
implementations-PR saknar issuereferens, och när dess issue saknar läsbar ruta.
"""

import difflib
import fnmatch
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.request

# Rutan står i två former, båda i bruk. GitHub renderar issue-formulärets fält som
# "### <etikett>"; issues skrivna för hand ur backloggfilerna sätter i stället en
# fetstilt etikett under "## Omfång": "**In scope**". M2:s tio issues (#91-#100) är
# alla av den senare sorten, och när skriptet bara kände den förra svarade det
# "ingen ifylld ruta" och exit 0 på var enda en av dem - tredje gången samma skript
# hoppade över sig själv tyst. Se docs/Process/Lärdomar.md.
#
# Alla rubriknivåer räknas som gräns, inte bara `###`. Den handskrivna rutan står
# under `## Omfång` och följs av `## Axlar`; kändes bara `###` igen slutade
# avsnittet `Out of scope` aldrig, och läste in hela issuens beslutstext som
# globbar - 73 stycken i #91, varav flera var dess egna In scope-filer.
RUBRIK = re.compile(r"^(?:#{1,6}\s+(?P<falt>.+?)|\*\*(?P<fet>.+?)\*\*)\s*$", re.MULTILINE)
# GitHub stänger på close/closes/closed, fix/fixes/fixed, resolve/resolves/resolved.
# `stänger` accepteras för PR:er skrivna före 2026-08-30 men varnar - den stänger inget.
STANGER = re.compile(r"\b(clos(?:e|es|ed)|fix(?:|es|ed)|resolv(?:e|es|ed)|stänger)\s+#(\d+)", re.IGNORECASE)
# Implementationsgrenar heter feature/issue-NN, se AGENTS.md § Arbetsgång - NN är
# GitHub-numret, inte backlognumret (issue-15b stängde #72). Den gamla formen
# issue-NN-kort-namn accepteras också: den står i M0-M1:s grennamn, och regexet är
# det enda som avgör om en PR utan Closes-rad ska fällas eller släppas igenom.
IMPLEMENTATIONSGREN = re.compile(r"^(?:feature/)?issue-\d+")
TOMT = {"_No response_", "_Inget svar_"}

# Issue 30 (PR #186) byggde app/Models/Notification.php med sju TYPE_*-konstanter
# men medvetet ingen samlad TYPES-lista - modellens docblock säger uttryckligen
# att `type` är ett ÖPPET namnrum, och notification_preference-migrationen ger
# `type` inget CHECK-villkor av samma skäl. Ändå refererade tre senare issuer
# `Notification::TYPES` som en given
# förutsättning: issue 173 (på fyra ställen, "finns redan från issue 30
# § Beslut 4"), issue 175 (Beslut 6) och issue 183. Tre implementerare gjorde
# samma utredning var för sig, kom till samma slutsats, och skrev samma fynd i
# "Frågor och antaganden" - PR #189:s processnotering säger att just det
# kostade mest i den issuen. Lösningarna gled dessutom isär: 173 lade listan på
# NotificationPreferences::types(), 183 på WebhookEndpoint::EVENT_TYPES. Se
# docs/Process/Lärdomar.md § Observerat.
#
# Kontrollen nedan fångar mönstret innan det upprepas en fjärde gång: en
# bakåtciterad `Klass::KONSTANT` i issuekroppen där klassen finns under app/
# men konstanten inte gör det. Den är en VARNING, aldrig ett fel - grinden ska
# inte fälla en issue för att en tidigare issue medvetet valde bort en
# konstant, bara flagga att den som implementerar bör dubbelkolla innan hen
# antar att den finns, i stället för att göra samma utredning en fjärde gång.
#
# Mönstret är medvetet snävt till VERSALKONSTANTER (`Klass::KONSTANT`, inte
# `Klass::metod()`). Mätt mot milstolpens sexton issuekroppar gav den bredare
# formen - som även fångar metodanrop - 20 varningar varav bara 3 äkta; resten
# var fasadanrop (`Schedule::call`, `Notification::fake`) och ärvda
# Eloquent-metoder (`EmailSuppression::where`). En grind som är röd på allt
# slutar betyda något, se ci.yml:s egna kommentarer om ordbudgeten.
KLASSKONSTANT = re.compile(r"`(?P<klass>[A-Z][A-Za-z0-9_]*)::(?P<konstant>[A-Z0-9_]{3,})`")
KODBLOCK = re.compile(r"```.*?```", re.DOTALL)


def notis(niva: str, text: str) -> None:
    """Skriv en GitHub Actions-annotering. Hamnar i körningens sammanfattning."""
    print(f"::{niva}::{text}")


def hamta_issue(repo: str, nummer: str, token: str) -> dict:
    """Issuen som dict. Anroparen skiljer på issue och pull request via nyckeln
    "pull_request", som bara finns på den senare."""
    begaran = urllib.request.Request(
        f"https://api.github.com/repos/{repo}/issues/{nummer}",
        headers={
            "Authorization": f"Bearer {token}",
            "Accept": "application/vnd.github+json",
            "X-GitHub-Api-Version": "2022-11-28",
        },
    )
    with urllib.request.urlopen(begaran, timeout=30) as svar:
        return json.load(svar)


# REST-svaret ovan ger bara den kropp som gäller just nu - exakt det en ruta som
# skrivits om efter PR-öppningen gör sig osynlig för. GraphQL:s
# `userContentEdits` ger historiken: varje redigering, nyast först, med
# `editedAt` och ett `diff`-fält som i praktiken är hela kroppen vid den
# revisionen - inte en differens mot föregående. Det har verifierats manuellt
# mot repots egna issues (t.ex. #175): den senaste noden är alltid byte-för-byte
# identisk med den aktuella kroppen, vilket bara stämmer om `diff` är
# tillståndet *efter* redigeringen, inte före.
GRAPHQL_URL = "https://api.github.com/graphql"


def hamta_redigeringshistorik(repo: str, nummer: str, token: str) -> dict:
    """Issuens skapelsetid, aktuella kropp och redigeringshistorik via GraphQL.

    Kastar vidare vid nätverksfel, GraphQL-fel eller ett svar utan issue -
    anroparen avgör om det ska varna eller fälla. Se `linjalen_flyttad` för
    varför det bara får bli en varning.
    """
    agare, namn = repo.split("/", 1)
    fraga = """
    query($agare: String!, $namn: String!, $nummer: Int!) {
      repository(owner: $agare, name: $namn) {
        issue(number: $nummer) {
          createdAt
          body
          userContentEdits(first: 50) {
            nodes { editedAt diff }
          }
        }
      }
    }
    """
    kropp = json.dumps(
        {"query": fraga, "variables": {"agare": agare, "namn": namn, "nummer": int(nummer)}}
    ).encode()
    begaran = urllib.request.Request(
        GRAPHQL_URL,
        data=kropp,
        headers={
            "Authorization": f"Bearer {token}",
            "Accept": "application/vnd.github+json",
            "Content-Type": "application/json",
        },
    )
    with urllib.request.urlopen(begaran, timeout=30) as svar:
        svarskropp = json.load(svar)
    fel = svarskropp.get("errors")
    if fel:
        raise RuntimeError(f"GraphQL svarade med fel: {fel}")
    issue = ((svarskropp.get("data") or {}).get("repository") or {}).get("issue")
    if issue is None:
        raise RuntimeError(f"#{nummer} gick inte att hämta via GraphQL - tomt svar.")
    return issue


# Ett beviljat undantag måste gå att läsa maskinellt, annars fälls PR:en av den
# order den följde. Issue 223 (PR #231, M6): Opus skrev ordagrant "Undantag från
# `Out of scope`, uttryckligen beviljat: skapa `lang/sv/export.php` och
# `lang/en/export.php`" - motiverat, eftersom listan fanns för att skydda
# BEFINTLIGA filer mot kollision med issue 39 och 40, inte för att förbjuda två
# nya. Implementeraren gjorde som den blev tillsagd, granskaren såg beviljandet
# och godkände, och det här skriptet fällde ändå körningen på exakt de två
# filerna: det läste bara issuekroppen. Kön vägrade merga, och PR:en stod fem
# timmar tills Tony mergade den röd. Ingen instans gjorde fel - det saknades en
# plats att skriva beviljandet där grinden kunde se det.
#
# Issuekroppen är fel plats: att redigera den i efterhand ÄR att flytta linjalen,
# och `linjalen_flyttad` finns till för att fälla just det. PR-kroppen är också
# fel plats - den skrivs av implementeraren, alltså den som ska hindras. Kvar
# står arkitektsvaret: en kommentar pipelinen själv postar (se
# process_next_issue.py, run_opus_answer och besvara_arkitektfraga), som ingen
# kan efterredigera osynligt och som står kvar i tråden för granskningen och för
# retron.
#
# Undantaget vidgar rutan men göms aldrig: varje beviljad fil skrivs ut som en
# ::warning:: med länk till kommentaren som beviljade den, så att omfångsdrift
# fortfarande går att räkna i efterhand. En tyst vidgning vore samma metrikförlust
# som en omskriven ruta.
UNDANTAGSMARKOR = "Beviljat undantag från omfångsrutan:"
ARKITEKTRUBRIK = re.compile(r"^###\s+.*arkitektsvar", re.IGNORECASE)


def pr_nummer_ur_handelsen() -> str | None:
    """PR-numret ur GITHUB_EVENT_PATH.

    Actions sätter den variabeln i varje steg utan att workflowen behöver räkna
    upp den i sitt `env:`-block, så kontrollen nedan kan läggas till utan att
    ci.yml rörs. Saknas filen - skriptet körs för hand - hoppas kontrollen över.
    """
    sokvag = os.environ.get("GITHUB_EVENT_PATH") or ""
    if not sokvag or not os.path.exists(sokvag):
        return None
    try:
        with open(sokvag, encoding="utf-8") as f:
            handelse = json.load(f)
    except (OSError, ValueError):
        return None
    nummer = (handelse.get("pull_request") or {}).get("number")
    return str(nummer) if nummer else None


def beviljade_undantag(repo: str, pr_nummer: str, token: str) -> list[tuple[str, str]]:
    """Sökvägar som ett arkitektsvar uttryckligen beviljat, med länk till svaret.

    Formen är avsiktligt stel: en rad som är exakt UNDANTAGSMARKOR, följd av ett
    kodblock med en sökväg per rad, i en kommentar vars rubrik är ett
    arkitektsvar. Stelheten är poängen - en grind som gissar vad ett svar menade
    är ingen grind. Står markören inte där finns inget undantag, och rutan gäller
    som förut.

    Felar öppet, inte stängt: kan kommentarerna inte läsas returneras en tom
    lista, vilket ger exakt det utfall skriptet hade innan den här funktionen
    fanns. Ett tappat API-anrop ska inte vidga rutan.
    """
    url = f"https://api.github.com/repos/{repo}/issues/{pr_nummer}/comments?per_page=100"
    begaran = urllib.request.Request(url, headers={
        "Authorization": f"Bearer {token}",
        "Accept": "application/vnd.github+json",
        "User-Agent": "omfangsruta",
    })
    try:
        with urllib.request.urlopen(begaran, timeout=30) as svar:
            kommentarer = json.load(svar)
    except (urllib.error.HTTPError, urllib.error.URLError, TimeoutError, ValueError) as fel:
        notis("warning", f"Kunde inte läsa PR #{pr_nummer}s kommentarer: {fel}. "
                         "Beviljade undantag kan inte läsas - rutan gäller som skriven.")
        return []

    beviljade: list[tuple[str, str]] = []
    for kommentar in kommentarer:
        kropp = kommentar.get("body") or ""
        forsta = kropp.lstrip().splitlines()[0] if kropp.strip() else ""
        if not ARKITEKTRUBRIK.match(forsta):
            continue
        for block in re.findall(
            rf"^\s*{re.escape(UNDANTAGSMARKOR)}\s*\n\s*```[^\n]*\n(.*?)^\s*```",
            kropp, re.MULTILINE | re.DOTALL,
        ):
            for rad in block.splitlines():
                rad = rad.strip().strip("`").strip()
                if rad and ar_sokvag(rad):
                    beviljade.append((rad, kommentar.get("html_url", "")))
    return beviljade


def kropp_vid_pr_oppning(historik: dict, pr_skapad: str) -> str:
    """Issuekroppen som den såg ut när PR:en öppnades.

    `userContentEdits` levereras nyast först. Baslinjen är den sista
    redigeringen vars `editedAt` ligger **före** PR:ens `createdAt` - allt
    nyare hörde till efter öppningen och ska inte räknas som ursprungsläge.
    Finns ingen sådan redigering (issuen redigerades först efter att PR:en
    öppnades, eller aldrig alls) är den äldsta kända revisionen originalet;
    saknas redigeringar helt är den aktuella kroppen sitt eget original.
    """
    noder = historik.get("userContentEdits", {}).get("nodes") or []
    if not noder:
        return historik.get("body") or ""
    fore = [n for n in noder if n["editedAt"] < pr_skapad]
    if fore:
        return fore[0]["diff"] or ""
    return noder[-1]["diff"] or ""


def avsnitt(kropp: str, etikett: str) -> str:
    """Texten under "### <etikett>" eller "**<etikett>**", fram till nästa rubrik."""
    traffar = list(RUBRIK.finditer(kropp))
    for index, traff in enumerate(traffar):
        namn = traff.group("falt") or traff.group("fet") or ""
        if namn.strip().lower() != etikett.lower():
            continue
        start = traff.end()
        slut = traffar[index + 1].start() if index + 1 < len(traffar) else len(kropp)
        return kropp[start:slut]
    return ""


def linjalen_flyttad(kropp_vid_oppning: str, kropp_nu: str) -> list[tuple[str, str, str]]:
    """Vilka av `In scope`/`Out of scope` som skiljer sig från vid PR-öppningen.

    Returnerar en lista av (etikett, då, nu) för varje avsnitt vars text har
    ändrats. Bara de här två avsnitten räknas - en redigering av målet, ett
    beslut eller "Klart när" är en legitim rättelse, inte en flyttad linjal,
    och ska inte fälla kontrollen. Se modulens docstring för bakgrunden:
    issue 175 och 183 är exakt det här mönstret, verifierat i M5.
    """
    skillnader = []
    for etikett in ("In scope", "Out of scope"):
        da = avsnitt(kropp_vid_oppning, etikett).strip()
        nu = avsnitt(kropp_nu, etikett).strip()
        if da != nu:
            skillnader.append((etikett, da, nu))
    return skillnader


def ar_sokvag(token: str) -> bool:
    """Ser token ut som en sökväg eller en glob, och inte som ett ord i löptext?

    Handskrivna rutor blandar sökvägar med förklaringar i samma punkt - `files`
    är ett disknamn, `auth:sanctum` en middlewaregrupp, `create_stored_file_table`
    en migrationsklass. Bara det som bär ett snedstreck eller en filändelse får
    bli en glob; resten skulle ändå aldrig matcha en fil i diffen, men i rutan
    `Out of scope` skulle det kunna fälla fel PR.
    """
    return "/" in token or re.fullmatch(r"[^\s/]+\.[A-Za-z0-9]+", token) is not None


def globbar(text: str) -> list[str]:
    """Globbarna i ett avsnitt, oavsett om rutan är maskinskriven eller handskriven.

    Issue-formuläret ger en bar glob per rad. Handskrivna rutor ger punktlistor
    där globben står i bakåtcitat följd av en förklaring - `app/Models/Item.php`
    — **bara** relationen `attachments()`. Läses raden rå blir den en glob som
    aldrig matchar något, och då är varenda fil i diffen en överträdelse.
    """
    rader = []
    for rad in text.splitlines():
        rad = rad.strip()
        if not rad or rad.startswith("```") or rad.startswith("#") or rad in TOMT:
            continue
        citerade = [t for t in re.findall(r"`([^`]+)`", rad) if ar_sokvag(t)]
        if citerade:
            rader.extend(citerade)
        elif not rad.startswith(("-", "*")):
            rader.append(rad)
    return rader


def matchar(fil: str, monster: str) -> bool:
    """Matcha en sökväg mot en glob ur omfångsrutan.

    `fnmatch` låter `*` passera snedstreck, så `app/Policies/**` täcker hela
    underträdet - vilket är precis vad rutan menar. Det gör matchningen något
    tillåtande, och det är rätt håll att fela åt: kontrollen finns för att fånga
    filer som ligger uppenbart utanför, inte för att tvista om kataloggränser.
    """
    monster = monster.rstrip("/")
    return fnmatch.fnmatch(fil, monster) or fnmatch.fnmatch(fil, f"{monster}/*")


def hitta_klassfil(klass: str) -> str | None:
    """Sökvägen till filen som definierar `class|interface|trait|enum <klass>`
    under app/, eller None om ingen sådan finns.

    En klass som inte finns är inte ett fel här - det är det vanliga fallet
    när issuen som citerar konstanten är samma issue som ska skapa klassen.
    Varningen gäller bara när klassen redan finns men konstanten inte gör
    det.
    """
    deklaration = re.compile(rf"\b(?:class|interface|trait|enum)\s+{re.escape(klass)}\b")
    for rot, _, filer in os.walk("app"):
        for namn in filer:
            if not namn.endswith(".php"):
                continue
            sokvag = os.path.join(rot, namn)
            with open(sokvag, encoding="utf-8") as f:
                if deklaration.search(f.read()):
                    return sokvag
    return None


def varna_om_paihittade_konstanter(kropp_issue: str, nummer: str) -> None:
    """Varna för varje `Klass::KONSTANT` i issuekroppen vars klass finns under
    app/ men vars konstant inte gör det.

    Kodblock hoppas över först - exempelkod som visar hur en konstant *skulle*
    kunna heta ska inte läsas som ett påstående om att den finns. Samma par
    varnas bara en gång även om det citeras flera gånger i kroppen.
    """
    sedda: set[tuple[str, str]] = set()
    utan_kodblock = KODBLOCK.sub("", kropp_issue)
    for traff in KLASSKONSTANT.finditer(utan_kodblock):
        klass, konstant = traff.group("klass"), traff.group("konstant")
        if (klass, konstant) in sedda:
            continue
        sedda.add((klass, konstant))

        sokvag = hitta_klassfil(klass)
        if sokvag is None:
            continue

        with open(sokvag, encoding="utf-8") as f:
            if re.search(rf"\bconst\s+{re.escape(konstant)}\b", f.read()):
                continue

        notis(
            "warning",
            f"Issue #{nummer} citerar `{klass}::{konstant}`, men den konstanten "
            f"finns inte i {sokvag}. Kontrollera innan du antar att den finns - "
            "en tidigare issue kan ha valt bort den med flit (som "
            "Notification::TYPES, som app/Models/Notification.php medvetet "
            "saknar, se issue 30 § Beslut 4).",
        )


def hitta_issue(kropp_pr: str, gren: str) -> tuple[str | None, str]:
    """Issuenumret ur PR-kroppen, plus vad utfallet betyder.

    Status är en av: "engelskt" (normalfallet), "svenskt" (matchar, men GitHub
    stänger inte på det), "saknas-implementation" (fel) eller "saknas-process".
    """
    traff = STANGER.search(kropp_pr)
    if traff:
        nyckelord, nummer = traff.group(1), traff.group(2)
        return nummer, "svenskt" if nyckelord.lower() == "stänger" else "engelskt"
    if IMPLEMENTATIONSGREN.match(gren):
        return None, "saknas-implementation"
    return None, "saknas-process"


def main() -> int:
    kropp_pr = os.environ.get("PR_BODY") or ""
    repo = os.environ.get("GITHUB_REPOSITORY") or ""
    token = os.environ.get("GITHUB_TOKEN") or ""
    bas = os.environ.get("BASE_SHA") or ""
    gren = os.environ.get("HEAD_REF") or ""

    nummer, status = hitta_issue(kropp_pr, gren)

    if status == "saknas-implementation":
        notis(
            "error",
            f"Grenen `{gren}` är en implementations-PR men kroppen refererar ingen issue. "
            "Utan `Closes #NN` går omfångsrutan inte att kontrollera, och GitHub stänger "
            "inte issuen vid merge. Fyll i Closes-raden i PR-mallen.",
        )
        return 1
    if status == "saknas-process":
        notis(
            "warning",
            f"Grenen `{gren or 'okänd'}` refererar ingen issue - omfångsrutan hoppas över. "
            "Det är väntat för retro-, process- och teknisk skuld-grenar; är det här en "
            "implementations-PR ska den heta issue-NN-kort-namn och bära en Closes-rad.",
        )
        return 0
    if status == "svenskt":
        notis(
            "warning",
            f"PR:en skriver `Stänger #{nummer}`. Omfångsrutan kontrolleras, men GitHub "
            "stänger inte issuen på svenska nyckelord - använd `Closes #NN`.",
        )

    if not (repo and token and bas):
        notis("error", "GITHUB_REPOSITORY, GITHUB_TOKEN och BASE_SHA måste vara satta.")
        return 1

    # Kontrollen felar stängt: kan rutan inte läsas får PR:en inte passera på
    # antagandet att den nog var innanför. Meddelandet ska däremot peka på rätt
    # orsak - en felaktig referens och en trasig API-nyckel ser likadana ut i en
    # rå HTTPError, och den som läser loggen letar då på fel ställe.
    try:
        issue = hamta_issue(repo, nummer, token)
    except urllib.error.HTTPError as fel:
        if fel.code == 404:
            notis(
                "error",
                f"#{nummer} finns inte i {repo}. Referensen i PR-kroppen pekar fel - "
                "rätta Closes-raden till issuen den här grenen faktiskt löser.",
            )
        elif fel.code in (401, 403):
            notis(
                "error",
                f"Nekad åtkomst till #{nummer} ({fel.code}). Vanligaste orsaken är att "
                "referensen pekar på en pull request och inte en issue: issues-endpointen "
                "kräver `pull-requests: read` för dem, medan ci.yml ger `issues: read`. "
                "Kontrollera Closes-raden innan du misstänker behörigheterna.",
            )
        else:
            notis("error", f"Kunde inte hämta #{nummer}: {fel}")
        return 1
    except (urllib.error.URLError, TimeoutError) as fel:
        notis("error", f"Kunde inte nå GitHub-API:et för #{nummer}: {fel}")
        return 1

    if "pull_request" in issue:
        notis(
            "error",
            f"#{nummer} är en pull request, inte en issue - den har ingen omfångsruta. "
            "Rätta Closes-raden i PR-kroppen.",
        )
        return 1

    kropp_issue = issue.get("body") or ""

    # Ren varning, oberoende av rutans utfall nedan - byter aldrig exit-koden.
    varna_om_paihittade_konstanter(kropp_issue, nummer)

    # Linjalen flyttad efter att PR:en öppnades? Bara implementationsgrenar -
    # samma villkor som `hitta_issue` använder för att kräva en Closes-rad.
    # Verktygs-, retro- och processgrenar har ingen bindande ruta att flytta.
    # Kan GraphQL-anropet inte göras varnas det i stället för att fälla -
    # se modulens docstring och `hamta_redigeringshistorik`.
    if IMPLEMENTATIONSGREN.match(gren):
        pr_skapad = os.environ.get("PR_CREATED_AT") or ""
        if not pr_skapad:
            notis(
                "warning",
                "PR_CREATED_AT är inte satt - kan inte avgöra om omfångsrutan "
                "redigerades efter att PR:en öppnades. Kontrollen av flyttad "
                "linjal hoppas över.",
            )
        else:
            try:
                historik = hamta_redigeringshistorik(repo, nummer, token)
                kropp_vid_oppning = kropp_vid_pr_oppning(historik, pr_skapad)
            except (
                urllib.error.HTTPError,
                urllib.error.URLError,
                TimeoutError,
                RuntimeError,
                KeyError,
                ValueError,
            ) as fel:
                notis(
                    "warning",
                    f"Kunde inte läsa #{nummer}s redigeringshistorik via GraphQL: {fel}. "
                    "Kontrollen av flyttad linjal hoppas över - vanligaste orsaken är att "
                    "GITHUB_TOKEN saknar GraphQL-behörighet på issuen.",
                )
            else:
                skillnader = linjalen_flyttad(kropp_vid_oppning, kropp_issue)
                if skillnader:
                    for etikett, da, nu in skillnader:
                        notis(
                            "error",
                            f"`{etikett}` i #{nummer} redigerades efter att PR:en öppnades.",
                        )
                        diff = difflib.unified_diff(
                            (da + "\n").splitlines(keepends=True),
                            (nu + "\n").splitlines(keepends=True),
                            fromfile=f"{etikett} vid PR-öppning",
                            tofile=f"{etikett} nu",
                        )
                        print("".join(diff))
                    notis(
                        "error",
                        f"Omfångsrutan i #{nummer} ändrades efter att PR:en öppnades - det "
                        "är att flytta linjalen, inte att rätta en stavning. Deklarera "
                        "avvikelsen under `## Frågor och antaganden` och vänta på svar, "
                        "eller revertera raderna som ligger utanför rutan - vidga inte "
                        "rutan i efterhand.",
                    )
                    return 1

    innanfor = globbar(avsnitt(kropp_issue, "In scope"))
    utanfor = globbar(avsnitt(kropp_issue, "Out of scope"))

    # Felar stängt för implementations-PR:er. Fram till 2026-09-02 var det här en
    # varning plus exit 0, och eftersom rubrikformen inte kändes igen tog varje
    # M2-PR den vägen: tio gröna process-jobb, åtta filer utanför rutan. En grind
    # som svarar "ej tillämplig" går inte att skilja från en som godkänner, så en
    # implementations-PR vars ruta inte går att läsa ska stanna.
    if not innanfor:
        if IMPLEMENTATIONSGREN.match(gren):
            notis(
                "error",
                f"Issue #{nummer} har ingen läsbar In scope-ruta, och `{gren}` är en "
                "implementations-PR. Rutan är bindande enligt AGENTS.md och kan inte "
                "hoppas över: skriv den i issuen som `### In scope` (issue-formuläret) "
                "eller som `**In scope**` följt av en punktlista med sökvägar i "
                "bakåtcitat, och kör om.",
            )
            return 1
        notis(
            "warning",
            f"Issue #{nummer} har ingen ifylld In scope-ruta - kontrollen hoppas över. "
            "Använd .github/ISSUE_TEMPLATE/agent_task.yml när issuen skrivs.",
        )
        return 0

    # Vad som diffas mot är inte självklart, och fel svar ger fantomöverträdelser.
    # `actions/checkout@v4` checkar vid en pull_request-händelse ut refs/pull/N/merge,
    # alltså PR:ens head redan mergad med mains NUVARANDE spets, medan BASE_SHA är
    # pull_request.base.sha från när PR:en öppnades. Allt main hunnit få under tiden
    # ligger därför i `bas...HEAD`, och trepunktsformen hjälper inte - basen är
    # förfader till merge-commiten, så merge-basen ÄR basen. PR #310 fälldes på två
    # filer ur PR #311 och PR #312 på exakt PR #313:s tre; båda mergades röda, och
    # PR #287 (M11) på fyra docs-filer som redan låg i main. Se docs/Process/
    # Lärdomar.md § Bekräftat.
    #
    # På en merge-ref är HEAD^1 mains spets och HEAD^2 PR:ens head, alltså är
    # `HEAD^1 HEAD` exakt det PR:en tillför utöver main just nu. Finns ingen andra
    # förälder - lokal körning, eller en checkout av head i stället för merge-refen -
    # faller kontrollen tillbaka på BASE_SHA, som då är rätt bas.
    if subprocess.run(
        ["git", "rev-parse", "--verify", "--quiet", "HEAD^2"],
        capture_output=True,
        text=True,
    ).returncode == 0:
        diffbas = "HEAD^1"
        tvapunkt = True
    else:
        diffbas = bas
        tvapunkt = False

    # -z, inte radbrytningar: valvets filnamn bär både mellanslag ("docs/00 Index.md")
    # och å/ä/ö, och utan -z styckar en whitespace-split de förra medan git C-citerar
    # de senare ("docs/Process/L\303\244rdomar.md"). Båda ger filer som inte matchar
    # någon glob, alltså falska överträdelser på varje docs-PR. Upptäckt när grinden
    # kördes skarpt första gången, 2026-09-01.
    andrade = [
        f
        for f in subprocess.run(
            ["git", "diff", "--name-only", "-z"]
            + ([diffbas, "HEAD"] if tvapunkt else [f"{diffbas}...HEAD"]),
            capture_output=True,
            text=True,
            check=True,
        ).stdout.split("\0")
        if f
    ]

    # In scope vinner över Out of scope. Rutan är en positiv lista, och `Out of
    # scope` är oftast löptext som förklarar vad som *inte* ingår - och som därför
    # nämner de filer som ingår, i bakåtcitat. Vore ordningen den omvända fälldes
    # sex av M2:s tio PR:er på sina egna tillåtna filer.
    # Beviljade undantag vidgar rutan, men bara de som står i ett arkitektsvar och
    # bara med markören - se beviljade_undantag(). Varje träff skrivs ut, så att
    # omfångsdrift går att räkna i efterhand trots att grinden släpper igenom den.
    undantag: list[tuple[str, str]] = []
    pr_nummer = pr_nummer_ur_handelsen()
    if pr_nummer:
        undantag = beviljade_undantag(repo, pr_nummer, token)

    brott: list[str] = []
    for fil in andrade:
        if any(matchar(fil, m) for m in innanfor):
            continue
        beviljat = [(m, url) for m, url in undantag if matchar(fil, m)]
        if beviljat:
            _, url = beviljat[0]
            notis(
                "warning",
                f"{fil} ligger utanför omfångsrutan men är uttryckligen beviljad i "
                f"arkitektsvaret ({url}). Släpps igenom, räknas som omfångsdrift i retron.",
            )
            continue
        traffad_utanfor = [m for m in utanfor if matchar(fil, m)]
        if traffad_utanfor:
            brott.append(f"{fil} ligger under Out of scope ({', '.join(traffad_utanfor)})")
        else:
            brott.append(f"{fil} matchar ingen glob i In scope")

    print(f"Issue #{nummer}: {len(innanfor)} In scope-globbar, {len(utanfor)} Out of scope.")
    print(f"Diffen rör {len(andrade)} filer.")

    if brott:
        for rad in brott:
            notis("error", rad)
        notis(
            "error",
            f"{len(brott)} fil(er) utanför omfångsrutan. Ligger en fil utanför rutan med "
            "avsikt: skriv vilken och varför i PR:en och vänta på svar - vidga inte rutan "
            "i efterhand.",
        )
        return 1

    print("Alla ändrade filer ligger innanför omfångsrutan.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
