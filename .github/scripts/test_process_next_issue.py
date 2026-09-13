#!/usr/bin/env python3
"""Enhetstester för process_next_issue.py - ren python3, inget pytest.

Repot har inga python-tester sedan tidigare, och den här filen inför
medvetet ingen testberoende (issue 259, beslut 11): bara stdlib-`assert` och
ett `if __name__ == "__main__"`-block som kör och rapporterar. Modulen är
importerbar utan sidoeffekter - allt som faktiskt kör ett kommando ligger
bakom `if __name__ == "__main__":` i process_next_issue.py - så den här filen
importerar den direkt i stället för att mocka den. De tre funktioner som
testas (`ska_eskalera_till_arkitekt`, `bygg_granskningsprompt`, `oppna_fragor`)
är rena: inget `gh`-anrop att mocka bort.

Körs av CI (.github/workflows/ci.yml, steget "Pipelinens egna enhetstester")
och lokalt med:
    python3 .github/scripts/test_process_next_issue.py
"""
import os
import subprocess
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import process_next_issue as p


# =====================================================================
# Beslut 2: ska_eskalera_till_arkitekt() - dämparen
# =====================================================================

def test_eskalerar_med_fraga_och_ingen_etikett():
    """Klart när #1: en obesvarad fråga utan etiketten eskalerar."""
    assert p.ska_eskalera_till_arkitekt("en fråga", []) is True


def test_eskalerar_inte_med_fraga_och_etikett_satt():
    """Klart när #2: granskaren har redan triagerat frågan."""
    assert p.ska_eskalera_till_arkitekt("en fråga", ["fraga:besvarad"]) is False


def test_eskalerar_inte_utan_fraga_och_utan_etikett():
    """Klart när #3: inget att eskalera när frågeavsnittet är tomt."""
    assert p.ska_eskalera_till_arkitekt("", []) is False


def test_eskalerar_inte_utan_fraga_men_med_etikett():
    """Klart när #4: en gammal etikett utan en ny fråga är fortfarande inget
    att eskalera - etiketten dämpar bara en fråga som faktiskt finns."""
    assert p.ska_eskalera_till_arkitekt("", ["fraga:besvarad"]) is False


def test_liknande_etiketter_eskalerar_fortfarande():
    """Klart när #5: fail-closed. `fraga:arkitekt` (Tonys egen väg TILL
    arkitekten) och `besvarad` (utan prefixet `fraga:`) är inte
    ARKITEKTFRAGA_BESVARAD, och ska inte dämpa något."""
    assert p.ska_eskalera_till_arkitekt("en fråga", ["fraga:arkitekt"]) is True
    assert p.ska_eskalera_till_arkitekt("en fråga", ["besvarad"]) is True
    assert p.ska_eskalera_till_arkitekt("en fråga", ["fraga:arkitekt", "besvarad"]) is True


# =====================================================================
# Beslut 3/4: bygg_granskningsprompt()s fragor=""-argument
# =====================================================================

def _dagens_prompt_utan_fragor(issue_body, diff, uppfoljning=False):
    """Rekonstruktion av bygg_granskningsprompt()s utdata som den var innan
    issue 259 - facit att jämföra mot, inte samma kod som testas. Skrivs ut
    här i stället för att importeras, så att en framtida ändring av den
    riktiga funktionen som råkar bevara byte-identiteten ändå upptäcks om
    den ändrar den delen av prompten som INTE hör till frågeblocket."""
    inledning = (
        "Du gör en avslutande granskning av en PR vars tidigare fynd ska vara åtgärdade. "
        "Det här är INTE en avprickning av att fynden är fixade - det är en ny granskning "
        "av hela lösningen som den ser ut nu. Åtgärderna kan ha infört något nytt."
        if uppfoljning else
        "Gör en noggrann säkerhets- och arkitekturgranskning av lösningen nedan."
    )
    return (
        f"{inledning}\n\n"
        f"=== ISSUEN, som är kontraktet ===\n{issue_body}\n\n"
        f"=== HELA DIFFEN ===\n{diff}\n\n"
        f"=== SÅ HÄR GRANSKAR DU ===\n"
        f"1. Gå igenom issuens 'Klart när'-punkter en och en och peka ut vilket "
        f"namngivet test som bevisar var och en. En punkt utan test är ett fynd - "
        f"det är den enda kontrollen av att inget missats.\n"
        f"2. Kontrollera att issuens numrerade beslut faktiskt följs, och att varje "
        f"avvikelse är motiverad i PR-kroppen.\n"
        f"3. Håll dig till issuens omfångsruta. Ligger en ändrad fil utanför 'In scope' "
        f"är det ett fynd. Beställ ALDRIG en ändring i en fil som ligger utanför rutan - "
        f"be i så fall om att den bryts ut till en egen issue. Rutan kontrolleras även "
        f"maskinellt av .github/scripts/omfangsruta.py, så en sådan beställning gör bara "
        f"PR:en röd.\n"
        f"4. Sedan det vanliga: säkerhet, samtidighet, felhantering, datamodell.\n\n"
        f"Har du fynd, skriv dem som en numrerad lista - konkret nog att en annan "
        f"implementerare kan åtgärda dem utan att fråga dig något mer."
    )


