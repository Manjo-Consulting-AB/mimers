#!/usr/bin/env -S python3 -u
"""Plockar äldsta öppna GitHub-issue och kör den genom Deepseek -> (vid fel) Sonnet,
med Sonnet-review på varje PR oavsett risk_class innan merge (manuell hos Tony på
high, annars automatisk). En obesvarad fråga i PR-kroppen eskaleras smalt till Opus
(arkitekten) i stället för att gå direkt till Tony - se run_opus_answer(). Se
ADR-0025/0026 (uppföljning 2026-09-03)/0027.

Körs i en isolerad git worktree (.claude/worktrees/issue-<n>), inte i huvudarbetsträdet -
se ADR-0026: Docker valdes bort just för att batch-agenter redan körs isolerat i worktrees.
"""
import subprocess
import json
import sys
import os
import re
import fcntl
import time
from datetime import datetime, timezone

# Ovillkorligen oskiftad utskrift - relevant oavsett hur skriptet startas
# (shebangens -u gäller bara vid direkt körning, inte `python3 script.py`).
# Utan den flushas print()-loggen aldrig till disk innan en kill -9, vilket
# gjorde det första skarpa testet (issue #91) blint för hur långt körningen
# hunnit.
sys.stdout.reconfigure(line_buffering=True)
sys.stderr.reconfigure(line_buffering=True)


def is_peak_hour() -> bool:
    """Sant under DeepSeeks peak hours (UTC, vardagar) - vi vill inte köra mot
    dem då. Två fönster: 01:00-03:59 och 06:00-09:59 UTC, måndag-fredag."""
    now = datetime.now(timezone.utc)
    is_weekday = now.weekday() < 5  # 0 = måndag ... 6 = söndag
    hour = now.hour

    if is_weekday:
        if 1 <= hour < 4:
            return True
        if 6 <= hour < 10:
            return True

    return False


# Avbryt körningen direkt om det är peak-tid - innan låset tas eller något
# issue plockas, oavsett vilket kommandoradsläge skriptet startas i.
if is_peak_hour():
    print("Hoppar över körning: Peak hours pågår (UTC).")
    sys.exit(0)

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
WORKTREE_BASE = os.path.join(REPO_ROOT, ".claude", "worktrees")
LOCK_PATH = os.path.join(REPO_ROOT, ".claude", "process-next-issue.lock")
GH_REPO = "Manjo-Consulting-AB/mimers"

# Kortaste granskningsutlåtande som får bära ett godkännande. Se run_review().
# Den första granskningen går igenom hela diffen och blir alltid lång. Ett
# uppföljningsvarv som bekräftar att ett enda fynd är åtgärdat är legitimt
# kort - samma tröskel på båda kastade ett giltigt godkännande på PR #162
# (241 tecken) och brände sedan tre åtgärdsvarv på ett "fynd" som i själva
# verket var godkännandet. Därav två trösklar. Det tappade svaret som
# heuristiken faktiskt finns för ("review:approved satt på #113.") är en
# rad på ~30 tecken och fastnar fortfarande i den lägre.
MIN_GRANSKNINGSTEXT = 400
MIN_GRANSKNINGSTEXT_UPPFOLJNING = 150

# Lägsta andel kvar av Anthropic-kontots rullande 5-timmarsfönster för att
# påbörja ett nytt issue. Se usage_ok_to_proceed().
MIN_USAGE_REMAINING = 0.15

# =====================================================================
# KONFIGURATION & HJÄLPFUNKTIONER
# =====================================================================

def send_pushover(message):
    """Anropar det lokala skriptet ~/.local/bin/notify-tony säkert utan skal-exekvering."""
    script_path = os.path.expanduser("~/.local/bin/notify-tony")
    try:
        subprocess.run([script_path, message], check=True)
    except Exception as e:
        print(f"⚠️ Kunde inte skicka Pushover-notis via {script_path}: {e}")


def run_cmd(args, check=True, capture_output=True, cwd=None, env=None):
    """Kör terminalkommandon säkert (utan shell=True)."""
    result = subprocess.run(args, text=True, capture_output=capture_output, cwd=cwd, env=env)
    if check and result.returncode != 0:
        cmd_str = " ".join(args)
        raise Exception(f"Kommando misslyckades: {cmd_str}\nFEL: {result.stderr}")
    return result


def run_local_tests(cwd):
    """
    Kvalitetsgrind: Pint (auto-fix, aldrig ett skäl att fela), PHPStan
    (composer analyse), testsviten (composer test), och rott-pa-basen.sh -
    bevisar att nya/ändrade tester faktiskt är röda på baskommiten, annars
    bevisar en avbockad "Klart när"-punkt ingenting (se 13b i
    [[deepseek-shadow-run-results]]). Körs vid varje försök i FAS 1/2 och
    varje varv i granskningens åtgärdsloop - fel ska in i nästa försöks
    prompt, inte upptäckas först vid PR:en eller i CI, som inte ens blockerar
    merge på den här GitHub-planen.

    rott-pa-basen.sh behöver en committad HEAD att diffa mot basen. Committar
    därför tillfälligt och backar den (soft reset, rör varken index eller
    arbetsträd) oavsett utfall - STEG 5:s enda riktiga commit ska vara
    opåverkad.

    Returnerar (passed: bool, output: str).
    """
    run_cmd(["composer", "fix"], check=False, cwd=cwd)

    analyse = run_cmd(["composer", "analyse"], check=False, cwd=cwd)
    if analyse.returncode != 0:
        output = (analyse.stdout or "") + "\n" + (analyse.stderr or "")
        return False, f"PHPStan (composer analyse) hittade fel:\n{output}"

    res = run_cmd(["composer", "test"], check=False, cwd=cwd)
    if res.returncode != 0:
        return False, (res.stdout or "") + "\n" + (res.stderr or "")

    # Den tillfälliga commiten behövs bara för att få ocommittat arbete in i
    # HEAD. Har agenten committat själv ligger ändringarna redan där, och
    # rott-pa-basen kan köras rakt av - att som förr returnera tidigt på ett
    # rent arbetsträd hade tyst hoppat över hela grinden i just det fallet.
    status = run_cmd(["git", "status", "--porcelain"], cwd=cwd).stdout.strip()
    tillfallig_commit = bool(status)
    if tillfallig_commit:
        run_cmd(["git", "add", "."], cwd=cwd)
        run_cmd(["git", "commit", "-m", "Tillfällig commit för rott-pa-basen-kontroll"], cwd=cwd)

    run_cmd(["git", "fetch", "origin", "main"], cwd=cwd)
    base_sha = run_cmd(["git", "merge-base", "HEAD", "origin/main"], cwd=cwd).stdout.strip()
    head_sha = run_cmd(["git", "rev-parse", "HEAD"], cwd=cwd).stdout.strip()
    if head_sha == base_sha:
        # Varken arbetsträd eller commits skiljer sig från basen - rott-pa-basen
        # har inget nytt/ändrat test att pröva. Om det här var det enda försöket
        # fångar STEG 5:s egen "ingen ändring alls"-kontroll det separat.
        return True, ""

    rott_res = run_cmd(
        ["bash", ".github/scripts/rott-pa-basen.sh"], check=False, cwd=cwd,
        env={**os.environ, "BASE_SHA": base_sha},
    )
    rott_ok = (rott_res.returncode == 0)
    rott_output = (rott_res.stdout or "") + "\n" + (rott_res.stderr or "")

    if tillfallig_commit:
        run_cmd(["git", "reset", "--soft", "HEAD~1"], cwd=cwd)

    if not rott_ok:
        return False, f"Nya/ändrade tester är gröna redan på basen (rott-pa-basen.sh):\n{rott_output}"

    return True, ""


def call_deepseek(prompt, cwd):
    """
    Kör DeepSeek V4-Flash via ~/.local/bin/claude-subagent, som sätter
    ANTHROPIC_BASE_URL mot den lokala LiteLLM-proxyn INNAN claude --model haiku
    anropas. Detta är den enda vägen som faktiskt routar till DeepSeek - ett
    direkt `claude --model haiku`-anrop använder orkestrerarens egna
    ANTHROPIC_BASE_URL (obefintlig -> riktig Anthropic Haiku, 5-7x dyrare).
    """
    print("--> Startar DeepSeek via claude-subagent...")
    cmd = [
        os.path.expanduser("~/.local/bin/claude-subagent"),
        "-p", prompt,
        "--permission-mode", "bypassPermissions",
        "--output-format", "text",
    ]
    result = run_cmd(cmd, check=True, cwd=cwd)
    return result.stdout


