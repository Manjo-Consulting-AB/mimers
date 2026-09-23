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

**Spärren stoppar allt som gallrar kontots innehåll:** papperskorgens gallring av containrar, items och bilagor, gallringen av lagrade filer som kontots bilagor pekar på, kontoraderingen och gallringen av vilande konton. Issue 115 lägger till loggarnas gallring. **Spärren syns inte för användaren**: allt fungerar som vanligt i vyerna.

Kontrollen är en enda fråga, `LegalHold::covers($account)`, som varje gallrande jobb anropar. Ingen egen formulering per jobb.

**Läs:** [[ADR-0043 Tre loggar]] § Den rättsliga spärren och § Vad lagen kräver, som vi läser den, `app/Console/PurgesExpiredTrash.php`, `app/Console/PurgesExpiredStoredFiles.php`, `app/Console/DeletesDormantAccounts.php`, `app/Actions/Account/DeleteAccount.php`
**Klart när:** en spärr går att sätta och häva från kommandoraden med ärendenummer och anledning; en spärrad containers papperskorg gallras inte; en spärrad användares lagrade filer gallras inte; ett spärrat konto raderas inte, varken på begäran eller som vilande, och raderingen nekas med en felkod enligt `AGENTS.md` § Felformat; en hävd spärr släpper gallringen nästa natt; ingen vy och ingen API-resurs avslöjar spärren; [[Registerförteckning]] har en rad för `legal_hold`; hela testsviten är grön.
**Beror på:** -

### 113. Säkerhetsloggen
En ny tabell, `security_log`, med en egen väg in, `RecordSecurityEvent`: användaren, kontot, handlingen, IP-adressen, webbläsaren och `meta`. Samma `meta`-regel som händelseloggen, och aldrig ett lösenord, en kod eller en token, inte ens en hashad.

Loggade handlingar: lyckad och misslyckad inloggning, inlöst magic link, tvåfaktor på och av, använd återställningskod, bytt lösenord, skickad inbjudan, beställd och hämtad export, och **nedladdning av en fil ur en container användaren inte äger**. En misslyckad inloggning mot en e-postadress som inte finns loggas utan användare och utan adressen.

**Läs:** [[ADR-0043 Tre loggar]] § Säkerhetsloggen, [[ADR-0017 Missbruksvektorer]] § Mätningen, `app/Http/Controllers/Auth/AuthenticatedSessionController.php`, `app/Http/Controllers/Auth/MagicLinkLoginController.php`, `app/Http/Controllers/Auth/TotpController.php`, `app/Http/Controllers/FileDeliveryController.php`, `app/Console/ReportsAbuseSignals.php` (docblocken)
**Klart när:** varje uppräknad handling skriver exakt en rad; en misslyckad inloggning mot en okänd adress skriver en rad utan användare och utan adress; en nedladdning ur användarens egen container skriver ingen rad, en ur någon annans skriver en; ingen rad bär lösenord, kod eller token; den rättsliga spärrens kommandon skriver hit; [[Registerförteckning]] har en rad för `security_log`; hela testsviten är grön.
**Beror på:** 112

### 114. Mätningen
En ny tabell, `usage_metric`: datum, handling, plan och antal. Inget användar-id, inget konto-id, inget container-id och ingen IP. Ett nattligt jobb räknar gårdagens rader i `audit_log` och `security_log` och skriver in dem.

**Grupper under fem slås ihop med `other`.** En rad som säger att en enda användare på en viss plan gjorde något en viss dag kan peka ut en person.

**En natt som missats räknas i efterhand**, så länge raderna finns kvar. Jobbet är idempotent: att köra det två gånger för samma dag ger samma summor, inte dubbla.

**Läs:** [[ADR-0043 Tre loggar]] § Mätningen, [[ADR-0031 Köarbetaren körs av schemaläggaren]], `app/Console/ReportsAbuseSignals.php` (docblocken, om varför rapporten själv inte skriver)
**Klart när:** jobbet räknar en dags rader per handling och plan; en grupp under fem hamnar i `other`; två körningar för samma dag ger samma rader; en missad dag räknas vid nästa körning; tabellen har inga kolumner som pekar på en användare, ett konto eller en container; jobbet schemaläggs före loggarnas gallring; hela testsviten är grön.
**Beror på:** 109, 110, 111, 113

