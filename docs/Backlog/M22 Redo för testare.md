# M22 · Redo för testare

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

Tillagd 2026-09-26, när M21 var klar. MVP:n är i stort sett byggd, och det som står i vägen för riktiga testare är inte nya funktioner. Det är ett fel som skulle radera aktiva konton, en rätt enligt GDPR som saknas, två raderingar som inte går att genomföra och ett par ytor som lovar något de inte håller. Flera regler i processen är dessutom tillfälliga *"tills testare finns"*: automergen i [[ADR-0026 Implementering och granskning efter riskaxlar]] och att staging bara byggs på taggar. Milstolpen är det som ska vara sant innan de reglerna vänds tillbaka.

**Aktiviteten först (141).** `user.last_active_at` driver kontolivscykeln: påminnelse efter 12 månader, stängning efter 15 och radering efter 18. Middlewaren som skriver kolumnen sitter bara i `api`-gruppen, och webbappen går genom `web`. Den som bara använder webben räknas därför som inaktiv sedan kontot skapades, hur ofta hen än loggar in. Provkört 2026-09-26: en inloggad `GET /dashboard` lämnar kolumnen orörd. Ingen data har skadats ännu, eftersom de äldsta kontona är från augusti 2026.

**Raderingen (142–145)** följer [[ADR-0045 Radering av konto och person]]. Författarkolumnerna blir nullbara (142), kontoraderingen släpper innehåll i andras containers i stället för att fastna på det (143), och en person kan radera sig själv (144 och 145).

**Ytorna som lovar för mycket (146–147).** Inställningssidan visar en preferens för inbjudningsmejl som ingenting läser, och kalenderflödet bär varken produktens namn eller ett namn som kalenderappar som följer RFC 7986 läser.

**Testplanen (148–149).** [[Testplan filer]] säger vad som redan är bevisat om dedup och filåtkomst, och vad som saknas. De två issuerna skriver proven som saknas.

**Utanför milstolpen, avgjort samtidigt.** Magic link kräver redan tvåfaktorkoden: det byggdes i issue 80, och frågan i [[Tankar]] hade bara inte flyttats. Papperskorgen tar emot saker, inte inställningar eller egenskaper ([[ADR-0008 Soft delete och papperskorg]] § Uppföljning 2026-09-26): scheman, lån och kostnadsrader försvinner redan direkt, och kategorier och taggar lämnar papperskorgen i 150. Anmälningsvägen enligt DSA blir en egen milstolpe. Hela testsviten mot MariaDB i CI är ett pipelinearbete och går vid sidan av.

---

### 141. Webben räknas som aktivitet
`UpdateLastActiveAt` läggs också i `web`-gruppen i `bootstrap/app.php`, efter sessionen, så att `$request->user()` finns. **Samtidigt stryps skrivningen:** kolumnen skrivs bara när den är `NULL` eller äldre än ett dygn. Livscykeln räknar i månader, och en UPDATE per sidvisning ger den ingenting.

Strypningen jämför mot värdet som redan är laddat på användarmodellen. Den gör ingen egen fråga. Skrivningen är fortfarande `saveQuietly()`.

**Webbaktivitet öppnar ett stängt konto igen**, precis som ett API-anrop gör i dag (`AdvancesAccountLifecycle`, Beslut 7). Regeln i [[Planer och kvoter]] § Kontolivscykel, *"API-anrop från vilken klient som helst"*, skrivs om till *"aktivitet i webben eller API:et"*.

Flera frågeräkningsprov filtrerar bort `last_active_at` med kommentaren att kolumnen skrivs *"vid varje autentiserat anrop"*. Filtren får stå kvar. Kommentarerna rättas bara i filer som ändå står i rutan.

**Läs:** [[Planer och kvoter]] § Kontolivscykel, [[Konton och åtkomst]] § user, `app/Http/Middleware/UpdateLastActiveAt.php`, `bootstrap/app.php`, `app/Console/AdvancesAccountLifecycle.php` (docblocken), `tests/Feature/SenasteAktivitetTest.php`
**Klart när:** en inloggad webbsida uppdaterar `last_active_at`; ett autentiserat API-anrop gör det fortfarande; en andra begäran inom ett dygn gör ingen UPDATE, varken i webben eller i API:et; en begäran när värdet är äldre än ett dygn skriver; en utloggad begäran rör inte kolumnen; ett konto stängt för inaktivitet öppnas igen av att en medlem öppnar en webbsida; [[Planer och kvoter]] § Kontolivscykel och [[Konton och åtkomst]] § user beskriver regeln; hela testsviten är grön.
**Beror på:** -

