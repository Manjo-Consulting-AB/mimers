#!/usr/bin/env python3
"""Läser en issue mot ruttabellen och pekar ut filer rutan saknar.

Steg 3 i svaret på issue 83 (PR #392). De två första stegen — omfångsläget
`spårad` och att granskningen slutar döma om rutan — gör en trasig ruta
billigare att leva med. Den här filen försöker i stället låta bli att skriva
den trasiga rutan: den körs av kön INNAN implementationssessionen startar, och
säger vad rutan verkar sakna medan det fortfarande kostar en kommentar att
rätta, inte tre granskningsvarv.

**Varför en ruta för en designissue blir fel** står i omfangsruta.py: rutan
skrivs ur valvet, och valvet vet vilka filer som finns — inte vilken av dem som
serverar en skärm. Den kopplingen står i `routes/`, och den går att läsa
maskinellt. Issue 83:s ruta listade `ContainerController.php`; rutten
`GET /containers/{container}` bärs av `ItemController::index()`.

Två halvor, med olika säkerhet:

1. **Den mekaniska.** Varje URL, ruttnamn och sidkomponent issuen faktiskt
   NÄMNER slås upp i ruttabellen, och kontrollern (plus de Inertia-sidor den
   renderar) jämförs med rutan. Deterministiskt: ingen modell, inga
   bedömningar, och den fäller inget — den skriver en rad. Att en nämnd rutt
   bärs av en fil som ligger `Out of scope` är den skarpaste signalen, men inte
   ett bevis: en issue får nämna en rutt som ren destination utan att vilja röra
   den. Därför en rad att läsa, inte en grind att passera.
2. **Den bedömande.** Issue 83:s egen `Klart när`-punkt — "att öppna en
   container sätter sessionsnyckeln" — nämner ingen rutt alls, och den
   mekaniska halvan hade därför gått förbi den. Vilken fil som utför "att öppna
   en container" är en bedömning, alltså en modell, inte ett skript. Den här
   filen bygger frågan (`bygg_modellfraga`) och verifierar svaret
   (`fynd_ur_modellsvar`); själva anropet görs av process_next_issue.py, som
   äger modellanropen. Modellen föreslår, skriptet kontrollerar: en fil som
   inte finns på disk eller som redan täcks av rutan kastas. Frågan binder inte
   modellen till ruttabellen, och det är med flit: PR #398 (issue 84) föll på tre
   filer, varav två — `app/Actions/Container/CreateContainer.php` och
   `resources/js/data/categoryPresets.js` — inte serverar någon URL och alltså
   inte står i tabellen. Precisionen kommer ur att svaret verifieras mot
   filsystemet, inte ur att frågan är snäv.

Linten blockerar aldrig kön. Ett falskt positivt utfall som stoppar arbetet är
dyrare än det den ska spara, och rutan är fortfarande bindande — den som skriver
issuen bestämmer, linten upplyser.

Ruttfilerna skriver sina URI:er i full längd (inga `Route::prefix`-grupper i
det här repot per 2026-09-18, bara `middleware()->group`), så parsningen kan
vara enkel. Skulle en prefixgrupp införas kommer rutterna i den att ha fel URI
här — då är det den här docstringen som ljuger, och `rutter()` som ska lagas.
`routes/api.php` serveras under `/api`, satt i bootstrap/app.php.

Körs av .github/scripts/process_next_issue.py, och för hand medan en issue
skrivs:
    python3 .github/scripts/omfangslint.py --issue 387
    python3 .github/scripts/omfangslint.py --fil issue.md
"""
import argparse
import json
import os
import re
import subprocess
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import omfangsruta as o

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

RUTTFILER = (("routes/web.php", ""), ("routes/api.php", "/api"))

# Route::get('/uri', [X::class, 'metod']) och Route::get('/uri', X::class).
# Verben räknas upp i stället för \w+ - `Route::middleware(...)` och
# `Route::redirect(...)` ska inte läsas som rutter till en kontroller.
RUTTRAD = re.compile(
    r"Route::(?P<metod>get|post|put|patch|delete|options|any|match)\(\s*"
    r"'(?P<uri>[^']*)'\s*,\s*"
    r"(?:\[\s*(?P<klass>[A-Za-z_]\w*)::class\s*,\s*'(?P<action>[^']+)'\s*\]"
    r"|(?P<invokerbar>[A-Za-z_]\w*)::class)",
)
NAMN = re.compile(r"->name\('([^']+)'\)")
IMPORT = re.compile(r"^use\s+(App\\[\w\\]+);", re.MULTILINE)
INERTIA = re.compile(r"Inertia::render\(\s*'([^']+)'")
# Sökvägsliknande tokens i issuen: /settings/security, /containers/{container}.
URL_I_TEXT = re.compile(r"(?<![\w/])(/[a-z0-9][a-z0-9\-/]*(?:\{[a-z_]+\}[a-z0-9\-/]*)*)")
# Ruttnamn står alltid i bakåtcitat i issuerna: `containers.show`.
NAMN_I_TEXT = re.compile(r"`([a-z][a-z0-9_-]*(?:\.[a-z0-9_-]+)+)`")
KLART_NAR_PUNKT = re.compile(r"^\s*-\s*\[[ xX]\]\s*(.+?)\s*$", re.MULTILINE)


