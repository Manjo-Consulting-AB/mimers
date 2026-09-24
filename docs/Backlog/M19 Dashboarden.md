# M19 · Dashboarden

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

Tillagd 2026-09-24, efter retron för M18. Förlagan är `docs/Design/main.jpeg`. Besluten togs vid genomgången av dashboardmockupen 2026-09-18 och stod i [[Att sortera efter mockuparna]] § Dashboarden tills de blev issues här. Beslut med egen ADR: [[ADR-0038 Gränsen för Pro i kostnaderna]] för kostnaderna och [[ADR-0043 Tre loggar]] § Konsekvenser för händelsepanelen. [[M17 Designsystemet]] § Detta ingår inte lämnade sidan åt den här milstolpen *"när instrumenteringen finns"*, och det gör den sedan [[M18 Loggarna]].

**`/dashboard` är i dag todo-vyn.** Issue 122 flyttar den till en egen sida och gör `/dashboard` till en ny sida med en uppgiftspanel. De andra issuerna lägger var sin panel på den nya sidan. **Alla bygger på 122**, och de rör samma två filer: `DashboardController` och `Dashboard.vue`. Varje panel får därför en egen komponent och en egen prop, så att en konflikt stannar vid en rad i varje fil.

**Rättelse av en äldre rad.** [[Att sortera efter mockuparna]] sa 2026-09-18 att händelsepanelen visar *användarens egna rader*. [[ADR-0043 Tre loggar]] § Konsekvenser beslutade fem dagar senare något annat: panelen är *händelseloggen med läsregeln, över alla användarens konton*. ADR:en gäller.

**En issue är `risk_class: elevated`:** 125, som visar pengar. Resten är `none`. Klockan i 127 läser bara användarens egna rader.

**Detta ingår inte:** containerns foto och undertitel på korten, framdriftsstapeln och vädret (ingen datakälla, se [[ADR-0042 Designsystemet]]), den globala vänstermenyn (avvisad av [[ADR-0041 Itemets vy]]), en webbvy för kostnadsrapporten (donuten är därför inte klickbar än), en sida med *alla* händelser, och `/api`. API:ets todo-ändpunkt ändras inte.

---

### 122. Todo-vyn får en egen sida
`TodoController` flyttar till `GET /tasks` med ruttnamnet `tasks`, och `Dashboard.vue` byter namn till `Tasks/Index.vue`. **Allt som redan är genomtänkt i vyn följer med oförändrat.** Det gäller urvalet i `ScheduleOccurrence::scopeTodoFor()`, grupperingen på servern, `can.update` per rad, kontoförvalet och den konstanta frågekostnaden via `ResolveItemScope`. Huvudmenyn får en länk till `/tasks`.

`/dashboard` blir en ny sida, `DashboardController`, och behåller namnet: ramverket skickar en nyinloggad användare dit. Sidan har en panel, *Kommande uppgifter*. Den visar de fem första raderna ur samma urval och i samma ordning som task-vyn, med en länk dit. Panelen läser urvalet genom samma fråga som `TodoController` och formulerar inget eget `where`.

**De två tomma lägena följer med till dashboarden.** Den som inte har någon container alls får meningen och länken till att skapa en. Den som har containrar utan öppna uppgifter får den andra meningen. `TodoController` skiljer dem åt redan i dag, se issue 64 § Beslut 6.

**Läs:** [[Att sortera efter mockuparna]] § Dashboarden, `app/Http/Controllers/TodoController.php` (docblocken), `resources/js/pages/Dashboard.vue` (kommentaren överst), `routes/web.php` (kommentaren vid `/dashboard`), `docs/Design/main.jpeg`
**Klart när:** `GET /tasks` visar todo-vyn med samma grupper och samma rader som `/dashboard` visade före issuen; `GET /dashboard` visar en panel med högst fem rader ur samma urval i samma ordning; panelen länkar till `/tasks`; en omfångsbegränsad mottagare ser inga rader utanför omfånget på någon av sidorna; de två tomma lägena finns på dashboarden; frågekostnaden på dashboarden är konstant oberoende av antalet containrar; huvudmenyn länkar till `/tasks`; strängarna ligger i `lang/en/ui.php`; hela testsviten är grön.
**Beror på:** -