def call_claude_direct(model, prompt, cwd):
    """
    Kör Sonnet/Opus direkt (inte via claude-subagent - den är hårdkodad till
    --model haiku). Kräver att '~/.claude/settings.json' har en permissions.allow-
    rad för exakt detta kommando, annars stannar den oövervakade cron-körningen
    på permission-klassificeraren. Se ADR-0026: eskalering körs oövervakad, inte
    med mänsklig tillsyn i realtid, vilket kräver den explicita allow-listningen.
    """
    print(f"--> Startar {model} direkt...")
    cmd = [
        "claude",
        "-p", prompt,
        "--model", model,
        "--permission-mode", "bypassPermissions",
        "--output-format", "text",
    ]
    result = run_cmd(cmd, check=True, cwd=cwd)
    return result.stdout


def usage_ok_to_proceed():
    """Vakt mot att påbörja ett issue när Anthropic-kontots rullande
    5-timmarsfönster snart är slut - en Sonnet-granskning eller
    Opus-eskalering mitt i issuet skulle annars kunna avbrytas halvvägs.

    Kollen görs med ett minimalt Sonnet-anrop vars stream-json-utdata
    innehåller en rate_limit_event-rad. Kommandots prefix (fram till och med
    bypassPermissions) måste vara identiskt med call_claude_direct()s -
    ändra bara svansen, annars matchar ingen allow-rad i
    ~/.claude/settings.json och den oövervakade cron-körningen stannar på
    permission-klassificeraren.
    """
    cmd = [
        "claude",
        "-p", "ok",
        "--model", "sonnet",
        "--permission-mode", "bypassPermissions",
        "--output-format", "stream-json",
        "--verbose",
        "--max-turns", "1",
    ]
    result = run_cmd(cmd, check=False)
    for line in result.stdout.splitlines():
        try:
            event = json.loads(line)
        except json.JSONDecodeError:
            continue
        if event.get("type") == "rate_limit_event":
            utilization = event["rate_limit_info"]["unifiedWindows"]["five_hour"]["utilization"]
            remaining = 1 - utilization
            if remaining < MIN_USAGE_REMAINING:
                print(f"⏸️ Endast {remaining:.0%} kvar av 5-timmarsfönstret "
                      f"(kräver minst {MIN_USAGE_REMAINING:.0%}). Hoppar över den här körningen.")
                return False
            return True

    print("⚠️ Kunde inte läsa usage-status (ingen rate_limit_event i svaret) - fortsätter ändå.")
    return True


def bygg_granskningsprompt(issue_body, diff, uppfoljning=False):
    """Granskningsprompten: issuen och diffen, inte diffen ensam.

    Fram till 2026-09-02 fick granskaren bara `gh pr diff`. Den kunde därför
    varken pricka av issuens "Klart när"-punkter - alltså definitionen av att
    inget missats - eller veta vad omfångsrutan tillät. Följden syntes i M2:
    fyra av åtta filer utanför rutan beställdes av granskningen själv (fynd 8-9
    på PR #104, fynd 2 på #109, fynd 5 på #111). Fynden var sakligt riktiga och
    ändå regelbrott, för granskaren kunde inte se rutan.

    `uppfoljning` styr slutvarvet efter en åtgärdsloop: samma underlag, men
    uttryckligen en ny granskning i stället för en efterlevnadskontroll.
    """
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


def run_findings_fix_loop(issue_body, pr_number, branch_name, worktree_path, findings):
    """
    Åtgärdar `findings` (Opus ursprungliga fynd, eller en tidigare
    Sonnet-avvisning), testar, committar, pushar till den befintliga PR:en,
    och Sonnet verifierar smalt att just de fynden är åtgärdade - tre varv med
    DeepSeek, och om alla tre misslyckas ett fjärde och sista varv där Sonnet
    själv gör åtgärden i stället för att bara verifiera den. Tanken är att en
    stympad DeepSeek-lösning inte ska gå raka vägen till eskalering när
    modellen som redan har hela kontexten (samma Sonnet som skrev fyndet) kan
    ha bättre förutsättningar att lösa det själv.

    Sonnets APPROVE är slutgiltigt, ingen ny Opus-omgång här. Delad mellan
    huvudflödet (STEG 5, high risk) och --resume-pr, så det bara finns en
    implementation av loopen att hålla korrekt.

    Returnerar (resolved: bool, findings: str) - findings är den senaste
    avvisningstexten om inte löst, annars oförändrad.
    """
    varv = [
        ("DeepSeek", call_deepseek),
        ("DeepSeek", call_deepseek),
        ("DeepSeek", call_deepseek),
        ("Sonnet", lambda prompt, cwd: call_claude_direct("sonnet", prompt, cwd)),
    ]
    for round_num, (agent_namn, fixare) in enumerate(varv, start=1):
        print(f" -> Åtgärdsvarv {round_num}/{len(varv)} ({agent_namn})...")
        fix_prompt = (
            f"Åtgärda följande fynd från en kodgranskning av din egen lösning på detta issue:\n\n"
            f"{issue_body}\n\n"
            f"Granskningens fynd att åtgärda:\n{findings}\n\n"
            f"Ändra koden i arbetsträdet så att varje fynd är löst. Uppfinn inget nytt - lös "
            f"bara det som listas."
        )
        head_fore = run_cmd(["git", "rev-parse", "HEAD"], cwd=worktree_path).stdout.strip()
        fixare(fix_prompt, worktree_path)

        # Agenten kan svara utan att röra en enda fil (missförstod fyndet,
        # eller trodde felaktigt att det redan var löst). `git commit` kraschar
        # då hela pipelinen med "nothing to commit" - fånga det innan dess och
        # låt varvet räknas som ett misslyckat försök i stället för en krasch.
        #
        # Den kan också ha committat själv: PR #163 fick sin åtgärd som en egen
        # commit av agenten, varpå arbetsträdet var rent och alla fyra varven
        # bokfördes som "inga ändringar" trots att fynden var lösta. Ett rent
        # arbetsträd ensamt betyder alltså inte att inget hänt - HEAD måste
        # också stå kvar.
        status = run_cmd(["git", "status", "--porcelain"], cwd=worktree_path).stdout.strip()
        head_efter = run_cmd(["git", "rev-parse", "HEAD"], cwd=worktree_path).stdout.strip()
        if not status and head_efter == head_fore:
            print(f"  ⚠ {agent_namn} gjorde inga ändringar på varv {round_num}.")
            findings = (
                f"{findings}\n\nFörra åtgärdsförsöket ändrade inga filer alls - agenten "
                f"verkar inte ha förstått vad som skulle göras, eller trodde felaktigt att "
                f"det redan var löst. Peka ut exakt fil och rad för varje kvarstående fynd."
            )
            continue

        if head_efter != head_fore:
            print(f"  i {agent_namn} committade själv på varv {round_num} - behåller den commiten.")

        passed, test_output = run_local_tests(cwd=worktree_path)
        if not passed:
            print(f"  ✗ Åtgärden bröt testsviten på varv {round_num}.")
            findings = f"{findings}\n\nÅtgärden bröt testsviten:\n```\n{test_output[:1500]}\n```"
            continue

        # Bara det agenten lämnade ocommittat ska bli en ny commit. Har den
        # committat själv, och Pint inte ändrat något ovanpå, finns inget kvar
        # att committa - och ett ovillkorligt `git commit` kraschar då på
        # "nothing to commit", precis den krasch guarden ovan finns för.
        run_cmd(["git", "add", "."], cwd=worktree_path)
        staged = run_cmd(["git", "diff", "--cached", "--name-only"], cwd=worktree_path).stdout.strip()
        if staged:
            run_cmd(["git", "commit", "-m", f"Åtgärda granskningsfynd, varv {round_num} ({agent_namn})"], cwd=worktree_path)
        run_cmd(["git", "push", "origin", branch_name], cwd=worktree_path)
        pushed_sha = run_cmd(["git", "rev-parse", "HEAD"], cwd=worktree_path).stdout.strip()
        if not wait_for_pr_head(pr_number, pushed_sha, worktree_path):
            print(f"  ⚠ PR:ens head hann inte synka mot commit {pushed_sha[:8]} - läser diffen ändå.")

        # Slutvarvet är en ny granskning, inte en efterlevnadskontroll. Tidigare
        # fick Sonnet bara fynden plus diffen och frågan "är samtliga fynd
        # åtgärdade?" - en åtgärd som löste fyndet och bröt något annat gick då
        # igenom, eftersom ingen tittade på det andra. Sonnet får nu hela issuen
        # och hela den slutliga diffen, och får uttryckligen resa nya fynd.
        new_diff = run_cmd(["gh", "pr", "diff", pr_number], cwd=worktree_path).stdout
        check_prompt = (
            f"{bygg_granskningsprompt(issue_body, new_diff, uppfoljning=True)}\n\n"
            f"=== FYND SOM SKULLE ÅTGÄRDAS I DET HÄR VARVET ===\n{findings}\n\n"
            f"Börja med att avgöra om vart och ett av dem är löst. Fortsätt sedan med "
            f"den nya granskningen enligt punkterna ovan - godkänn bara om båda delarna "
            f"är rena."
        )
        approved, sonnet_check, tappad = run_review(
            "sonnet", check_prompt, pr_number, worktree_path,
            min_text=MIN_GRANSKNINGSTEXT_UPPFOLJNING,
        )
        if tappad:
            # Etiketten satt men texten är borta. Ett tappat utlåtande är inte
            # fynd: skickas det in i nästa varvs fix_prompt får åtgärdsagenten
            # ett godkännande att "åtgärda" och gör följdriktigt ingenting
            # (PR #162 brände tre varv så). Läs om granskningen en gång i
            # stället - håller den inte andra gången går PR:en till Tony.
            print("  ↻ Utlåtandet tappat - läser om granskningen en gång.")
            approved, sonnet_check, tappad = run_review(
                "sonnet", check_prompt, pr_number, worktree_path,
                min_text=MIN_GRANSKNINGSTEXT_UPPFOLJNING,
            )

        run_cmd(["gh", "pr", "comment", pr_number, "--body",
                  f"### Sonnet 5 - verifiering av åtgärdsvarv {round_num}\n{sonnet_check}"],
                 cwd=REPO_ROOT)

        if approved:
            return True, findings
        if tappad:
            return False, (
                f"{sonnet_check}\n\nGranskningens utlåtande tappades två gånger i rad på "
                f"varv {round_num}. Loopen kan inte avgöra om fynden är lösta, och vägrar "
                f"gissa - PR:en behöver läsas av en människa."
            )
        findings = sonnet_check

    return False, findings