def test_bygg_granskningsprompt_tom_fragor_ar_teckenidentisk_med_dagens():
    """Klart när #6: `bygg_granskningsprompt(issue, diff)` och
    `bygg_granskningsprompt(issue, diff, fragor="")` ger identiska strängar,
    och identiska med utdata innan issue 259."""
    utan_arg = p.bygg_granskningsprompt("ISSUEBODY", "DIFFTEXT")
    med_tomt = p.bygg_granskningsprompt("ISSUEBODY", "DIFFTEXT", fragor="")
    assert utan_arg == med_tomt
    assert utan_arg == _dagens_prompt_utan_fragor("ISSUEBODY", "DIFFTEXT")

    # Samma sak för uppföljningsvarvet.
    utan_arg_up = p.bygg_granskningsprompt("ISSUEBODY", "DIFFTEXT", uppfoljning=True)
    med_tomt_up = p.bygg_granskningsprompt("ISSUEBODY", "DIFFTEXT", uppfoljning=True, fragor="")
    assert utan_arg_up == med_tomt_up
    assert utan_arg_up == _dagens_prompt_utan_fragor("ISSUEBODY", "DIFFTEXT", uppfoljning=True)


def test_bygg_granskningsprompt_med_fragor_innehaller_text_etikett_och_gh_api():
    """Klart när #7: en icke-tom `fragor` bjuder in etiketten - texten, dess
    eget namn, och gh-kommandot som sätter den ska alla synas i prompten, med
    det riktiga PR-numret ilagt - inte platshållaren `<PR-numret>` som en
    granskare aldrig kunde fylla i (den bugg den här issuen fixar)."""
    prompt = p.bygg_granskningsprompt(
        "ISSUEBODY", "DIFFTEXT", fragor="Vilket fält ska X ligga i?", pr_number="260"
    )
    assert "Vilket fält ska X ligga i?" in prompt
    assert p.ARKITEKTFRAGA_BESVARAD in prompt
    assert "gh api" in prompt
    assert f"labels[]={p.ARKITEKTFRAGA_BESVARAD}" in prompt
    assert "issues/260/labels" in prompt
    assert "<PR-numret>" not in prompt


def test_bygg_granskningsprompt_uppfoljning_far_samma_fragoblock():
    """Klart när #8: åtgärdsvarvets granskning (uppfoljning=True) får samma
    frågeblock som förstagångsgranskningen, med samma riktiga PR-nummer."""
    prompt = p.bygg_granskningsprompt(
        "ISSUEBODY", "DIFFTEXT", uppfoljning=True, fragor="Vilket fält ska X ligga i?",
        pr_number="260",
    )
    assert "Vilket fält ska X ligga i?" in prompt
    assert p.ARKITEKTFRAGA_BESVARAD in prompt
    assert "gh api" in prompt
    assert f"labels[]={p.ARKITEKTFRAGA_BESVARAD}" in prompt
    assert "issues/260/labels" in prompt
    assert "<PR-numret>" not in prompt