class Rutt:
    def __init__(self, metod, uri, klass, action, namn, fil, sidor):
        self.metod = metod
        self.uri = uri
        self.klass = klass
        self.action = action
        self.namn = namn
        self.fil = fil
        self.sidor = sidor

    def __repr__(self):
        return f"<{self.metod.upper()} {self.uri} -> {self.fil}::{self.action or '__invoke'}>"

    def filer(self):
        return [self.fil] + list(self.sidor)


def klassfil(fqcn: str) -> str:
    """`App\\Http\\Controllers\\ItemController` -> `app/Http/Controllers/ItemController.php`."""
    delar = fqcn.split("\\")
    return os.path.join("app", *delar[1:]) + ".php"


def inertiasidor(controllerfil: str, rot: str) -> list[str]:
    """Sidkomponenterna kontrollern renderar, som sökvägar under resources/js/pages.

    Sidnamnet är kontraktet mellan rutten och komponenten (routes/web.php säger
    det själv i sin ingress), så en issue som ändrar vad en skärm visar rör
    nästan alltid båda - och rutan behöver därför nämna båda.
    """
    absolut = os.path.join(rot, controllerfil)
    if not os.path.exists(absolut):
        return []
    with open(absolut, encoding="utf-8") as f:
        innehall = f.read()
    sidor = []
    for namn in dict.fromkeys(INERTIA.findall(innehall)):
        sokvag = f"resources/js/pages/{namn}.vue"
        if os.path.exists(os.path.join(rot, sokvag)):
            sidor.append(sokvag)
    return sidor


def rutter(rot: str = REPO_ROOT) -> list[Rutt]:
    """Ruttabellen, läst statiskt ur routes/*.php.

    Statiskt och inte `php artisan route:list --json`: linten ska gå att köra
    innan `composer install`, utan .env och utan APP_KEY - alltså också i en
    fräsch worktree och i CI. Priset är att formerna nedan måste kännas igen en
    och en; vinsten är att den inte kan falla på att appen inte startar.
    """
    tabell: list[Rutt] = []
    for relativ, prefix in RUTTFILER:
        absolut = os.path.join(rot, relativ)
        if not os.path.exists(absolut):
            continue
        with open(absolut, encoding="utf-8") as f:
            kalla = f.read()

        importer = {fqcn.split("\\")[-1]: fqcn for fqcn in IMPORT.findall(kalla)}
        sidcache: dict[str, list[str]] = {}

        for traff in RUTTRAD.finditer(kalla):
            klass = traff.group("klass") or traff.group("invokerbar")
            fqcn = importer.get(klass)
            if not fqcn:
                continue
            fil = klassfil(fqcn)
            if fil not in sidcache:
                sidcache[fil] = inertiasidor(fil, rot)

            # Namnet står på samma sats, alltså före nästa Route::-anrop.
            svans = kalla[traff.end():traff.end() + 400]
            nasta = svans.find("Route::")
            if nasta != -1:
                svans = svans[:nasta]
            namntraff = NAMN.search(svans)

            uri = traff.group("uri")
            if not uri.startswith("/"):
                uri = "/" + uri
            tabell.append(Rutt(
                metod=traff.group("metod"),
                uri=(prefix + uri).rstrip("/") or "/",
                klass=klass,
                action=traff.group("action"),
                namn=namntraff.group(1) if namntraff else None,
                fil=fil,
                sidor=sidcache[fil],
            ))
    return tabell


def _nyckel(uri: str) -> str:
    """URI:n med parameternamnen borttagna, så `/c/{container}` och `/c/{id}`
    jämförs som samma rutt - issuen skriver sällan av parameternamnet exakt."""
    return re.sub(r"\{[^}]*\}", "{}", uri.rstrip("/")) or "/"