### 142. Författarkolumnerna blir nullbara
Kolumnerna i [[ADR-0045 Radering av konto och person]] § Beslut 2 blir `NULL`-bara, i en migrering. Nycklarna förblir `ON DELETE RESTRICT`. Migreringen är additiv i expand/contract-mening och får inte ändra någon befintlig rad.

**Varje ställe som läser en författare klarar `NULL`.** Resurserna returnerar `null` för fältet och hittar inte på något namn. Vyerna visar ingen författare alls, eller texten *Removed user* där raden annars blir obegriplig, till exempel *"Granted by"* i delningsvyn. Exporten skriver en tom cell.

Skrivvägarna ändras inte. `CreateItem`, `StoreAttachment`, `CloseOccurrence` och de andra sätter fortfarande författaren. Det är bara raderingen i 143 och 144 som nollställer den.

**Läs:** [[ADR-0045 Radering av konto och person]] § Beslut 2, [[Datamodell – översikt]], `database/migrations/2026_08_31_000000_create_item_table.php` (förlagan för nycklarna)
**Klart när:** alla kolumner i ADR:ens tabell är nullbara och har kvar sin främmande nyckel med RESTRICT; migreringen går på MariaDB och ändrar ingen befintlig rad; varje resurs som bär en författare ger `null` när kolumnen är `NULL`, i både webben och `/api`; delningsvyn, förekomstvyn, inbjudningarna och exporten renderar utan fel med en nollställd författare; datamodellfilerna visar kolumnerna som nullbara; hela testsviten är grön.
**Beror på:** -

### 143. Kontoraderingen släpper innehåll i andras containers
`DeleteAccount` gör två saker till, i samma transaktion och före containergallringen:

1. **Bilagor som kontot betalar för i andras containers** får containerns ägarkonto som `billed_account_id`. Bytena flyttar mellan kontonas `usage_counter`. Kvoten kontrolleras inte ([[ADR-0045 Radering av konto och person]] § Beslut 1). `AcceptOwnershipTransfer` är förlagan för flytten.
2. **`item.created_by_account_id`, `cost_entry.created_by_account_id` och `schedule_occurrence.completed_by_account_id`** sätts till `NULL` där de pekar på kontot.

`DeletesDormantAccounts` tappar spärren för främmande bilagor (29b § Beslut 5) och `hasForeignBilledAttachment()`. Spärren för delade containers med aktiva medlemmar står kvar.

**Läs:** [[ADR-0045 Radering av konto och person]] § Beslut 1 och 2, [[Planer och kvoter]] § Kontolivscykel och § usage_counter, `app/Actions/Account/DeleteAccount.php` (docblocken), `app/Console/DeletesDormantAccounts.php` (docblocken), `app/Actions/OwnershipTransfer/AcceptOwnershipTransfer.php` (flytten av `billed_account_id`)
**Klart när:** ett konto med bilagor i en annans container raderas, och bilagorna står kvar med containerns ägare som betalare; ägarkontots förbrukning ökar med exakt bilagornas `byte_size`; flytten går igenom också när ägarkontot hamnar över sin kvot; ett konto som skapat ett item, en kostnadsrad och bockat av en förekomst i en annans container raderas, och raderna står kvar med författarkontot nollställt; ett konto med en delad container med aktiva medlemmar spärras fortfarande; `stored_file.reference_count` ändras inte av flytten; allt sker i en transaktion, och ett fel mitt i lämnar ingenting halvt; [[Planer och kvoter]] § Kontolivscykel beskriver regeln; hela testsviten är grön.
**Beror på:** 142