def test_bygg_granskningsprompt_fragor_utan_pr_number_reser_fel():
    """Fragor utan pr_number får inte gå tyst fel - se docstringen för valet:
    ett direkt ValueError vid promptbygget, hellre än att tyst skriva
    platshållaren igen eller tyst hoppa över frågeblocket."""
    try:
        p.bygg_granskningsprompt("ISSUEBODY", "DIFFTEXT", fragor="Vilket fält ska X ligga i?")
    except ValueError:
        pass
    else:
        raise AssertionError("bygg_granskningsprompt() borde ha rest ValueError utan pr_number")


# =====================================================================
# Beslut 1: oppna_fragor() - triggern, oförändrad (regressionsskydd)
# =====================================================================

def test_oppna_fragor_inga_ger_tomt():
    assert p.oppna_fragor("## Frågor och antaganden\nInga.\n") == ""


def test_oppna_fragor_dash_ger_tomt():
    assert p.oppna_fragor("## Frågor och antaganden\n-\n") == ""


def test_oppna_fragor_platshallare_fran_bygg_pr_kropp():
    """Klart när #9, med en dokumenterad avvikelse: issue 259s "Klart när"-
    lista påstår att `oppna_fragor()` ska ge tomt för avsnittet
    bygg_pr_kropp() skriver när modellen inte fyllde i rubriken alls
    ("_Modellen skrev inte det här avsnittet._"). Den befintliga koden gör
    inte det - och ska inte ändras av den här issuen (beslut 1: "oppna_fragor()
    är kvar som trigger, oförändrad"). Funktionens egen kommentar säger
    uttryckligen motsatsen till "Klart när"-listan: "Kursiv markering ... är
    inte samma sak som 'Inga.', och ska stanna PR:en" - dvs. den ska vara
    icke-tom, inte tom. Testet här låser den verkliga, oförändrade koden.
    Se PR-kroppens '## Frågor och antaganden' för motsägelsen i sak."""
    body = "## Frågor och antaganden\n\n_Modellen skrev inte det här avsnittet._\n"
    assert p.oppna_fragor(body) != ""


def test_oppna_fragor_riktig_fraga_ger_icke_tomt():
    body = "## Frågor och antaganden\nVilket fält ska X ligga i?\n"
    assert p.oppna_fragor(body) == "Vilket fält ska X ligga i?"


# =====================================================================
# sakerstall_closes_rad() - lagar en agent-öppnad PR som saknar Closes-raden
# (PR #262 / issue #254: agenten öppnade PR:en själv med en egen mall utan
# "Closes #", omfangsruta.py fällde CI och issue #254 landade hos Tony för
# att lägga till en rad vars värde redan stod i branchnamnet)
# =====================================================================

def test_sakerstall_closes_rad_saknas_laggs_till():
    kropp = p.sakerstall_closes_rad("## Sammanfattning\n\nNågot.", "254")
    assert kropp == "Closes #254\n\n## Sammanfattning\n\nNågot."


def test_sakerstall_closes_rad_finns_redan_ger_none():
    assert p.sakerstall_closes_rad("Closes #254\n\nText.", "254") is None


def test_sakerstall_closes_rad_fel_nummer_raknas_som_saknad():
    """En Closes-rad mot fel issue ska lagas, inte tolkas som redan löst."""
    kropp = p.sakerstall_closes_rad("Closes #47\n\nText.", "254")
    assert kropp == "Closes #254\n\nCloses #47\n\nText."


def test_sakerstall_closes_rad_case_insensitive():
    assert p.sakerstall_closes_rad("closes #254", "254") is None


def test_sakerstall_closes_rad_tom_kropp():
    assert p.sakerstall_closes_rad("", "254") == "Closes #254\n\n"


def test_sakerstall_closes_rad_none_kropp():
    assert p.sakerstall_closes_rad(None, "254") == "Closes #254\n\n"


# =====================================================================
# bygg_kroppsuppdatering() - PR-kroppen skrivs om via REST, inte gh pr edit
# (issue #291: `gh pr edit 297 --body ...` kraschade hela körningen med
# "Projects (classic) is being deprecated ... repository.pullRequest.projectCards",
# kroppen blev aldrig uppdaterad, och CI fällde PR #297 för saknad Closes-rad)
# =====================================================================

def test_kroppsuppdatering_gar_via_rest_inte_gh_pr_edit():
    argv = p.bygg_kroppsuppdatering("297", "Closes #291\n\n## Sammanfattning")
    assert argv[:3] == ["gh", "api", "--method"]
    assert "edit" not in argv
    assert argv[3] == "PATCH"
    assert argv[4] == f"repos/{p.GH_REPO}/pulls/297"


