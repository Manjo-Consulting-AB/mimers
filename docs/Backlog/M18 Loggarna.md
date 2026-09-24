# M18 · Loggarna

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

Tillagd 2026-09-23, efter retron för M12–M17. Besluten står i [[ADR-0043 Tre loggar]]. **Händelseloggen** visar användaren vem som gjort vad med det användaren äger. **Säkerhetsloggen** finns för missbruk och intrång. **Mätningen** är anonyma summor för utvecklingen. Över alla tre ligger en **rättslig spärr** som stoppar gallringen när något behöver utredas.

**Ordningen är bindande, och den är inte numrets ordning i allt.**

1. Issue 107 rättar ett gallringsfel som instrumenteringen annars gör universellt. Den mergas innan en enda ny rad skrivs.
2. Issue 112, spärren, mergas innan något gallras. Issue 115 gallrar, och en gallring utan spärr kan radera bevis.
3. Issue 114, mätningen, mergas före 115. Det som gallras innan det räknats är borta ur mätningen för alltid.

**Skrivningarna bor ofta på två ställen.** Items, scheman och lån skapas i både `app/Http/Controllers/` och `app/Http/Controllers/Api/`, utan en gemensam Action. Instrumenteras båda för sig glider de isär inom milstolpen. Issue 109–111 bryter därför ut en Action där det behövs och loggar där. Det gör dem `cross-module`, och den som skriver GitHub-issuen ska lista **båda** controllerna i `In scope`.

**Fem issues är `risk_class: elevated`:** 108 (behörighet), 112, 113 och 115 (radering och personuppgifter) och 117 (konto). Resten är `none`.

**Detta ingår inte:** dashboardsidan, rapporter och grafer över mätningen, personraderingen, och anmälningsvägen och motiveringen enligt DSA artikel 16 och 17. De två sista står i [[Att sortera efter mockuparna]] § Ännu inte issues.

---

### 107. Händelseloggen överlever det den handlar om
`audit_log.container_id`, `user_id` och `account_id` har `ON DELETE RESTRICT`. `PurgeContainer` tar hårt bort en container efter trettio dagar i papperskorgen men rensar inte loggen, och ska inte göra det. En container med en loggrad, i dag en som överlåtits eller fått en åtkomst indragen, fäller därför den nattliga gallringen varje natt.

Issuen släpper de tre främmande nycklarna och behåller kolumnerna som identifierare. Den lägger till `item_id`, nullbar och utan främmande nyckel, och indexen `(item_id, created_at)` och `(user_id, created_at)`. `RecordAuditEvent` tar emot ett item.

`PurgeContainer` skriver `container.purged` och `DeleteAccount` skriver `account.deleted`, var och en i sin egen transaktion. De två raderna är vad gallringen i issue 115 räknar tolv månader från.

**Migreringen måste gå på MariaDB.** Att släppa en främmande nyckel före dess index har en dialekt, och CI-jobbet *Migreringarna går på MariaDB* är den enda instans i kedjan som ser den.

**Läs:** [[ADR-0043 Tre loggar]] § Kontext och § Händelseloggen, [[Konton och åtkomst]] § audit_log, `app/Actions/Trash/PurgeContainer.php` (docblocken), `database/migrations/2026_09_07_020000_create_audit_log_table.php`
**Klart när:** ett test gallrar en container som har en `audit_log`-rad, och gallringen lyckas; raden finns kvar efteråt med sitt `container_id` orört; samma sak gäller ett item som gallras med `PurgeContent`; gallringen skriver `container.purged` och kontoraderingen `account.deleted`; `audit_log` har kolumnen `item_id` och de två nya indexen; `RecordAuditEvent` sätter `item_id` när ett item skickas in; migreringen går igenom i MariaDB-jobbet; [[Konton och åtkomst]] § audit_log beskriver tabellen som den är efter migreringen; hela testsviten är grön.
**Beror på:** -

