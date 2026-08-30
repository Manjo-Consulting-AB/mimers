#!/usr/bin/env python3
"""Kontrollera att PR:ens diff håller sig inom issuens omfångsruta.

Omfångsrutan är bindande enligt AGENTS.md, men fram tills nu har ingen kontrollerat
den. Globbarna i `.github/ISSUE_TEMPLATE/agent_task.yml` skrivs maskinläsbart just
för det här - den kontrollen är det som gör en billigare modell säker att köra,
eftersom den fångar den dyraste feltypen (ändringar utanför rutan) utan att någon
behöver läsa diffen rad för rad.

Deterministiskt villkor, alltså ett skript - inte en modell. Samma resonemang som
ordbudgeten i ci.yml.

Läser:
    PR_BODY            PR-beskrivningen, för att hitta "Stänger #NN"
    GITHUB_REPOSITORY  ägare/repo
    GITHUB_TOKEN       för att hämta issuen
    BASE_SHA           commit att diffa mot

Avslutar 0 om allt ligger innanför rutan eller om kontrollen inte är tillämplig
(PR:en stänger ingen issue, eller issuen saknar ifylld ruta), 1 vid överträdelse.
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
STANGER = re.compile(r"[Ss]tänger\s+#(\d+)")
TOMT = {"_No response_", "_Inget svar_"}


def notis(niva: str, text: str) -> None:
    """Skriv en GitHub Actions-annotering. Hamnar i körningens sammanfattning."""
    print(f"::{niva}::{text}")


def hamta_issue(repo: str, nummer: str, token: str) -> str:
    begaran = urllib.request.Request(
        f"https://api.github.com/repos/{repo}/issues/{nummer}",
        headers={
            "Authorization": f"Bearer {token}",
            "Accept": "application/vnd.github+json",
            "X-GitHub-Api-Version": "2022-11-28",
        },
    )
    with urllib.request.urlopen(begaran, timeout=30) as svar:
        return json.load(svar).get("body") or ""


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


def main() -> int:
    kropp_pr = os.environ.get("PR_BODY") or ""
    repo = os.environ.get("GITHUB_REPOSITORY") or ""
    token = os.environ.get("GITHUB_TOKEN") or ""
    bas = os.environ.get("BASE_SHA") or ""

    traff = STANGER.search(kropp_pr)
    if not traff:
        notis("notice", "PR:en stänger ingen issue - omfångsrutan hoppas över.")
        return 0
    nummer = traff.group(1)

    if not (repo and token and bas):
        notis("error", "GITHUB_REPOSITORY, GITHUB_TOKEN och BASE_SHA måste vara satta.")
        return 1

    try:
        kropp_issue = hamta_issue(repo, nummer, token)
    except (urllib.error.URLError, urllib.error.HTTPError, TimeoutError) as fel:
        notis("error", f"Kunde inte hämta issue #{nummer}: {fel}")
        return 1

    innanfor = globbar(avsnitt(kropp_issue, "In scope"))
    utanfor = globbar(avsnitt(kropp_issue, "Out of scope"))

    if not innanfor:
        notis(
            "warning",
            f"Issue #{nummer} har ingen ifylld In scope-ruta - kontrollen hoppas över. "
            "Använd .github/ISSUE_TEMPLATE/agent_task.yml när issuen skrivs.",
        )
        return 0

    andrade = subprocess.run(
        ["git", "diff", "--name-only", f"{bas}...HEAD"],
        capture_output=True,
        text=True,
        check=True,
    ).stdout.split()

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
