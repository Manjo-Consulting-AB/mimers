# ADR-0018 Utvecklingsprocess och deploy

**Status:** Antagen 2026-08-04 · Utökad 2026-09-03 med § Befordranstakt · [[ADR-index]]

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
| Gren `feature/issue-NN` | implementatören | arbetet sker isolerat; NN är GitHub-numret, inte backlognumret |
| PR mot `main` | implementatören | CI kör lint, analys och tester |
| Merge | **Tony** | koden blir del av `main` |
| Deploy till staging | automatiskt | varje commit i `main` |
| Release `vX.Y.Z` | **Tony** | vid stängd milstolpe, på en commit som redan testats på staging |
| Deploy till produktion | automatiskt efter godkännande | GitHub Environment med Tony som required reviewer |

**Ingen dev-gren.** `main` är den senast överenskomna versionen, inte produktion. Produktionsgränsen dras med en tagg.

**En release per stängd milstolpe.** När milstolpens sista issue är mergad och staging är grön på den commiten publiceras en release — i samma svep som retron körs, aldrig som en separat sak att komma ihåg. Milstolpen är befordringsenheten: `M0`–`M3` blev `v0.1.0`, `M4` blir `v0.2.0`, och `1.0.0` är MVP i drift. Se § Befordranstakt.

**Bygg en gång.** Bygget sker vid merge till `main` och paketeras till en artefakt. Staging och produktion rullar ut **samma artefakt** — produktionsdeployen bygger aldrig om.

**Push, inte pull.** GitHub Actions skickar paketet över SSH och kör utrullningen. Servern hämtar ingenting och behöver varken git, composer eller node.

**Release-kataloger med symlänkbyte.** Varje deploy packas upp i en ny katalog; `current` flippas när allt är klart. Rollback är att flippa tillbaka.

**Dokumentationen flyttar in i repot** under `docs/`, och Obsidian-valvet pekar dit.

Den tekniska uppsättningen — workflow-filer, deploy-skript, kataloglayout — bor i [[Pipeline]].

## Motivering

**`main` är inte produktion, och det är hela poängen.** Med en tagg som produktionsgräns kan `main` ligga före produktion utan att något är fel. Tony bestämmer när ett sammanhängande knippe funktionalitet är värt att skeppa, oberoende av när enskilda PR:er råkade bli klara.

**Men "ett sammanhängande knippe" behövde ett namn.** Formuleringen ovan stod ensam i fyra veckor och gav ingen signal om *när* knippet var färdigt. Utfallet står i § Befordranstakt: fyra milstolpar och 166 commits hann samlas innan någon tittade. Friheten var rätt, avsaknaden av enhet var det inte — milstolpen är det knippe ADR:en menade från början, den råkade bara aldrig bli utskriven.

**En dev-gren hade skapat en commit som aldrig testats.** Merge-commiten från `dev` till `main` är per definition ny kod — den sammanslagningen har inte funnits någonstans tidigare, allra minst på testmiljön. Man testar alltså A och skeppar B. Med en tagg pekas exakt den commit ut som redan verifierats. Att den artefakt som testas är den som levereras är samma princip som motiverar bygg-en-gång, och den bär hela processen.

**Med en granskare finns inget release-tåg att samordna.** Dev-grenen löser ett problem som uppstår när flera team ska släppa i takt. Här skulle den bara lägga ett samlingssteg mellan PR och staging, och göra befordran till en stor sammanslagning som inte går att granska eftersom delarna redan granskats var för sig.

**Grindarna väger tyngre än grenstrategin.** När implementationen är delegerad är det branch protection, obligatorisk CI och kravet att acceptanskriterier motsvaras av tester som gör arbetet, inte hur grenarna är arrangerade. Kriterierna i [[Backlog]] är formulerade som testbara påståenden just för att kunna fylla den rollen.

**Servern är fel plats att bygga på.** Delad hosting har begränsat minne, oförutsägbara versioner och ingen bra felrapportering. Bygger Actions istället behöver servern inga credentials mot GitHub, och misslyckade bygg syns på samma ställe som allt annat.

**Utan dokumentationen i repot faller läslistemodellen.** En implementatör som får issue 9 måste kunna läsa [[Konton och åtkomst]]. Ligger valvet bara på Tonys disk får modellen gissa, och hela uppdelningen i [[00 Index]] blir verkningslös.

## Konsekvenser

