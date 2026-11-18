# M66 · MCP-server för agenter

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-18, efter Tonys beslut om en MCP-server som låter AI-agenter (Claude, ChatGPT och andra MCP-klienter) läsa och skriva i Mimers genom API:et. Beslutet, de nitton riktningarna och motiveringen står i [[ADR-0087 MCP-server för agenter]].

Elva issues, 400–410. Alla är skrivna direkt i GitHub (#1387–#1397) och sammanfattade här. Hjälpsidan, supportadressen och ansökan till katalogerna ingår inte (Riktning 19).

**Releasen:** en, när 400–410 är mergade. MCP slås på i produktion först när testarna godkänt den på staging (Riktning 17): `mcp.enabled` är en switch per miljö, så koden kan rullas ut utan att MCP öppnas.

---

### 400. OAuth-servern och anslutningen av en agent

GitHub #1387. **Läget:** ingen OAuth-server och ingen tabell för agenter. Sanctum räcker för appen men inte för Claude.ai och ChatGPT.

**Lösningen:** `laravel/passport` och `laravel/mcp` installeras, med en egen guard `mcp` så att ingen Passport-token släpps in på `/api` och ingen Sanctum-token på `/mcp`. Krocken mellan Sanctums och Passports `HasApiTokens` löses så att `$user->tokens()` förblir Sanctums. Authorization code med PKCE (S256), dynamisk klientregistrering, metadata under `/.well-known/` och redirect-domäner i `config/mcp.php`. En egen godkännandesida: gratiskonto nekas med länk till planen, ett konto med tio agenter kan inte ansluta en elfte. Tabellen `agent` (UNIQUE `(user_id, oauth_client_id)`). Samma klient igen återanvänder raden, en ny klient kan ersätta en befintlig agent. Anslutningen skriver `agent.connected` i säkerhetsloggen och skickar ett mejl som inte går att stänga av, med en signerad länk som kopplar bort agenten (`DisconnectAgent`). Ett lösenordsbyte återkallar tokens men lämnar raderna.

**Läs:** [[ADR-0087 MCP-server för agenter]] § Riktning 4, 5, 12 och 19, [[ADR-0011 Autentisering]], [[Konton och åtkomst]] § security_log, [[Notiser]] § notification och § notification_preference, AGENTS.md § Felformat i API:et

**Axlar:** `ambiguity` high · `blast_radius` cross-module · `risk_class` elevated

**Klart när:** med `MCP_ENABLED=false` svarar registreringen, godkännandet och metadatan 404; en redirect-URI utanför listan eller över `http` (utom localhost) avvisas; PKCE krävs och refresh-token roteras; en Passport-token avvisas på `/api` och en Sanctum-token av `mcp`; ett gratiskonto kan inte godkänna; en elfte agent avvisas, men ersättning går; ett godkännande ger en rad i `agent`, en rad i säkerhetsloggen och ett mejl; en raderad rad gör tokens obrukbara; mejllänken kopplar bort utan inloggning; ett lösenordsbyte gör tokens ogiltiga och lämnar raderna; hela sviten är grön.

**Beror på:** valvets PR för ADR-0087 och M66 (#1386)

---

### 401. Agentens rättigheter på kontot, i containern och ägarens tak

GitHub #1388. **Läget:** agenter finns som rader men har inga rättigheter, och ingenting svarar på vad agent X får göra i container Y.

**Lösningen:** grupperna `items`, `schedules`, `costs`, `loans`, `files` och `containers` (`AgentGroup`) med samma ladder som `container_access.level`, plus `create_containers`. Tabellerna `agent_account_grant` och `agent_container_grant`, kolumnerna `agent_level` på `container_access` och `invitation`, och `audit_log.agent_id`. `ResolveAgentAccess` ger den lägsta nivån av kontots, containerns, ägarens tak och användarens egen, och ingen alls vid paus eller Gratis hos användaren eller ägaren. Höjning på kontot rör inte containrarna, sänkning sänker dem. Actions för sidorna: `UpdateAgentAccountGrants`, `EnableAgentInContainer`, `DisableAgentInContainer`, `SetMemberAgentLevel`.

**Läs:** [[ADR-0087 MCP-server för agenter]] § Riktning 3, 4, 6 och 7, [[ADR-0028 Åtkomst på itemnivå]], [[Konton och åtkomst]] § container_access, § invitation, § audit_log och § Behörighetsregler, [[ADR-0043 Tre loggar]] § Händelseloggen

**Axlar:** `ambiguity` high · `blast_radius` cross-module · `risk_class` elevated

**Klart när:** en ny agent har ingen nivå; ett prov per lager visar att just det lagret kapar; utan containerrad ingen åtkomst; höjning och sänkning på kontot fungerar enligt § 4; switchen för allt sparar en ögonblicksbild; `AcceptInvitation` för över `agent_level`; pausad agent och Gratis hos användare eller ägare ger ingen åtkomst; en container som agenten skapar får kontots nivåer; `RecordAuditEvent` skriver `agent_id`; hela sviten är grön.

**Beror på:** 400 (#1387), #1386

---

### 402. MCP-servern: endpoint, felformat, gränser och kontraktsprov

GitHub #1389. **Läget:** ingen endpoint och inget gemensamt för verktygen.

**Lösningen:** `laravel/mcp`:s webbserver på `/mcp` över HTTP-transporten, med guarden `mcp` och 404 när `mcp.enabled` är falsk. Avvisning före verktyget vid paus (`agent.paused`) och Gratis (`plan.mcp_unavailable`). Felformatet `{"error": {"code", "data", "hint"}}` med `hint` ur tabellen `App\Support\Mcp\Hints`. Rate limiting per agent och per konto för slagen `read`, `write`, `delete` och `upload`. En basklass som deklarerar slag, grupp och annotationer, prövar `ResolveAgentAccess` och loggar skrivningar. Verktygen `containers_list` och `containers_tree` med markörsidindelning, och `VerktygslistaTest` som låser `tools/list` mot en ögonblicksbild.

**Läs:** [[ADR-0087 MCP-server för agenter]] § Riktning 3, 9, 13, 15, 16 och 18, AGENTS.md § Felformat i API:et, `app/Support/Api/ApiError.php`, `app/Providers/AppServiceProvider.php` (begränsarna)

**Axlar:** `risk_class` elevated

**Klart när:** `/mcp` svarar 404 när MCP är av och 401 med `WWW-Authenticate` utan giltig token; pausad agent och Gratis ger verktygsfel med `hint`; `data` är ett objekt och `server.error` bär ingen undantagstext; den 121:a läsningen ger `agent.rate_limited` med `retry_after_seconds` utan att störa en annan agent; `containers_list` visar bara containrar med `read`, har `next_cursor` och en `hint` när den är tom; `containers_tree` respekterar `depth`; `VerktygslistaTest` fälls av en ändrad signatur; hela sviten är grön.

**Beror på:** 400 (#1387), 401 (#1388), #1386

---

### 403. Läsverktygen: sök, items, bilagor och resten av domänerna

GitHub #1390. **Läget:** agenten kan bara lista containrar.

**Lösningen:** `items_search` med appens filter genom `SearchAccessibleItems`, där agentens containrar begränsas i själva frågan. `items_get` klipper beskrivningen vid 4 000 tecken och lägger fritext i fältet `content`. `attachments_get` lämnar miniatyren, en inbäddad resurs upp till 10 MB eller en signerad adress. Dessutom `relations_list`, `categories_list`, `tags_list`, `schedules_list`, `occurrences_list`, `costs_list`, `loans_list` och `history_list`. Aldrig en token, kalenderflödets URL, en webhook eller en annan persons e-postadress i ett svar. Ett item från inkorgen bär `source: "email"`.

**Läs:** [[ADR-0087 MCP-server för agenter]] § Riktning 7, 9 och 16, [[Items och organisation]] § Sök och filtrering, [[Filer och lagring]] § attachment, § image_derivative och § Säkerhet vid leverans, `app/Actions/Item/SearchAccessibleItems.php`

**Axlar:** `risk_class` elevated

**Klart när:** `items_search` hittar det appens sökning gör, minus containrar utan `read` i `items`, och ger fulla sidor; ett item användaren inte når syns aldrig; `items_get` klipper och `offset` ger resten; `attachments_get` lämnar miniatyr, inbäddad resurs eller engångsadress som går ut efter 15 minuter; inget svar bär en hemlighet; `source: "email"` finns; alla verktyg har `readOnlyHint: true`; hela sviten är grön.

**Beror på:** 402 (#1389), #1386

---

### 404. Skrivverktygen och förbuden

GitHub #1391. **Läget:** agenten kan läsa men inte skriva.

**Lösningen:** skrivverktygen `items_create`, `items_update`, `items_move`, `relations_create`, `relations_remove`, `categories_create`, `categories_update`, `tags_set`, `schedules_create`, `schedules_update`, `occurrences_complete`, `costs_create`, `costs_update`, `loans_create`, `loans_return`, `containers_create` och `containers_update`, alla genom samma actions som REST-API:et. Flytt, ny förälder och `parent`-relation som ger någon ny åtkomst avvisas med `agent.move_widens_access`. Servern minns vad agenten läst (`agent_read_audience`, 24 timmar) och avvisar en skrivning som når någon utanför snittet med `agent.copy_widens_access`. `ForbudTest` fäller ett verktyg i en förbjuden domän. Varje skrivning loggas med agenten.

**Läs:** [[ADR-0087 MCP-server för agenter]] § Riktning 1, 3, 7 och 10, [[ADR-0028 Åtkomst på itemnivå]], [[ADR-0069 Flytta ett item inom containern]], `app/Actions/Item/MoveItem.php`

**Axlar:** `risk_class` elevated

**Klart när:** varje skrivverktyg ger `agent.forbidden` utan nivån; flytt, `items_create` och `parent`-relation som vidgar åtkomsten avvisas, men inte en flytt ut eller inom samma åtkomst; en flytt med färre men en ny läsare avvisas; `containers_create` kräver `create_containers`; `ForbudTest` fäller förbjudna verktyg; varje skrivning har `agent_id` i `audit_log`; kopieringsspärren avvisar efter en läsning i en privat container men inte efter en läsning bara i den delade; fönstret går ut och snittet överlever `cache:clear`; hela sviten är grön.

**Beror på:** 403 (#1390), #1386

---

### 405. Uppladdning av filer via MCP

GitHub #1392. **Läget:** agenten kan inte ladda upp.

**Lösningen:** `attachments_upload` tar en base64-kodad fil på högst 20 MB, och `attachments_upload_url` lämnar en signerad adress (15 minuter, en användning, bunden till agenten, itemet, filnamnet och storleken) som tar emot en `PUT` upp till `max_file_bytes`. Båda går genom `StoreAttachment` med samma plangräns, virusskanning, dedup och kvot, belastar agentens användares personkonto och prövas mot kopieringsspärren. Inget verktyg hämtar en fil från en URL.

**Läs:** [[ADR-0087 MCP-server för agenter]] § Riktning 4 och 13, [[Filer och lagring]] § Uppladdningsflödet och § attachment, [[Pipeline]] § Uppladdningsgränser, `app/Actions/Attachment/StoreAttachment.php`

**Axlar:** `risk_class` elevated

**Klart när:** en fil under 20 MB blir en bilaga med rätt `billed_account_id` och en över avvisas; kvoten ger `quota.storage_exceeded`; adressen tar emot en fil en gång, avvisar en för stor fil och går ut efter 15 minuter; utan `create` i `files` ger `agent.forbidden`; kopieringsspärren avvisar uppladdningen; den elfte uppladdningen inom en minut ger `agent.rate_limited`; hela sviten är grön.

**Beror på:** 404 (#1391), #1386

---

### 406. Radering via MCP, papperskorgen och notisen agent.deleted

GitHub #1393. **Läget:** agenten kan inte radera, och papperskorgen vet inte vem som raderat.

**Lösningen:** `items_delete`, `attachments_delete`, `schedules_delete`, `costs_delete` och `containers_delete` med `destructiveHint`, genom appens actions, så att allt hamnar i papperskorgen. Inget verktyg tömmer den. Varje radering bär `deleted_by_agent_id` och en batch (nytt tillfälle efter mer än 15 minuter). Papperskorgen visar *Deleted by {agent}* och kan återställa hela batchen (`RestoreAgentDeletionBatch`). Notisen `agent.deleted` skapas 15 minuter efter sista raderingen till användaren och till en annan ägare. Efter 20 raderingar i timmen avvisas fler med `agent.delete_limit`, och taket släpps när användaren öppnat notisen.

**Läs:** [[ADR-0087 MCP-server för agenter]] § Riktning 7, 8, 9 och 14

**Axlar:** `risk_class` elevated

**Klart när:** det agenten raderar kan återställas med agentens namn synligt; batcharna delas vid 15 minuter; `RestoreAgentDeletionBatch` återställer bara batchen; `agent.deleted` skapas en gång per batch till rätt mottagare, och bara mejlet går att stänga av; den 21:a raderingen ger `agent.delete_limit` och taket släpps av notisen; avvisade försök förlänger inte batchen; notisen för en container räknar innehållet; `containers_delete` på någon annans container avvisas; inget verktyg når `EmptyTrash`, `PurgeContent` eller `PurgeContainer`; hela sviten är grön.

**Beror på:** 404 (#1391), #1386

---

### 407. Sidan för anslutna agenter

GitHub #1394. **Läget:** en ansluten agent kan inte ses eller styras.

**Lösningen:** `/settings/agents` med listan (namn, anslutningsdatum, senast använd, antal containrar, paus), en sida per agent med nivåväljare och switch per grupp, `create_containers`, containrarna där agenten är påslagen och de 20 senaste händelserna ur `audit_log`. Paus (`agent.paused` och `agent.resumed` i säkerhetsloggen) och bortkoppling bakom bekräftelsedialogen genom `DisconnectAgent`. Länken *Agents* efter *Webhooks*. Sidan syns bara med Pro eller Pro+ och när MCP är på.

**Läs:** [[ADR-0087 MCP-server för agenter]] § Riktning 6 och 12

**Axlar:** `risk_class` elevated

**Klart när:** ett gratiskonto ser varken länk eller sida och sidan ger 404 när MCP är av; listan visar fälten; ett val sparas direkt och syns i `ResolveAgentAccess`, namnet sparas med *Save*; en sänkning sänker containrarnas rader; paus och bortkoppling fungerar och loggas; händelselistan visar bara agentens rader; hela sviten är grön.

**Beror på:** 401 (#1388), #1386

---

### 408. Agenter i containerns inställningar och ägarens tak i inbjudan

GitHub #1395. **Läget:** en agent kan inte slås på i en container, och ägaren kan inte sätta något tak.

**Lösningen:** avsnittet *Agents* i containerns inställningar: allt avslaget från start, en switch för allt upp till kontots nivå (ögonblicksbild) eller en nivå per grupp, aldrig över kontot eller ägarens tak. Fältet *Agent access* i inbjudningsformuläret (`invitation.agent_level`, standard *None*) och per medlem i åtkomstlistan (`SetMemberAgentLevel`). Allt syns bara när både användaren och ägaren har Pro eller Pro+ och MCP är på.

**Läs:** [[ADR-0087 MCP-server för agenter]] § Riktning 4 och 6

**Axlar:** `risk_class` elevated

**Klart när:** en agent är avslagen tills den slås på; switchen för allt sparar kontots nivåer som de är; ingen grupp över kontot eller ägarens tak; en inbjudan för `agent_level` vidare vid accept; fältet erbjuder inget över inbjudans nivå; ägaren kan ändra taket för en medlem; inget syns med Gratis; hela sviten är grön.

**Beror på:** 401 (#1388), 407 (#1394), #1386

---

### 409. Agenter vid nedgradering och notisen agent.access_lost

GitHub #1396. **Läget:** agenterna vet inget om planen.

**Lösningen:** när planen blir Gratis återkallas inga tokens, och raderna, rättigheterna och tokens står kvar, vilande. Planprövningen avvisar varje anrop med `plan.mcp_unavailable`. Vid `past_due` kan agenten läsa men inte skriva. En uppgradering gör att samma token och rättigheter gäller igen. När en ägare går till Gratis tappar andras agenter åtkomsten till ägarens containrar, och varje berörd användare får `agent.access_lost` en gång per ägare. Varningsmejlen vid nedgradering nämner MCP för konton med agenter.

**Läs:** [[ADR-0087 MCP-server för agenter]] § Riktning 11 och 14, [[Planer och kvoter]] § subscription och § Nedgradering

**Axlar:** `risk_class` elevated

**Klart när:** efter `current_period_end` ger varje anrop `plan.mcp_unavailable` med tokens och rättigheter kvar; före fungerar agenten; vid `past_due` lyckas läsning och skrivning avvisas; efter uppgradering fungerar samma token; en ägares nedgradering stänger andras agenter ute och ger en `agent.access_lost` per berörd användare; varningsmejlen nämner MCP bara för konton med agenter; hela sviten är grön.

**Beror på:** 401 (#1388), 402 (#1389), 408 (#1395), #1386

---

### 410. Testkontot för MCP på staging

GitHub #1397. **Läget:** testarna kan inte prova delade containrar och ägarens tak utan handpåläggning.

**Lösningen:** `testkonto:skapa --mcp` skapar en andra testanvändare med Pro och delar en av den förstas containrar med den på `write` med `agent_level` `read`. Utan flaggan beter sig kommandot som i dag. `deploy/deploy.sh` kör `passport:keys` bara när nycklarna saknas, och nycklarna ligger i den delade katalogen. Att slå på `MCP_ENABLED` och lägga nycklarna som secrets gör Tony och ingår inte.

**Läs:** [[ADR-0087 MCP-server för agenter]] § Riktning 17, [[Pipeline]] § Miljöer och secrets i GitHub och § Testkontot

**Axlar:** `risk_class` none

**Klart när:** `testkonto:skapa --mcp` skapar två Pro-användare och en delad container med `agent_level` `read`; utan `--mcp` skapas samma konto som i dag och de befintliga proven är gröna; `deploy.sh` skapar nycklarna när de saknas och rör dem inte när de finns; hela sviten är grön.

**Beror på:** 400 (#1387), 408 (#1395), #1386