### 108. Läsregeln
[[ADR-0043 Tre loggar]] ger tre regler för vem som får läsa en rad i händelseloggen. Regel 1 finns redan som `ContainerPolicy::viewAuditLog()`: medlem i ägarkontot. Regel 2 är ny och är ett **radfilter**, inte en grind. En användare ser sina egna rader i en container eller ett item användaren fortfarande når, och ingenting annat där.

Issuen bryter ut en Action, `ListAuditEvents`, med tre startpunkter: en container, ett item och en användare. Alla tre tillämpar samma regel i samma fråga. Omfånget prövas genom `ResolveItemScope`. Actionen får inte bygga en egen vandring.

`GET /api/containers/{container}/audit-log` ger i dag `403` till en gäst. Efter issuen får gästen sina egna rader. **Det är en ändring av ett befintligt API-svar** och ska stå i PR-kroppen.

**Läs:** [[ADR-0043 Tre loggar]] § Händelseloggen, [[ADR-0028 Åtkomst på itemnivå]] § Beslut, `app/Http/Controllers/Api/AuditLogController.php`, `app/Policies/ContainerPolicy.php` (`viewAuditLog`)
**Klart när:** ägarkontots medlem ser alla rader i containern och dess items; en gäst med `write` ser bara sina egna rader i containern; en mottagare av en itemgrant ser bara sina egna rader om items inom grantens omfång; en användare vars åtkomst återkallats ser inga rader i containern, inte heller sina egna; en rad utan container syns för medlemmarna i radens konto; frågekostnaden är konstant oberoende av antalet rader; API-svaret för en gäst är `200` med gästens egna rader; hela testsviten är grön.
**Beror på:** 107

### 109. Innehållshändelserna
Varje skrivning på ett item och det som hänger på det: itemet självt (skapat, ändrat, raderat, återställt), dess relationer och taggar, bilagorna och kostnadsraderna. Varje rad skrivs i handlingens transaktion, genom `RecordAuditEvent`, med `item_id` satt. Handlingarnas namn är konstanter på `AuditLog`.

**En ändring loggas med fältens namn, inte med deras innehåll.** För fält med en värdelista eller ett tal följer gamla och nya värdet med. Fritext följer aldrig med: inte namn, beskrivning, anteckning, filnamn eller leverantör. Ett test per handling läser `meta` och bevisar det.

**Items skapas i två controllers** (`ItemController` och `Api\ItemController`). Där en handling saknar Action bryts en ut innan den loggas.

**Läs:** [[ADR-0043 Tre loggar]] § Händelseloggen, [[ADR-0024 Tunna controllers och actions]], `app/Actions/Audit/RecordAuditEvent.php`, `app/Actions/Attachment/StoreAttachment.php`, `app/Actions/Item/LinkItems.php`, `app/Actions/Trash/RestoreContent.php`
**Klart när:** varje skrivning på item, relation, tagg, bilaga och kostnad skriver exakt en rad, både via webben och via API:t där båda vägarna finns; en ändring som inte ändrar något skriver ingen rad; en handling som rullas tillbaka skriver ingen rad; `meta` saknar all fritext; `item_id` är satt på varje rad; gallringen skriver ingen rad utöver `container.purged`; hela testsviten är grön.
**Beror på:** 107

### 110. Uppgifts- och utlåningshändelserna
Varje skrivning på scheman, förekomster och lån. Att bocka av en förekomst loggas i `CloseOccurrence`, som redan öppnar nästa förekomst i samma transaktion. Förekomsten som öppnas loggas inte: den är en följd, inte en handling.

Samma regel för ändringar som i issue 109. `recurrence_type`, intervallet och förfallodatumet följer med som gamla och nya värden. Titeln och anteckningen gör det inte.

**Scheman och lån skapas i två controllers vardera.** Samma regel som i issue 109.