### 144. En person kan raderas
En ny action, `App\Actions\User\DeleteUser`, gör det [[ADR-0045 Radering av konto och person]] § Beslut 3 beskriver. Den har två ingångar: `blockers(User $user)` ger listan över det som spärrar, och `handle(User $user)` raderar och kastar om listan inte är tom. Ytan kommer i 145. Den här issuen bygger verktyget.

**Ordningen i transaktionen:** spärrarna prövas, varje konto där personen är enda medlem raderas med `DeleteAccount`, personen lämnar övriga konton, författarkolumnerna mot `user` nollställs, personens egna rader raderas, väntande inbjudningar och ägarbyten som personen startat dras tillbaka, säkerhetsloggen får `user.deleted`, och sist raderas `user`-raden.

**Spärrarna är tre:** personen är enda `owner` i ett konto som har andra medlemmar; ett konto som skulle raderas har en delad container med aktiva medlemmar; en rättslig spärr täcker något av personens konton (`LegalHold::covers()`). **En aktiv prenumeration spärrar inte.** Den avslutas utan återbetalning när kontot raderas, genom att `DeleteAccount` tar bort `subscription`-raden som i dag. Varje spärr har en maskinläsbar kod och det den gäller, så att 145 kan visa den.

**`user.deleted` bär inte e-postadressen**, bara `user_id` och antalet raderade och lämnade konton i `meta`.

**Läs:** [[ADR-0045 Radering av konto och person]], [[ADR-0043 Tre loggar]] § Säkerhetsloggen och § Den rättsliga spärren, [[Konton och åtkomst]] § user och § account_user, `app/Actions/Account/DeleteAccount.php` (docblocken), `app/Console/DeletesDormantAccounts.php` (spärrarna), `app/Models/LegalHold.php`
**Klart när:** en person som är enda medlem i sitt konto raderas, och kontot, dess containers och personens rader är borta; en person i ett konto med andra medlemmar lämnar kontot, och kontot står kvar orört; var och en av de tre spärrarna gör att ingenting raderas och ger sin kod; ett konto med en prenumeration som är `active` raderas ändå, och prenumerationen är borta; innehåll som personen skapat i en annans container står kvar med författaren nollställd; väntande inbjudningar och ägarbyten som personen startat är tillbakadragna; säkerhetsloggen har en rad `user.deleted` utan e-postadress; loggraderna med personens `user_id` står kvar; e-postadressen kan registreras igen; allt sker i en transaktion; [[Konton och åtkomst]] och [[Registerförteckning]] beskriver raderingen; hela testsviten är grön.
**Beror på:** 142, 143

### 145. Personraderingen i inställningarna
Inställningarna får en sektion där personen raderar sig själv. Flödet har samma form som lösenordsbytet i issue 140:

1. **Sidan** visar vad som kommer att hända, ur `DeleteUser::blockers()` och personens konton: vilka konton som raderas och vilka som lämnas. Finns en spärr visas den med vad den gäller och vägen ut. Knappen är då avstängd.
2. **Begäran**, `POST /settings/delete-user`: plus `code` när tvåfaktorn är påslagen, prövad av `TwoFactorChallenge`. En rad skrivs i en ny tabell, `user_deletion`, i samma form som `password_change`: `user_id`, `token_hash CHAR(64)` unik, `expires_at` (en timme), `confirmed_at` och tidsstämplarna. Ett mejl går till `user.email`. En ny begäran ogiltigförklarar en tidigare obekräftad rad.
3. **Länken**, `GET /settings/delete-user/{token}`, måste öppnas av samma inloggade användare. Är det någon annan, eller har tokenet gått ut eller redan använts, blir svaret `404`. Spärrarna prövas igen. Därefter körs `DeleteUser`, sessionen avslutas och personen hamnar på startsidan med ett kvitto.

**Ordet.** Kontot heter *account* i gränssnittet och är något annat än personen. Texten säger därför inte *Delete account*. Den säger vad som raderas: personen, och de konton där personen är ensam.