def run_review(model, review_prompt, pr_number, worktree_path, min_text=MIN_GRANSKNINGSTEXT):
    """
    Kör en granskning och avgör godkännande via en GitHub-label modellen själv
    sätter som sista åtgärd - inte genom att tolka fritext. En label är alltid
    exakt samma token oavsett hur modellen formulerar sig i övrigt (sett i
    praktiken: en granskning som skrev "Full suite: 405 tester...\\n\\nAPPROVE"
    lästes fel av ett strikt första-ord-krav, och "DISAPPROVE" hade kunnat
    matcha ett för löst substrängs-sök). Modellen kör redan gh-kommandon med
    fulla rättigheter (bypassPermissions) för sin egen kodändring, så det
    extra kommandot kostar inget nytt förtroende.

    Labeln nollställs innan anropet (frånvaro = inte godkänt = säkrast
    default), så en tidigare varvs label aldrig läcker in i det här beslutet.

    Använder gh:s REST-API (`gh api .../labels`), inte `gh pr edit --add-label` /
    `--remove-label` - det senare går via en GraphQL-mutation som i det här
    repot alltid svarar med ett fel om att "Projects (classic)" fasas ut
    (repository.pullRequest.projectCards), trots att inget projekt är
    inblandat. Felet gjorde att en modell som körde --add-label rimligen drog
    slutsatsen att labeln inte satt (och rapporterade det ärligt i sin
    granskningskommentar) - fast den faktiskt gjorde det ibland. REST-API:et
    har ingen sådan bieffekt och svarar rent.

    Returnerar (approved: bool, review_text: str, tappad: bool). `tappad` är
    sant när modellen satte etiketten men texten är för kort för att bära
    godkännandet. Då är utlåtandet borta, inte negativt - anroparen får inte
    behandla texten som fynd att åtgärda.
    """
    run_cmd(["gh", "api", "--method", "DELETE", f"repos/{GH_REPO}/issues/{pr_number}/labels/review:approved"],
             check=False, cwd=REPO_ROOT)

    full_prompt = (
        f"{review_prompt}\n\n"
        f"Gör din granskning. Om och bara om koden är godkänd utan kvarstående "
        f"anmärkningar, kör detta kommando (REST-API:et, inte 'gh pr edit "
        f"--add-label' - det senare ger alltid ett ofarligt men förvirrande "
        f"GraphQL-fel om 'Projects (classic)' i det här repot) INNAN du skriver "
        f"ditt slutgiltiga svar, inte efter:\n"
        f"gh api repos/{GH_REPO}/issues/{pr_number}/labels -f \"labels[]=review:approved\"\n"
        f"Kör INTE det kommandot om du har några fynd kvar.\n\n"
        f"Avsluta alltid med skriven text som sammanfattar vad du granskade och "
        f"varför - godkänt eller inte. Bara den sista textturen sparas i loggen; "
        f"ett verktygsanrop utan text efter sig försvinner spårlöst."
    )
    review_text = call_claude_direct(model, full_prompt, cwd=worktree_path)

    pr = json.loads(run_cmd(["gh", "pr", "view", pr_number, "--json", "labels"], cwd=REPO_ROOT).stdout)
    approved = any(label["name"] == "review:approved" for label in pr["labels"])

    # En granskning utan text är tappad, inte kortfattad. `--output-format text`
    # sparar bara sista textturen, så ett verktygsanrop efter analysen åt upp
    # den: Opus granskning av PR #113 blev raden "review:approved satt på #113."
    # och ingenting mer. Etiketten satt, alltså mergades PR:en - på ett
    # granskningsutlåtande som inte längre fanns. Att etiketten är kvar men
    # texten borta är exakt det fallet ett tomt svar inte får godkänna.
    if approved and len(review_text.strip()) < min_text:
        notera = (
            f"Granskningen godkände men lämnade bara {len(review_text.strip())} tecken text "
            f"(minst {min_text} krävs). Utlåtandet är tappat, inte kort - "
            f"godkännandet räknas inte."
        )
        print(f"!! {notera}")
        run_cmd(["gh", "api", "--method", "DELETE",
                 f"repos/{GH_REPO}/issues/{pr_number}/labels/review:approved"],
                check=False, cwd=REPO_ROOT)
        return False, f"{review_text}\n\n_{notera}_", True

    return approved, review_text, False


MIN_OPUS_SVAR = 80


def run_opus_answer(issue_body, fragor, pr_number, worktree_path):
    """Eskalerar PR-kroppens '## Frågor och antaganden' till Opus - arkitekten -
    i stället för att stanna hela PR:en hos Tony för varje fråga en implementerare
    skrev. Se docs/Tankar.md / retro-anteckningen 2026-09-02: M3:s PR:er kom
    tillbaka till Tony med frågor som Opus, inte Tony, är rätt instans att svara på.

    Token-snålt med vilje: Opus får issuen och frågetexten, INTE diffen eller
    hela PR:en - att läsa och hantera koden är granskningens jobb (run_review),
    inte den här funktionens. Samma etikett-mönster som run_review() använder
    för APPROVE, av samma skäl (se den funktionens docstring): fritext är inte
    ett tillförlitligt facit för om ett svar kräver en kodändring.

    Returnerar (svar: str, kraver_kodandring: bool).
    """
    run_cmd(["gh", "api", "--method", "DELETE",
             f"repos/{GH_REPO}/issues/{pr_number}/labels/svar:kodandring-kravs"],
            check=False, cwd=REPO_ROOT)

    prompt = (
        "Du är projektets arkitekt. En implementerande agent hittade inte svaret på "
        "frågan/frågorna nedan i issuens läslista och gissade inte - den frågade i "
        "stället, precis som den ska. Svara direkt och konkret på varje punkt, med "
        "hänvisning till rätt ADR eller Datamodell-fil där det är relevant, så att "
        "en implementerare kan agera på svaret utan att fråga igen.\n\n"
        "Läs INTE koden, diffen eller PR:en - det är inte din uppgift här, bara att "
        "ta det arkitekturbeslut frågan efterfrågar utifrån issuen och dokumentationen.\n\n"
        f"=== ISSUEN ===\n{issue_body}\n\n"
        f"=== FRÅGOR OCH ANTAGANDEN FRÅN IMPLEMENTERAREN ===\n{fragor}\n\n"
        "Avsluta med att avgöra om ditt svar kräver en ändring i den redan skrivna "
        "koden. Om ja, kör detta kommando (REST-API:et, inte 'gh pr edit --add-label' "
        "- se skälet i granskningsinstruktionen du känner till) INNAN du skriver ditt "
        "slutgiltiga svar, inte efter:\n"
        f"gh api repos/{GH_REPO}/issues/{pr_number}/labels -f \"labels[]=svar:kodandring-kravs\"\n"
        "Kör INTE det kommandot om svaret bara är en klargöring utan kodpåverkan.\n\n"
        "Avsluta alltid med skriven text som är ditt svar - bara den sista textturen "
        "sparas i loggen."
    )
    svar = call_claude_direct("opus", prompt, cwd=worktree_path)

    pr = json.loads(run_cmd(["gh", "pr", "view", pr_number, "--json", "labels"], cwd=REPO_ROOT).stdout)
    kraver_kodandring = any(label["name"] == "svar:kodandring-kravs" for label in pr["labels"])

    if len(svar.strip()) < MIN_OPUS_SVAR:
        # Samma fail-closed-resonemang som run_review(): ett tappat svar (bara
        # ett verktygsanrop, ingen text efter) ska inte tolkas som "inget att
        # göra" bara för att labeln råkar saknas.
        svar = (
            f"{svar}\n\n_Svaret var bara {len(svar.strip())} tecken - för kort för att "
            f"lita på. Behandlas som att kodändring krävs._"
        )
        kraver_kodandring = True

    return svar, kraver_kodandring