def namnda_rutter(kropp: str, tabell: list[Rutt]) -> list[tuple[str, Rutt]]:
    """(referensen som stod i issuen, rutten den syftar på) - utan dubletter."""
    trafffar: list[tuple[str, Rutt]] = []
    sedda: set[tuple[str, int]] = set()

    per_uri: dict[str, list[Rutt]] = {}
    per_namn: dict[str, Rutt] = {}
    for rutt in tabell:
        per_uri.setdefault(_nyckel(rutt.uri), []).append(rutt)
        if rutt.namn:
            per_namn[rutt.namn] = rutt

    for url in dict.fromkeys(URL_I_TEXT.findall(kropp)):
        for rutt in per_uri.get(_nyckel(url), []):
            if (url, id(rutt)) not in sedda:
                sedda.add((url, id(rutt)))
                trafffar.append((url, rutt))

    for namn in dict.fromkeys(NAMN_I_TEXT.findall(kropp)):
        rutt = per_namn.get(namn)
        if rutt and (namn, id(rutt)) not in sedda:
            sedda.add((namn, id(rutt)))
            trafffar.append((namn, rutt))

    return trafffar


def mekaniska_fynd(kropp: str, innanfor: list[str], utanfor: list[str],
                   tabell: list[Rutt]) -> list[str]:
    """Filer som bär något issuen nämner men som rutan inte täcker."""
    fynd: list[str] = []
    rapporterade: set[str] = set()

    for referens, rutt in namnda_rutter(kropp, tabell):
        for fil in rutt.filer():
            if fil in rapporterade or any(o.matchar(fil, m) for m in innanfor):
                continue
            rapporterade.add(fil)
            traffad_utanfor = [m for m in utanfor if o.matchar(fil, m)]
            vad = f"`{rutt.metod.upper()} {rutt.uri}`" if referens.startswith("/") else f"`{referens}`"
            if traffad_utanfor:
                fynd.append(
                    f"- `{fil}` bär {vad}, som issuen nämner — men filen ligger "
                    f"**Out of scope** ({', '.join(traffad_utanfor)}). Nämns rutten bara som "
                    f"en destination är det i sin ordning; ska beteendet bakom den ändras "
                    f"säger issuen emot sig själv."
                )
            else:
                fynd.append(f"- `{fil}` bär {vad}, som issuen nämner, men matchar ingen glob i `In scope`.")
    return fynd


def klart_nar_punkter(kropp: str) -> list[str]:
    return [t.strip() for t in KLART_NAR_PUNKT.findall(o.avsnitt(kropp, "Klart när")) if t.strip()]


def ruttabell_text(tabell: list[Rutt]) -> str:
    rader = []
    for rutt in tabell:
        sidor = f" [{', '.join(rutt.sidor)}]" if rutt.sidor else ""
        rader.append(f"{rutt.metod.upper():6} {rutt.uri} -> {rutt.fil}::{rutt.action or '__invoke'}{sidor}")
    return "\n".join(rader)


def bygg_modellfraga(kropp: str, innanfor: list[str], tabell: list[Rutt]) -> str | None:
    """Frågan till modellen: vilken fil utför varje `Klart när`-punkt?

    Medvetet smal. Modellen får inte koden, bara punkterna, rutabellen och
    rutan, och ombeds svara med sökvägar - inte med en bedömning av issuen.
    Ju mindre den har att tycka om, desto mindre finns att hallucinera, och
    fynd_ur_modellsvar() kastar ändå det som inte finns på disk.
    """
    punkter = klart_nar_punkter(kropp)
    if not punkter:
        return None
    punktlista = "\n".join(f"{i}. {text}" for i, text in enumerate(punkter, 1))
    return (
        "Du kontrollerar en issues omfångsruta INNAN någon börjar bygga. Du ska inte "
        "skriva kod, inte bedöma issuen och inte föreslå en lösning.\n\n"
        "Frågan är EN: bär någon av punkterna nedan en fil som inte täcks av rutan?\n\n"
        f"=== KLART NÄR ===\n{punktlista}\n\n"
        f"=== IN SCOPE (globbar) ===\n" + "\n".join(innanfor) + "\n\n"
        f"=== RUTTABELL (URI -> kontrollerfil::metod [Inertia-sidor]) ===\n"
        f"{ruttabell_text(tabell)}\n\n"
        "Gå igenom punkterna en och en och fråga dig vilken BEFINTLIG fil som måste "
        "ändras för att punkten ska bli sann. Är den filen täckt av en glob i In scope: "
        "hoppa över punkten, den är i sin ordning.\n\n"
        "Svara med enbart rader på formen\n"
        "SAKNAS: <sökväg> — punkt <nummer>\n"
        "en per fil, eller ordet INGA om varje punkt täcks av rutan. Ingen annan text.\n"
        "Ruttabellen är din utgångspunkt, inte din gräns: bär punkten en fil som inte "
        "serverar en URL - en Action, en delad datamodul, en komponent - så skriv den, "
        "men bara om du är säker på att den redan finns i repot. Gissa aldrig en sökväg. "
        "Är du osäker på en punkt, hoppa över den: rutan gäller ändå, och grinden "
        "kontrollerar den ändå."
    )


