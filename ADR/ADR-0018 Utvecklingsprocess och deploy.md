# ADR-0018 Utvecklingsprocess och deploy

**Status:** Antagen 2026-08-04 · [[ADR-index]]

## Kontext

De 47 issuerna i [[Backlog]] implementeras av Sonnet och Haiku. Tony granskar och äger besluten, men skriver ingen kod. Se [[ADR-0001 Stack]].

Det ställer tre krav som en vanlig utvecklingsprocess inte behöver ta lika allvarligt:

- **Trasig kod ska inte kunna nå produktion.** Granskaren är en person med begränsad tid och kodmängden produceras snabbare än den kan läsas rad för rad.
- **Separata implementationer ska inte driva isär.** Ramverkets konventioner gör en del av jobbet, men bara en del.
- **Befordran till produktion ska vara ett medvetet beslut**, inte en följd av att någon mergade något.

Driftmiljön är delad hosting hos inleed: ingen root, ingen container, inget Docker. Cron kan köras varje minut. **Inkommande SSH med nyckel är bekräftat tillgängligt 2026-08-04.**

## Beslut

**Gren per issue, PR till `main`, tagg till produktion.**

| Steg | Vem | Vad |
|---|---|---|
| Gren `issue-NN-kort-namn` | implementatören | arbetet sker isolerat |
| PR mot `main` | implementatören | CI kör lint, analys och tester |
| Merge | **Tony** | koden blir del av `main` |
| Deploy till staging | automatiskt | varje commit i `main` |
| Release `vX.Y.Z` | **Tony** | på en commit som redan testats på staging |
| Deploy till produktion | automatiskt efter godkännande | GitHub Environment med Tony som required reviewer |

**Ingen dev-gren.** `main` är den senast överenskomna versionen, inte produktion. Produktionsgränsen dras med en tagg.

**Bygg en gång.** Bygget sker vid merge till `main` och paketeras till en artefakt. Staging och produktion rullar ut **samma artefakt** — produktionsdeployen bygger aldrig om.

**Push, inte pull.** GitHub Actions skickar paketet över SSH och kör utrullningen. Servern hämtar ingenting och behöver varken git, composer eller node.

**Release-kataloger med symlänkbyte.** Varje deploy packas upp i en ny katalog; `current` flippas när allt är klart. Rollback är att flippa tillbaka.

**Dokumentationen flyttar in i repot** under `docs/`, och Obsidian-valvet pekar dit.

Den tekniska uppsättningen — workflow-filer, deploy-skript, kataloglayout — bor i [[Pipeline]].

## Motivering

**`main` är inte produktion, och det är hela poängen.** Med en tagg som produktionsgräns kan `main` ligga fem issues före produktion i veckor utan att något är fel. Tony bestämmer när ett sammanhängande knippe funktionalitet är värt att skeppa, oberoende av när enskilda PR:er råkade bli klara.

**En dev-gren hade skapat en commit som aldrig testats.** Merge-commiten från `dev` till `main` är per definition ny kod — den sammanslagningen har inte funnits någonstans tidigare, allra minst på testmiljön. Man testar alltså A och skeppar B. Med en tagg pekas exakt den commit ut som redan verifierats. Att den artefakt som testas är den som levereras är samma princip som motiverar bygg-en-gång, och den bär hela processen.

**Med en granskare finns inget release-tåg att samordna.** Dev-grenen löser ett problem som uppstår när flera team ska släppa i takt. Här skulle den bara lägga ett samlingssteg mellan PR och staging, och göra befordran till en stor sammanslagning som inte går att granska eftersom delarna redan granskats var för sig.

**Grindarna väger tyngre än grenstrategin.** När implementationen är delegerad är det branch protection, obligatorisk CI och kravet att acceptanskriterier motsvaras av tester som gör arbetet, inte hur grenarna är arrangerade. Kriterierna i [[Backlog]] är formulerade som testbara påståenden just för att kunna fylla den rollen.

**Servern är fel plats att bygga på.** Delad hosting har begränsat minne, oförutsägbara versioner och ingen bra felrapportering. Bygger Actions istället behöver servern inga credentials mot GitHub, och misslyckade bygg syns på samma ställe som allt annat.

