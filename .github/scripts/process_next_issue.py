#!/usr/bin/env -S python3 -u
"""Plockar äldsta öppna GitHub-issue och kör den genom Deepseek -> (vid fel) Sonnet,
med Sonnet-review på medium risk och Opus-granskning på high risk innan mänsklig merge.
Se ADR-0025/0026/0027.

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

# Ovillkorligen oskiftad utskrift - relevant oavsett hur skriptet startas
# (shebangens -u gäller bara vid direkt körning, inte `python3 script.py`).
# Utan den flushas print()-loggen aldrig till disk innan en kill -9, vilket
# gjorde det första skarpa testet (issue #91) blint för hur långt körningen
# hunnit.
sys.stdout.reconfigure(line_buffering=True)
sys.stderr.reconfigure(line_buffering=True)

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
WORKTREE_BASE = os.path.join(REPO_ROOT, ".claude", "worktrees")
LOCK_PATH = os.path.join(REPO_ROOT, ".claude", "process-next-issue.lock")
GH_REPO = "Manjo-Consulting-AB/mimers"

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

    status = run_cmd(["git", "status", "--porcelain"], cwd=cwd).stdout.strip()
    if not status:
        # Inget att committa - rott-pa-basen har då inget nytt/ändrat test att
        # pröva. Om det här var det enda försöket fångar STEG 5:s egen
        # "ingen ändring alls"-kontroll det separat.
        return True, ""

    run_cmd(["git", "add", "."], cwd=cwd)
    run_cmd(["git", "commit", "-m", "Tillfällig commit för rott-pa-basen-kontroll"], cwd=cwd)

    run_cmd(["git", "fetch", "origin", "main"], cwd=cwd)
    base_sha = run_cmd(["git", "merge-base", "HEAD", "origin/main"], cwd=cwd).stdout.strip()
    rott_res = run_cmd(
        ["bash", ".github/scripts/rott-pa-basen.sh"], check=False, cwd=cwd,
        env={**os.environ, "BASE_SHA": base_sha},
    )
    rott_ok = (rott_res.returncode == 0)
    rott_output = (rott_res.stdout or "") + "\n" + (rott_res.stderr or "")

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


def run_findings_fix_loop(issue_body, pr_number, branch_name, worktree_path, findings):
    """
    DeepSeek åtgärdar `findings` (Opus ursprungliga fynd, eller en tidigare
    Sonnet-avvisning), testar, committar, pushar till den befintliga PR:en,
    och Sonnet verifierar smalt att just de fynden är åtgärdade - max 3 varv.
    Sonnets APPROVE är slutgiltigt, ingen ny Opus-omgång här. Delad mellan
    huvudflödet (STEG 5, high risk) och --resume-pr, så det bara finns en
    implementation av loopen att hålla korrekt.

    Returnerar (resolved: bool, findings: str) - findings är den senaste
    avvisningstexten om inte löst, annars oförändrad.
    """
    for round_num in range(1, 4):
        print(f" -> Åtgärdsvarv {round_num}/3...")
        fix_prompt = (
            f"Åtgärda följande fynd från en kodgranskning av din egen lösning på detta issue:\n\n"
            f"{issue_body}\n\n"
            f"Granskningens fynd att åtgärda:\n{findings}\n\n"
            f"Ändra koden i arbetsträdet så att varje fynd är löst. Uppfinn inget nytt - lös "
            f"bara det som listas."
        )
        call_deepseek(fix_prompt, cwd=worktree_path)

        # DeepSeek kan svara utan att röra en enda fil (missförstod fyndet,
        # eller trodde felaktigt att det redan var löst). `git commit` kraschar
        # då hela pipelinen med "nothing to commit" - fånga det innan dess och
        # låt varvet räknas som ett misslyckat försök i stället för en krasch.
        status = run_cmd(["git", "status", "--porcelain"], cwd=worktree_path).stdout.strip()
        if not status:
            print(f"  ⚠ DeepSeek gjorde inga ändringar på varv {round_num}.")
            findings = (
                f"{findings}\n\nFörra åtgärdsförsöket ändrade inga filer alls - agenten "
                f"verkar inte ha förstått vad som skulle göras, eller trodde felaktigt att "
                f"det redan var löst. Peka ut exakt fil och rad för varje kvarstående fynd."
            )
            continue

        passed, test_output = run_local_tests(cwd=worktree_path)
        if not passed:
            print(f"  ✗ Åtgärden bröt testsviten på varv {round_num}.")
            findings = f"{findings}\n\nÅtgärden bröt testsviten:\n```\n{test_output[:1500]}\n```"
            continue

        run_cmd(["git", "add", "."], cwd=worktree_path)
        run_cmd(["git", "commit", "-m", f"Åtgärda granskningsfynd, varv {round_num}"], cwd=worktree_path)
        run_cmd(["git", "push", "origin", branch_name], cwd=worktree_path)

        new_diff = run_cmd(["gh", "pr", "diff", pr_number], cwd=worktree_path).stdout
        check_prompt = (
            f"Här är de fynd som skulle åtgärdas:\n{findings}\n\n"
            f"Här är den uppdaterade diffen:\n\n{new_diff}\n\n"
            f"Är samtliga fynd åtgärdade?"
        )
        approved, sonnet_check = run_review("sonnet", check_prompt, pr_number, worktree_path)
        run_cmd(["gh", "pr", "comment", pr_number, "--body",
                  f"### Sonnet 5 - verifiering av åtgärdsvarv {round_num}\n{sonnet_check}"],
                 cwd=REPO_ROOT)

        if approved:
            return True, findings
        findings = sonnet_check

    return False, findings


def run_review(model, review_prompt, pr_number, worktree_path):
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

    Returnerar (approved: bool, review_text: str).
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
    return approved, review_text


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
        return "low"

    value = m.group(1).strip().lower()
    legacy_map = {"none": "low", "elevated": "high"}
    return legacy_map.get(value, value)


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
        return result.returncode == 0
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

def process_next_issue():
    # -----------------------------------------------------------------
    # STEG 1: HÄMTA DET ABSOLUT ÄLDSTA ÖPPNA ISSUET (STRIKT FIFO)
    # -----------------------------------------------------------------
    res = run_cmd([
        "gh", "issue", "list",
        "--state", "open",
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

    print(f"\n==================================================")
    print(f" Påbörjar arbete med Issue #{issue_num}: {issue_title} (risk_class: {risk_class})")
    print(f"==================================================\n")

    send_pushover(f"🚀 Påbörjar Issue #{issue_num}: '{issue_title}' med DeepSeek V4 Flash.")
    run_cmd(["gh", "issue", "edit", issue_num, "--add-label", "in-progress"], cwd=REPO_ROOT)

    worktree_path = setup_worktree(branch_name)
    print("--> Bootstrappar worktree (composer setup)...")
    run_cmd(["composer", "setup"], cwd=worktree_path)

    try:
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
        "\n\nAvsluta ditt svar med en kort sammanfattning under rubriken "
        "'## Sammanfattning': vilka filer som ändrades och varför, vilka av "
        "issuens numrerade beslut som följdes, och eventuella avvikelser från "
        "issuens instruktioner - och i så fall varför. Den sammanfattningen "
        "blir PR-beskrivningen, så skriv den för en granskare som inte har "
        "sett ditt arbete, inte för dig själv."
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

        passed, test_output = run_local_tests(cwd=worktree_path)
        if passed:
            print("  ✓ Tester GRÖNA med DeepSeek!")
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

    # agent_summary är modellens egen slutsammanfattning (se summary_instruction
    # i FAS 1/2) - tidigare kastades den bort helt och PR-kroppen var bara
    # "Closes #N", utan förklaring. Särskilt allvarligt för risk_class: low,
    # som aldrig granskas av vare sig människa eller modell - PR-beskrivningen
    # är då det enda som förklarar vad som gjordes och varför.
    pr_body = f"Closes #{issue_num}\n\n{agent_summary}" if agent_summary.strip() else f"Closes #{issue_num}"

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
    # REVIEW: HIGH RISK (Opus 5 + Manuell Merge)
    # -----------------------------------------------------------------
    if risk_class == "high":
        print("--> HIGH RISK: Genererar Opus 5 review...")
        pr_diff = run_cmd(["gh", "pr", "diff", pr_number], cwd=worktree_path).stdout

        opus_prompt = (
            f"Gör en noggrann säkerhets- och arkitekturgranskning av denna diff:\n\n{pr_diff}\n\n"
            f"Om du har fynd, skriv dem som en numrerad lista - konkret nog att en annan "
            f"implementerare kan åtgärda dem utan att fråga dig något mer."
        )
        resolved, opus_review = run_review("opus", opus_prompt, pr_number, worktree_path)
        run_cmd(["gh", "pr", "comment", pr_number, "--body", f"### Opus 5 Granskningsanalys\n{opus_review}"], cwd=REPO_ROOT)

        opus_approved_directly = resolved
        findings = opus_review

        # Opus fynd -> DeepSeek åtgärdar, Sonnet verifierar per varv (max 3).
        # Sonnets APPROVE är slutgiltigt - ingen ny Opus-omgång efteråt. Sonnet
        # kollar bara "gjorde agenten det som listades", inte en ny öppen
        # granskning; det är Opus initiala fynd som är den bärande kontrollen,
        # Sonnet verifierar bara efterlevnaden av dem.
        if not resolved:
            print("--> Opus hittade fynd - startar åtgärdsloop (DeepSeek + Sonnet-verifiering, max 3 varv)...")
            resolved, findings = run_findings_fix_loop(issue_body, pr_number, branch_name, worktree_path, findings)

            if not resolved:
                run_cmd(["gh", "issue", "edit", issue_num, "--remove-label", "in-progress", "--add-label", "needs-human"],
                         cwd=REPO_ROOT)
                send_pushover(
                    f"🚨 Issue #{issue_num}: Opus fynd kvarstår efter åtgärdsloopen. "
                    f"PR #{pr_number} kräver manuell granskning."
                )
                cleanup_worktree(worktree_path, branch_name)
                sys.exit(1)

        godkand_av = "Opus 5 direkt" if opus_approved_directly else "Sonnet 5, efter att Opus fynd åtgärdats"
        run_cmd(["gh", "issue", "edit", issue_num, "--remove-label", "in-progress", "--add-label", "needs-human"],
                 cwd=REPO_ROOT)
        send_pushover(
            f"🔍 HIGH RISK: PR #{pr_number} för Issue #{issue_num} är klar för din manuella merge!\n"
            f"Godkänd av {godkand_av}."
        )
        cleanup_worktree(worktree_path, branch_name)
        sys.exit(0)

    # -----------------------------------------------------------------
    # REVIEW: MEDIUM RISK (Sonnet 5 Review, mergar på APPROVE)
    # -----------------------------------------------------------------
    elif risk_class == "medium":
        print("--> MEDIUM RISK: Verifierar med Sonnet 5...")
        pr_diff = run_cmd(["gh", "pr", "diff", pr_number], cwd=worktree_path).stdout

        review_prompt = f"Granska följande PR-diff för säkerhet och fel:\n{pr_diff}"
        approved, review_result = run_review("sonnet", review_prompt, pr_number, worktree_path)

        if approved:
            if wait_for_checks(pr_number):
                run_cmd(["gh", "pr", "merge", pr_number, "--squash"], cwd=REPO_ROOT)
                send_pushover(f"✅ Issue #{issue_num} ('{issue_title}') verifierad av Sonnet 5 och mergad, PR #{pr_number}!")
            else:
                run_cmd(["gh", "pr", "comment", pr_number, "--body",
                          "### CI rött efter godkännande\nSonnet 5 godkände PR:en, men CI blev inte grönt. Mergar inte automatiskt."],
                         cwd=REPO_ROOT)
                run_cmd(["gh", "issue", "edit", issue_num, "--remove-label", "in-progress", "--add-label", "needs-human"],
                         cwd=REPO_ROOT)
                send_pushover(f"🚨 PR #{pr_number} för Issue #{issue_num} godkändes av Sonnet men CI blev rött. Kräver granskning.")
                cleanup_worktree(worktree_path, branch_name)
                sys.exit(1)
        else:
            run_cmd(["gh", "pr", "comment", pr_number, "--body", f"### Sonnet 5 Underkände PR\n{review_result}"], cwd=REPO_ROOT)
            run_cmd(["gh", "issue", "edit", issue_num, "--remove-label", "in-progress", "--add-label", "needs-human"],
                     cwd=REPO_ROOT)
            send_pushover(f"🚨 Sonnet 5 underkände PR #{pr_number} för Issue #{issue_num}. Kräver granskning.")
            cleanup_worktree(worktree_path, branch_name)
            sys.exit(1)

    # -----------------------------------------------------------------
    # REVIEW: LOW RISK (Automatisk Merge)
    # -----------------------------------------------------------------
    else:
        print("--> risk_class: low - Väntar in CI innan automatisk merge...")
        if wait_for_checks(pr_number):
            run_cmd(["gh", "pr", "merge", pr_number, "--squash"], cwd=REPO_ROOT)
            send_pushover(f"✅ Issue #{issue_num} ('{issue_title}') löst och mergad, PR #{pr_number}!")
        else:
            run_cmd(["gh", "pr", "comment", pr_number, "--body",
                      "### CI rött\nrisk_class: low skulle mergas automatiskt, men CI blev inte grönt. Mergar inte."],
                     cwd=REPO_ROOT)
            run_cmd(["gh", "issue", "edit", issue_num, "--remove-label", "in-progress", "--add-label", "needs-human"],
                     cwd=REPO_ROOT)
            send_pushover(f"🚨 PR #{pr_number} för Issue #{issue_num} (risk_class: low) fick rött CI. Kräver granskning.")
            cleanup_worktree(worktree_path, branch_name)
            sys.exit(1)

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

    opus_comments = [c["body"] for c in pr["comments"] if c["body"].startswith("### Opus 5 Granskningsanalys")]
    if not opus_comments:
        raise Exception(f"Hittade ingen 'Opus 5 Granskningsanalys'-kommentar på PR #{pr_number} att återuppta från.")

    return issue_num, issue["title"], issue["body"], branch_name, opus_comments[-1]


def resume_pr(pr_number):
    """Återupptar åtgärdsloopen för en befintlig PR vars Opus-granskning redan
    postats fynd på - t.ex. en PR skapad innan åtgärdsloopen fanns, eller en
    som körde slut på sina 3 varv och du vill ge en ny chans efter att själv
    ha petat i något. Kör INTE om DeepSeeks första försök eller Opus första
    granskning - de har redan hänt och står kvar i PR:ens historik."""
    issue_num, issue_title, issue_body, branch_name, findings = find_pr_context(pr_number)
    print(f"\n==================================================")
    print(f" Återupptar PR #{pr_number} (Issue #{issue_num}: {issue_title})")
    print(f"==================================================\n")

    worktree_path = setup_worktree_for_existing_branch(branch_name)
    print("--> Bootstrappar worktree (composer setup)...")
    run_cmd(["composer", "setup"], cwd=worktree_path)

    try:
        print("--> Startar åtgärdsloop (DeepSeek + Sonnet-verifiering, max 3 varv)...")
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


# =====================================================================
# STARTPUNKT
# =====================================================================
if __name__ == "__main__":
    lock_fd = acquire_lock()
    try:
        if len(sys.argv) >= 3 and sys.argv[1] == "--resume-pr":
            resume_pr(sys.argv[2])
        else:
            process_next_issue()
    finally:
        fcntl.flock(lock_fd, fcntl.LOCK_UN)
        lock_fd.close()