### 123. Task-vyn pagineras
**Pagineringsförbudet faller.** Issue 64 § Beslut 3 och [[ADR-0005 Schema och förekomst]] motiverar den opaginerade listan med att *"i april förfaller allt samtidigt"*. Den premissen är båtpärmen och inte produkten, se [[ADR-0033 Produktens omfång]]. ADR-0005 står kvar som historik enligt regeln i [[ADR-0032 Produktens ord]]. Omprövningen hör hemma här och behöver ingen ADR.

`/tasks` pagineras med en markör över `(due_at, ulid)`, alltså samma ordning som i dag, femtio rader per sida. Markören står i querysträngen. **Grupperingen räknas fortfarande på servern, per rad.** En sida kan därför börja mitt i en grupp, och gruppens rubrik upprepas på nästa sida. Dashboardens panel pagineras inte, den tar sina fem.

Kommentarerna som säger *"Ingen paginering"* i `TodoController`, `Tasks/Index.vue` och `TodovyTest` skrivs om så att de säger vad som gäller och varför.

**Läs:** [[ADR-0005 Schema och förekomst]] § Beslut, [[ADR-0033 Produktens omfång]] § Beslut, `app/Http/Controllers/TodoController.php` (docblocken), [[Att sortera efter mockuparna]] § Dashboarden
**Klart när:** `/tasks` visar högst femtio rader; nästa sida börjar exakt efter föregående sidas sista rad, även när två rader har samma `due_at`; en rad hamnar i samma grupp oavsett vilken sida den står på; en grupp som delas mellan två sidor har sin rubrik på båda; frågekostnaden per sida är konstant; ingen kommentar i de tre filerna säger längre att listan är opaginerad; hela testsviten är grön.
**Beror på:** 122

### 124. Brickorna och containerkorten
Två brickor och en rad kort, byggda av `UiStat` och `UiCard` ur [[M17 Designsystemet]].

**Brickorna är tre, inte fyra.** Mockupens *Uppgifter* och *Underhåll* är samma tabell. Den tredje brickan, kostnaden, är issue 125. Den här issuen bygger två:

1. **Containrar:** antalet containrar användaren når, samma urval som `ContainerController::index()`.
2. **Uppgifter:** antalet rader i `scopeTodoFor()`, alltså exakt de rader som står bakom länken till `/tasks`. Det är inget annat tal. Underraden är antalet försenade.

**Containerkorten** visar namn, antal items och antal öppna uppgifter. **Varje tal räknar det användaren själv når** ([[ADR-0039 Containerns översikt]]): en mottagare av en itemgrant ser antalet items inom sitt omfång. Korten grupperas enligt regeln i [[ADR-0036 Containerns art]]: en art med minst två containrar får en egen rubrik med artens namn. Resten ligger under rubriken *My containers*, som mockupen gav som namn. Korten länkar till containerns översikt.

**Foto, undertitel och framdriftsstapel ritas inte.** Ingen av dem har en datakälla.

**Läs:** [[ADR-0039 Containerns översikt]] § Beslut, [[ADR-0036 Containerns art]] § Beslut, [[Att sortera efter mockuparna]] § Dashboarden, `app/Http/Controllers/ContainerController.php` (`index`), `app/Actions/Access/ResolveItemScope.php` (docblocken), `docs/Design/main.jpeg`
**Klart när:** containerbrickan visar antalet containrar användaren når; uppgiftsbrickan visar samma tal som antalet rader på `/tasks` och antalet försenade som underrad; varje kort visar antalet items och öppna uppgifter inom användarens omfång, bevisat med en omfångsbegränsad mottagare; en art med två containrar får en egen rubrik; en art med en container hamnar under *My containers*; frågekostnaden är konstant oberoende av antalet containrar; strängarna ligger i `lang/en/ui.php`; hela testsviten är grön.
**Beror på:** 122

### 125. Kostnaderna på dashboarden
Den tredje brickan och donuten. Båda är fria enligt [[ADR-0038 Gränsen för Pro i kostnaderna]] § Beslut: *"Dashboardens totalsumma för innevarande månad"* och *"Dashboardens donut, nedbruten per container"*.