**Läs:** [[ADR-0045 Radering av konto och person]] § Beslut 3, [[ADR-0011 Autentisering]] § Uppföljning 2026-09-26, `app/Actions/Account/RequestPasswordChange.php` och `app/Actions/Account/ConfirmPasswordChange.php` (förlagorna), `app/Http/Controllers/Settings/PasswordController.php`, `resources/js/pages/Settings/Security.vue`
**Klart när:** sidan visar vilka konton som raderas och vilka som lämnas; en spärr visas med vad den gäller, och begäran går då inte att göra; en begäran raderar ingenting och skickar ett mejl till `user.email`; med tvåfaktor påslagen misslyckas begäran utan giltig kod; länken raderar personen och loggar ut; länken fungerar en gång och inte efter en timme; en annan inloggad användare får `404` och ingenting raderas; en spärr som uppstått mellan begäran och länken stoppar raderingen; `user_deletion` innehåller aldrig tokenet i klartext; [[Konton och åtkomst]] har ett avsnitt `user_deletion` och [[Registerförteckning]] en rad för tabellen; strängarna ligger i `lang/en/`; hela testsviten är grön.
**Beror på:** 140, 144

### 146. Inbjudningsnotisen som aldrig skickas tas bort
`Notification::TYPE_INVITATION_RECEIVED` skrivs av ingen kod ([[Tankar]] § Avgjort och flyttat). Inbjudningsmejlet går direkt via `InvitationNotification`, och det ska det fortsätta göra. En inbjudan kommer från en människa, inte från systemet, och ska inte vänta på tysta timmar. Klockan visar redan väntande inbjudningar ur `invitation` (issue 131). Konstanten tas bort, och med den:

- raden i `NotificationPreferences::EMAIL_DEFAULTS`, och därmed preferensraden på inställningssidan och i `/api`,
- typen i `WebhookEndpoint::EVENT_TYPES`, så att ett nytt abonnemang på den nekas som vilken okänd typ som helst,
- etiketten `invitation_received` i `lang/en/notiser.php`.

**En migrering städar data:** rader i `notification_preference` med typen raderas, och typen plockas ur `webhook_endpoint.event_types` där den finns. En endpoint som därefter saknar typer står kvar.

Nyckeln `inbox.invitation.received` i `lang/en/ui.php` är klockans rad för en väntande inbjudan och **står kvar**. Docblocken som förklarar att konstanten *"finns men inte skrivs"* skrivs om.

**Läs:** [[Notiser]] § notification och § notification_preference, `app/Models/Notification.php`, `app/Support/Notification/NotificationPreferences.php` (docblocken), `app/Models/WebhookEndpoint.php`, `app/Http/Middleware/HandleInertiaRequests.php` (inbjudningarna i klockan)
**Klart när:** konstanten finns inte; preferenssidan och `/api` visar ingen rad för inbjudningar; ett webhookabonnemang på `invitation.received` nekas med samma fel som en okänd typ; migreringen raderar befintliga preferensrader för typen och tar bort den ur befintliga endpoints; inbjudningsmejlet går fortfarande direkt, också under mottagarens tysta timmar; klockan visar fortfarande väntande inbjudningar; [[Notiser]] räknar inte upp typen; hela testsviten är grön.
**Beror på:** -

### 147. Kalenderflödet bär produktens och containerns namn
Kalendern heter i dag *Maintenance: Båten*, i `X-WR-CALNAME`. Produktens namn saknas, och egenskapen är en utvidgning som bara en del kalenderappar läser. Tre ändringar:

- **Namnet** blir *Mimers · :container* (`notiser.calendar.name`).
- **`NAME:`** enligt RFC 7986 skrivs bredvid `X-WR-CALNAME`, med samma värde. `X-WR-CALDESC` och `DESCRIPTION:` får en rad om vad flödet är.
- **Filnamnet** i `Content-Disposition` blir `mimers-<container>.ics`, med containernamnet som ASCII-slug, och bara `mimers.ics` om sluggen blir tom.

**Läs:** `app/Support/Notification/IcsDocument.php` (docblocken), `app/Http/Controllers/CalendarFeedDownloadController.php`, `tests/Feature/Notis/IcsFeedTest.php`
**Klart när:** flödet bär `NAME:` och `X-WR-CALNAME:` med *Mimers · * och containerns namn; `X-WR-CALDESC:` och `DESCRIPTION:` finns; ett containernamn med kommatecken, semikolon och å escapas korrekt i alla fyra; filnamnet är `mimers-` plus sluggen, och `mimers.ics` när sluggen blir tom; strängarna ligger i `lang/en/notiser.php`; hela testsviten är grön.
**Beror på:** -

