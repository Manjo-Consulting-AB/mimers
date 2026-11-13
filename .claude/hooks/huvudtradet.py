#!/usr/bin/env python3
"""SessionStart-hook: säg till när huvudträdet står på en inaktuell gren.

Cron och interaktiva sessioner läser filer från huvudträdets utcheckade gren.
Står trädet kvar på en mergad gren som ligger efter origin/main läser sessionen
gamla regler (AGENTS.md, retro.md, hooks). Hooken skriver då en rad på stdout,
som Claude Code lägger i sessionens kontext.

Registreras i .claude/settings.json under SessionStart.

Hooken läser bara lokala referenser: ingen hämtning, ingen nätverkstrafik. Den
byter aldrig gren och ändrar ingenting - ett byte är Tonys beslut, eftersom cron
kör den utcheckade grenen. Den avslutar alltid med 0; git som saknas eller felar
ger ingen rad.
"""

import os
import subprocess
import sys

FIX = "git switch main && git pull --ff-only"


def git(*args):
    """Kör ett skrivskyddat git-kommando; None vid fel eller om git saknas."""
    try:
        res = subprocess.run(
            ["git", *args], capture_output=True, text=True, timeout=10
        )
    except (OSError, subprocess.SubprocessError):
        return None
    if res.returncode != 0:
        return None
    return res.stdout.strip()


def message():
    top = git("rev-parse", "--show-toplevel")
    if not top:
        return None
    # Agenternas worktrees ligger under .claude/worktrees/ och är avsiktligt
    # på egna grenar.
    if "/.claude/worktrees/" in top.replace(os.sep, "/") + "/":
        return None

    branch = git("symbolic-ref", "--short", "-q", "HEAD")
    if not branch or branch == "main":
        return None

    behind = 0
    count = git("rev-list", "--count", "HEAD..origin/main")
    if count is not None and count.isdigit():
        behind = int(count)

    track = git("for-each-ref", "--format=%(upstream:track)", f"refs/heads/{branch}")
    gone = track is not None and "[gone]" in track

    if behind == 0 and not gone:
        return None

    parts = []
    if behind:
        parts.append(f"ligger {behind} commits efter origin/main")
    if gone:
        parts.append("har ingen upstream längre (gone)")
    return (
        f"Huvudträdet står på grenen {branch}, som {' och '.join(parts)}. "
        f"Du läser inaktuella regler. Byt med: {FIX}"
    )


def main():
    try:
        line = message()
        if line:
            print(line)
    except Exception:
        pass
    return 0


if __name__ == "__main__":
    sys.exit(main())
