# M18 · Händelseloggen

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

Tillagd 2026-09-23, efter retron för M12–M17. Besluten står i [[ADR-0043 Händelseloggen]]: loggen sparas för evigt, den överlever det den handlar om, och användaren ser sina egna rader plus allt i det användaren äger.

Milstolpen gör de tre historikytorna i designerns bilder möjliga, men den bygger bara två av dem. **Dashboardens händelsepanel ingår inte.** Den hör ihop med att `/dashboard` i dag är todo-vyn och blir en egen milstolpe när den här är stängd ([[Att sortera efter mockuparna]] § Dashboarden).

Ordningen är bindande: **schemat, sedan läsregeln, sedan skrivningarna, sedan ytorna.** Issue 107 rättar ett gallringsfel som instrumenteringen annars gör universellt. Den ska mergas innan en enda ny rad skrivs.

**Skrivningarna bor ofta på två ställen.** Items, scheman och lån skapas i både `app/Http/Controllers/` och `app/Http/Controllers/Api/`, utan en gemensam Action. Instrumenteras båda för sig glider de isär inom milstolpen. Issue 109–111 bryter därför ut en Action där det behövs, enligt [[ADR-0024 Tunna controllers och actions]], och loggar där. Det gör dem `cross-module`, och den som skriver GitHub-issuen ska lista **båda** controllerna i `In scope`.

**Detta ingår inte:** dashboardsidan, trender och rapporter över loggen, loggning av fältändringar på ett item, och personraderingen. Den sista rör inte loggen när den byggs, se [[ADR-0043 Händelseloggen]] § Konsekvenser.

---

### 107. Loggen överlever det den handlar om
`audit_log.container_id`, `user_id` och `account_id` har `ON DELETE RESTRICT`. `PurgeContainer` tar hårt bort en container efter trettio dagar i papperskorgen, men rensar inte loggen, och ska inte göra det. En container med en loggrad, i dag en som överlåtits eller fått en åtkomst indragen, fäller därför den nattliga gallringen varje natt.

Issuen släpper de tre främmande nycklarna och behåller kolumnerna som identifierare, samma sak som `subject_id` redan är. Den lägger till `item_id`, nullbar och utan främmande nyckel, och indexen `(item_id, created_at)` och `(user_id, created_at)`. `RecordAuditEvent` tar emot ett item.

**Migreringen måste gå på MariaDB.** Att släppa en främmande nyckel före dess index har en dialekt, och CI-jobbet *Migreringarna går på MariaDB* är den enda instans i kedjan som ser den ([[Lärdomar]], posten om att testsvitens databas skiljer sig från produktionens).

**Läs:** [[ADR-0043 Händelseloggen]] § Beslut och § Motivering, [[Konton och åtkomst]] § audit_log, `app/Actions/Trash/PurgeContainer.php` (docblocken), `database/migrations/2026_09_07_020000_create_audit_log_table.php`
**Klart när:** ett test gallrar en container som har en `audit_log`-rad och gallringen lyckas; raden finns kvar efteråt med sitt `container_id` orört; samma sak gäller ett item som gallras med `PurgeContent`; `audit_log` har kolumnen `item_id` och de två nya indexen; `RecordAuditEvent` sätter `item_id` när ett item skickas in; migreringen går igenom i MariaDB-jobbet; [[Konton och åtkomst]] § audit_log beskriver tabellen som den är efter migreringen; hela testsviten är grön.
**Beror på:** -

### 108. Läsregeln
[[ADR-0043 Händelseloggen]] ger tre regler för vem som får läsa en rad. Regel 1 finns redan som `ContainerPolicy::viewAuditLog()`: medlem i ägarkontot. Regel 2 är ny och är ett **radfilter**, inte en grind. En användare ser sina egna rader i en container eller ett item användaren fortfarande når, och ingenting annat där.

Issuen bryter ut en Action, `ListAuditEvents`, med tre startpunkter: en container, ett item och en användare. Alla tre tillämpar samma regel i samma fråga. Omfånget prövas genom `ResolveItemScope`. Actionen får inte bygga en egen vandring.

`GET /api/containers/{container}/audit-log` ger i dag `403` till en gäst. Efter issuen får gästen sina egna rader. **Det är en ändring av ett befintligt API-svar** och ska stå i PR-kroppen.

**Läs:** [[ADR-0043 Händelseloggen]] § Beslut (läsregeln), [[ADR-0028 Åtkomst på itemnivå]] § Beslut, `app/Http/Controllers/Api/AuditLogController.php`, `app/Policies/ContainerPolicy.php` (`viewAuditLog`)
**Klart när:** ägarkontots medlem ser alla rader i containern och dess items; en gäst med `write` ser bara sina egna rader i containern; en mottagare av en itemgrant ser bara sina egna rader om items inom grantens omfång; en användare vars åtkomst återkallats ser inga rader i containern, inte heller sina egna; en rad utan container syns för medlemmarna i radens konto; frågekostnaden är konstant oberoende av antalet rader; API-svaret för en gäst är `200` med gästens egna rader; hela testsviten är grön.
**Beror på:** 107