SAKNAS_RAD = re.compile(r"^\s*SAKNAS:\s*`?([^\s`—-]+)`?\s*(?:[—-]\s*(.*))?$", re.MULTILINE)


def fynd_ur_modellsvar(svar: str, innanfor: list[str], rot: str = REPO_ROOT) -> list[str]:
    """Modellens rader, minus allt skriptet kan motbevisa.

    Tre filter, i ordning: filen ska finnas på disk (annars är den gissad),
    den ska inte redan täckas av rutan (modellen läser inte globbar lika noga
    som fnmatch), och samma fil rapporteras en gång. Kvar blir bara påståenden
    som är sanna om filsystemet och falska om rutan - alltså precis de fynd
    linten finns för.
    """
    fynd: list[str] = []
    sedda: set[str] = set()
    for sokvag, motivering in SAKNAS_RAD.findall(svar):
        sokvag = sokvag.strip().strip("`")
        if sokvag in sedda:
            continue
        sedda.add(sokvag)
        if not os.path.exists(os.path.join(rot, sokvag)):
            continue
        if any(o.matchar(sokvag, m) for m in innanfor):
            continue
        svans = f" — {motivering.strip()}" if motivering and motivering.strip() else ""
        fynd.append(f"- `{sokvag}` verkar bära en `Klart när`-punkt men matchar ingen glob i `In scope`{svans}")
    return fynd


RUBRIK = "### Omfångslinten"


def rapport(mekaniska: list[str], modellfynd: list[str], lage: str) -> str | None:
    """Kommentaren, eller None när det inte finns något att säga.

    Tyst när rutan ser hel ut. En lint som skriver en kommentar på varje issue
    slutar läsas, precis som en grind som är röd på allt slutar betyda något
    (se ci.yml om ordbudgeten).
    """
    if not mekaniska and not modellfynd:
        return None
    rader = [RUBRIK, "", "Rutan verkar sakna filer som bär det issuen beskriver. "
             "Linten fäller ingenting — den läser `routes/` innan sessionen startar, "
             "så att rutan går att rätta medan det kostar en redigering.", ""]
    if mekaniska:
        rader += ["**Nämnda rutter, upplösta mot ruttabellen:**", ""] + mekaniska + [""]
    if modellfynd:
        rader += ["**`Klart när`-punkter utan en fil i rutan:**", ""] + modellfynd + [""]
    if lage == o.OMFANGSLAGE_SPARAD:
        rader.append("Issuen står i läget `spårad`: behövs en av filerna ändå går den att "
                     "deklarera i PR-kroppen under `Utanför rutan:`. Vidga gärna rutan här "
                     "i stället — en deklaration räknas som omfångsdrift i retron.")
    else:
        rader.append("Issuen står i läget `fast`: en fil utanför rutan går inte att "
                     "deklarera sig till. Antingen hör filerna hemma i rutan, eller så gör "
                     "de det inte — och då är det bra att det står här i förväg.")
    return "\n".join(rader)


def analysera(kropp: str, rot: str = REPO_ROOT) -> tuple[list[str], str | None, list[str], str]:
    """(mekaniska fynd, frågan till modellen, In scope-globbarna, omfångsläget)."""
    innanfor = o.globbar(o.avsnitt(kropp, "In scope"))
    utanfor = o.globbar(o.avsnitt(kropp, "Out of scope"))
    lage = o.omfangslage(kropp)
    if not innanfor:
        return [], None, [], lage
    tabell = rutter(rot)
    return mekaniska_fynd(kropp, innanfor, utanfor, tabell), bygg_modellfraga(kropp, innanfor, tabell), innanfor, lage


def main() -> int:
    parser = argparse.ArgumentParser(description="Läs en issue mot ruttabellen.")
    parser.add_argument("--issue", help="issuenummer att hämta med gh")
    parser.add_argument("--fil", help="fil med issuekroppen i stället för --issue")
    parser.add_argument("--fraga", action="store_true",
                        help="skriv ut frågan till modellen i stället för rapporten")
    args = parser.parse_args()

    if args.fil:
        with open(args.fil, encoding="utf-8") as f:
            kropp = f.read()
    elif args.issue:
        kropp = json.loads(subprocess.run(
            ["gh", "issue", "view", args.issue, "--json", "body"],
            capture_output=True, text=True, check=True, cwd=REPO_ROOT,
        ).stdout)["body"]
    else:
        parser.error("--issue eller --fil krävs")

    mekaniska, fraga, _, lage = analysera(kropp)
    if args.fraga:
        print(fraga or "(inga Klart när-punkter att fråga om)")
        return 0

    text = rapport(mekaniska, [], lage)
    print(text or "Rutan täcker varje rutt issuen nämner. Den bedömande halvan körs av kön.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
