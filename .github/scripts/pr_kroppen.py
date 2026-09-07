#!/usr/bin/env python3
"""Kontrollerar att en implementations-PR bär PR-mallens två retro-fält.

`## Frågor och antaganden` och `## Processnotering` är de enda artefakter som
överlever sessionen. Båda har nu försvunnit tyst en gång var: PR #168 öppnades
utan frågeavsnittet trots att issue 149 uttryckligen krävde tre punkter där, och
PR #210 utan processnoteringen överhuvudtaget. Båda gångerna var det ett dyrare,
modellbaserat steg — eller inget steg alls — som fick upptäcka en frånvarande
rubrik, och #210 mergades grön. Rubriken finns eller finns inte: deterministiskt
villkor, alltså ett skript. Se docs/Process/Lärdomar.md.

Kontrollen kräver bara att rubriken finns med något under sig, inte att
innehållet är bra. "Inget." och "Inga." är giltiga och vanliga svar enligt
AGENTS.md; det som inte är giltigt är att hoppa över fältet. Mallens egna
HTML-kommentarer räknas inte som innehåll — annars vore en oredigerad mall grön.

Gäller bara implementationsgrenar. Retro-, process- och teknisk skuld-PR:er
använder inte mallen. Grenprefixet är samma avgränsning som granskningsgrinden
använde innan den flyttade in i kön (process_next_issue.py: pr_far_mergas).
"""

from __future__ import annotations

import os
import re
import sys

RUBRIKER = ("Frågor och antaganden", "Processnotering")


def ar_implementationsgren(gren: str) -> bool:
    return re.match(r"^(feature/)?issue-\d+", gren) is not None


def innehall_under(kropp: str, rubrik: str) -> str | None:
    """Texten mellan rubriken och nästa `## `. None om rubriken saknas."""
    traff = re.search(rf"^##[ \t]+{re.escape(rubrik)}[ \t]*$", kropp, flags=re.M)
    if traff is None:
        return None
    rest = kropp[traff.end() :]
    nasta = re.search(r"^##[ \t]+", rest, flags=re.M)
    return (rest[: nasta.start()] if nasta else rest).strip()


def main() -> int:
    gren = os.environ.get("HEAD_REF", "")
    if not ar_implementationsgren(gren):
        print(
            f"::notice::`{gren}` är ingen implementationsgren — "
            "mallkravet gäller inte."
        )
        return 0

    kropp = re.sub(r"<!--.*?-->", "", os.environ.get("PR_BODY") or "", flags=re.S)

    brott = []
    for rubrik in RUBRIKER:
        text = innehall_under(kropp, rubrik)
        if text is None:
            brott.append(f"`## {rubrik}` saknas i PR-kroppen")
        elif not text:
            brott.append(f"`## {rubrik}` finns men är tom")

    for rad in brott:
        print(f"::error::{rad}")

    if brott:
        print(
            '::error::PR-mallens två retro-fält är obligatoriska. "Inget." och '
            '"Inga." är giltiga svar — men skriv dem aktivt, hoppa inte över '
            "fältet. Det är det enda som överlever sessionen och läses vid "
            "milstolpsretron. Se AGENTS.md och docs/Process/Lärdomar.md."
        )
        return 1

    print("Båda fälten finns och är ifyllda.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