def test_kroppsuppdatering_skickar_hela_kroppen():
    """Kroppen skickas som ett enda -f-argument; radbrytningar och backticks
    ska överleva ordagrant (argv, inget skal)."""
    kropp = "Closes #291\n\n## Sammanfattning\n\n- `lang/sv/ui.php` \u2014 text"
    argv = p.bygg_kroppsuppdatering("297", kropp)
    assert argv[-2] == "-f"
    assert argv[-1] == f"body={kropp}"


def test_ingen_gh_pr_edit_kvar_i_skriptet():
    """Tripwire: varje `gh pr edit` mot en PR i det här repot felar på
    projectCards. Etiketten (satt_label) och kroppen gick båda den vägen;
    nya anrop ska inte smyga tillbaka in."""
    kalla = open(
        os.path.join(os.path.dirname(os.path.abspath(__file__)), "process_next_issue.py"),
        encoding="utf-8",
    ).read()
    rader = [
        rad.strip() for rad in kalla.splitlines()
        if '"gh", "pr", "edit"' in rad.replace(" ", " ")
    ]
    assert rader == [], f"gh pr edit används fortfarande: {rader}"


# =====================================================================
# Beslut 11: modulen är importerbar utan sidoeffekter
# =====================================================================

def test_import_gor_inga_processanrop():
    """Klart när #10: `import process_next_issue` kör inga gh-kommandon och
    gör inga nätanrop. Kör importen i en fräsch process med `subprocess.run`
    monkeypatchad att krascha om den anropas, i stället för att mocka en
    funktion i process_next_issue - vi testar importens sidoeffekter, inte
    en av de tre rena funktionerna."""
    script = (
        "import subprocess, sys\n"
        "def kraschande(*a, **kw):\n"
        "    raise SystemExit('subprocess.run anropades vid import')\n"
        "subprocess.run = kraschande\n"
        f"sys.path.insert(0, {os.path.dirname(os.path.abspath(__file__))!r})\n"
        "import process_next_issue\n"
        "print('OK')\n"
    )
    result = subprocess.run([sys.executable, "-c", script], capture_output=True, text=True)
    assert result.returncode == 0, (
        f"Importen gjorde ett processanrop eller kraschade:\n"
        f"stdout: {result.stdout}\nstderr: {result.stderr}"
    )
    assert result.stdout.strip() == "OK"



# =====================================================================
# beviljar_undantag() - omkörningen av CI efter ett beviljat undantag
# =====================================================================

def test_undantagsmarkoren_ar_synkad_med_omfangsrutan():
    """Markören är speglad i två skript; drift gör omkörningen tyst verkningslös."""
    import omfangsruta
    assert p.UNDANTAGSMARKOR == omfangsruta.UNDANTAGSMARKOR


def test_beviljar_undantag_med_markoren():
    svar = ("### Arkitektsvar\n\nUndantag beviljat.\n\n"
            "Beviljat undantag från omfångsrutan:\n```\ntests/Feature/Omfang/MigreringTest.php\n```")
    assert p.beviljar_undantag(svar) is True


def test_beviljar_inte_undantag_utan_markoren():
    """PR #284 fick sitt undantag i prosa först - den formen är inte ett undantag."""
    assert p.beviljar_undantag("Undantag från omfångsrutan beviljas för filen.") is False


def test_beviljar_inte_undantag_pa_tomt_svar():
    """Ett tappat Opus-svar får inte råka starta om CI."""
    assert p.beviljar_undantag("") is False
    assert p.beviljar_undantag(None) is False


# =====================================================================
# arkitektsvar_pa_oppen_fraga() - eskaleringen finns på BÅDA banorna
# (issue 292 / PR #299: granskaren skrev tre varv i rad att fyndet krävde
#  ett arkitektbeslut, och ingen väg ledde dit)
# =====================================================================

def _kalla(filnamn="process_next_issue.py"):
    return open(
        os.path.join(os.path.dirname(os.path.abspath(__file__)), filnamn),
        encoding="utf-8",
    ).read()


