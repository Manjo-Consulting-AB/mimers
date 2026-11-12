#!/usr/bin/env python3
"""Enhetstester för rott-pa-basen.sh - ren python3, inget pytest.

Samma form som test_omfangsruta.py. Skriptet körs på riktigt mot ett litet
git-repo i en tillfällig katalog, med `php` och `composer` utbytta mot
skalskript i PATH. Det falska `php artisan test` låter ett testfall gå igenom
om dess namn innehåller GRON och annars falla; en fil är grön bara om alla dess
testfall har GRON i namnet. Det räcker för att pröva vaktmarkören (issue 1100)
utan Laravel, databas eller vendor.

Körs av CI (.github/workflows/ci.yml, steget "Pipelinens egna enhetstester")
och lokalt med:
    python3 .github/scripts/test_rott_pa_basen.py
"""
import os
import subprocess
import sys
import tempfile

SKRIPT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "rott-pa-basen.sh")

FALSK_PHP = """#!/usr/bin/env bash
# Falsk php: bara `artisan test FIL [--filter=NAMN]` och `artisan key:generate`.
[ "$2" = "test" ] || exit 0
if [ -n "${4:-}" ]; then
    case "$4" in *GRON*) exit 0 ;; *) exit 1 ;; esac
fi
if grep -E "^[[:space:]]*(it|test)\\(" "$3" | grep -qv GRON; then exit 1; fi
exit 0
"""


def _kor(cmd, cwd, env=None):
    return subprocess.run(cmd, cwd=cwd, env=env, capture_output=True, text=True)


def _kor_skriptet(bas_fil, head_fil):
    """Kör skriptet med `bas_fil` som basens testfil (None = ny fil) och
    `head_fil` som PR:ens. Returnerar (exitkod, utdata)."""
    with tempfile.TemporaryDirectory() as rot:
        repo = os.path.join(rot, "repo")
        bin_ = os.path.join(rot, "bin")
        os.makedirs(os.path.join(repo, "tests"))
        os.makedirs(bin_)
        for namn, innehall in (("php", FALSK_PHP), ("composer", "#!/usr/bin/env bash\nexit 0\n")):
            sokvag = os.path.join(bin_, namn)
            with open(sokvag, "w") as f:
                f.write(innehall)
            os.chmod(sokvag, 0o755)
        env = dict(os.environ, PATH=bin_ + os.pathsep + os.environ["PATH"],
                   GIT_AUTHOR_NAME="t", GIT_AUTHOR_EMAIL="t@t", GIT_COMMITTER_NAME="t",
                   GIT_COMMITTER_EMAIL="t@t")
        _kor(["git", "init", "-q", "-b", "main"], repo, env)
        with open(os.path.join(repo, ".env.example"), "w") as f:
            f.write("APP_KEY=\n")
        fil = os.path.join(repo, "tests", "ExempelTest.php")
        if bas_fil is not None:
            with open(fil, "w") as f:
                f.write(bas_fil)
        _kor(["git", "add", "-A"], repo, env)
        _kor(["git", "commit", "-q", "-m", "bas", "--allow-empty"], repo, env)
        bas = _kor(["git", "rev-parse", "HEAD"], repo, env).stdout.strip()
        with open(fil, "w") as f:
            f.write(head_fil)
        _kor(["git", "add", "-A"], repo, env)
        _kor(["git", "commit", "-q", "-m", "head"], repo, env)
        env.update(BASE_SHA=bas, ROTT_PA_BASEN_KATALOG=rot)
        r = _kor(["bash", SKRIPT], repo, env)
        return r.returncode, r.stdout + r.stderr


def _fil(*fall):
    return "<?php\n\n" + "\n".join(fall) + "\n"


BAS = _fil("it('GRON gammal', function () {});")
ROT_FALL = "it('ROD ny', function () {});"
VAKT_FALL = "// vakt: regressionsvakt, ska vara grön\nit('GRON vakt', function () {});"
OMARKT_GRON = "it('GRON omärkt', function () {});"
TOM_VAKT = "// vakt:\nit('GRON tom', function () {});"


def test_vakt_i_rod_fil_ger_ingen_varning():
    kod, ut = _kor_skriptet(BAS, _fil(*[BAS.split("\n\n")[1].strip(), ROT_FALL, VAKT_FALL]))
    assert kod == 0, ut
    assert "::warning" not in ut, ut
    assert "vakt: testfallet 'GRON vakt'" in ut, ut
    assert "regressionsvakt, ska vara grön" in ut, ut


def test_omarkt_fall_i_rod_fil_ger_fortfarande_varning():
    kod, ut = _kor_skriptet(BAS, _fil(ROT_FALL, OMARKT_GRON))
    assert kod == 0, ut
    assert "::warning" in ut and "GRON omärkt" in ut, ut


def test_fil_med_bara_vakter_som_ar_gron_ger_exit_0():
    kod, ut = _kor_skriptet(BAS, _fil("it('GRON gammal', function () {});", VAKT_FALL))
    assert kod == 0, ut
    assert "bara vakter" in ut, ut


def test_gron_fil_med_omarkt_nytt_fall_fallsfortfarande():
    kod, ut = _kor_skriptet(BAS, _fil("it('GRON gammal', function () {});", VAKT_FALL, OMARKT_GRON))
    assert kod == 1, ut
    assert "gröna redan på baskommiten" in ut, ut


def test_vakt_utan_motivering_ar_ingen_markor():
    kod, ut = _kor_skriptet(BAS, _fil("it('GRON gammal', function () {});", TOM_VAKT))
    assert kod == 1, ut
    kod, ut = _kor_skriptet(BAS, _fil(ROT_FALL, TOM_VAKT))
    assert "::warning" in ut and "GRON tom" in ut, ut


def test_markor_maste_sta_direkt_ovanfor():
    glest = "// vakt: motivering\n\nit('GRON glest', function () {});"
    kod, ut = _kor_skriptet(BAS, _fil("it('GRON gammal', function () {});", glest))
    assert kod == 1, ut


def test_ny_fil_med_bara_vakter_ar_ok():
    kod, ut = _kor_skriptet(None, _fil(VAKT_FALL))
    assert kod == 0, ut


if __name__ == "__main__":
    antal = 0
    for namn, fn in sorted(globals().items()):
        if namn.startswith("test_") and callable(fn):
            fn()
            antal += 1
            print(f"ok {namn}")
    print(f"{antal} prov gick igenom")