def extract_risk_class(issue_body):
    """
    risk_class är ett dropdown-fält i issue-formuläret (low/medium/high), inritat
    i body-texten som '### risk_class\\n\\nhigh' - inte en GitHub-label.

    Issues skrivna innan mallen fanns (t.ex. #91-93, migrerade ur backloggfilerna
    2026-08-31) bär axeln som en Markdown-tabellrad i stället: '| `risk_class` |
    `elevated` |'. Den gamla tvågradiga skalan (none/elevated) normaliseras till
    den nya tregradiga (ADR-0027 § Alternativ diskuterade aldrig en mellannivå,
    men 'elevated' beskrevs uttryckligen som "läses rad för rad" - samma
    innebörd som 'high' har nu, inte 'medium').
    """
    body = issue_body or ""

    m = re.search(r"^#{2,4}\s*risk_class\s*\n+\s*(\S+)", body, re.MULTILINE | re.IGNORECASE)
    if not m:
        m = re.search(r"`risk_class`\s*\|\s*`([^`]+)`", body, re.IGNORECASE)

    if not m:
        # Felar stängt. Tidigare gav en oläsbar axel "low", alltså automatisk merge
        # utan att någon läser diffen - den bana som har minst kontroll, vald av ett
        # regex som inte träffade. Samma fail-open-klass som omfångsrutans grind, och
        # issue-mallen säger själv "vid tvekan: elevated". Se docs/Process/Lärdomar.md.
        print("!! risk_class gick inte att läsa ur issuen - kör som 'high' (manuell merge).")
        return "high"

    value = m.group(1).strip().lower()
    legacy_map = {"none": "low", "elevated": "high"}
    return legacy_map.get(value, value)


PR_RUBRIKER = ("## Sammanfattning", "## Frågor och antaganden", "## Processnotering")


def bygg_pr_kropp(issue_num, agent_summary):
    """PR-kroppen: Closes-raden, modellens sammanfattning, och en synlig lucka
    där en obligatorisk rubrik saknas.

    agent_summary är modellens egen slutsammanfattning (se summary_instruction).
    Tidigare skrevs den rakt av, och var den tom blev kroppen bara "Closes #N" -
    tre av M2:s tio PR:er fick en kropp på exakt tio tecken. Värre: kön rör
    aldrig .github/pull_request_template.md, så `Frågor och antaganden` och
    `Processnotering` fanns i noll respektive tre av tio. De två fälten är
    punkt 1 och 6 i retrons bevismängd, och en milstolpe utan dem går inte att
    utvärdera i efterhand.

    Saknas en rubrik skrivs den ut som en tom rubrik med en markering, i stället
    för att utelämnas. En lucka som syns i PR:en kan åtgärdas; en som inte finns
    i texten går inte att skilja från ett "Inget." - vilket är exakt samma
    fail-open-fel som omfångsrutans grind gjorde. Se docs/Process/Lärdomar.md.
    """
    delar = [f"Closes #{issue_num}"]
    text = (agent_summary or "").strip()
    if text:
        delar.append(text)

    saknade = [r for r in PR_RUBRIKER if r.lower() not in text.lower()]
    for rubrik in saknade:
        delar.append(f"{rubrik}\n\n_Modellen skrev inte det här avsnittet._")
    if saknade:
        print(f"!! PR-kroppen saknar {len(saknade)} obligatorisk(a) rubrik(er): {', '.join(saknade)}")

    return "\n\n".join(delar)


INGA_FRAGOR = {"inga", "inga.", "inget", "inget.", "nej", "nej.", "-", "n/a"}


def oppna_fragor(pr_body):
    """Texten under '## Frågor och antaganden', om den inte är ett tomt svar.

    Fältet finns för att en implementerare som inte hittar svaret ska fråga i
    stället för att gissa i koden - men i M2 läste ingen det. PR #108 skrev
    "uteslut det från PR:en eller ta ställning separat" om en fil utanför
    omfångsrutan och mergades tretton sekunder senare, eftersom
    `risk_class: none` gick raka vägen till automatisk merge. AGENTS.md §
    Omfångsrutan säger "stanna och fråga i PR:en", och en bana som mergar innan
    någon kan svara gör den regeln omöjlig att följa.

    Returnerar frågetexten (sanningsvärde True) eller "" när den är tom. Saknas
    rubriken helt blockerar den inget - bygg_pr_kropp() skriver alltid ut den, så
    det fallet är en handskriven PR, och de mergar Tony ändå.
    """
    m = re.search(r"^##\s*Frågor och antaganden\s*$(.*?)(?=^##\s|\Z)",
                  pr_body or "", re.MULTILINE | re.DOTALL)
    if not m:
        return ""
    text = m.group(1).strip()
    # Kursiv markering från bygg_pr_kropp() betyder att modellen inte skrev
    # avsnittet alls - det är inte samma sak som "Inga.", och ska stanna PR:en.
    if not text:
        return ""
    return "" if text.strip("*_ ").lower() in INGA_FRAGOR else text


def eskalera(issue_num, pr_number, worktree_path, branch_name, skal, exit_code=1):
    """Lämna över till Tony: etikett, pushover, städa worktreen, avsluta.

    Samlad på ett ställe därför att varje utgång ur granskningen måste göra
    exakt de fyra sakerna. PR #104 mergades i M2 utan `review:approved` för att
    en av vägarna ut inte gjorde dem.
    """
    run_cmd(["gh", "issue", "edit", issue_num, "--remove-label", "in-progress", "--add-label", "needs-human"],
             cwd=REPO_ROOT)
    send_pushover(f"🚨 Issue #{issue_num}: {skal} PR #{pr_number} kräver dig.")
    cleanup_worktree(worktree_path, branch_name)
    sys.exit(exit_code)


def setup_worktree(branch_name):
    os.makedirs(WORKTREE_BASE, exist_ok=True)
    worktree_path = os.path.join(WORKTREE_BASE, branch_name.replace("/", "-"))
    run_cmd(["git", "fetch", "origin", "main"], cwd=REPO_ROOT)
    if os.path.isdir(worktree_path):
        run_cmd(["git", "worktree", "remove", "--force", worktree_path], check=False, cwd=REPO_ROOT)
    run_cmd(["git", "branch", "-D", branch_name], check=False, cwd=REPO_ROOT)
    run_cmd(["git", "worktree", "add", worktree_path, "-b", branch_name, "origin/main"], cwd=REPO_ROOT)
    return worktree_path