- **Ingen pushar direkt till `main`**, inte heller Tony. Grön CI och en godkänd review före merge. Spärren som skulle framtvinga det går inte att slå på på nuvarande kontoplan, och är medvetet uppskjuten; se avsnittet nedan.
- **PHPStan sätts på hög nivå från första commiten.** Att höja nivån i efterhand över 47 issues blir en egen milstolpe.
- **En PR mergas inte om "Klart när" saknar motsvarande test.** Det är den enda mekanism som skalar när granskaren inte hinner läsa allt.
- **`AGENTS.md` i repo-roten** upprepar konventionerna från [[Datamodell – översikt]] och felformatet från issue 7, plus regeln: hittar du inte svaret i din läslista — gissa inte, fråga.
- **Nya composer-paket kräver Tonys godkännande.** Ett beroende är ett arkitekturbeslut och hör hemma i en ADR, inte i en implementationsissue.
- **PR-mallen tvingar implementatören att lista vilka dokument den läst.** Billigaste sättet att upptäcka att någon läst för mycket eller för lite.
- **Migrationer rullas aldrig tillbaka i produktion.** Expand/contract: additiva steg i en release, destruktiva i en senare, när ingen kod längre använder kolumnen. Fel åtgärdas framåt.
- **Kort underhållsfönster vid deploy.** Några sekunders 503. Nolltid kräver att PHP:s opcache och realpath-cache töms efter symlänkbytet, vilket inte är tillförlitligt på delad hosting. Får vänta till VPS, precis som Meilisearch i [[ADR-0012 Sök]].
- **`.env` och `storage/` bor utanför release-katalogerna** och symlänkas in. Produktionens hemligheter finns bara på servern och syns aldrig för Actions eller för implementatörerna.
- **Staging behöver egen databas och egen minutcron.** Utan cron kan varken kön eller outboxen i [[ADR-0010 Notisarkitektur]] testas.
- **Schemalagda uppgifter uttrycks som `->call()` eller `->job()`, aldrig `->command()`.** `proc_open` är avstängt hos inleed; se avsnittet nedan.
- **En stängd milstolpe är inte stängd förrän den är i produktion.** Retron och releasen hör ihop; körs den ena utan den andra är milstolpen halvstängd. Se § Befordranstakt.
- **Issue 0 byggs före all funktionalitet.** En tom Laravel ska gå hela vägen till både staging och produktion innan första raden domänkod skrivs. Går något sönder senare ska felet aldrig kunna vara röret.

**Verifierat hos inleed 2026-08-23.** Frågorna nedan låg öppna tills någon loggade in och tittade. Det gjordes inför issue 0, och svaren står här eftersom de bär flera av besluten ovan.

| Fråga | Svar |
|---|---|
| Kan document root peka på `current/public`? | **Ja.** `public_html` byttes mot en symlänk till `/home/s174280/mimers/current/public` och LiteSpeed följde den hela vägen genom `current` till releasen. Det är alltså symlänkbytet i [[Pipeline]] som utgör utrullningen, precis som beslutet förutsätter. |
| Två siter med varsin databas inom kontot? | **Siter ja** — tretton domäner ligger redan uppe, och subdomäner läggs upp som egna kataloger direkt under `~/domains/`. **Databastaket är okänt**: kontot är cagefs-jailat, så DirectAdmins konfiguration går inte att läsa över SSH. Står i panelen. |
| Cron per site? | **Nej, per konto.** En enda crontab. Det räcker — miljöerna får varsin rad med olika sökväg. |
| Hur många siter ryms? | Minst tretton, eftersom så många redan är uppe. `mimers.app` är en av dem. Exakt tak står i panelen. |
| Certifikat för alla tre värdnamnen? | **Utfärdas automatiskt.** `mimers.app` svarade över HTTPS direkt. `staging.mimers.app` och `files.mimers.app` finns ännu inte i DNS och måste läggas upp innan de kan svara alls — `.app` är HSTS-preloadad, så det finns ingen HTTP-fallback att felsöka mot. |
| S3 hos inleed? | Besvarat redan 2026-08-04, se [[ADR-0007 Fillagring hos inleed]]. Ren disk. Bekräftat på plats: inga spår av objektlagring. |

Uppsättningen på servern är gjord: `~/mimers` och `~/mimers-staging` med `incoming/`, `releases/` och `shared/storage/`, samt en minutcron per miljö. Kvar står `shared/.env` för varje miljö, som skapas för hand och bara på servern.

### proc_open är avstängt, och det begränsar schemaläggaren

Det tyngsta fyndet stod inte på frågelistan. `disable_functions` hos inleed täcker `exec`, `system`, `passthru`, `shell_exec`, `proc_open`, `proc_close` och `popen` — i både webb-SAPI och CLI, på samtliga PHP-versioner.

Laravels scheduler kör `->command(...)`-uppgifter genom Symfony Process, som bygger på `proc_open`. **Sådana uppgifter kan inte köras här alls.** `->call(...)` och `->job(...)` blir däremot `CallbackEvent` och körs i schemaläggarens egen process, vilket fungerar. Samma skiljelinje gäller köerna: `queue:work` är en process som hämtar jobb och fungerar, medan `queue:listen` startar subprocesser och gör det inte.

Det är en verklig inskränkning på outboxen i [[ADR-0010 Notisarkitektur]], inte en formalitet, och den kan inte kringgås på delad hosting. Utrullningen är däremot opåverkad: `config:cache`, `route:cache`, `view:cache`, `migrate`, `down` och `up` rör aldrig proc_open, så `deploy.sh` i [[Pipeline]] fungerar som skrivet.

### Spärrarna är uppskjutna, med öppna ögon

