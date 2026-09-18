#!/usr/bin/env python3
"""Enhetstester för omfangslint.py - ren python3, inget pytest.

Samma form som repots övriga pipelinetester. Fixturen är issue 83:s riktiga
ruta och `Klart när`-punkter (GitHub-issue #387), alltså fallet hela steg 3
finns för - inklusive den dokumenterade gränsen: den mekaniska halvan är TYST
där, eftersom issuen inte nämner rutten som bär punkten. Att det står som ett
test och inte bara i en docstring är poängen; skulle någon senare tro att den
mekaniska halvan räcker, säger testet emot.

Körs av CI (.github/workflows/ci.yml, steget "Pipelinens egna enhetstester")
och lokalt med:
    python3 .github/scripts/test_omfangslint.py
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import omfangslint as l
import omfangsruta as o

ROT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

# Issue 83 (#387), ordagrant i de delar linten läser.
ISSUE_83 = """### Mål

Kontexten sätts av att användaren öppnar en container, inte av att hon trycker
"gör aktiv" i listan.

### In scope

```shell
app/Support/Frontend/ActiveContainer.php
app/Http/Controllers/ActiveContainerController.php
app/Http/Controllers/ContainerController.php
app/Http/Middleware/HandleInertiaRequests.php
routes/web.php
resources/js/pages/Containers/Index.vue
tests/Feature/Frontend/AktivContainerTest.php
```

### Out of scope

```shell
app/Models/**
app/Policies/**
lang/**
resources/js/layouts/**
docs/**
```

### Omfångsläge

fast

### Klart när