**Perioden är innevarande kalendermånad, och servern bestämmer den.** Sidan tar ingen parameter. En månad som går att välja är en fråga, och en fråga är Pro. Månaden räknas i användarens tidszon, med kontots som reserv, samma regel som [[Konton och åtkomst]] § user.

`CostReport` får en metod för månadens rader över användarens containrar, grupperad per container. Den bygger på samma `rowSet()` och samma omfångsfilter som `summaryForContainers()`. **Ingen egen radmängd**, och ingen summering i PHP. **Valutor summeras aldrig ihop** ([[ADR-0040 Underträdets summor]] § Konsekvenser). Brickan visar ett belopp per valuta, och donuten ritas en gång per valuta.

Donuten är en ny komponent, `CostDonut.vue`, ritad som SVG utan nytt beroende. Tårtbitarna är containrarna och ordningen är fallande belopp. Donuten är inte klickbar, för rapportvyn finns inte i webben än.

**Läs:** [[ADR-0038 Gränsen för Pro i kostnaderna]], [[ADR-0040 Underträdets summor]] § Konsekvenser, `app/Support/Cost/CostReport.php` (docblocken och `summaryForContainers`), `app/Http/Controllers/Api/CostSummaryController.php` (docblocken), `docs/Design/main.jpeg`
**Klart när:** brickan visar summan av innevarande månads kostnadsrader per valuta; en rad från förra månaden räknas inte; månadsgränsen följer användarens tidszon; donuten har en tårtbit per container med kostnader; två valutor ger två belopp och två donuts, aldrig en summa; en rad på ett item utanför mottagarens omfång räknas inte; sidan ignorerar en period i querysträngen; ingen plangrind; frågekostnaden är konstant oberoende av antalet containrar; hela testsviten är grön.
**Beror på:** 122

### 126. Händelsepanelen
Dashboarden visar de fem senaste raderna i händelseloggen som användaren får läsa, över alla användarens konton. Raderna läses genom `ListAuditEvents::forUser()` och formas av `PresentAuditEvents`, båda från [[M18 Loggarna]]. **Läsregeln ändras inte, och panelen filtrerar inte själv.**

En rad på dashboarden står utanför containern, så den säger vilken container den gäller. Det är en egen nyckel per handling eller en gemensam rad under meningen. PR:en till issue 116 lät det vänta hit. En rad vars container inte längre finns visas med ersättaren *a deleted container*, på samma sätt som *a former user* och *a deleted item*. Datumet följer datumregeln från issue 104. Panelen har ingen *Visa alla*, för sidan med alla händelser finns inte.

**Läs:** [[ADR-0043 Tre loggar]] § Händelseloggen och § Konsekvenser, `app/Actions/Audit/ListAuditEvents.php` (docblocken och `forUser`), `app/Actions/Audit/PresentAuditEvents.php`, `resources/js/components/HistoryRow.vue`
**Klart när:** panelen visar högst fem rader, nyast först; ägarkontots medlem ser andra användares rader i sina containrar; en gäst ser bara sina egna; en användare vars åtkomst återkallats ser inga rader från den containern; varje rad säger vilken container den gäller; en rad om en gallrad container visas med ersättaren; frågekostnaden är konstant oberoende av antalet rader och containrar; strängarna ligger i `lang/en/ui.php`; hela testsviten är grön.
**Beror på:** 122

### 127. Notisklockan
Sidhuvudet får en klocka som listar användarens notiser: raderna i `notification` med användarens `user_id`, de tjugo senaste. **Klockan är ingen kanal.** Den skapar inga `notification_delivery`-rader och påverkas inte av preferenser eller tysta timmar. Den läser tabellen som den redan är.

**Oläst är en tidsstämpel, inte en kolumn per rad.** `user` får `notifications_read_at TIMESTAMP NULL`. Klockans siffra är antalet rader skapade efter den tidpunkten. Att öppna klockan sätter den. Siffran är en delad prop, och den kostar en fråga per sidladdning, inte fler. Listan hämtas först när klockan öppnas.