**Läs:** [[ADR-0043 Tre loggar]] § Händelseloggen, [[Scheman och uppgifter]] § schedule, `app/Actions/Schedule/CloseOccurrence.php`, `app/Actions/Audit/RecordAuditEvent.php`
**Klart när:** varje skrivning på schema, förekomst och lån skriver exakt en rad via både webben och API:t; att bocka av en förekomst skriver en rad och ingenting för förekomsten som öppnas; `meta` saknar titel och anteckning; `item_id` är satt; hela testsviten är grön.
**Beror på:** 107

### 111. Container- och åtkomsthändelserna
Varje skrivning på containern själv (skapad, ändrad, raderad, återställd), på dess kategorier och taggar, och på åtkomsten: skickad inbjudan, accepterad inbjudan, ändrad nivå. `container.transferred` och `access.revoked` finns redan och ska inte röras. Mottagarens nivå och typ följer med i `meta`, men aldrig en e-postadress, enligt issue 40 § Beslut 10.

**Läs:** [[ADR-0043 Tre loggar]] § Händelseloggen, `app/Actions/Container/CreateContainer.php`, `app/Actions/Container/TrashContainer.php`, `app/Actions/Trash/RestoreTrashedContainer.php`, `app/Actions/Invitation/CreateInvitation.php`, `app/Actions/Invitation/AcceptInvitation.php`, `app/Actions/Access/UpdateContainerAccess.php`
**Klart när:** varje skrivning på container, kategori, tagg och åtkomst skriver exakt en rad; en accepterad inbjudan bär nivån och mottagarens typ men ingen e-postadress; en inbjudan till ett item sätter `item_id`; en inbjudan som avböjs eller löper ut av sig själv skriver ingenting; hela testsviten är grön.
**Beror på:** 107

### 112. Den rättsliga spärren
En ny tabell, `legal_hold`: kontot, ärendenumret, anledningen, när spärren sattes och `lifted_at`. En rad tas aldrig bort. Ett konto är spärrat när det har en rad utan `lifted_at`.

Spärren sätts och hävs med två artisan-kommandon och har ingen yta i webben. Varje åtgärd skriver en rad i säkerhetsloggen när den finns. Fram till dess skriver kommandot till applikationsloggen.

**Spärren stoppar de två jobb som raderar hårt:** papperskorgens gallring (`PurgesExpiredTrash`) och gallringen av vilande konton (`DeletesDormantAccounts`). Lagrade filer skyddas utan en egen kontroll: en bilaga som inte gallras håller kvar sin referens, och `PurgesExpiredStoredFiles` rör bara filer utan referenser. Ett test ska bevisa det. Issue 115 lägger till loggarnas gallring. **Spärren syns inte för användaren**: allt fungerar som vanligt i vyerna.

Kontrollen är en enda fråga, `LegalHold::covers($account)`, som varje gallrande jobb anropar. Ingen egen formulering per jobb. Kontrollen ligger i jobben och inte i `PurgeContainer` eller `DeleteAccount`: de är verktygen, jobben är grindarna.

**Läs:** [[ADR-0043 Tre loggar]] § Den rättsliga spärren och § Vad lagen kräver, som vi läser den, `app/Console/PurgesExpiredTrash.php`, `app/Console/PurgesExpiredStoredFiles.php`, `app/Console/DeletesDormantAccounts.php`, `app/Actions/Account/DeleteAccount.php`
**Klart när:** en spärr går att sätta och häva från kommandoraden med ärendenummer och anledning; ett spärrat kontos papperskorg gallras inte; den lagrade filen bakom en spärrad bilaga finns kvar efter båda gallringsjobben; ett spärrat vilande konto raderas inte; en hävd spärr släpper gallringen nästa natt; ingen vy och ingen API-resurs avslöjar spärren; [[Registerförteckning]] har en rad för `legal_hold`; hela testsviten är grön.
**Beror på:** -