### 109. Innehållshändelserna
Skriv `item.created`, `item.trashed`, `item.restored`, `attachment.added`, `attachment.trashed`, `cost.recorded` och `cost.trashed`. Varje rad skrivs i handlingens transaktion, genom `RecordAuditEvent`, och med `item_id` satt.

`meta` följer [[ADR-0043 Händelseloggen]] § Beslut. Bilagans `kind` ska med, men inte filnamnet. Kostnadens belopp och valuta ska med, men inte beskrivningen eller leverantören. Ett test per handling läser `meta` och bevisar att ingen fritext kom med.

**Items skapas i två controllers** (`ItemController` och `Api\ItemController`), och kostnader bara i API:t. Där en handling saknar Action bryts en ut innan den loggas.

**Läs:** [[ADR-0043 Händelseloggen]] § Beslut, [[ADR-0024 Tunna controllers och actions]], `app/Actions/Audit/RecordAuditEvent.php`, `app/Actions/Attachment/StoreAttachment.php`, `app/Actions/Trash/RestoreContent.php`
**Klart när:** var och en av de sju handlingarna skriver exakt en rad, både via webben och via API:t där båda vägarna finns; en handling som rullas tillbaka skriver ingen rad; `meta` saknar filnamn, beskrivning, leverantör och e-post; `item_id` är satt på varje rad; gallring (`PurgeContent`) skriver ingen rad; hela testsviten är grön.
**Beror på:** 107

### 110. Uppgifts- och utlåningshändelserna
Skriv `schedule.created`, `schedule.changed`, `schedule.trashed`, `occurrence.completed`, `loan.started` och `loan.returned`. `occurrence.completed` skrivs i `CloseOccurrence`. Den öppnar redan nästa förekomst i samma transaktion, och raden hör hemma där.

`schedule.changed` bär de ändrade fälten med gamla och nya värden, bara för fält med en värdelista eller ett tal: `recurrence_type`, intervallet och förfallodatumet. Titeln och anteckningen följer inte med.

**Scheman och lån skapas i två controllers vardera.** Samma regel som i issue 109.

**Läs:** [[ADR-0043 Händelseloggen]] § Beslut, [[Scheman och uppgifter]] § schedule, `app/Actions/Schedule/CloseOccurrence.php`, `app/Actions/Audit/RecordAuditEvent.php`
**Klart när:** var och en av de sex handlingarna skriver exakt en rad via både webben och API:t; att bocka av en förekomst skriver `occurrence.completed` och ingenting för förekomsten som öppnas; `schedule.changed` skrivs inte när inget loggat fält ändrats; `meta` saknar titel och anteckning; `item_id` är satt; hela testsviten är grön.
**Beror på:** 107

### 111. Container- och åtkomsthändelserna
Skriv `container.created`, `container.trashed`, `container.restored` och `access.granted`. `container.transferred` och `access.revoked` finns redan och ska inte röras. `access.granted` skrivs när en inbjudan accepteras, i `AcceptInvitation`. Nivån och mottagarens typ ska med i `meta`, men e-postadressen inte, enligt issue 40 § Beslut 10.

**Läs:** [[ADR-0043 Händelseloggen]] § Beslut, `app/Actions/Container/CreateContainer.php`, `app/Actions/Container/TrashContainer.php`, `app/Actions/Trash/RestoreTrashedContainer.php`, `app/Actions/Invitation/AcceptInvitation.php`
**Klart när:** var och en av de fyra handlingarna skriver exakt en rad; `access.granted` bär nivån och mottagarens typ men ingen e-postadress; en inbjudan till ett item sätter `item_id`; en inbjudan som avböjs eller löper ut skriver ingenting; hela testsviten är grön.
**Beror på:** 107

### 112. Historikflikarna
Containern och itemet får var sin historikflik, byggd av `UiTabs`. Issue 101 lämnade medvetet containerns flik bort *"eftersom historiken inte ritas ännu"*, och den förutsättningen faller här.

Raderna läses genom `ListAuditEvents` från issue 108. Varje rad är en mening i `lang/en/ui.php`, med en nyckel per `action`. Namn slås upp när raden läses, genom samma omfång som resten av vyn. En rad vars container, item eller användare inte längre finns visas med en neutral ersättare, till exempel *a former user* och *a deleted item*, och aldrig med ett tomt fält. Datumet följer datumregeln från issue 104.

**Ingen paginering uppfinns här.** Fliken visar de hundra senaste raderna, samma gräns som API:t. Behövs mer är det ett eget beslut.

**Läs:** [[ADR-0043 Händelseloggen]] § Beslut och § Konsekvenser, [[ADR-0042 Designsystemet]] § Beslut, `docs/Design/container.jpeg`, `docs/Design/struktur - item.jpeg`, [[M17 Designsystemet]] § 101, § 102 och § 104
**Klart när:** containern och itemet har var sin historikflik; en gäst ser bara sina egna rader och ägaren allas, bevisat med ett test per roll; varje `action` i ADR:ens tabell har en sträng i `lang/en/ui.php`; en rad om ett gallrat item eller en raderad användare renderas med en ersättare; datumen följer datumregeln; ingen komponent bär en strängliteral; hela testsviten är grön.
**Beror på:** 108, 109, 110, 111