Varje typ får en mening i `lang/en/ui.php` som byggs ur radens `payload`. Payloaden bär ingen färdig text ([[Notiser]] § notification). En rad länkar dit den handlar om när det finns en sådan sida: en uppgift till sin förekomst, ett lån till sitt item, ett ägarbyte till `/transfers`.

**Inbjudningar ingår inte, och det är inte ett förbiseende.** [[Att sortera efter mockuparna]] sa att `invitation.received` *"finns redan"* och att bara ytan saknas. Konstanten finns, men **ingen kod skriver en sådan rad**: `CreateInvitation` skickar mejlet direkt med `InvitationNotification`. Inbjudningssidan kräver dessutom tokenet ur mejlet. Att visa en inbjudan för en inloggad användare kräver alltså en ny väg till accept, och det är behörighetskod. Den är issue 131 i [[M20 Kontot]]. Klockan visar de sex typer som faktiskt skrivs.

**Läs:** [[Notiser]] § notification, [[ADR-0010 Notisarkitektur]], `app/Models/Notification.php`, `app/Actions/Notification/CreateNotification.php` (docblocken), `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/layouts/AppLayout.vue`
**Klart när:** klockan listar användarens tjugo senaste notiser; ingen annan användares rad syns; siffran räknar rader skapade efter `notifications_read_at`; att öppna klockan nollställer siffran; var och en av de sex typer som skrivs har en mening i `lang/en/ui.php`; ingen `notification_delivery`-rad skapas; siffran kostar en fråga per sidladdning; migreringen är additiv och går på MariaDB; [[Konton och åtkomst]] § user har kolumnen; hela testsviten är grön.
**Beror på:** 122

### 128. Informationsytan
Mockupens exempelbanner ersätts av korta tips som användaren bläddrar igenom och kan dölja med ett kryss. Samma komponent, `InfoPanel.vue`, står på dashboarden och på containerns översikt ([[ADR-0039 Containerns översikt]] § Konsekvenser). Fyra krav, alla från genomgången:

1. **Det dolda tillståndet lagras på användaren**, inte i webbläsaren. En ny tabell, `dismissed_tip`: `user_id`, `tip_key` och tidsstämplarna, unik på `(user_id, tip_key)`. Tabellen har ingen `ulid`, för raderna syns aldrig i API:et.
2. **Tillståndet lagras per tips.** Ett nytt tips visas alltså även för den som dolt alla tidigare.
3. **Ordningen är bestämd**, inte slumpad. Tipsen är en ordnad lista av nycklar i en klass, och ingen tabell håller dem.
4. **Tipsen är strängar i `lang/en/ui.php`**, en rubrik och en brödtext per nyckel.

Tre tips från start: `containers` (*a container holds everything that belongs together*), `structure` (*an item can hold other items*) och `schedules` (*a schedule reminds you before something is due*). Den exakta texten skrivs efter [[ADR-0032 Produktens ord]]. Att dölja ett tips är en POST med nyckeln, och en okänd nyckel ger 404. När alla tips är dolda ritas ytan inte alls.

**Tabellen hör till personen.** Personraderingen finns inte än, och `DeleteAccount` raderar kontot men aldrig användarraden. `dismissed_tip` ska därför stå i [[Registerförteckning]], så att den kommer med när personraderingen byggs.

**Läs:** [[Att sortera efter mockuparna]] § Dashboarden (*Informationsytan*), [[ADR-0039 Containerns översikt]] § Konsekvenser, [[Datamodell – översikt]], `app/Actions/Item/ListFavorites.php` (förlagan för en tabell per användare)
**Klart när:** dashboarden och containerns översikt visar det första tipset användaren inte dolt; tipsen kommer i den bestämda ordningen; att dölja ett tips gäller i alla webbläsare, eftersom det lagras på servern; ett nytt tips visas för den som dolt alla tidigare; en okänd nyckel ger 404; ytan ritas inte när alla tips är dolda; migreringen följer konventionerna och går på MariaDB; [[Konton och åtkomst]] har ett avsnitt `dismissed_tip`; [[Registerförteckning]] har en rad för tabellen; strängarna ligger i `lang/en/ui.php`; hela testsviten är grön.
**Beror på:** 122
