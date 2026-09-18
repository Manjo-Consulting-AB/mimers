#!/usr/bin/env python3
"""Enhetstester för omfangsruta.py - ren python3, inget pytest.

Samma form som test_process_next_issue.py och av samma skäl (issue 259,
beslut 11): stdlib-`assert` och ett `if __name__ == "__main__"`-block. Modulen
går att importera utan sidoeffekter - allt som kör ett kommando ligger i
`main()`.

Det som prövas är rutans läge och deklarationen, alltså regeln som infördes
efter issue 83 (PR #392): `bedom_fil()` är hela ordningen mellan `In scope`, ett
beviljat undantag, `Out of scope` och en deklarerad fil, och den ordningen är
det som avgör om en PR fälls. `omfangslage()` prövas särskilt på att den faller
tillbaka på `fast` - en axel som inte går att läsa ska routa till banan med MER
kontroll, inte mindre (se docs/Process/Lärdomar.md om extract_risk_class()).

Körs av CI (.github/workflows/ci.yml, steget "Pipelinens egna enhetstester")
och lokalt med:
    python3 .github/scripts/test_omfangsruta.py
"""
import io
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import omfangsruta as o


INNANFOR = ["app/Support/Frontend/ActiveContainer.php", "tests/Feature/Frontend/**"]
UTANFOR = ["app/Models/**", "lang/**"]


def _bedom(fil, deklarerade=(), lage="fast", undantag=()):
    return o.bedom_fil(fil, INNANFOR, UTANFOR, list(undantag), list(deklarerade), lage)


# =====================================================================
# omfangslage() - fältet i issuen
# =====================================================================

def test_lage_saknas_ger_fast():
    """Issues skrivna innan fältet fanns beter sig exakt som förut."""
    assert o.omfangslage("### In scope\n\n```\napp/**\n```\n") == "fast"


def test_lage_sparad_lases():
    kropp = "### Omfångsläge\n\nspårad\n\n### ambiguity\n\nlow\n"
    assert o.omfangslage(kropp) == o.OMFANGSLAGE_SPARAD


def test_lage_sparad_utan_diakriter_lases():
    """`sparad` utan å duger - formuläret ger å, en handskriven issue kanske inte."""
    assert o.omfangslage("### Omfångsläge\n\nsparad\n") == o.OMFANGSLAGE_SPARAD


def test_lage_fast_lases_explicit():
    assert o.omfangslage("### Omfångsläge\n\nfast\n") == "fast"


def test_tomt_svar_ger_fast():
    """GitHubs `_No response_` är inte ett läge, och får inte vidga rutan."""
    assert o.omfangslage("### Omfångsläge\n\n_No response_\n") == "fast"


def test_felstavat_lage_ger_fast():
    """Fail-closed: det som inte går att läsa ger den strängare banan."""
    assert o.omfangslage("### Omfångsläge\n\nspårbar-ish\n") == "fast"


# =====================================================================
# deklarerade_sokvagar() - markören i PR-kroppen
# =====================================================================

def test_deklaration_lases_ur_kodblock():
    kropp = (
        "## Omfångsrutan\n\n"
        "Utanför rutan:\n```\napp/Http/Controllers/ItemController.php\n```\n"
    )
    assert o.deklarerade_sokvagar(kropp) == ["app/Http/Controllers/ItemController.php"]


def test_deklaration_i_loptext_raknas_inte():
    """Stelheten är poängen - PR #231 visar vad en fri form kostar."""
    kropp = "Utanför rutan ligger app/Http/Controllers/ItemController.php, men den behövs.\n"
    assert o.deklarerade_sokvagar(kropp) == []


def test_ingen_markor_ger_tom_lista():
    assert o.deklarerade_sokvagar("## Sammanfattning\n\nInget särskilt.\n") == []


def test_mallens_exempel_i_html_kommentar_raknas_inte():
    """PR-mallen visar formen med ett exempel, och halvifyllda mallar är
    normalfallet - exemplet får aldrig bli en verklig deklaration."""
    mall = io.open(
        os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
                     "pull_request_template.md"),
        encoding="utf-8",
    ).read()
    assert o.deklarerade_sokvagar(mall) == []