### 113. Säkerhetsloggen
En ny tabell, `security_log`, med en egen väg in, `RecordSecurityEvent`: användaren, kontot, handlingen, `ip_group`, enhetsnamnet och `meta`. **Ingen rå IP-adress och ingen rå webbläsarsträng sparas.** `ip_group` är samma pseudonym som missbruksrapporten räknar fram, och formeln bryts ut ur `ReportsAbuseSignals` till en delad klass så att de två aldrig kan glida isär. Enhetsnamnet tolkas ur webbläsarsträngen när raden skrivs, med en enkel regel utan nytt beroende, och strängen kastas. Samma `meta`-regel som händelseloggen, och aldrig ett lösenord, en kod eller en token, inte ens en hashad.

Loggade handlingar: lyckad och misslyckad inloggning, inlöst magic link, tvåfaktor på och av, nya återställningskoder, skickad inbjudan, beställd och hämtad export, skapad och borttagen webhook, tömd lagring, och **nedladdning av en fil ur en container användaren inte äger**. En misslyckad inloggning mot en e-postadress som inte finns loggas utan användare och utan adressen. Byte av lösenord och e-post finns inte i produkten än; de loggas här när de byggs.

Den rättsliga spärrens kommandon från issue 112 byter från applikationsloggen till säkerhetsloggen.

