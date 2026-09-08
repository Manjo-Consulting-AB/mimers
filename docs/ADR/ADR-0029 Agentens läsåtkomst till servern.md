# ADR-0029 Agentens läsåtkomst till servern

**Status:** Antagen 2026-09-08 · [[ADR-index]]

## Kontext

Retron läser GitHub: mergade PR:er, issuekroppar, CI-loggar, `.claude/usage.jsonl`. Den läser aldrig servern, och därför slutar varje fråga om **produktionens faktiska tillstånd** i ett antagande.

M6-retron gjorde luckan mätbar. Fyra frågor gick inte att besvara:

- Vad står `QUEUE_CONNECTION` på i `shared/.env`? Två köade jobb är utrullade — `GenerateImageDerivatives` sedan v0.1.0 och `BuildContainerExport` i v0.4.0 — och ingenting i pipelinen startar en arbetare. Är värdet `database` har miniatyrer inte genererats sedan september; är det `sync` byggs exportens ZIP i webbrequesten. Se issue 235.
- Har `jobs`-tabellen liggande rader? Det är samma fråga, besvarad från andra hållet, och den enda som skiljer "aldrig köad" från "köad och aldrig körd".
- Pekar `current` på den release som senast publicerades?
- Finns båda deployraderna kvar i `authorized_keys`? [[Pipeline]] noterar att de är utrullningens enda väg in och att bortfallet märks först vid nästa release.

Samtliga fyra är **läsningar**. Ingen av dem kräver skrivrättighet, och ingen av dem går att härleda från GitHub. `curl` mot en publik URL svarar inte på någon av dem — [[Pipeline]] § *Kör `ssh` med `-4`* skriver redan ut varför ett grönt `curl` inte bevisar något om servern.

Nuläget: agenten har ingen nyckel. `~/.ssh/` på utvecklings-VPS:en innehåller `known_hosts` från 2026-09-03 och ingenting mer — en tidigare session kopplade upp sig med nyckelmaterial som inte lämnades kvar. `Pipeline.md` skriver `ssh -4 -p 2020 -i ~/.ssh/<nyckel>` med platshållare, som om nyckeln fanns.

Två fakta styr beslutet:

- **Hos inleed går det inte att få en läsbegränsad inloggning.** Ett unix-konto (`s174280`), ingen restricted shell, ingen separat användare vi styr. En nyckel som kan `cat shared/.env` kan lika gärna `rm -rf current`. "Read-only" är då en disciplin, inte en spärr.
- **En nyckel som ska fungera obevakat måste ligga okrypterad på VPS:en.** [[Pipeline]] § *En röjd nyckel* beskriver exakt den situationen: `claude_rsa` låg i serverns egen `~/.ssh/` och låste upp maskinen den låg på. VPS:en kör dessutom en cron-loop utan tillsyn.

## Beslut

**Agenten får en egen nyckel, låst till ett skript med `command=` i `authorized_keys`.** Inget interaktivt skal, ingen skrivrättighet, ingen väg till utrullningen.

Raden:

```
command="/home/s174280/bin/retro-fakta",restrict ssh-ed25519 AAAA... claude-retro
```

`command=` gör att `authorized_keys` kör skriptet oavsett vad klienten ber om — klientens kommando hamnar i `SSH_ORIGINAL_COMMAND` och skriptet väljer själv om det bryr sig. `restrict` stänger portforwarding, agentforwarding, X11 och pty.

Skriptet är `deploy/retro-fakta.sh` i repot, installerat som `~/bin/retro-fakta`. Det skriver ut en fast uppsättning fakta och ingenting annat. Nyckelns sprängradie är därmed exakt skriptets utdata.

**Två villkor på skriptet.**

1. **Det dumpar aldrig `shared/.env`.** Nyckelnamnen listas; värden skrivs bara ut för en namngiven lista ofarliga (`APP_ENV`, `APP_URL`, `QUEUE_CONNECTION`, `MAIL_MAILER`, `DB_CONNECTION`, `FILES_INTERNAL_REDIRECT`, …). Allt annat redovisas som *satt* eller *saknas*. En röjd nyckel ska inte läcka `APP_KEY`, `MAILGUN_SECRET` eller databaslösenordet.
2. **Det bootar inte Laravel.** Ren shell plus `mysql`-klienten. Skälet är att diagnostiken måste fungera när appen inte gör det — en halv utrullning är precis det läge man vill kunna läsa av, och `php artisan` svarar då inte.

**Skrivvägarna ändras inte.** GitHub Actions håller deploynycklarna, `production.yml` äger `current`, `deploy.sh` äger migreringarna. Den här nyckeln kan ingenting av det, och ska inte kunna det.

**Nyckelparet genereras av Tony**, som deploynycklarna. Privathalvan passerar aldrig genom en agent; publikhalvan läggs till additivt över shell med en backup före — aldrig genom DirectAdmins SSH Keys-sida, av skälet i [[Pipeline]] § *En röjd nyckel*.

## Motivering

Frågan är inte om agenten ska ha åtkomst utan hur mycket, och det billigaste svaret som täcker behovet är att flytta gränsen från vad agenten *får* göra till vad nyckeln *kan* göra. Ett forced command är en spärr servern upprätthåller, inte en regel någon ska minnas — samma resonemang som repot redan använder för omfångsrutan och ordbudgeten: ett deterministiskt villkor hör hemma i ett skript.