def test_deklaration_utanfor_kommentar_lases_i_samma_kropp():
    """Och den riktiga deklarationen läses ändå, med mallens kommentar kvar."""
    kropp = (
        "<!--\nUtanför rutan:\n```\napp/Exempel.php\n```\n-->\n\n"
        "Utanför rutan:\n```\napp/Http/Controllers/ItemController.php\n```\n"
    )
    assert o.deklarerade_sokvagar(kropp) == ["app/Http/Controllers/ItemController.php"]


# =====================================================================
# bedom_fil() - ordningen mellan de fyra utfallen
# =====================================================================

def test_fil_i_rutan_ar_ok_i_bada_lagena():
    for lage in ("fast", o.OMFANGSLAGE_SPARAD):
        assert _bedom("tests/Feature/Frontend/SkalTest.php", lage=lage)[0] == "ok"


def test_odeklarerad_fil_faller_i_sparat_lage():
    """Tripwiren mot en session som vandrar är kvar i det spårade läget."""
    utfall, text = _bedom("app/Http/Controllers/ItemController.php",
                          lage=o.OMFANGSLAGE_SPARAD)
    assert utfall == "brott"
    assert o.DEKLARATIONSMARKOR in text


def test_deklarerad_fil_slapps_igenom_i_sparat_lage():
    utfall, text = _bedom(
        "app/Http/Controllers/ItemController.php",
        deklarerade=["app/Http/Controllers/ItemController.php"],
        lage=o.OMFANGSLAGE_SPARAD,
    )
    assert utfall == "deklarerad"
    assert "omfångsdrift" in text


def test_deklarerad_fil_faller_i_fast_lage():
    """Deklarationen vidgar ingenting där issuen inte bett om det."""
    assert _bedom(
        "app/Http/Controllers/ItemController.php",
        deklarerade=["app/Http/Controllers/ItemController.php"],
        lage="fast",
    )[0] == "brott"


def test_out_of_scope_faller_aven_deklarerad():
    """Out of scope är ett beslut, inte en gissning - det går inte att
    deklarera sig förbi det."""
    utfall, text = _bedom(
        "app/Models/Container.php",
        deklarerade=["app/Models/Container.php"],
        lage=o.OMFANGSLAGE_SPARAD,
    )
    assert utfall == "brott"
    assert "Out of scope" in text


def test_beviljat_undantag_slapps_igenom_i_bada_lagena():
    undantag = [("app/Http/Controllers/ItemController.php", "https://example/1")]
    for lage in ("fast", o.OMFANGSLAGE_SPARAD):
        utfall, text = _bedom("app/Http/Controllers/ItemController.php",
                              lage=lage, undantag=undantag)
        assert utfall == "beviljad"
        assert "arkitektsvaret" in text


def test_deklaration_som_glob_matchar_undertradet():
    """Samma matchning som rutan själv använder, inte en egen."""
    assert _bedom(
        "app/Support/Item/Status.php",
        deklarerade=["app/Support/Item/**"],
        lage=o.OMFANGSLAGE_SPARAD,
    )[0] == "deklarerad"


if __name__ == "__main__":
    testfunktioner = [
        (namn, func) for namn, func in sorted(globals().items())
        if namn.startswith("test_") and callable(func)
    ]

    lyckade = 0
    misslyckade = []
    for namn, func in testfunktioner:
        try:
            func()
        except AssertionError as e:
            misslyckade.append((namn, str(e)))
            print(f"✗ {namn}: {e}")
        except Exception as e:
            misslyckade.append((namn, f"{type(e).__name__}: {e}"))
            print(f"✗ {namn} (oväntat fel): {e}")
        else:
            lyckade += 1
            print(f"✓ {namn}")

    print(f"\n{lyckade}/{len(testfunktioner)} tester gröna.")
    if misslyckade:
        print(f"{len(misslyckade)} misslyckade:")
        for namn, fel in misslyckade:
            print(f"  - {namn}: {fel}")
        sys.exit(1)