Beslutet ovan vilar på tre GitHub-mekanismer: branch protection på `main`, required status check, och required reviewer på `production`. Vid uppsättningen 2026-08-23 visade det sig att **ingen av dem går att aktivera** på ett privat repo i en org på Free-planen. Branch protection och rulesets svarar 403, environment-reviewern 422, alla tre med `Upgrade to GitHub Pro or make this repository public`.

Environments och deras secrets fungerar, så deploykedjan i [[Pipeline]] är opåverkad. Det som saknas är tvånget: `main` går att pusha till, och produktionsdeployen kör utan att fråga.

**Tony beslutade 2026-08-23 att skjuta upp frågan.** Skälet är att projektet har två deltagare, att endast agenten pushar, och att en uppgradering till GitHub Team därmed köper en spärr mot ett beteende som ingen ändå utför. Det är ett rimligt vägval så länge premisserna håller.

Beslutet i den här ADR:en är alltså **oförändrat i sak** — gren per issue, PR mot `main`, tagg till produktion. Det som ändras är vem som håller det: processen vilar på disciplin i stället för på GitHub. Konkret betyder det att agenten aldrig pushar till `main` direkt, inte heller för en trivial ändring, och aldrig mergar en PR med röd CI.

**Tas upp igen när någon av premisserna brister:** en tredje person får skrivrättigheter, någon annan än agenten börjar pusha, eller repot blir publikt (då är spärrarna gratis). Alternativen står i [[Pipeline]] § Kontoplanen tar bort tre av spärrarna.

### Befordranstakt: en release per stängd milstolpe

Beslutet ovan sade vem som taggar, men inte när. Under M0–M3 blev följden att `main` sprang ifrån produktionen helt: den 2026-09-03 låg produktionen kvar på `v0.0.1` från 2026-08-23 — issue 0:s tomma Laravel — medan `main` var **166 commits** och fyra milstolpar före. Kedjan var hela tiden grön; ingen hade fel. Det saknades bara ett tillfälle där någon var tvungen att fråga sig om produktionen borde flyttas fram.

Det gjorde tre saker samtidigt, och alla tre blir värre ju längre gapet får växa:

- **Releasen slutade vara granskningsbar.** En release notes över fyra milstolpar är inte en text någon läser före publiceringen; den skrivs efteråt, som en sammanfattning. Poängen med att befordran ska vara ett medvetet beslut — kravet högst upp i § Kontext — går förlorad när mängden gör beslutet omöjligt att fatta med öppna ögon.
- **Rollback blev teoretisk.** `current` går att flippa tillbaka på en sekund, men mellan `v0.0.1` och `v0.1.0` ligger 27 migrationer, och migrationer rullas aldrig tillbaka. Ju fler releaser gapet innehåller, desto mindre betyder det att rollbacken finns.
- **Produktionen slutade vara ett bevis.** Miljöskillnader — `shared/.env` som glidit isär, en `FILES_INTERNAL_REDIRECT` som aldrig sattes — upptäcks först vid utrullning. Rullar man ut varannan månad hittas de i ett svep, mitt i den release som redan är för stor för att felsöka styckvis.

**Regeln:** milstolpen är befordringsenheten. Är milstolpens sista issue mergad och staging grön på den commiten, publiceras releasen — i samma svep som `/retro` körs för milstolpen, inte som ett separat steg någon ska minnas. Numret följer milstolpen: `M0`–`M3` blev `v0.1.0`, nästa stängda milstolpe blir `v0.2.0`, och `1.0.0` reserveras för MVP i drift.

En rättning som inte kan vänta på nästa milstolpe skeppas som en patch — `v0.1.1` — på precis samma väg. Kravet är oförändrat: taggen ska peka på en commit som redan varit grön på staging, och `production.yml` avbryter deployen om den inte har det.

Ritualen — kommandona och vad som ska stämma före publiceringen — står i [[Pipeline]] § Releaseritualen. Den hör hemma där, inte här: det här avsnittet säger *när*, Pipeline säger *hur*.

## Alternativ

**Dev-gren som befordras till `main`.** Tonys ursprungliga förslag och den vanligaste modellen i äldre material. Valdes bort — den skapar en otestad merge-commit vid varje befordran och lägger ett samlingssteg som inget team drar nytta av när granskaren är en enda person. Skyddet den ger finns redan i branch protection.

**Direktpush till `main` utan PR.** Snabbare, men tar bort det enda stället där CI och granskning hinner mellan implementatören och koden. Otänkbart när koden skrivs av modeller.

**Pull: cron på servern som hämtar nya taggar.** Nödvändig fallback om inkommande SSH hade saknats. Valdes bort — kräver credentials på produktionsservern, sprider loggarna över två system och gör att en misslyckad deploy inte syns i GitHub.

**FTP med `lftp mirror`.** Sista utvägen. Valdes bort — ingen atomisk växling, migrationerna måste köras separat, och katalogen är trasig medan överföringen pågår.

**Docker eller VPS med riktig CD-kedja.** Bättre på alla tekniska mått. Valdes bort av samma skäl som i [[ADR-0001 Stack]]: driftprofilen ska vara så tråkig som möjligt tills produkten har kunder som motiverar något annat.
