#!/usr/bin/env python3
"""Enhetstester för huvudtradet.py. Körs: python3 .claude/hooks/test_huvudtradet.py"""

import os
import pathlib
import shutil
import subprocess
import tempfile
import unittest

HOOK = pathlib.Path(__file__).with_name("huvudtradet.py")
PYTHON = shutil.which("python3")
ENV = {
    **os.environ,
    "GIT_AUTHOR_NAME": "t", "GIT_AUTHOR_EMAIL": "t@t",
    "GIT_COMMITTER_NAME": "t", "GIT_COMMITTER_EMAIL": "t@t",
    "GIT_CONFIG_GLOBAL": os.devnull, "GIT_CONFIG_SYSTEM": os.devnull,
}


def git(cwd, *args):
    subprocess.run(["git", *args], cwd=cwd, env=ENV, check=True, capture_output=True)


def commit(cwd, name):
    pathlib.Path(cwd, name).write_text(name)
    git(cwd, "add", name)
    git(cwd, "commit", "-qm", name)


def run_hook(cwd, env=None):
    return subprocess.run(
        [PYTHON, str(HOOK)], cwd=cwd, env=env or ENV,
        capture_output=True, text=True,
    )


class HuvudtradetTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        root = pathlib.Path(self.tmp.name)
        self.origin = root / "origin.git"
        self.repo = root / "repo"
        git(root, "init", "-q", "--bare", "-b", "main", str(self.origin))
        git(root, "init", "-q", "-b", "main", str(self.repo))
        git(self.repo, "remote", "add", "origin", str(self.origin))
        commit(self.repo, "a")
        git(self.repo, "push", "-q", "-u", "origin", "main")

    def advance_origin(self):
        """Lägg en commit på origin/main och hämta den som lokal referens."""
        other = pathlib.Path(self.tmp.name) / "other"
        git(self.tmp.name, "clone", "-q", str(self.origin), str(other))
        commit(other, "b")
        git(other, "push", "-q", "origin", "main")
        git(self.repo, "fetch", "-q")

    def test_settings_registrerar_hooken_och_behaller_sessionend(self):
        import json
        hooks = json.loads(
            HOOK.parent.with_name("settings.json").read_text()
        )["hooks"]
        start = [h["command"] for g in hooks["SessionStart"] for h in g["hooks"]]
        end = [h["command"] for g in hooks["SessionEnd"] for h in g["hooks"]]
        self.assertIn("python3 .claude/hooks/huvudtradet.py", start)
        self.assertIn("python3 .claude/hooks/session-usage.py", end)

    def test_ci_kor_testfilen_i_pipelinens_enhetstester(self):
        ci = HOOK.parents[2].joinpath(".github/workflows/ci.yml").read_text()
        steg = ci.split("name: Pipelinens egna enhetstester", 1)[1]
        steg = steg.split("\n      - name:", 1)[0]
        self.assertIn("python3 .claude/hooks/test_huvudtradet.py", steg)

    def test_main_i_niva_ar_tyst(self):
        r = run_hook(self.repo)
        self.assertEqual((r.returncode, r.stdout), (0, ""))

    def test_main_efter_origin_ar_tyst(self):
        self.advance_origin()
        self.assertEqual(run_hook(self.repo).stdout, "")

    def test_annan_gren_efter_origin_namnger_gren_antal_och_kommando(self):
        git(self.repo, "switch", "-q", "-c", "drift")
        self.advance_origin()
        r = run_hook(self.repo)
        self.assertEqual(r.returncode, 0)
        self.assertIn("drift", r.stdout)
        self.assertIn("1 commits", r.stdout)
        self.assertIn("git switch main && git pull --ff-only", r.stdout)

    def test_annan_gren_i_niva_ar_tyst(self):
        git(self.repo, "switch", "-q", "-c", "drift")
        self.assertEqual(run_hook(self.repo).stdout, "")

    def test_upstream_gone_ger_rad_utan_att_ligga_efter(self):
        git(self.repo, "switch", "-q", "-c", "drift")
        git(self.repo, "push", "-q", "-u", "origin", "drift")
        git(self.repo, "push", "-q", "origin", "--delete", "drift")
        git(self.repo, "fetch", "-q", "--prune")
        r = run_hook(self.repo)
        self.assertEqual(r.returncode, 0)
        self.assertIn("drift", r.stdout)
        self.assertIn("gone", r.stdout)
        self.assertIn("git switch main && git pull --ff-only", r.stdout)

    def test_worktree_under_claude_worktrees_ar_tyst(self):
        wt = self.repo / ".claude" / "worktrees" / "agent-x"
        git(self.repo, "worktree", "add", "-q", "-b", "feature/x", str(wt))
        self.advance_origin()
        r = run_hook(wt)
        self.assertEqual((r.returncode, r.stdout), (0, ""))

    def test_hooken_anropar_aldrig_fetch_switch_eller_checkout(self):
        src = HOOK.read_text()
        # Docstring och kommentarer får nämna kommandona; koden får inte.
        code = src.split('"""', 2)[2]
        code = "\n".join(
            l for l in code.splitlines() if not l.lstrip().startswith("#")
        )
        for verb in ('"fetch"', '"switch"', '"checkout"'):
            self.assertNotIn(verb, code)

    def test_git_saknas_ger_exit_0_utan_rad(self):
        r = run_hook(self.repo, {**ENV, "PATH": "/nonexistent"})
        self.assertEqual((r.returncode, r.stdout), (0, ""))

    def test_utanfor_git_repo_ger_exit_0_utan_rad(self):
        with tempfile.TemporaryDirectory() as d:
            env = {**ENV, "GIT_CEILING_DIRECTORIES": os.path.dirname(d)}
            r = run_hook(d, env)
        self.assertEqual((r.returncode, r.stdout), (0, ""))


if __name__ == "__main__":
    unittest.main()