Skriptet ligger i repot och inte bara på servern för att det ska gå att granska innan nyckeln finns. Man ska kunna läsa exakt vad nyckeln kan åstadkomma i en diff, inte behöva lita på en beskrivning av den.

**Det installeras däremot inte av `deploy.sh`.** Det ligger i `~/bin/`, utanför `current/`, och uppdateras för hand som resten av engångsuppsättningen. Skälet är samma som att det inte bootar Laravel: en trasig eller halv utrullning får inte ta diagnostikverktyget med sig, och `current` flippar under en utrullning. Priset är att repots version och serverns kan glida isär — skriptet skriver därför ut sin egen version, så att glappet syns i utdatan i stället för att tystas.

Alternativet att inte ge någon åtkomst alls är inte gratis. Kostnaden betalas i antaganden: M6-retron skrev "går inte att läsa från GitHub" tre gånger, och issue 235 blockerar en release på en fråga som tar tio sekunder att besvara på plats. En retro som inte kan se produktionen mäter processen och inte produkten.

## Konsekvenser

- `deploy/retro-fakta.sh` finns i repot. Den installeras manuellt som `~/bin/retro-fakta`, `chmod 700`, i båda miljöerna — eller i produktionens konto med miljön som argument, se skriptets huvud.
- [[Pipeline]] § *Engångsuppsättning* får nyckeln och skriptet som punkter, och § *Läget på GitHub och hos inleed* en rad om att `authorized_keys` nu bär tre driftkritiska rader, inte två.
- `Pipeline.md`s `ssh -4 -p 2020 -i ~/.ssh/<nyckel>`-rad får ett riktigt nyckelnamn när nyckeln finns.
- Retron får en punkt i bevismängden: kör `retro-fakta` mot båda miljöerna innan releasen bockas av. Det hör hemma i retro-prompten i ai-standards, inte i `AGENTS.md`.
- Nyckeln ligger okrypterad på utvecklings-VPS:en. Det är accepterat **därför att** den inte kan något annat än att köra skriptet; skulle forced command någon gång tas bort är den en fullvärdig inloggning till produktionen, och då gäller den här ADR:n inte längre.
- Utökas skriptet senare gäller de två villkoren fortfarande. Ett tillägg som skriver ut ett hemligt värde, eller som börjar skriva på disk, är en ny ADR — inte en commit.

## Uppföljning 2026-09-08: första körningen

Nyckeln sattes upp samma dag och skriptet kördes mot båda miljöerna. Tre saker att bära med sig.

**Den första installationen var trasig, och rapporten såg ändå fullständig ut.** Serverns kopia saknade sina första 51 rader — shebang, `set -uo pipefail`, `VERSION`, `case`-blocket som sätter `MILJO` och `APP`, samt `ENVFIL`. Följden blev att `$APP` och `$ENVFIL` var tomma, alltså att varje sökvägsberoende avsnitt rapporterade *saknas* om sökvägen `""`. Utan `set -u` — den raden var också borta — avbröt ingenting, och utdatan påstod att `current` saknades på en server där den fanns. Skriptet bör därför i en senare version vägra köra när `VERSION` eller `APP` är tomma; en tom rapport ska aldrig kunna se ut som en fullständig. Installera med `scp`, inte genom att klistra in i en heredoc.

**`mysql` är ett deprecerat alias hos inleed** och skriver `Deprecated program name … use '/usr/bin/mariadb' instead` på stderr. v1 slog ihop stderr med svaret och rapporterade *"databasen svarar inte"* mot en databas som svarade utmärkt. v2 väljer `mariadb` när den finns och håller stderr isär från svaret.

**Noll rader i `jobs` besvarar inte frågan ensamt.** Det kan lika gärna betyda att inget någonsin köats som att kön töms, och i produktion var det det förra — `storage/files` är tom, alltså har inget miniatyrjobb någonsin dispatchats. Skriptet skriver därför sedan v2 ut om det finns en arbetare alls, som ett eget svar bredvid radantalet.

## Alternativ

**En vanlig nyckel med skal.** Enklast att sätta upp, och det som först föreslogs. Avfärdat: hos inleed går läsrättighet inte att avgränsa, så nyckeln blir i praktiken produktionsåtkomst med skrivrättighet, liggande okrypterad på en maskin som kör obevakad cron. Det är samma nyckelsituation som redan röjts en gång.

**Ingen åtkomst; Tony klistrar in svaren.** Fungerar för en enskild fråga — issue 235 hade lösts så. Avfärdat som stående ordning: frågorna kommer per retro och per release, och den som ska svara är då i loopen varje gång. Det är också fel person att fråga om `readlink current`.

**En statusrutt eller ett artisan-kommando som rapporterar samma fakta.** Attraktivt, och kräver ingen nyckel alls. Avfärdat för det här behovet: det förutsätter att appen är uppe, och de intressanta frågorna ställs oftast just när den inte är det. Kan bli ett komplement senare — en `/api/health`-yta för driftövervakning är en annan fråga än en retro som läser servern.

**`rrsync` eller motsvarande begränsad filöverföring.** Avfärdat: hämtar filer i stället för att svara på frågor, och `shared/.env` är just den fil som inte ska hämtas. Skriptets värde ligger i urvalet, inte i åtkomsten.