### 115. Gallringen av loggarna
Ett nattligt jobb med tre steg:

1. Händelseloggens rader för en container tas bort tolv månader efter containerns `container.purged`-rad. Rader utan container följer kontots `account.deleted` på samma sätt.
2. Säkerhetsloggens IP-adress och webbläsare nollställs efter 90 dagar.
3. Säkerhetsloggens rader tas bort efter tolv månader.

**Den rättsliga spärren går före allt.** Ett spärrat kontos rader rörs inte, inte heller IP-adresserna. Jobbet kör efter mätningen varje natt.

**Läs:** [[ADR-0043 Tre loggar]] § Beslut och § Konsekvenser, `app/Console/PrunesRegistrationIps.php` (förlagan för nittiodagarsfristen), [[Återläsning]] § Efter varje återläsning: tillämpa raderingarna igen
**Klart när:** en containers rader finns kvar elva månader efter `container.purged` och är borta efter tolv; en levande containers rader rörs aldrig; IP-adress och webbläsare är nollställda efter 90 dagar och raden finns kvar; säkerhetsloggens rader är borta efter tolv månader; ett spärrat kontos rader rörs inte i något av stegen; jobbet schemaläggs efter mätningen; [[Återläsning]] säger att loggarnas gallring tillämpas igen efter en återläsning; hela testsviten är grön.
**Beror på:** 107, 112, 114

### 116. Historikflikarna
Containern och itemet får var sin historikflik, byggd av `UiTabs`. Issue 101 lämnade medvetet containerns flik bort *"eftersom historiken inte ritas ännu"*, och den förutsättningen faller här.

Raderna läses genom `ListAuditEvents` från issue 108. Varje rad är en mening i `lang/en/ui.php`, med en nyckel per handling. En ändring visar vilka fält som ändrades. Namn slås upp när raden läses, genom samma omfång som resten av vyn. En rad vars container, item eller användare inte längre finns visas med en neutral ersättare, till exempel *a former user* och *a deleted item*, och aldrig med ett tomt fält. Datumet följer datumregeln från issue 104.

**Ingen paginering uppfinns här.** Fliken visar de hundra senaste raderna, samma gräns som API:t.

**Läs:** [[ADR-0043 Tre loggar]] § Händelseloggen och § Konsekvenser, [[ADR-0042 Designsystemet]] § Beslut, `docs/Design/container.jpeg`, `docs/Design/struktur - item.jpeg`, [[M17 Designsystemet]] § 101, § 102 och § 104
**Klart när:** containern och itemet har var sin historikflik; en gäst ser bara sina egna rader och ägaren allas, bevisat med ett test per roll; varje handling har en sträng i `lang/en/ui.php`; en rad om ett gallrat item eller en raderad användare renderas med en ersättare; datumen följer datumregeln; ingen komponent bär en strängliteral; hela testsviten är grön.
**Beror på:** 108, 109, 110, 111

### 117. Inloggningshistoriken
Kontoinställningarnas säkerhetssida får en lista över användarens senaste inloggningar: tid, ungefärlig enhet ur webbläsarsträngen, och om inloggningen lyckades. **Bara användarens egna rader**, och bara inloggningar. Resten av säkerhetsloggen är vår.

Visas IP-adressen syns den bara så länge den finns kvar, alltså i 90 dagar. Listan får inte påstå något om en rad vars IP redan nollställts.

**Läs:** [[ADR-0043 Tre loggar]] § Säkerhetsloggen, `app/Http/Controllers/Settings/SecurityController.php`, `resources/js/pages/Settings/Security.vue`
**Klart när:** säkerhetssidan visar användarens senaste inloggningar med tid, enhet och utfall; ingen annan användares rader syns; inga andra handlingar ur säkerhetsloggen syns; en rad med nollställd IP visas utan adress; strängarna ligger i `lang/en/ui.php`; hela testsviten är grön.
**Beror på:** 113