### 148. Referensräkningen bevisas över hela livscykeln
Skriv proven R1–R8 i [[Testplan filer]] § Del 1. **Ingen produktionskod ändras.** Visar ett prov ett fel markeras det `->todo()` med en rad om vad det visar, och felet beskrivs under `## Frågor och antaganden` i PR-kroppen.

R7 är invariantprovet: en följd av operationer över två konton, och efter varje steg en kontroll av att `reference_count` är lika med antalet ogallrade `attachment`-rader för varje `stored_file`. Följden är fast, inte slumpad, så att ett rött prov går att köra om.

**Läs:** [[Testplan filer]], [[Filer och lagring]] § stored_file och § Radering, [[ADR-0006 Innehållsadresserad lagring]], `tests/Feature/Attachment/ReferensrakningTest.php`, `tests/Support/Testhjalpare.php`
**Klart när:** R1–R8 har var sitt prov, namngivet så att bokstaven och siffran går att hitta; inget prov upprepar ett som tabellen *Redan bevisat* pekar på; ingen fil utanför `tests/` och [[Testplan filer]] ändras; ett prov som visar ett fel är markerat `->todo()` och beskrivet i PR-kroppen; [[Testplan filer]] pekar på de nya proven; hela testsviten är grön.
**Beror på:** 143

### 149. Filåtkomsten bevisas över alla vägar in
Skriv proven A1–A8 i [[Testplan filer]] § Del 2, under samma regel som 148: **ingen produktionskod ändras**, och ett prov som visar ett fel markeras `->todo()` och beskrivs i PR-kroppen.

**Läs:** [[Testplan filer]], [[ADR-0019 Filleverans]], [[ADR-0028 Åtkomst på itemnivå]], `tests/Feature/Attachment/NedladdningTest.php`, `tests/Feature/Filleverans/FiloriginTest.php`, `tests/Feature/Omfang/ItemgrindTest.php`
**Klart när:** A1–A8 har var sitt prov, namngivet så att bokstaven och siffran går att hitta; inget prov upprepar ett som tabellen *Redan bevisat* pekar på; ingen fil utanför `tests/` och [[Testplan filer]] ändras; ett prov som visar ett fel är markerat `->todo()` och beskrivet i PR-kroppen; [[Testplan filer]] pekar på de nya proven; hela testsviten är grön.
**Beror på:** -

### 150. Kategorier och taggar lämnar papperskorgen
Kategorier och taggar är inställningar, inte saker ([[ADR-0008 Soft delete och papperskorg]] § Uppföljning 2026-09-26). Papperskorgen i containern visar i dag fyra typer. Den ska visa två: `item` och `attachment`.

- **`ListTrash` och `FindTrashedInContainer`** tar inte längre med `category` och `tag`. `RestoreContent` tappar grenarna för dem, och `RestoreRequest` godtar bara `item` och `attachment`. En återställning av en kategori eller tagg nekas som vilken okänd typ som helst, i både webben och `/api`.
- **Vyn** (`Containers/Trash.vue`, `TrashRow.vue`, `trashPresentation.js`) tappar typerna och deras strängar.
- **Raderingen ändras inte:** kategorin och taggen mjukraderas som i dag och gallras av `PurgesExpiredTrash` trettio dagar efter `deleted_at`. Kvittona efter en radering nämner redan varken papperskorgen eller trettio dagar och ändras inte.

**Läs:** [[ADR-0008 Soft delete och papperskorg]] § Uppföljning 2026-09-26, `app/Actions/Trash/ListTrash.php` (docblocken), `app/Actions/Trash/RestoreContent.php`, `app/Http/Requests/Trash/RestoreRequest.php`, `resources/js/pages/Containers/Trash.vue`
**Klart när:** papperskorgen visar mjukraderade items och bilagor men inga kategorier eller taggar, i både webben och `/api`; en återställning av typen `category` eller `tag` nekas och ändrar ingenting; en raderad kategori eller tagg gallras fortfarande efter trettio dagar; hela testsviten är grön.
**Beror på:** -
