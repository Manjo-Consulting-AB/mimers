#!/usr/bin/env python3
"""SessionEnd-hook: skriv sessionens faktiska förbrukning till .claude/usage.jsonl.

Agenten rapporterar inte sin egen förbrukning - den har ingen tillgång till sin
tokenräkning under sessionen, och ett fält den fyller i för hand blir ett påhittat
men trovärdigt tal. Den här hooken läser transkriptet i stället.

Registreras i .claude/settings.json:

    {"hooks": {"SessionEnd": [{"hooks": [
      {"type": "command", "command": "python3 .claude/hooks/session-usage.py"}
    ]}]}}

Raden nycklas på grennamn. Heter grenarna efter issuenummer mappas kostnaden
till issue utan att något extra fält behöver fyllas i någonstans.

Loggen skrivs till huvudträdets .claude/, inte till sessionens cwd. En session i
en git-worktree har en egen .claude/usage.jsonl - och eftersom filen är
gitignorerad följer den med när worktreen tas bort. M1:s issue-sessioner kördes
alla i worktrees, så alla femton rader som överlevde var main-sessioner: precis
de rader som inte kan svara på vad en issue kostade. Huvudträdet hittas via
`git rev-parse --git-common-dir`, som pekar på originalrepots .git även från en
worktree (--git-dir gör det inte).

De fyra räknarna hålls isär med flit. Cache-läsning kostar ungefär en tiondel av
vanlig input och cache-skrivning ungefär en fjärdedel mer, så en summa av råa
tokens säger inget om vad sessionen kostade.
"""

import datetime
import json
import pathlib
import subprocess
import sys

# USD per miljon tokens. Multiplikatorerna gäller relativt input:
# cache-läsning ~0.1x, cache-skrivning ~1.25x.
PRICES = {
    "claude-opus-5": (5.0, 25.0),
    "claude-sonnet-5": (2.0, 10.0),
    "claude-haiku-4-5": (1.0, 5.0),
}
CACHE_READ_MULTIPLIER = 0.1
CACHE_WRITE_MULTIPLIER = 1.25

COUNTERS = (
    "input_tokens",
    "output_tokens",
    "cache_read_input_tokens",
    "cache_creation_input_tokens",
)

# Modeller som inte är modeller. "<synthetic>" är harnessets egna meddelanden
# (avbrott, felnoteringar); de bär alltid noll tokens men saknas i PRICES, och
# eftersom cost_usd() ger None så snart EN modell saknas nollställde de kostnaden
# för hela raden. Tre av femton rader föll så, och det var de tre dyraste.
GRATIS_MODELLER = frozenset({"<synthetic>"})


def read_usage(transcript: pathlib.Path) -> dict:
    """Summera usage per modell ur ett JSONL-transkript."""
    per_model: dict[str, dict[str, int]] = {}
    for line in transcript.read_text(encoding="utf-8", errors="replace").splitlines():
        try:
            entry = json.loads(line)
        except json.JSONDecodeError:
            continue  # transkriptet skrivs asynkront - sista raden kan vara halv
        if not isinstance(entry, dict) or entry.get("type") != "assistant":
            continue
        message = entry.get("message")
        if not isinstance(message, dict):
            continue
        usage = message.get("usage")
        if not isinstance(usage, dict):
            continue
        bucket = per_model.setdefault(
            message.get("model") or "okänd", dict.fromkeys(COUNTERS, 0)
        )
        for counter in COUNTERS:
            value = usage.get(counter)
            if isinstance(value, int):
                bucket[counter] += value
    return per_model


def cost_usd(per_model: dict) -> float | None:
    """Kostnad i USD. None om någon modell saknas i pristabellen."""
    total = 0.0
    for model, counters in per_model.items():
        if model in GRATIS_MODELLER:
            continue
        price = PRICES.get(model)
        if price is None:
            return None
        input_price, output_price = price
        total += counters["input_tokens"] / 1e6 * input_price
        total += counters["output_tokens"] / 1e6 * output_price
        total += (
            counters["cache_read_input_tokens"]
            / 1e6
            * input_price
            * CACHE_READ_MULTIPLIER
        )
        total += (
            counters["cache_creation_input_tokens"]
            / 1e6
            * input_price
            * CACHE_WRITE_MULTIPLIER
        )
    return round(total, 4)


def merge(into: dict, other: dict) -> None:
    for model, counters in other.items():
        bucket = into.setdefault(model, dict.fromkeys(COUNTERS, 0))
        for counter in COUNTERS:
            bucket[counter] += counters[counter]


def git(cwd: str, *args: str) -> str | None:
    try:
        return subprocess.run(
            ["git", "-C", cwd, *args],
            capture_output=True,
            text=True,
            timeout=10,
            check=True,
        ).stdout.strip()
    except (subprocess.SubprocessError, OSError):
        return None


def git_branch(cwd: str) -> str:
    return git(cwd, "rev-parse", "--abbrev-ref", "HEAD") or "okänd"


def loggfil(cwd: str) -> pathlib.Path:
    """Huvudträdets .claude/usage.jsonl, även när sessionen kör i en worktree."""
    gemensam = git(cwd, "rev-parse", "--path-format=absolute", "--git-common-dir")
    if gemensam:
        return pathlib.Path(gemensam).parent / ".claude" / "usage.jsonl"
    return pathlib.Path(cwd) / ".claude" / "usage.jsonl"


def main() -> int:
    try:
        payload = json.load(sys.stdin)
    except (json.JSONDecodeError, ValueError):
        return 0  # ingen hook-indata är inte ett fel värt att avbryta sessionen för

    transcript = payload.get("transcript_path")
    cwd = payload.get("cwd") or "."
    if not transcript:
        return 0

    transcript = pathlib.Path(transcript)
    if not transcript.exists():
        return 0

    main_usage = read_usage(transcript)

    # Subagenternas transkript ligger i en katalog bredvid huvudtranskriptet,
    # namngiven efter sessionen. De räknas separat - "vad kostade subagenterna"
    # är en egen fråga och ska inte döljas i totalen.
    subagent_usage: dict[str, dict[str, int]] = {}
    subagent_dir = transcript.with_suffix("") / "subagents"
    if subagent_dir.is_dir():
        for path in sorted(subagent_dir.glob("*.jsonl")):
            merge(subagent_usage, read_usage(path))

    combined: dict[str, dict[str, int]] = {}
    merge(combined, main_usage)
    merge(combined, subagent_usage)

    record = {
        "timestamp": datetime.datetime.now(datetime.timezone.utc).isoformat(
            timespec="seconds"
        ),
        "session_id": payload.get("session_id"),
        "branch": git_branch(cwd),
        "main": main_usage,
        "subagents": subagent_usage,
        "cost_usd": cost_usd(combined),
    }

    out = loggfil(cwd)
    out.parent.mkdir(parents=True, exist_ok=True)
    with out.open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(record, ensure_ascii=False) + "\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