def setup_worktree_for_existing_branch(branch_name):
    """Som setup_worktree, men checkar ut en redan pushad branch (en öppen PR:s
    HEAD) i stället för att grena en ny från origin/main. Används av --resume-pr."""
    os.makedirs(WORKTREE_BASE, exist_ok=True)
    worktree_path = os.path.join(WORKTREE_BASE, branch_name.replace("/", "-"))
    run_cmd(["git", "fetch", "origin", branch_name], cwd=REPO_ROOT)
    if os.path.isdir(worktree_path):
        run_cmd(["git", "worktree", "remove", "--force", worktree_path], check=False, cwd=REPO_ROOT)
    run_cmd(["git", "branch", "-D", branch_name], check=False, cwd=REPO_ROOT)
    run_cmd(["git", "worktree", "add", worktree_path, "-b", branch_name, f"origin/{branch_name}"], cwd=REPO_ROOT)
    return worktree_path


def cleanup_worktree(worktree_path, branch_name):
    run_cmd(["git", "worktree", "remove", "--force", worktree_path], check=False, cwd=REPO_ROOT)
    run_cmd(["git", "branch", "-D", branch_name], check=False, cwd=REPO_ROOT)


def wait_for_checks(pr_number):
    """Väntar in CI innan merge. `gh pr merge` mergar annars direkt,
    oavsett om GitHub Actions ens hunnit starta - repot har ingen
    branch protection som stoppar det (PR #112, ren race: mergad
    14:25:32, testjobbet klart 14:27:01). "no checks reported" strax
    efter en push betyder att Actions inte registrerat körningen än,
    inte att inga checkar finns - då väntar vi och försöker igen i
    stället för att läsa det som grönt."""
    for attempt in range(3):
        result = run_cmd(
            ["gh", "pr", "checks", pr_number, "--watch", "--interval", "15"],
            check=False, cwd=REPO_ROOT,
        )
        output = ((result.stdout or "") + (result.stderr or "")).lower()
        if "no checks reported" in output and attempt < 2:
            time.sleep(15)
            continue
        # Granskningsgrinden kör om på `labeled`, och den körningen registreras
        # inte i samma ögonblick som labeln sätts. Mätt 2026-09-02 på PR #118:
        # `gh pr checks` visar bara den senaste körningen per checknamn (den
        # gamla röda faller bort ur listningen, även om check-runs-API:et bär
        # båda) - men läser man för tidigt är det fortfarande den gamla som är
        # den senaste. Ett rött utfall läses därför om en gång innan det tros på.
        if result.returncode != 0 and attempt < 2 and "granskning" in output:
            print("  ⚠ Rött utfall med granskningsgrinden inblandad - läser om efter 20 s.")
            time.sleep(20)
            continue
        return result.returncode == 0
    return False


def wait_for_pr_head(pr_number, expected_sha, cwd, tries=5, delay=2):
    """Väntar in att GitHub redovisar den nyss pushade committen som PR:ens
    head innan diffen läses tillbaka - annars kan `gh pr diff` visa en diff
    som ligger ett steg bakom den commit som just pushades.

    Sett i praktiken på PR #132: åtgärdsloopens Sonnet-verifiering av varv 1
    (kommentaren postades 52 s efter push) beskrev exakt det kodmönster
    varvet just hade tagit bort - alltså en läsning mot en diff som ännu inte
    hunnit spegla den pushade committen. Loopens tre kvarvarande varv fick då
    ett fynd som redan var åtgärdat, kunde aldrig hitta något att ändra, och
    hela PR:en eskalerades i onödan.

    `gh pr view --json headRefOid` är billigt jämfört med `gh pr diff` och ett
    direkt sätt att bekräfta synk innan den dyrare läsningen. Bäst-möjligt:
    om synken aldrig sker inom `tries` försök läses diffen ändå - en evig
    väntan här vore fel sorts försiktighet - men resultatet talar om att den
    kan vara stale så anroparen kan logga en varning.

    Returnerar True om headRefOid matchade `expected_sha` inom `tries` försök,
    annars False.
    """
    for attempt in range(tries):
        result = run_cmd(
            ["gh", "pr", "view", pr_number, "--json", "headRefOid", "-q", ".headRefOid"],
            check=False, cwd=cwd,
        )
        if result.stdout.strip() == expected_sha:
            return True
        if attempt < tries - 1:
            time.sleep(delay)
    return False


