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
    GITHUB_TOKEN       för att hämta issuen
    BASE_SHA           commit att diffa mot

Avslutar 0 om allt ligger innanför rutan, eller om kontrollen inte är tillämplig
(process-PR utan issuereferens, eller issue utan ifylld ruta). 1 vid överträdelse,
och 1 när en implementations-PR saknar issuereferens.
"""

import fnmatch
import json
import os
import re
import subprocess
import sys
import urllib.error
import urllib.request

# GitHub renderar issue-formulärets fält som "### <etikett>" följt av innehållet.
RUBRIK = re.compile(r"^###\s+(.+?)\s*$", re.MULTILINE)
# GitHub stänger på close/closes/closed, fix/fixes/fixed, resolve/resolves/resolved.
# `stänger` accepteras för PR:er skrivna före 2026-08-30 men varnar - den stänger inget.
STANGER = re.compile(r"\b(clos(?:e|es|ed)|fix(?:|es|ed)|resolv(?:e|es|ed)|stänger)\s+#(\d+)", re.IGNORECASE)
# Implementationsgrenar heter issue-NN-kort-namn, se AGENTS.md § Arbetsgång.
IMPLEMENTATIONSGREN = re.compile(r"^issue-\d+")
TOMT = {"_No response_", "_Inget svar_"}


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


def avsnitt(kropp: str, etikett: str) -> str:
    """Texten under "### <etikett>", fram till nästa rubrik."""
    traffar = list(RUBRIK.finditer(kropp))
    for index, traff in enumerate(traffar):
        if traff.group(1).strip().lower() != etikett.lower():
            continue
        start = traff.end()
        slut = traffar[index + 1].start() if index + 1 < len(traffar) else len(kropp)
        return kropp[start:slut]
    return ""


def globbar(text: str) -> list[str]:
    """Raderna i ett avsnitt, utan kodstaket, kommentarer och tomrader."""
    rader = []
    for rad in text.splitlines():
        rad = rad.strip()
        if not rad or rad.startswith("```") or rad.startswith("#") or rad in TOMT:
            continue
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

    innanfor = globbar(avsnitt(kropp_issue, "In scope"))
    utanfor = globbar(avsnitt(kropp_issue, "Out of scope"))

    if not innanfor:
        notis(
            "warning",
            f"Issue #{nummer} har ingen ifylld In scope-ruta - kontrollen hoppas över. "
            "Använd .github/ISSUE_TEMPLATE/agent_task.yml när issuen skrivs.",
        )
        return 0

    # -z, inte radbrytningar: valvets filnamn bär både mellanslag ("docs/00 Index.md")
    # och å/ä/ö, och utan -z styckar en whitespace-split de förra medan git C-citerar
    # de senare ("docs/Process/L\303\244rdomar.md"). Båda ger filer som inte matchar
    # någon glob, alltså falska överträdelser på varje docs-PR. Upptäckt när grinden
    # kördes skarpt första gången, 2026-09-01.
    andrade = [
        f
        for f in subprocess.run(
            ["git", "diff", "--name-only", "-z", f"{bas}...HEAD"],
            capture_output=True,
            text=True,
            check=True,
        ).stdout.split("\0")
        if f
    ]

    brott: list[str] = []
    for fil in andrade:
        traffad_utanfor = [m for m in utanfor if matchar(fil, m)]
        if traffad_utanfor:
            brott.append(f"{fil} ligger under Out of scope ({', '.join(traffad_utanfor)})")
        elif not any(matchar(fil, m) for m in innanfor):
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