**Läs:** [[ADR-0043 Tre loggar]] § Säkerhetsloggen, [[ADR-0017 Missbruksvektorer]] § Mätningen, `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, `app/Http/Controllers/Auth/MagicLinkLoginController.php`, `app/Http/Controllers/Auth/TotpController.php`, `app/Http/Controllers/FileDeliveryController.php`, `app/Console/ReportsAbuseSignals.php` (docblocken och `ipGroup()`)
**Klart när:** varje uppräknad handling skriver exakt en rad; ingen rad bär en rå IP-adress eller en rå webbläsarsträng; samma IP-adress ger samma `ip_group` i loggen som i missbruksrapporten; en misslyckad inloggning mot en okänd adress skriver en rad utan användare och utan adress; en nedladdning ur användarens egen container skriver ingen rad, en ur någon annans skriver en; ingen rad bär lösenord, kod eller token; den rättsliga spärrens kommandon skriver hit; [[Registerförteckning]] har en rad för `security_log`; hela testsviten är grön.
**Beror på:** 112

### 114. Mätningen
En ny tabell, `usage_metric`: datum, handling, plan och antal. Inget användar-id, inget konto-id, inget container-id och ingen IP. Ett nattligt jobb räknar gårdagens rader i `audit_log` och `security_log` och skriver in dem.

**Grupper under fem slås ihop med `other`.** En rad som säger att en enda användare på en viss plan gjorde något en viss dag kan peka ut en person.

**En natt som missats räknas i efterhand**, så länge raderna finns kvar. Jobbet är idempotent: att köra det två gånger för samma dag ger samma summor, inte dubbla.

**Läs:** [[ADR-0043 Tre loggar]] § Mätningen, [[ADR-0031 Köarbetaren körs av schemaläggaren]], `app/Console/ReportsAbuseSignals.php` (docblocken, om varför rapporten själv inte skriver)
**Klart när:** jobbet räknar en dags rader per handling och plan; en grupp under fem hamnar i `other`; två körningar för samma dag ger samma rader; en missad dag räknas vid nästa körning; tabellen har inga kolumner som pekar på en användare, ett konto eller en container; jobbet schemaläggs före loggarnas gallring; hela testsviten är grön.
**Beror på:** 109, 110, 111, 113

### 115. Gallringen av loggarna
Ett nattligt jobb med två steg:

1. Händelseloggens rader för en container tas bort tolv månader efter containerns `container.purged`-rad. Rader utan container följer kontots `account.deleted` på samma sätt.
2. Säkerhetsloggens rader tas bort efter tolv månader.

**Den rättsliga spärren går före allt.** Ett spärrat kontos rader rörs inte. Jobbet kör efter mätningen varje natt.

**Läs:** [[ADR-0043 Tre loggar]] § Beslut och § Konsekvenser, `app/Console/PrunesRegistrationIps.php` (förlagan för jobbet och fristen), [[Återläsning]] § Efter varje återläsning: tillämpa raderingarna igen
**Klart när:** en containers rader finns kvar elva månader efter `container.purged` och är borta efter tolv; en levande containers rader rörs aldrig; säkerhetsloggens rader är borta efter tolv månader; ett spärrat kontos rader rörs inte i något av stegen; jobbet schemaläggs efter mätningen; [[Återläsning]] säger att loggarnas gallring tillämpas igen efter en återläsning; hela testsviten är grön.
**Beror på:** 107, 112, 114

### 116. Historikflikarna
Containern och itemet får var sin historikflik, byggd av `UiTabs`. Issue 101 lämnade medvetet containerns flik bort *"eftersom historiken inte ritas ännu"*, och den förutsättningen faller här.

Raderna läses genom `ListAuditEvents` från issue 108. Varje rad är en mening i `lang/en/ui.php`, med en nyckel per handling. En ändring visar vilka fält som ändrades. Namn slås upp när raden läses, genom samma omfång som resten av vyn. En rad vars container, item eller användare inte längre finns visas med en neutral ersättare, till exempel *a former user* och *a deleted item*, och aldrig med ett tomt fält. Datumet följer datumregeln från issue 104.

**Ingen paginering uppfinns här.** Fliken visar de hundra senaste raderna, samma gräns som API:t.

**Läs:** [[ADR-0043 Tre loggar]] § Händelseloggen och § Konsekvenser, [[ADR-0042 Designsystemet]] § Beslut, `docs/Design/container.jpeg`, `docs/Design/struktur - item.jpeg`, [[M17 Designsystemet]] § 101, § 102 och § 104
**Klart när:** containern och itemet har var sin historikflik; en gäst ser bara sina egna rader och ägaren allas, bevisat med ett test per roll; varje handling har en sträng i `lang/en/ui.php`; en rad om ett gallrat item eller en raderad användare renderas med en ersättare; datumen följer datumregeln; ingen komponent bär en strängliteral; hela testsviten är grön.
**Beror på:** 108, 109, 110, 111

### 117. Inloggningshistoriken
Kontoinställningarnas säkerhetssida får en lista över användarens tjugo senaste inloggningar: tid, ungefärlig enhet och om inloggningen lyckades. **Bara användarens egna rader**, och bara inloggningar. Resten av säkerhetsloggen är vår. IP-adressen visas inte.

Enheten är det enhetsnamn säkerhetsloggen redan sparat (issue 113). En rad utan enhetsnamn visas som *okänd enhet*.

**Läs:** [[ADR-0043 Tre loggar]] § Säkerhetsloggen, `app/Http/Controllers/Settings/SecurityController.php`, `resources/js/pages/Settings/Security.vue`
**Klart när:** säkerhetssidan visar användarens tjugo senaste inloggningar med tid, enhet och utfall; ingen annan användares rader syns; inga andra handlingar ur säkerhetsloggen syns; ingen IP-adress visas; en rad utan enhetsnamn visas som okänd enhet; strängarna ligger i `lang/en/ui.php`; hela testsviten är grön.
**Beror på:** 113

---

## Tillagda vid retron

Tillagda 2026-09-24, efter retron för M18. De fyra stänger det milstolpens egna PR:er pekade ut men inte fick röra, och de mergas **före** releasen av M18: tre av dem rättar text som annars beskriver en logg som inte finns, och den fjärde tar bort den flake som två milstolpar i rad mergade röd på. Underlaget står i [[Lärdomar]] under `Bekräftat` och `Infört`.

### 118. Datamodellen känner alla tabeller
Åtta av appens tabeller nämns ingenstans under `docs/Datamodell/`: `calendar_feed`, `favorite`, `heartbeat`, `legal_hold`, `magic_link_token`, `security_log`, `totp_recovery_code` och `usage_metric`. Varje tabell får ett avsnitt i rätt fil, i samma form som grannarna: rubriken är tabellnamnet, och en kolumntabell följer. Innehållet läses ur migreringen och modellens docblock. Motiveringar skrivs inte om, de pekar på sin ADR.

Därefter ett prov som gör luckan omöjlig att öppna igen. Varje tabell som en migrering skapar med `Schema::create()` ska nämnas i en fil under `docs/Datamodell/`, utom ramverkets egna tabeller och de som en senare migrering tar bort. De står i en uttalad lista i provet.

**Läs:** [[Datamodell – översikt]] § Var bor vad, `tests/Feature/Dokumentation/ProduktbeskrivningenTest.php` (förlagan för att pröva dokumentation som text), migreringarna för de åtta tabellerna
**Klart när:** de åtta tabellerna har var sitt avsnitt; ett prov fäller en tabell som saknas i datamodellen; provet bär en uttalad lista över ramverkets tabeller; hela testsviten är grön.
**Beror på:** 112, 113, 114

### 119. Identitetsbytet i testerna
`actingAs()` sätter webbguarden, och Sanctums guard cachar den autentiserade användaren. Ett andra anrop i samma test, som en annan användare, svarar därför tyst som den första. Fyra issues har felsökt det var för sig (337, 345, 451, 452), och lösningen, `auth()->forgetGuards()`, finns bara i testkommentarer. Issuen lägger en hjälpare i `tests/Support/Testhjalpare.php` som byter användare och glömmer guardens cache. De filer som i dag gör det för hand byter till hjälparen.

**Läs:** `tests/Support/Testhjalpare.php`, `tests/Feature/Revision/ContainerhandelserTest.php` (kommentaren vid `forgetGuards()`)
**Klart när:** hjälparen finns; ett prov visar att två användare i samma test får var sitt svar; de befintliga `forgetGuards()`-anropen går genom hjälparen; hela testsviten är grön.
**Beror på:** -

### 120. Frågeräkningen fryser tiden
`UpdateLastActiveAt` skriver `last_active_at` bara när sekundvärdet har ändrats. Därför får en frågeräkning över två HTTP-anrop en `UPDATE` extra varje gång en sekundgräns faller mellan dem. Issue 80 fixade det i ett tjugotal filer, PR #465 i `LeverantorTest` och PR #472 i `LasregelTest`. Arton filer som använder `DB::listen` fryser fortfarande inte tiden. Issuen fryser tiden i dem, och ett prov fäller varje testfil som använder `DB::listen` utan att frysa tiden.

**Läs:** `tests/Feature/Kostnad/LeverantorTest.php` och `tests/Feature/Kostnad/FastSummeringTest.php` (förlagorna), `app/Http/Middleware/UpdateLastActiveAt.php`
**Klart när:** de arton filerna fryser tiden runt sin mätning; ett prov fäller en testfil med `DB::listen` utan `Carbon::setTestNow`; hela testsviten är grön.
**Beror på:** -

### 121. Texterna som M18 gjorde fel
Fem ställen säger något som M18 gjort osant. `AuditLog`s klassdocblock och den ursprungliga migreringens docblock säger att loggen aldrig gallras, men det gör den sedan issue 115. `routes/api.php` säger *"Bara GET och bara regel 1"* om en rutt som sedan issue 108 läser alla tre reglerna. [[Konton och åtkomst]] § audit_log säger *"Aktörens konto"* om `account_id`, som sedan issue 109 är containerns ägarkonto. [[Att sortera efter mockuparna]] säger att det saknas ett index för itemhistoriken, men det lade issue 107 till. Bara kommentarer och dokumentation ändras, ingen kod.

**Läs:** [[ADR-0043 Tre loggar]] § Händelseloggen, `app/Console/PrunesLogs.php` (docblocken), `app/Actions/Audit/ListAuditEvents.php` (docblocken)
**Klart när:** de fem ställena beskriver koden som den är; ingen rad kod utanför kommentarer är ändrad; hela testsviten är grön.
**Beror på:** -