def _funktionskropp(namn, filnamn="process_next_issue.py"):
    """Källtexten för EN funktion på toppnivå - så att ett träffande ord i en
    annan funktions docstring inte kan få ett tripwire-test att se grönt ut."""
    kalla = _kalla(filnamn)
    start = kalla.index(f"\ndef {namn}(")
    nasta = kalla.find("\ndef ", start + 1)
    return kalla[start:nasta if nasta != -1 else len(kalla)]


def test_arkitekteskaleringen_anropas_fran_bada_banorna():
    """Klart när: både MERGE-steget (efter godkännande) och GRANSKNINGS-steget
    (när åtgärdsloopen inte fick något godkänt) går via samma funktion.

    Tripwire mot exakt den generaliseringsmiss retron redan noterat: en
    mekanism som finns på ett anropsställe och saknas på ett annat."""
    for funktion in ("_process_in_worktree", "los_fraga_och_merga"):
        assert "= arkitektsvar_pa_oppen_fraga(" in _funktionskropp(funktion), (
            f"{funktion}() eskalerar inte en obesvarad fråga till arkitekten. "
            f"Ett omnämnande i en kommentar räcker inte - anropet ska finnas."
        )


def test_ingen_egen_kopia_av_eskaleringen_kvar():
    """Tripwire: run_opus_answer() ska bara nås via den delade funktionen.
    Två inline-kopior var hela felet - den ena hann aldrig få banan som
    saknades."""
    rader = [
        rad.strip() for rad in _kalla().splitlines()
        if "= run_opus_answer(" in rad
    ]
    assert len(rader) == 1, f"run_opus_answer anropas från fler än ett ställe: {rader}"


def test_atgardsloopens_avslag_far_inte_ga_rakt_till_eskalera():
    """Klart när: `if not godkand: eskalera(...)` direkt efter åtgärdsloopen i
    run_review_flow är precis det som lämnade issue 292 på needs-human med kön
    blockerad. Arkitektfrågan ska ligga emellan."""
    kropp = _funktionskropp("_process_in_worktree")
    loop = kropp.index("granskning, fragor=fragor")
    eskalering = kropp.index("Fynd kvarstår efter åtgärdsloopen")
    assert "= arkitektsvar_pa_oppen_fraga(" in kropp[loop:eskalering], (
        "Åtgärdsloopens avslag går rakt till eskalera() igen - arkitektfrågan hoppas över."
    )


# =====================================================================
# backa_trasig_egen_commit() - en agentcommit som föll på testgrinden
# får inte bli kvar (issue 292 / PR #299: varv 4:s Sonnet committade och
# pushade en revert som gjorde sviten röd, och den blev PR:ens head)
# =====================================================================

def test_backar_inget_nar_agenten_inte_committat_sjalv():
    """Ett varv där bara arbetsträdet ändrats ska inte röra git alls - loopens
    vanliga väg committar och pushar själv efter testgrinden."""
    class _Tyst:
        """Svarar på allt backningen kan tänkas läsa, så att testet faller på
        sin assertion i stället för på ett AttributeError."""
        returncode, stdout, stderr = 0, "", ""

    anropade = []
    original = p.run_cmd
    p.run_cmd = lambda *a, **kw: (anropade.append(a), _Tyst())[1]
    try:
        p.backa_trasig_egen_commit("/finns/inte", "feature/issue-292", "abc123", "abc123", 4)
    finally:
        p.run_cmd = original
    assert anropade == [], f"Rörde git trots att HEAD stod stilla: {anropade}"


def test_backningen_anvander_force_with_lease_inte_force():
    """En blank --force skriver över vad som helst som hunnit landa på grenen."""
    kropp = _funktionskropp("backa_trasig_egen_commit")
    assert "--force-with-lease" in kropp
    assert '"--force"' not in kropp


def test_testgrindens_avslag_backar_egen_commit():
    """Klart när: raden som skriver 'Åtgärden bröt testsviten' följs av
    backningen, inte av ett ensamt `continue`."""
    kalla = _kalla()
    start = kalla.index("Åtgärden bröt testsviten på varv")
    assert "        backa_trasig_egen_commit(" in kalla[start:start + 600], (
        "Ett varv som bröt sviten lämnar agentens egen commit kvar på grenen."
    )


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