def acquire_lock():
    os.makedirs(os.path.dirname(LOCK_PATH), exist_ok=True)
    lock_fd = open(LOCK_PATH, "w")
    try:
        fcntl.flock(lock_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        print("🔒 En annan körning pågår redan (lock upptagen). Avbryter.")
        sys.exit(0)
    lock_fd.write(str(os.getpid()))
    lock_fd.flush()
    return lock_fd

# =====================================================================
# HUVUDFLÖDE (PIPELINE)
# =====================================================================

def process_next_issue(issue_number=None):
    # -----------------------------------------------------------------
    # STEG 1: HÄMTA ISSUET - ANTINGEN DET ABSOLUT ÄLDSTA ÖPPNA (STRIKT FIFO)
    # ELLER, VID --issue, ETT UTTRYCKLIGEN VALT ISSUE SOM GÅR FÖRE KÖN.
    # -----------------------------------------------------------------
    if issue_number is not None:
        res = run_cmd([
            "gh", "issue", "view", issue_number,
            "--json", "number,title,labels,body,state",
        ], cwd=REPO_ROOT, check=False)
        if res.returncode != 0:
            print(f"Kunde inte hämta issue #{issue_number}: {res.stderr}")
            sys.exit(1)
        issue = json.loads(res.stdout)
        if issue["state"] != "OPEN":
            print(f"Issue #{issue_number} är inte öppet (state: {issue['state']}).")
            sys.exit(1)
    else:
        res = run_cmd([
            "gh", "issue", "list",
            "--state", "open",
            "--label", "Build",
            "--search", "sort:created-asc",
            "--limit", "1",
            "--json", "number,title,labels,body",
        ], cwd=REPO_ROOT)
        issues = json.loads(res.stdout)

        if not issues:
            print("Kön är tom. Inga öppna issues att behandla.")
            sys.exit(0)

        issue = issues[0]
    issue_num = str(issue["number"])
    issue_title = issue["title"]
    issue_body = issue["body"]
    labels = [l["name"] for l in issue["labels"]]
    risk_class = extract_risk_class(issue_body)
    branch_name = f"feature/issue-{issue_num}"

    if "needs-human" in labels:
        print(f"🛑 KÖN ÄR BLOCKERAD: Issue #{issue_num} kräver mänsklig granskning ('needs-human').")
        sys.exit(0)

    if "in-progress" in labels:
        # Undvik att dubbelköra ett issue som redan har en öppen PR - mergen är
        # synkron (repot saknar auto-merge på nuvarande GitHub-plan, se ADR),
        # men en misslyckad merge (konflikt, API-fel) kan ändå lämna issuet öppet.
        pr_check = run_cmd(
            ["gh", "pr", "list", "--head", branch_name, "--state", "open", "--json", "number"],
            check=False, cwd=REPO_ROOT,
        )
        open_prs = json.loads(pr_check.stdout) if pr_check.returncode == 0 else []
        if open_prs:
            print(f"⏳ Issue #{issue_num} har redan en öppen PR (#{open_prs[0]['number']}) som väntar på merge. Väntar.")
            sys.exit(0)
        print(f"⚠️ Issue #{issue_num} är märkt 'in-progress' utan öppen PR - trolig avbruten körning.")
        run_cmd(["gh", "issue", "comment", issue_num, "--body",
                  "⚠️ Hittades märkt `in-progress` utan öppen PR vid nästa kökörning - troligen en avbruten session. Kräver mänsklig kontroll innan ny körning."],
                 cwd=REPO_ROOT)
        run_cmd(["gh", "issue", "edit", issue_num, "--remove-label", "in-progress", "--add-label", "needs-human"], cwd=REPO_ROOT)
        send_pushover(f"⚠️ Issue #{issue_num} var 'in-progress' utan öppen PR. Märkt needs-human.")
        sys.exit(0)

    if not usage_ok_to_proceed():
        sys.exit(0)

    print(f"\n==================================================")
    print(f" Påbörjar arbete med Issue #{issue_num}: {issue_title} (risk_class: {risk_class})")
    print(f"==================================================\n")

    send_pushover(f"🚀 Påbörjar Issue #{issue_num}: '{issue_title}' med DeepSeek V4 Flash.")
    run_cmd(["gh", "issue", "edit", issue_num, "--add-label", "in-progress"], cwd=REPO_ROOT)

    worktree_path = setup_worktree(branch_name)

    try:
        print("--> Bootstrappar worktree (composer setup)...")
        run_cmd(["composer", "setup"], cwd=worktree_path)
        _process_in_worktree(issue_num, issue_title, issue_body, labels, risk_class, branch_name, worktree_path)
    except (Exception, KeyboardInterrupt) as e:
        # KeyboardInterrupt ärver BaseException, inte Exception - fångas inte
        # av ett rent `except Exception`. En cron-körning skickar aldrig ^C,
        # men manuell testkörning gör det, och lämnade annars samma
        # in-progress/worktree-skräp som en riktig krasch.
        avbruten = isinstance(e, KeyboardInterrupt)
        rubrik = "avbruten manuellt (^C)" if avbruten else f"kraschade oväntat: {e}"
        print(f"\n🚨 Processen {rubrik}")
        cleanup_worktree(worktree_path, branch_name)
        kommentar = (
            "⚠️ **Processen avbruten manuellt under körning (Ctrl-C)**"
            if avbruten else
            f"⚠️ **Processen kraschade oväntat**\n\n```\n{str(e)[:2000]}\n```"
        )
        run_cmd(["gh", "issue", "comment", issue_num, "--body", kommentar],
                 check=False, cwd=REPO_ROOT)
        run_cmd(["gh", "issue", "edit", issue_num, "--remove-label", "in-progress", "--add-label", "needs-human"],
                 check=False, cwd=REPO_ROOT)
        send_pushover(f"🚨 PIPELINE {'AVBRUTEN' if avbruten else 'KRASCHADE'} på Issue #{issue_num}: {e if not avbruten else 'manuellt'}")
        if avbruten:
            raise
        sys.exit(1)


def _process_in_worktree(issue_num, issue_title, issue_body, labels, risk_class, branch_name, worktree_path):
    # -----------------------------------------------------------------
    # STEG 2: DEEPSEEK V4 FLASH (MAX 3 FÖRSÖK)
    # -----------------------------------------------------------------
    deepseek_success = False
    last_error_output = ""
    error_history = ""

    summary_instruction = (
        "\n\nAvsluta ditt svar med tre rubriker, i den här ordningen. De blir "
        "PR-beskrivningen, så skriv dem för en granskare som inte har sett ditt "
        "arbete, inte för dig själv.\n\n"
        "'## Sammanfattning' - vilka filer som ändrades och varför, vilka av "
        "issuens numrerade beslut som följdes, och eventuella avvikelser från "
        "issuens instruktioner, i så fall varför.\n\n"
        "'## Frågor och antaganden' - hittade du inte svaret i issuens läslista, "
        "skriv frågan här i stället för att gissa i koden, och lista varje "
        "antagande du ändå tvingats göra. Ligger en ändrad fil utanför issuens "
        "'In scope', skriv vilken och varför här. 'Inga.' är ett giltigt svar.\n\n"
        "'## Processnotering' - en rad: vad kostade mer än det borde? Fel axel, "
        "för tunn läslista, otydlig omfångsruta, test som var svårt att skriva. "
        "'Inget.' är ett giltigt och vanligt svar, men skriv det aktivt. Det här "
        "fältet är det enda som överlever sessionen; det läses vid milstolpsretro."
    )
    agent_summary = ""

    print("[FAS 1] Kodgenerering med DeepSeek V4 Flash (Max 3 försök)...")
    for attempt in range(1, 4):
        print(f" -> DeepSeek Försök {attempt}/3...")

        prompt = f"Lös följande issue för vårt projekt:\n\n{issue_body}{summary_instruction}"
        if error_history:
            prompt += (
                f"\n\nTidigare kodförsök misslyckades i testerna med följande fel:\n"
                f"{error_history}\n"
                f"Analysera felet och korrigera projektkoden."
            )

        agent_summary = call_deepseek(prompt, cwd=worktree_path)
        print(f"  Agentens sammanfattning (försök {attempt}):\n{agent_summary}")

        # Görs innan run_local_tests(): en agent som inte rörde en fil ger en
        # tom `git status`, och run_local_tests() tolkar då avsaknaden av en
        # rott-pa-basen-kontroll att pröva som "gröna tester" (se dess
        # docstring). Utan den här kontrollen bröt loopen direkt på ett enda
        # no-op-försök och hoppade över både försök 2-3 och hela
        # Sonnet-eskaleringen i STEG 3 - upptäckt först i STEG 5:s egen
        # "ingen ändring alls"-kontroll, efter att hela pipelinen redan gett
        # upp på issuet.
        no_diff = not run_cmd(["git", "status", "--porcelain"], cwd=worktree_path).stdout.strip()
        if no_diff:
            print(f"  ✗ Försök {attempt}: inga filer ändrades.")
            last_error_output = "Inga filer ändrades alls - lösningen uteblev."
            error_history += (
                f"\n--- Försök {attempt} ---\nDu svarade utan att ändra en enda fil i "
                f"arbetsträdet. Skriv faktisk kod som löser issuet.\n"
            )
            continue

        passed, test_output = run_local_tests(cwd=worktree_path)
        if passed:
            print("  ✓ Tester GRÖNA med DeepSeek!")
            send_pushover(f"🧩 Issue #{issue_num}: DeepSeek löste testerna på försök {attempt}/3. Skapar PR...")
            deepseek_success = True
            break
        else:
            print(f"  ✗ Tester RÖDA på försök {attempt}.")
            last_error_output = test_output
            error_history += f"\n--- Försök {attempt} felutskrift ---\n{test_output[:1000]}\n"

    # -----------------------------------------------------------------
    # STEG 3: SONNET ESKALERING (EXAKT 1 FÖRSÖK)
    # -----------------------------------------------------------------
    if not deepseek_success:
        print("\n[FAS 2] DeepSeek misslyckades 3 gånger. Eskalerar till Sonnet...")
        send_pushover(f"⚠️ DeepSeek misslyckades efter 3 försök på Issue #{issue_num}. Eskalerar till Sonnet.")

        run_cmd(["gh", "issue", "edit", issue_num, "--remove-label", "model:deepseek", "--add-label", "model:sonnet"], cwd=REPO_ROOT)

        run_cmd(["git", "reset", "--hard", "HEAD"], cwd=worktree_path)
        run_cmd(["git", "clean", "-fd"], cwd=worktree_path)

        sonnet_prompt = (
            f"Lös följande issue:\n{issue_body}\n\n"
            f"DeepSeek misslyckades tidigare med detta testfel:\n```\n{last_error_output}\n```\n\n"
            f"Analysera problemet och genomför lösningen.{summary_instruction}"
        )

        agent_summary = call_claude_direct("sonnet", sonnet_prompt, cwd=worktree_path)
        passed, test_output = run_local_tests(cwd=worktree_path)

        if passed:
            send_pushover(f"🧩 Issue #{issue_num}: Sonnet löste testerna efter DeepSeeks 3 försök. Skapar PR...")

        if not passed:
            print("\n[FAS 3] Tester RÖDA även med Sonnet. Stoppar hela kön!")

            cleanup_worktree(worktree_path, branch_name)

            comment_body = (
                f"⚠️ **Processen avbruten - Mänsklig granskning krävs**\n\n"
                f"- **DeepSeek V4 Flash:** Misslyckades efter 3 försök.\n"
                f"- **Sonnet:** Misslyckades på försök 1.\n\n"
                f"**Sista testfelet:**\n```\n{test_output[:2000]}\n```"
            )
            run_cmd(["gh", "issue", "comment", issue_num, "--body", comment_body], cwd=REPO_ROOT)
            run_cmd([
                "gh", "issue", "edit", issue_num,
                "--remove-label", "in-progress",
                "--remove-label", "model:sonnet",
                "--add-label", "needs-human",
            ], cwd=REPO_ROOT)

            send_pushover(
                f"🚨 PIPELINE STOPPAD!\n"
                f"Issue #{issue_num} ('{issue_title}') misslyckades med både DeepSeek och Sonnet.\n"
                f"Mänsklig granskning krävs. Kön är nu låst."
            )
            sys.exit(1)

    # -----------------------------------------------------------------
    # STEG 5: PUSH & REVIEW (BASERAT PÅ risk_class)
    # -----------------------------------------------------------------
    print("\n[FAS 4] Skapar Pull Request och analyserar risk...")

    # Tester gröna men agenten rörde inte en enda fil - hände utan att krascha
    # på "nothing to commit" i --resume-pr:s åtgärdsloop (samma klass av fel),
    # och skulle krascha lika ograciöst här. En PR utan diff är meningslös,
    # så det är en riktig needs-human-situation, inte en pipelinekrasch.
    status = run_cmd(["git", "status", "--porcelain"], cwd=worktree_path).stdout.strip()
    if not status:
        print("\n⚠️ Tester gröna men ingen fil ändrades - agenten hävdade en lösning utan diff.")
        cleanup_worktree(worktree_path, branch_name)
        run_cmd(["gh", "issue", "comment", issue_num, "--body",
                  "⚠️ **Processen avbruten - Mänsklig granskning krävs**\n\n"
                  "Testerna blev gröna, men agenten ändrade ingen fil alls. Antingen var "
                  "issuet redan löst av befintlig kod, eller så missförstod agenten uppgiften "
                  "utan att det syntes i testresultatet."],
                 cwd=REPO_ROOT)
        run_cmd(["gh", "issue", "edit", issue_num, "--remove-label", "in-progress", "--add-label", "needs-human"],
                 cwd=REPO_ROOT)
        send_pushover(f"🚨 Issue #{issue_num}: agenten gjorde inga ändringar trots gröna tester. Kräver granskning.")
        sys.exit(1)

    run_cmd(["git", "add", "."], cwd=worktree_path)
    run_cmd(["git", "commit", "-m", f"Fix #{issue_num}: {issue_title}"], cwd=worktree_path)
    run_cmd(["git", "push", "origin", branch_name, "--force"], cwd=worktree_path)
    pushed_sha = run_cmd(["git", "rev-parse", "HEAD"], cwd=worktree_path).stdout.strip()

    pr_body = bygg_pr_kropp(issue_num, agent_summary)

    pr_res = run_cmd([
        "gh", "pr", "create",
        "--title", f"Fix #{issue_num}: {issue_title}",
        "--body", pr_body,
        # --head krävs explicit: `git push origin <branch>` (utan -u) pushar
        # branchen men sätter aldrig lokal upstream-tracking, och gh pr create
        # kan då inte avgöra head-branchen även om fjärr-branchen finns.
        "--head", branch_name,
    ], cwd=worktree_path)
    # gh 2.23.0 saknar --json på pr create; kommandot skriver PR-URL:en på stdout.
    pr_url = pr_res.stdout.strip().splitlines()[-1]
    pr_number = pr_url.rstrip("/").split("/")[-1]

    # -----------------------------------------------------------------
    # GRANSKNING: varje PR får en läsare. Axeln väljer djup och modell.
    # -----------------------------------------------------------------
    # Fram till 2026-09-02 gick risk_class: low rakt till automatisk merge utan
    # att någon läste diffen - PR #108 mergades 13 sekunder efter att den
    # öppnades, PR #112 efter 5, och #108 bar då både en fil utanför
    # omfångsrutan och en uttrycklig fråga i sin egen kropp. ADR-0026 säger att
    # en bred elevated-bucket ska kosta en djupare läsning, inte avgöra om det
    # finns en läsare. En Sonnet-läsning ovanpå en issue som kostat 0,50 USD är
    # brus i den summan; den ogranskade banan kostade oss mer än så.
    #
    # Fram till 2026-09-03 granskade Opus risk_class: high. Uppföljningen i
    # ADR-0026 tog bort den grenen: issue-mallen tvingar redan fram ett fullt
    # kontrakt (numrerade beslut, läslista, omfångsruta, ett test per "Klart
    # när"-punkt), så granskningen är en efterlevnadskontroll mot det
    # kontraktet - inte ett nytt arkitekturomdöme. Sonnet läser samma prompt
    # Opus fick (bygg_granskningsprompt() skiljer aldrig på modell). Opus roll
    # är nu bara den smala eskaleringen i los_fraga_och_merga() för obesvarade
    # frågor - det är den uppgift som faktiskt kräver ett nytt omdöme.
    granskare = "sonnet"
    modellnamn = "Sonnet 5"
    print(f"--> risk_class: {risk_class} - granskas av {modellnamn}...")
    send_pushover(f"👀 Issue #{issue_num}: PR #{pr_number} skapad, granskas nu av {modellnamn} (risk_class: {risk_class}).")

    if not wait_for_pr_head(pr_number, pushed_sha, worktree_path):
        print(f"  ⚠ PR:ens head hann inte synka mot commit {pushed_sha[:8]} - läser diffen ändå.")
    pr_diff = run_cmd(["gh", "pr", "diff", pr_number], cwd=worktree_path).stdout
    godkand, granskning, _ = run_review(
        granskare, bygg_granskningsprompt(issue_body, pr_diff), pr_number, worktree_path
    )
    run_cmd(["gh", "pr", "comment", pr_number, "--body",
              f"### {modellnamn} Granskningsanalys\n{granskning}"], cwd=REPO_ROOT)

    godkand_direkt = godkand
    if not godkand:
        print(f"--> {modellnamn} hittade fynd - startar åtgärdsloop (3 varv DeepSeek, sedan 1 varv Sonnet)...")
        send_pushover(f"🔁 Issue #{issue_num}: {modellnamn} hittade fynd på PR #{pr_number}, startar åtgärdsloop.")
        godkand, granskning = run_findings_fix_loop(
            issue_body, pr_number, branch_name, worktree_path, granskning
        )
        if not godkand:
            eskalera(
                issue_num, pr_number, worktree_path, branch_name,
                f"Fynd kvarstår efter åtgärdsloopen ({modellnamn} + tre DeepSeek-varv + ett Sonnet-varv).",
            )

    godkand_av = f"{modellnamn} direkt" if godkand_direkt else "Sonnet 5, efter åtgärdsvarv"

    los_fraga_och_merga(
        issue_num, issue_title, issue_body, pr_number, pr_body, risk_class,
        godkand_av, branch_name, worktree_path,
    )


def los_fraga_och_merga(issue_num, issue_title, issue_body, pr_number, pr_body,
                         risk_class, godkand_av, branch_name, worktree_path):
    """MERGE-steget: löser en eventuell obesvarad fråga via Opus, sedan merge
    eller överlämning enligt risk_class. Delad mellan huvudflödet (STEG 5) och
    resume_question(), som återupptar exakt den här delen manuellt för en PR
    som redan fastnat på den gamla "Obesvarad fråga"-banan (dvs. skapad innan
    Opus-eskaleringen fanns).

    En obesvarad fråga i PR-kroppen stoppade tidigare hela PR:en hos Tony,
    oavsett axel - men frågorna är nästan alltid arkitekturfrågor ("vilket
    fält", "vilken tabell"), och det är Opus, inte Tony, som är projektets
    arkitekt (AGENTS.md § Omfångsrutan säger "stanna och fråga", inte "fråga
    Tony"). Eskalera dit i stället: Opus svarar smalt (issuen + frågan, inte
    diffen - se run_opus_answer), och kräver svaret en kodändring går den
    genom samma DeepSeek+Sonnet-loop som ett vanligt granskningsfynd. Bara om
    den loopen inte löser det, eller om Opus svar är tomt/tappat, når det Tony.
    """
    fragor = oppna_fragor(pr_body)
    if fragor:
        print("--> Obesvarad fråga i PR-kroppen - eskalerar till Opus (arkitekt)...")
        send_pushover(f"❓ Issue #{issue_num}: obesvarad fråga på PR #{pr_number}, eskalerar till Opus (arkitekt).")
        opus_svar, kraver_kodandring = run_opus_answer(issue_body, fragor, pr_number, worktree_path)
        run_cmd(["gh", "pr", "comment", pr_number, "--body",
                  f"### Opus 5 - arkitektsvar på Frågor och antaganden\n{opus_svar}"],
                 cwd=REPO_ROOT)

        if kraver_kodandring:
            print("--> Opus svar kräver en kodändring - startar åtgärdsloop (DeepSeek + Sonnet)...")
            send_pushover(f"🔁 Issue #{issue_num}: Opus svar på PR #{pr_number} kräver en kodändring, startar åtgärdsloop.")
            godkand, granskning = run_findings_fix_loop(
                issue_body, pr_number, branch_name, worktree_path, opus_svar
            )
            if not godkand:
                eskalera(
                    issue_num, pr_number, worktree_path, branch_name,
                    f"Godkänd av {godkand_av}, men Opus svar på en PR-fråga kräver en "
                    f"kodändring som inte blev löst inom åtgärdsloopen.",
                )
            godkand_av = f"{godkand_av}; Opus-svar åtgärdat och verifierat av Sonnet 5"
        else:
            print("--> Opus svar kräver ingen kodändring - fortsätter mot merge.")

    if risk_class == "high":
        run_cmd(["gh", "issue", "edit", issue_num, "--remove-label", "in-progress", "--add-label", "needs-human"],
                 cwd=REPO_ROOT)
        send_pushover(
            f"🔍 HIGH RISK: PR #{pr_number} för Issue #{issue_num} är klar för din manuella merge!\n"
            f"Godkänd av {godkand_av}."
        )
        cleanup_worktree(worktree_path, branch_name)
        sys.exit(0)

    # medium och low mergas automatiskt - men först när varje check är grön,
    # och en av dem är nu granskning.yml, som är röd utan review:approved.
    print("--> Väntar in CI innan automatisk merge...")
    if wait_for_checks(pr_number):
        run_cmd(["gh", "pr", "merge", pr_number, "--squash"], cwd=REPO_ROOT)
        send_pushover(
            f"✅ Issue #{issue_num} ('{issue_title}') godkänd av {godkand_av} och mergad, PR #{pr_number}!"
        )
    else:
        run_cmd(["gh", "pr", "comment", pr_number, "--body",
                  "### CI rött efter godkännande\nGranskningen godkände PR:en, men CI blev inte "
                  "grönt. Mergar inte automatiskt."],
                 cwd=REPO_ROOT)
        eskalera(
            issue_num, pr_number, worktree_path, branch_name,
            f"Godkänd av {godkand_av} men CI blev rött.",
        )

    cleanup_worktree(worktree_path, branch_name)


def find_pr_context(pr_number):
    """Hämtar issue-numret ('Closes #N' i PR-beskrivningen), issuets titel och
    body, PR:ens branch, och den senast postade Opus-granskningens fynd -
    allt --resume-pr behöver för att återuppta åtgärdsloopen utan att köra om
    DeepSeeks första försök."""
    pr = json.loads(run_cmd(["gh", "pr", "view", pr_number, "--json", "headRefName,body,comments"], cwd=REPO_ROOT).stdout)
    branch_name = pr["headRefName"]

    m = re.search(r"Closes #(\d+)", pr.get("body") or "", re.IGNORECASE)
    if not m:
        raise Exception(f"Hittade ingen 'Closes #N' i PR #{pr_number}s beskrivning - vet inte vilket issue det hör till.")
    issue_num = m.group(1)

    issue = json.loads(run_cmd(["gh", "issue", "view", issue_num, "--json", "title,body"], cwd=REPO_ROOT).stdout)

    # Granskningen kan vara Opus eller Sonnet sedan alla PR:er får en läsare -
    # leta på den gemensamma delen av rubriken, inte på modellnamnet.
    granskningar = [c["body"] for c in pr["comments"] if "Granskningsanalys" in c["body"].splitlines()[0]]
    if not granskningar:
        raise Exception(f"Hittade ingen granskningskommentar på PR #{pr_number} att återuppta från.")

    return issue_num, issue["title"], issue["body"], branch_name, granskningar[-1]


def resume_pr(pr_number):
    """Återupptar åtgärdsloopen för en befintlig PR vars Opus-granskning redan
    postats fynd på - t.ex. en PR skapad innan åtgärdsloopen fanns, eller en
    som körde slut på sina 4 varv (3 DeepSeek + 1 Sonnet) och du vill ge en ny
    chans efter att själv ha petat i något. Kör INTE om DeepSeeks första
    försök eller Opus första granskning - de har redan hänt och står kvar i
    PR:ens historik."""
    issue_num, issue_title, issue_body, branch_name, findings = find_pr_context(pr_number)
    print(f"\n==================================================")
    print(f" Återupptar PR #{pr_number} (Issue #{issue_num}: {issue_title})")
    print(f"==================================================\n")

    worktree_path = setup_worktree_for_existing_branch(branch_name)
    print("--> Bootstrappar worktree (composer setup)...")
    run_cmd(["composer", "setup"], cwd=worktree_path)

    try:
        print("--> Startar åtgärdsloop (3 varv DeepSeek, sedan 1 varv Sonnet, med Sonnet-verifiering)...")
        resolved, findings = run_findings_fix_loop(issue_body, pr_number, branch_name, worktree_path, findings)
    except (Exception, KeyboardInterrupt) as e:
        avbruten = isinstance(e, KeyboardInterrupt)
        print(f"\n🚨 Återupptagandet {'avbrutet manuellt (^C)' if avbruten else f'kraschade oväntat: {e}'}")
        cleanup_worktree(worktree_path, branch_name)
        send_pushover(f"🚨 --resume-pr {pr_number} {'avbrutet' if avbruten else 'kraschade'}: {e if not avbruten else 'manuellt'}")
        if avbruten:
            raise
        sys.exit(1)

    if resolved:
        send_pushover(f"🔍 PR #{pr_number} (Issue #{issue_num}) är klar för din manuella merge! Fynd åtgärdade via --resume-pr.")
    else:
        run_cmd(["gh", "issue", "edit", issue_num, "--remove-label", "in-progress", "--add-label", "needs-human"],
                 check=False, cwd=REPO_ROOT)
        send_pushover(f"🚨 PR #{pr_number} (Issue #{issue_num}): fynd kvarstår efter --resume-pr:s åtgärdsloop.")

    cleanup_worktree(worktree_path, branch_name)


def resume_question(pr_number):
    """Återupptar en PR som fastnade på den gamla "Obesvarad fråga"-banan -
    dvs. skapad innan run_opus_answer() fanns, så dess `## Frågor och
    antaganden` aldrig nådde Opus utan gick direkt till Tony (se PR #131,
    issue #130). Körs INTE i det normala flödet - bara som manuell
    återupptagning av en PR som redan står med `needs-human` av exakt den
    anledningen."""
    pr = json.loads(run_cmd(["gh", "pr", "view", pr_number, "--json", "headRefName,body"], cwd=REPO_ROOT).stdout)
    pr_body = pr["body"] or ""
    branch_name = pr["headRefName"]

    m = re.search(r"Closes #(\d+)", pr_body, re.IGNORECASE)
    if not m:
        raise Exception(f"Hittade ingen 'Closes #N' i PR #{pr_number}s beskrivning - vet inte vilket issue det hör till.")
    issue_num = m.group(1)

    issue = json.loads(run_cmd(["gh", "issue", "view", issue_num, "--json", "title,body"], cwd=REPO_ROOT).stdout)
    issue_title, issue_body = issue["title"], issue["body"]
    risk_class = extract_risk_class(issue_body)

    fragor = oppna_fragor(pr_body)
    if not fragor:
        print(f"PR #{pr_number} har ingen obesvarad fråga att lösa - inget att göra.")
        return

    print(f"\n==================================================")
    print(f" Återupptar PR #{pr_number} (Issue #{issue_num}: {issue_title}) - obesvarad fråga")
    print(f"==================================================\n")

    worktree_path = setup_worktree_for_existing_branch(branch_name)
    print("--> Bootstrappar worktree (composer setup)...")
    run_cmd(["composer", "setup"], cwd=worktree_path)

    modellnamn = "Opus 5" if risk_class == "high" else "Sonnet 5"
    godkand_av = f"{modellnamn} (tidigare granskning, PR återupptagen för obesvarad fråga)"

    try:
        los_fraga_och_merga(
            issue_num, issue_title, issue_body, pr_number, pr_body, risk_class,
            godkand_av, branch_name, worktree_path,
        )
    except (Exception, KeyboardInterrupt) as e:
        avbruten = isinstance(e, KeyboardInterrupt)
        print(f"\n🚨 Återupptagandet {'avbrutet manuellt (^C)' if avbruten else f'kraschade oväntat: {e}'}")
        cleanup_worktree(worktree_path, branch_name)
        send_pushover(f"🚨 --resume-question {pr_number} {'avbrutet' if avbruten else 'kraschade'}: {e if not avbruten else 'manuellt'}")
        if avbruten:
            raise
        sys.exit(1)


# =====================================================================
# STARTPUNKT
# =====================================================================
if __name__ == "__main__":
    lock_fd = acquire_lock()
    try:
        if len(sys.argv) >= 3 and sys.argv[1] == "--resume-pr":
            resume_pr(sys.argv[2])
        elif len(sys.argv) >= 3 and sys.argv[1] == "--resume-question":
            resume_question(sys.argv[2])
        elif len(sys.argv) >= 3 and sys.argv[1] == "--issue":
            process_next_issue(issue_number=sys.argv[2])
        else:
            process_next_issue()
    finally:
        fcntl.flock(lock_fd, fcntl.LOCK_UN)
        lock_fd.close()