**Utan dokumentationen i repot faller läslistemodellen.** En implementatör som får issue 9 måste kunna läsa [[Konton och åtkomst]]. Ligger valvet bara på Tonys disk får modellen gissa, och hela uppdelningen i [[00 Index]] blir verkningslös.

## Konsekvenser

- **Ingen kan pusha direkt till `main`**, inte heller Tony. Branch protection kräver grön CI och en godkänd review.
- **PHPStan sätts på hög nivå från första commiten.** Att höja nivån i efterhand över 47 issues blir en egen milstolpe.
- **En PR mergas inte om "Klart när" saknar motsvarande test.** Det är den enda mekanism som skalar när granskaren inte hinner läsa allt.
- **`AGENTS.md` i repo-roten** upprepar konventionerna från [[Datamodell – översikt]] och felformatet från issue 7, plus regeln: hittar du inte svaret i din läslista — gissa inte, fråga.
- **Nya composer-paket kräver Tonys godkännande.** Ett beroende är ett arkitekturbeslut och hör hemma i en ADR, inte i en implementationsissue.
- **PR-mallen tvingar implementatören att lista vilka dokument den läst.** Billigaste sättet att upptäcka att någon läst för mycket eller för lite.
- **Migrationer rullas aldrig tillbaka i produktion.** Expand/contract: additiva steg i en release, destruktiva i en senare, när ingen kod längre använder kolumnen. Fel åtgärdas framåt.
- **Kort underhållsfönster vid deploy.** Några sekunders 503. Nolltid kräver att PHP:s opcache och realpath-cache töms efter symlänkbytet, vilket inte är tillförlitligt på delad hosting. Får vänta till VPS, precis som Meilisearch i [[ADR-0012 Sök]].
- **`.env` och `storage/` bor utanför release-katalogerna** och symlänkas in. Produktionens hemligheter finns bara på servern och syns aldrig för Actions eller för implementatörerna.
- **Staging behöver egen databas och egen minutcron.** Utan cron kan varken kön eller outboxen i [[ADR-0010 Notisarkitektur]] testas.
- **Issue 0 byggs före all funktionalitet.** En tom Laravel ska gå hela vägen till både staging och produktion innan första raden domänkod skrivs. Går något sönder senare ska felet aldrig kunna vara röret.

**Kvar att verifiera hos inleed**, utöver S3-frågan i [[ADR-0007 Fillagring hos inleed]]:

- kan domänens document root peka på `current/public`, eller måste den ligga i `public_html`?
- går det att köra två separata siter med varsin databas inom kontot, för staging och produktion?
- kan cron köras per site?
- hur många siter ryms inom kontot? Tre behövs: staging, produktion och filoriginet. Frontenden delar origin med API:et enligt [[ADR-0020 Plattformsidentitet och frontendgräns]] och kräver därför ingen egen site.

## Alternativ

**Dev-gren som befordras till `main`.** Tonys ursprungliga förslag och den vanligaste modellen i äldre material. Valdes bort — den skapar en otestad merge-commit vid varje befordran och lägger ett samlingssteg som inget team drar nytta av när granskaren är en enda person. Skyddet den ger finns redan i branch protection.

**Direktpush till `main` utan PR.** Snabbare, men tar bort det enda stället där CI och granskning hinner mellan implementatören och koden. Otänkbart när koden skrivs av modeller.

**Pull: cron på servern som hämtar nya taggar.** Nödvändig fallback om inkommande SSH hade saknats. Valdes bort — kräver credentials på produktionsservern, sprider loggarna över två system och gör att en misslyckad deploy inte syns i GitHub.

**FTP med `lftp mirror`.** Sista utvägen. Valdes bort — ingen atomisk växling, migrationerna måste köras separat, och katalogen är trasig medan överföringen pågår.

**Docker eller VPS med riktig CD-kedja.** Bättre på alla tekniska mått. Valdes bort av samma skäl som i [[ADR-0001 Stack]]: driftprofilen ska vara så tråkig som möjligt tills produkten har kunder som motiverar något annat.
