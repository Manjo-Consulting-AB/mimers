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

De fyra räknarna hålls isär med flit. En summa av råa tokens säger inget om vad
sessionen kostade - cache-läsning är en storleksordning billigare än vanlig input,
och hur mycket billigare skiljer sig mellan leverantörerna.
"""

import datetime
import json
import pathlib
import subprocess
import sys

# USD per miljon tokens: (input, output, cache-läsning, cache-skrivning).
#
# Priserna står absolut och inte som multiplikatorer mot input, eftersom
# leverantörerna inte skalar likadant. Claude tar ~0.1x för cache-läsning och
# ~1.25x för cache-skrivning. Deepseek tar ~0.02x för en cache-träff och lägger
# ingen premie alls på skrivningen - en miss är bara fullt inpris. Med en enda
# uppsättning multiplikatorer blir den ena leverantören fel, och det är just den
# termen som väger tyngst i långa sessioner.
#
# Deepseek-raderna är PEAK-pris. Sedan 2026-08-16 debiteras off-peak till 50%,
# vilket den här hooken inte känner till. Det är medvetet: en jämförelse ska inte
# kunna vinnas av att körningen råkade ligga på natten.
PRICES = {
    "claude-opus-5": (5.0, 25.0, 0.5, 6.25),
    "claude-sonnet-5": (2.0, 10.0, 0.2, 2.5),
    "claude-haiku-4-5": (1.0, 5.0, 0.1, 1.25),
    "deepseek-v4-flash": (0.14, 0.28, 0.0028, 0.14),
    "deepseek-v4-pro": (0.435, 0.87, 0.003625, 0.435),
}

COUNTERS = (
    "input_tokens",
    "output_tokens",
    "cache_read_input_tokens",
    "cache_creation_input_tokens",
)


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


def unpriced(per_model: dict) -> list[str]:
    """Modeller i transkriptet som saknas i pristabellen."""
    return sorted(model for model in per_model if model not in PRICES)


def cost_usd(per_model: dict) -> float | None:
    """Kostnad i USD. None om någon modell saknas i pristabellen.

    Att en okänd modell nollar hela sessionen och inte bara sin egen andel är
    avsiktligt: en halv kostnad ser rimlig ut och blir trodd. Vilken modell som
    fällde raden står i fältet `opriced`, annars går den inte att felsöka.
    """
    if unpriced(per_model):
        return None
    total = 0.0
    for model, counters in per_model.items():
        input_price, output_price, cache_read_price, cache_write_price = PRICES[model]
        total += counters["input_tokens"] / 1e6 * input_price
        total += counters["output_tokens"] / 1e6 * output_price
        total += counters["cache_read_input_tokens"] / 1e6 * cache_read_price
        total += counters["cache_creation_input_tokens"] / 1e6 * cache_write_price
    return round(total, 4)


def merge(into: dict, other: dict) -> None:
    for model, counters in other.items():
        bucket = into.setdefault(model, dict.fromkeys(COUNTERS, 0))
        for counter in COUNTERS:
            bucket[counter] += counters[counter]


def git_branch(cwd: str) -> str:
    try:
        return subprocess.run(
            ["git", "-C", cwd, "rev-parse", "--abbrev-ref", "HEAD"],
            capture_output=True,
            text=True,
            timeout=10,
            check=True,
        ).stdout.strip()
    except (subprocess.SubprocessError, OSError):
        return "okänd"


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
        "opriced": unpriced(combined),
    }

    out = pathlib.Path(cwd) / ".claude" / "usage.jsonl"
    out.parent.mkdir(parents=True, exist_ok=True)
    with out.open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(record, ensure_ascii=False) + "\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