- [ ] `PUT /containers/{container}/active` finns inte längre
- [ ] att öppna en container sätter sessionsnyckeln till containerns ULID
- [ ] propen `activeContainer` delas fortfarande ut
- [ ] hela testsviten är grön
"""


def _analys(kropp):
    return l.analysera(kropp, ROT)


# =====================================================================
# Ruttabellen
# =====================================================================

def test_ruttabellen_lases_utan_att_appen_startar():
    """Statisk parsning är hela poängen: linten ska gå i en fräsch worktree
    utan vendor/, utan .env och utan APP_KEY."""
    tabell = l.rutter(ROT)
    assert len(tabell) > 100, f"orimligt få rutter: {len(tabell)}"


def test_rutten_bakom_containern_pekar_pa_itemcontroller():
    """Det enda faktum hela steg 3 vilar på."""
    tabell = l.rutter(ROT)
    traff = [r for r in tabell if r.metod == "get" and l._nyckel(r.uri) == "/containers/{}"]
    assert traff, "GET /containers/{container} hittades inte i ruttabellen"
    assert traff[0].fil == "app/Http/Controllers/ItemController.php", traff[0].fil


def test_api_rutter_far_sitt_prefix():
    tabell = l.rutter(ROT)
    assert any(r.uri.startswith("/api/") for r in tabell)


def test_inertiasidor_kopplas_till_ruttens_egen_metod():
    """Per metod, inte per fil. ItemController renderar fem sidor; knöts de alla
    till var och en av dess rutter gav issue 89:s ruta fem fynd varav noll äkta."""
    tabell = l.rutter(ROT)
    index = [r for r in tabell if r.fil == "app/Http/Controllers/ItemController.php"
             and r.action == "index" and l._nyckel(r.uri) == "/containers/{}"]
    assert index, "GET /containers/{container} saknas"
    assert index[0].sidor == ["resources/js/pages/Containers/Items/Index.vue"], index[0].sidor


def test_ingen_sida_hangs_pa_en_rutt_som_inte_renderar_den():
    tabell = l.rutter(ROT)
    for rutt in tabell:
        if rutt.metod in ("post", "patch", "put", "delete"):
            assert rutt.sidor == [], f"{rutt} bär sidor trots att den inte renderar någon"


# =====================================================================
# Den mekaniska halvan
# =====================================================================

def test_namnd_rutt_utanfor_rutan_ger_fynd():
    kropp = ISSUE_83.replace(
        "- [ ] hela testsviten är grön",
        "- [ ] `/settings/security` nås från navigeringen\n- [ ] hela testsviten är grön",
    )
    mekaniska, _, _, _ = _analys(kropp)
    assert any("SecurityController" in rad for rad in mekaniska), mekaniska


def test_namnd_rutt_i_rutan_ger_inget_fynd():
    kropp = ISSUE_83.replace(
        "app/Http/Controllers/ActiveContainerController.php",
        "app/Http/Controllers/ActiveContainerController.php\napp/Http/Controllers/**",
    )
    mekaniska, _, _, _ = _analys(kropp)
    assert mekaniska == [], mekaniska


def test_rutt_som_ligger_out_of_scope_pekas_ut_sarskilt():
    """Den skarpaste signalen: issuen säger emot sig själv innan bygget börjat."""
    kropp = ISSUE_83.replace("docs/**", "docs/**\napp/Http/Controllers/Settings/**").replace(
        "- [ ] hela testsviten är grön",
        "- [ ] `/settings/security` nås från navigeringen\n- [ ] hela testsviten är grön",
    )
    mekaniska, _, _, _ = _analys(kropp)
    assert any("Out of scope" in rad for rad in mekaniska), mekaniska


def test_mekaniska_halvan_ar_tyst_pa_issue_83():
    """Den dokumenterade gränsen. Issue 83 nämner bara rutten som TAS BORT, och
    den ligger i rutan; punkten om att öppna en container nämner ingen rutt
    alls. Därför finns den bedömande halvan."""
    mekaniska, _, _, _ = _analys(ISSUE_83)
    assert mekaniska == [], mekaniska


def test_ingen_lasbar_ruta_ger_ingen_analys():
    mekaniska, fraga, innanfor, _ = _analys("### Mål\n\nNågot.\n")
    assert (mekaniska, fraga, innanfor) == ([], None, [])


# =====================================================================
# Den bedömande halvan
# =====================================================================

def test_modellfragan_bar_faktumet_modellen_behover():
    _, fraga, _, _ = _analys(ISSUE_83)
    assert fraga is not None
    assert "att öppna en container sätter sessionsnyckeln" in fraga
    assert "/containers/{container} -> app/Http/Controllers/ItemController.php::index" in fraga
    assert "app/Support/Frontend/ActiveContainer.php" in fraga


def test_ingen_modellfraga_utan_klart_nar():
    kropp = ISSUE_83.split("### Klart när")[0]
    _, fraga, _, _ = _analys(kropp)
    assert fraga is None


def test_modellsvar_ger_fynd_for_fil_utanfor_rutan():
    _, _, innanfor, _ = _analys(ISSUE_83)
    svar = "SAKNAS: app/Http/Controllers/ItemController.php — punkt 2\n"
    fynd = l.fynd_ur_modellsvar(svar, innanfor, ROT)
    assert len(fynd) == 1 and "ItemController" in fynd[0], fynd


def test_modellsvar_som_pekar_in_i_rutan_kastas():
    _, _, innanfor, _ = _analys(ISSUE_83)
    svar = "SAKNAS: app/Http/Controllers/ContainerController.php — punkt 2\n"
    assert l.fynd_ur_modellsvar(svar, innanfor, ROT) == []


def test_fil_utanfor_ruttabellen_overlever_om_den_finns():
    """PR #398 (issue 84) är fallet: punkten "kategorimallen väljs uttryckligen"
    bärs av `resources/js/data/categoryPresets.js`, som inte serverar någon URL
    och alltså inte står i ruttabellen. En instruktion som band modellen till
    tabellen hade gjort just den punkten omöjlig att rapportera - och
    existenskontrollen nedan är ändå det som stoppar en påhittad sökväg."""
    _, _, innanfor, _ = _analys(ISSUE_83)
    svar = "SAKNAS: resources/js/data/categoryPresets.js — punkt 2\n"
    fynd = l.fynd_ur_modellsvar(svar, innanfor, ROT)
    assert len(fynd) == 1 and "categoryPresets" in fynd[0], fynd


def test_modellfragan_binder_inte_modellen_till_ruttabellen():
    _, fraga, _, _ = _analys(ISSUE_83)
    assert "inte din gräns" in fraga


def test_pahittad_sokvag_kastas():
    """Modellen föreslår, filsystemet avgör."""
    _, _, innanfor, _ = _analys(ISSUE_83)
    svar = "SAKNAS: app/Http/Controllers/OppnaContainerController.php — punkt 2\n"
    assert l.fynd_ur_modellsvar(svar, innanfor, ROT) == []


def test_inga_ger_inga_fynd():
    _, _, innanfor, _ = _analys(ISSUE_83)
    assert l.fynd_ur_modellsvar("INGA", innanfor, ROT) == []


def test_samma_fil_rapporteras_en_gang():
    _, _, innanfor, _ = _analys(ISSUE_83)
    svar = ("SAKNAS: app/Http/Controllers/ItemController.php — punkt 2\n"
            "SAKNAS: app/Http/Controllers/ItemController.php — punkt 3\n")
    assert len(l.fynd_ur_modellsvar(svar, innanfor, ROT)) == 1


# =====================================================================
# Rapporten
# =====================================================================

def test_tyst_rapport_nar_inget_hittades():
    assert l.rapport([], [], "fast") is None


def test_rapporten_namner_deklarationen_i_sparat_lage():
    text = l.rapport(["- `x`"], [], o.OMFANGSLAGE_SPARAD)
    assert o.DEKLARATIONSMARKOR in text and text.startswith(l.RUBRIK)


def test_rapporten_namner_inte_deklarationen_i_fast_lage():
    text = l.rapport(["- `x`"], [], "fast")
    assert o.DEKLARATIONSMARKOR not in text


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
