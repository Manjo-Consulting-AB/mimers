#!/usr/bin/env python3
"""Enhetstester för granskningsspar.py - ren python3, inget pytest, inga nätanrop.

`_gh` är det enda som rör nätet, och varje test byter ut det.

Körs av CI (steget "Pipelinens egna enhetstester") och lokalt med:
    python3 .github/scripts/test_granskningsspar.py
"""
import json
import os
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import granskningsspar as g


def _fanga():
    """Byt ut _gh; returnerar listan som samlar anropen."""
    anrop = []

    def falsk(args, stdin=None):
        anrop.append((args, stdin))
        return "abc123\n" if "-q" in args else ""

    g._gh = falsk
    return anrop


def test_forsta_raden_ar_markoren_med_utfall_sha_och_granskare():
    anrop = _fanga()
    g.posta_spar(7, "godkänd", "Sonnet", "Allt stämmer.", repo="o/r")
    skickad = json.loads(anrop[1][1])["body"]
    assert skickad.splitlines()[0] == (
        "<!-- mimers-pipeline: granskning utfall=godkänd sha=abc123 granskare=Sonnet -->")
    assert skickad.splitlines()[1] == "### Sonnet Granskning: godkänd"
    assert skickad.endswith("Allt stämmer.")


def test_anropen_gar_via_rest_inte_gh_pr_comment():
    anrop = _fanga()
    g.posta_spar(7, "omtag", "Tony", "Fynd.", repo="o/r")
    assert anrop[0][0][:2] == ["api", "repos/o/r/pulls/7"]
    assert anrop[1][0][:2] == ["api", "repos/o/r/issues/7/comments"]
    assert all(a[0][0] == "api" for a in anrop)


def test_okant_utfall_postar_ingenting_och_ger_exit_1():
    anrop = _fanga()
    with tempfile.NamedTemporaryFile("w", suffix=".md", delete=False, encoding="utf-8") as f:
        f.write("text")
    try:
        assert g.main(["--pr", "1", "--utfall", "kanske", "--granskare", "x", "--text-fil", f.name]) == 1
    finally:
        os.unlink(f.name)
    assert anrop == []


def test_eskalerad_utan_mottagare_postar_ingenting_och_ger_exit_1():
    anrop = _fanga()
    with tempfile.NamedTemporaryFile("w", suffix=".md", delete=False, encoding="utf-8") as f:
        f.write("Svårt att säga.")
    try:
        assert g.main(["--pr", "1", "--utfall", "eskalerad", "--granskare", "x", "--text-fil", f.name]) == 1
    finally:
        os.unlink(f.name)
    assert anrop == []


def test_eskalerad_med_mottagare_postas_via_main():
    anrop = _fanga()
    with tempfile.NamedTemporaryFile("w", suffix=".md", delete=False, encoding="utf-8") as f:
        f.write("Eskalerad till arkitekten: motsägelse i rutan.")
    try:
        assert g.main(["--pr", "1", "--utfall", "eskalerad", "--granskare", "Tony", "--text-fil", f.name]) == 0
    finally:
        os.unlink(f.name)
    assert len(anrop) == 2


def test_blanksteg_i_granskare_byts_mot_bindestreck_i_markoren_men_inte_i_rubriken():
    kropp = g.bygg_kommentar("godkänd", "abc", "Sonnet 5", "x")
    rader = kropp.splitlines()
    assert rader[0] == "<!-- mimers-pipeline: granskning utfall=godkänd sha=abc granskare=Sonnet-5 -->"
    assert rader[1] == "### Sonnet 5 Granskning: godkänd"


def test_ar_sparkommentar_kanner_igen_markoren():
    kropp = g.bygg_kommentar("godkänd", "abc", "Sonnet", "x")
    assert g.ar_sparkommentar(kropp)
    assert not g.ar_sparkommentar("### Sonnet Granskningsanalys\nx")
    assert not g.ar_sparkommentar(None)


if __name__ == "__main__":
    testfunktioner = [(n, f) for n, f in sorted(globals().items())
                      if n.startswith("test_") and callable(f)]
    fel = []
    for namn, func in testfunktioner:
        try:
            func()
        except Exception as e:
            fel.append(namn)
            print(f"✗ {namn}: {type(e).__name__}: {e}")
        else:
            print(f"✓ {namn}")
    print(f"\n{len(testfunktioner) - len(fel)}/{len(testfunktioner)} tester gröna.")
    if fel:
        sys.exit(1)
