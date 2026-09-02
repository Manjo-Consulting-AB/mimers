# ADR-0027 Agentisolering under utveckling

**Status:** Antagen 2026-08-31 · [[ADR-index]]

## Kontext

Frågan uppstod om varje agent-session — särskilt DeepSeek-subagenter som körs i batch mot backloggen — borde köra i en egen Docker-container: en isolerad kopia av repot per agent, med tester och PR-skapande innanför containern, som förstörs efteråt. Motivet är global blast radius: ett olämpligt bash-kommando (`rm -rf` mot fel sökväg, en trasig miljövariabel, en ändring i globala cachar eller systempaket) skulle kunna slå ut den delade Debian-VM:en för alla andra agenter samtidigt, inte bara för agenten som gjorde felet.

Nuläget: agenter kör redan i separata git worktrees (`.claude/worktrees/`, gitignorerat), var och en med egen `vendor/` och `node_modules/`. Repofiler och beroenden är alltså redan isolerade mellan agent-sessioner — det är inte den delen av risken som är obesvarad. Ingen Docker-infrastruktur finns i repot idag, och `.claude/settings.json` saknar en `permissions`-sektion (ingen allow/deny-lista, inget uttryckligt bypass-läge). `.github/workflows/ci.yml` kör testsviten mot sqlite (`pdo_sqlite`, `sqlite3`) utan externa tjänster — ingen DB-server, ingen redis.

[[ADR-0018 Utvecklingsprocess och deploy]] valde bort Docker för produktionsdrift hos inleed ("ingen root, ingen container, inget Docker") med motiveringen att driftprofilen ska vara så tråkig som möjligt tills produkten motiverar något annat. Det är en annan fråga än agent-isolering under utveckling, men samma tänkesätt är relevant här: börja med den billigaste åtgärden som faktiskt täcker risken, innan ny infrastruktur läggs till.

## Beslut

**Ingen Docker per agent just nu.** Worktree-isoleringen som redan finns behålls oförändrad. Kvarvarande risk — kommandon som når utanför worktreen och skadar host-miljön — adresseras i första hand med en permissions-denylist i `.claude/settings.json` (blockera mönster som `rm -rf`, `sudo`, skrivning utanför `.claude/worktrees/`), inte med containrar.

## Motivering

Den risk som faktiskt är obesvarad ligger inte i repot utan i vad ett bash-anrop kan göra utanför sin worktree. En denylist på verktygsnivå adresserar precis det, oavsett om agenten kör i ett auto- eller bypass-läge, utan att lägga till en ny infrastrukturkedja.

Docker per agent är inte gratis att införa: en dev-image att hålla i synk med CI:s PHP 8.4/Node 20, spinup- och teardown-scripting, hantering av GitHub-token och andra secrets inne i containern. Den kostnaden ska vägas mot ett scenario som ännu inte har inträffat i det här repot — inte mot ett hypotetiskt värsta fall.

Skulle Docker ändå bli aktuellt senare är DeepSeek-batchflödet rätt första mål, inte Sonnet/Opus-huvudsessioner. Batchagenterna har lägst tillit och körs redan isolerat i worktrees för jämförelsekörningar (se [[ADR-0025 Modellval efter riskaxlar]]), medan Sonnet/Opus-sessioner redan har mänsklig tillsyn i realtid — den marginella nyttan av att containerisera dem är lägre.

Eftersom CI:s tester kör mot sqlite utan externa tjänster skulle en eventuell framtida container inte behöva en docker-compose-stack med databas eller redis — en enkel image (PHP 8.4 + Composer + Node 20, samma versioner som `ci.yml`) räcker. Det är värt att notera för att göra ett senare beslut billigare, men det är inte skälet att bygga det nu.

**Eskaleringströskel** — när Docker blir rätt svar:
- En faktisk incident inträffar: en agent skadar host-miljön, eller ett kommando läcker synligt utanför sin worktree.
- DeepSeek-batchvolymen växer till en nivå där blast radius per körning blir svår att resonera om manuellt, och en denylist inte längre känns tillräcklig.

## Konsekvenser

- `.claude/settings.json` behöver en `permissions`-denylist som täcker destruktiva kommandomönster. Det är en uppföljningspunkt från det här beslutet, inte implementerad av det.
- Ingen ny Dockerfile, docker-compose eller devcontainer-konfiguration i repot.
- Om Docker införs vid ett senare beslut: skalet räcker med PHP 8.4 + Node 20, ingen tjänste-stack, så länge testsviten fortsätter köra mot sqlite (se [[ADR-0022 Testramverk och statisk analys]]). Ändras testmiljön till att kräva en riktig databas eller redis i CI, gäller den förutsättningen inte längre och frågan bör tas om.

## Alternativ

**Docker för alla agenter**, som i det ursprungliga förslaget (master-process spinnar upp en container per issue, kör subagenten med bypass-permissions inuti, pushar PR, river containern). Avfärdat nu: löser en risk som redan är delvis täckt av worktrees, till priset av en ny infrastrukturkedja att underhålla, för ett scenario utan inträffad incident.

**Docker bara för DeepSeek-batchagenter.** Inte avfärdat, men prematurt — varken en incident eller en batchvolym som motiverar det finns än. Sparas som det första steget om eskaleringströskeln ovan nås.

**Lättare sandboxing än Docker** (t.ex. `firejail` eller `bubblewrap` runt agentens bash-process). Inte utforskat i det här beslutet. Kan vara en rimlig mellanväg om en permissions-denylist visar sig otillräcklig men en full container känns som överdrivet — värt att undersöka då, inte nu.
