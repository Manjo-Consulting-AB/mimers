#!/usr/bin/env python3
"""Postar ett spår på en PR varje gång den granskas eller eskaleras.

Varje granskning och varje eskalering ska lämna en kommentar som ett skript kan
läsa, oavsett om kön eller en interaktiv session gjorde jobbet. Batchen issue
1033-1095 granskades interaktivt och 33 av 34 PR:er fick inget spår alls, så
retron kunde inte se vem som granskat eller om någon eskalerat. Se
docs/Process/Lärdomar.md § Observerat (posten från 2026-11-11).

Kommentarens första rad är en markör:

    <!-- mimers-pipeline: granskning utfall=<utfall> sha=<head-sha> granskare=<…> -->

därefter rubriken `### <granskare> Granskning: <utfall>` och texten. Utfallen är
`godkänd`, `omtag` och `eskalerad`. En `eskalerad` måste säga till vem - arkitekten
eller Tony - annars postas ingenting och skriptet avslutar med 1.

En interaktiv session som granskar eller eskalerar en PR anropar skriptet:

    python3 .github/scripts/granskningsspar.py --pr 1099 --utfall godkänd \\
        --granskare Tony --text-fil granskning.md

Kön importerar `posta_spar()` i stället för att starta en process. Allt går via
REST (`gh api`), aldrig `gh pr comment`.

Körs av CI (steget "Pipelinens egna enhetstester") med:
    python3 .github/scripts/test_granskningsspar.py
"""
from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys

UTFALL = ("godkänd", "omtag", "eskalerad")
MARKOR_PREFIX = "<!-- mimers-pipeline: granskning "
_MOTTAGARE = re.compile(r"arkitekt|\btony\b", re.I)


def _gh(args: list[str], stdin: str | None = None) -> str:
    """Ett `gh`-anrop. Enda stället som rör nätet - tester byter ut det."""
    res = subprocess.run(["gh", *args], input=stdin, capture_output=True,
                         text=True, timeout=120)
    if res.returncode != 0:
        raise RuntimeError(res.stderr.strip() or f"gh {' '.join(args[:2])} misslyckades")
    return res.stdout


def ar_sparkommentar(body: str | None) -> bool:
    """Är det här en kommentar skriven av skriptet? Läsarna använder den vid
    sidan av det gamla ordet `Granskningsanalys` i rubriken."""
    return (body or "").startswith(MARKOR_PREFIX)


def fel_i_indata(utfall: str, text: str) -> str | None:
    if utfall not in UTFALL:
        return f"okänt utfall `{utfall}` (giltiga: {', '.join(UTFALL)})"
    if not text.strip():
        return "texten är tom"
    if utfall == "eskalerad" and not _MOTTAGARE.search(text):
        return "`eskalerad` måste säga till vem: arkitekten eller Tony"
    return None


def bygg_kommentar(utfall: str, sha: str, granskare: str, text: str) -> str:
    markor = f"{MARKOR_PREFIX}utfall={utfall} sha={sha} granskare={granskare} -->"
    return f"{markor}\n### {granskare} Granskning: {utfall}\n{text.strip()}"


def posta_spar(pr: str | int, utfall: str, granskare: str, text: str,
               repo: str = "{owner}/{repo}") -> str:
    """Postar spåret. Reser ValueError före något nätanrop om indatat är fel."""
    fel = fel_i_indata(utfall, text)
    if fel:
        raise ValueError(fel)
    sha = _gh(["api", f"repos/{repo}/pulls/{pr}", "-q", ".head.sha"]).strip()
    kropp = bygg_kommentar(utfall, sha, granskare, text)
    _gh(["api", f"repos/{repo}/issues/{pr}/comments", "--input", "-"],
        stdin=json.dumps({"body": kropp}))
    return kropp


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description="Posta ett granskningsspår på en PR.")
    ap.add_argument("--pr", required=True)
    ap.add_argument("--utfall", required=True)
    ap.add_argument("--granskare", required=True)
    ap.add_argument("--text-fil", required=True)
    ap.add_argument("--repo", default="{owner}/{repo}")
    a = ap.parse_args(argv)
    try:
        with open(a.text_fil, encoding="utf-8") as f:
            text = f.read()
        posta_spar(a.pr, a.utfall, a.granskare, text, repo=a.repo)
    except (ValueError, OSError, RuntimeError) as e:
        print(f"granskningsspar: {e}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
