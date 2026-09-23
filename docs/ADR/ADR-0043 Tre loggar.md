# ADR-0043 Tre loggar

**Status:** Antagen 2026-09-23, IP-adressen pseudonymiserad samma dag · Bygger vidare på issue 40 (revisionsloggen, [[M6 Resten av MVP]]) och [[ADR-0017 Missbruksvektorer]] § Mätningen · Löser ut instrumenteringen som [[ADR-0039 Containerns översikt]], [[ADR-0041 Itemets vy]] och [[ADR-0042 Designsystemet]] lämnade · [[ADR-index]]

Fattat efter retron för M12–M17. Uppdelningen i tre loggar, tidsgränserna och den rättsliga spärren är Tonys beslut 2026-09-23, efter ett första utkast som sparade en enda logg för evigt.

## Kontext

Loggen ska tjäna fyra syften, och de kräver olika saker:

1. **Användaren** ska se vem som gjort vad med det användaren äger. Designerns bilder har tre sådana ytor: dashboardens händelsepanel, containerns historikflik och itemets historikflik.
2. **Utvecklingen** ska kunna mäta hur systemet används över lång tid.
3. **Missbruket** ska gå att upptäcka och utreda. [[ADR-0017 Missbruksvektorer]] har en nattlig rapport, men den räknar ur produkttabellerna eftersom ingen logg finns.
4. **Rättsliga krav** ska kunna mötas när något olagligt händer.

Syfte 1 behöver detaljer så länge det användaren äger finns. Syfte 2 behöver lång tid men inga personer. Syfte 3 behöver kunna känna igen samma källa bakom flera konton och inloggningar, men inte veta vilken adress källan har. En enda tabell kan inte vara både evig och personuppgiftsbärande utan att bryta mot lagringsbegränsningen i GDPR artikel 5.1 e.

I dag finns en tabell, `audit_log`, med en enda väg in, `RecordAuditEvent`. Den skrivs från två ställen: `AcceptOwnershipTransfer` och `RevokeContainerAccess`. **Den blockerar dessutom redan gallringen.** `container_id`, `user_id` och `account_id` har `ON DELETE RESTRICT`. `PurgeContainer` rensar åtkomster, inbjudningar och kalenderflöden men inte loggen. En container med en loggrad fäller därför den nattliga gallringen varje natt när containern ska bort. I dag är det sällsynt. När varje handling skriver en rad blir det varje container.

### Vad lagen kräver, som vi läser den

**Ingen lag kräver att Mimers loggar i förväg.** Kravet på datalagring i lagen om elektronisk kommunikation gäller den som tillhandahåller elektronisk kommunikation, alltså operatörer. En tjänst där användaren lagrar sitt eget innehåll omfattas inte av det.

**EU:s förordning om digitala tjänster (DSA) gäller.** Mimers är en *värdtjänst*: vi lagrar information som användaren lämnar. Innehållet sprids inte till allmänheten, så vi är inte en *onlineplattform*, och de tyngre plattformskraven gäller oss inte. Tre artiklar gäller alla värdtjänster oavsett storlek:

- **Artikel 16:** vem som helst ska kunna anmäla innehåll som den anser vara olagligt, och vi ska hantera anmälan.
- **Artikel 17:** begränsar vi en användares innehåll ska vi motivera beslutet för användaren.
- **Artikel 18:** misstänker vi ett brott som hotar någons liv eller säkerhet ska vi underrätta polisen.

Myndigheter kan också beordra oss att agera mot innehåll eller att lämna ut uppgifter (artikel 9 och 10), och en svensk åklagare kan besluta om ett bevarandeföreläggande. **Ingen av reglerna säger hur länge vi ska spara något. Alla förutsätter att det som finns när frågan kommer inte försvinner medan den utreds.** Det är vad den rättsliga spärren nedan finns för.

Läsningen är vår egen och inte en juristbedömning. Den bör granskas av en jurist före lansering, men beslutet vilar inte på att det har skett.

## Beslut

**Tre loggar med var sitt syfte, var sin läsare och var sin livslängd.**

| | Händelseloggen | Säkerhetsloggen | Mätningen |
|---|---|---|---|
| **Tabell** | `audit_log`, den befintliga | `security_log`, ny | `usage_metric`, ny |
| **Svarar på** | Vem gjorde vad med det jag äger? | Missbruk, intrång och olagligt innehåll | Hur används systemet? |
| **Läses av** | Användaren, enligt läsregeln nedan | Bara vi, utom användarens egna inloggningar | Bara vi |
| **Personuppgifter** | Användarens id, aldrig fritext | Användarens id, en pseudonym för IP-adressen och ett tolkat enhetsnamn | Inga |
| **Sparas** | Så länge containern finns, plus 12 månader | 12 månader | För evigt |

### Händelseloggen

**Varje skrivning loggas.** Allt som skapar, ändrar, raderar, återställer, delar, överlåter eller bockar av något i en container. Läsningar loggas inte här.

**En ändring loggas med vilka fält som ändrades, inte med vad de innehåller.** Raden säger *namn och anteckning ändrades*, inte vad som stod där. För fält med en värdelista eller ett tal, som `recurrence_type`, belopp och datum, följer gamla och nya värdet med. Fritext följer aldrig med: inte namn, beskrivningar, anteckningar, filnamn, leverantörer eller e-postadresser. Namn slås upp när raden visas, genom samma omfång som resten av vyn. Loggen blir då aldrig ett andra register över det användaren skrivit.

**Loggen överlever det den handlar om.** `account_id`, `user_id` och `container_id` förlorar sina främmande nycklar och blir identifierare, samma sak som `subject_id` redan är. En tillkommande kolumn, `item_id`, är satt på varje händelse som hör till ett item, oavsett subjekt. Indexen blir `(container_id, created_at)`, `(item_id, created_at)` och `(user_id, created_at)`.

**Läsregeln.** En rad är läsbar för en användare när minst ett av följande gäller:

1. Användaren är medlem i kontot som **äger** radens container. Då syns alla rader i containern och i dess items, vem som än handlade.
2. Raden är användarens **egen**, och användaren når fortfarande radens container, eller radens item när raden gäller ett item. Omfånget prövas genom `ResolveItemScope`.
3. Raden saknar container och gäller ett konto användaren är medlem i.

En gäst ser alltså sina egna handlingar och inget annat. Den som förlorat åtkomsten ser inte ens sina egna rader: loggen får inte bli en väg tillbaka in i något som stängts.

**Livslängden följer containern.** När en container gallras skriver `PurgeContainer` en sista rad, `container.purged`. Tolv månader efter den raden tar gallringen bort alla containerns rader. Rader utan container följer kontot på samma sätt, genom `account.deleted`. Ingen rad ändras under tiden: raderna tas bort hela, eller inte alls.

### Säkerhetsloggen

**Den loggar det som rör konton och det som lämnar systemet:** lyckade och misslyckade inloggningar, inlösta magic links, tvåfaktor som slås på eller av, byte av lösenord och e-post när de finns, skickade inbjudningar, exporter, webhooks som skapas och tas bort, tömd lagring, och **nedladdningar av filer ur en container som användaren inte äger**. Det sista är den enda läsning som loggas. Det är genom den som innehåll sprids vidare via delning.

**Ingen rå IP-adress och ingen rå webbläsarsträng sparas.** IP-adressen skrivs som en pseudonym, `ip_group`: de första sexton hexatecknen av `hash_hmac('sha256', $ip, config('app.key'))`. Det är samma formel som missbruksrapporten redan använder, så grupperna i rapporten och i loggen är samma grupper. Webbläsarsträngen tolkas när raden skrivs till ett kort enhetsnamn, som *Firefox · macOS*, och själva strängen kastas.

Pseudonymen räcker till allt loggen finns för: att se att samma källa står bakom många konton, att se många inloggningsförsök från ett ställe, och att märka att ett konto plötsligt loggar in från en ny källa. Den räcker också när en myndighet frågar om en viss adress: vi räknar fram samma pseudonym och kan svara om adressen förekommit och i så fall för vilket konto. Det enda den inte kan är att svara på *vilken adress hade konto X?*. Ingen regel vi känner till kräver att vi kan det.

**Nyckeln är det som gör pseudonymen till en pseudonym.** En vanlig hash av en IPv4-adress går att vända genom att pröva alla fyra miljarder adresser. Med `APP_KEY` som nyckel går det inte. En byte av `APP_KEY` bryter kopplingen mellan gamla och nya rader, precis som för rapporten.

**Raden tas bort efter 12 månader.** Tolv månader räcker för att se mönster över ett år, till exempel säsongsmissbruk och vilande konton som vaknar. Tiden vilar på berättigat intresse. En pseudonym är fortfarande en personuppgift enligt GDPR, men en läckt tabell avslöjar ingen adress.

**Användaren ser sina egna inloggningar**, med tid, ungefärlig enhet och om de lyckades, i kontoinställningarna. IP-adressen visas inte. Det kostar lite och är det bästa skyddet mot ett kapat konto: användaren upptäcker det själv.

### Mätningen

**Ett nattligt jobb räknar ihop gårdagens rader i de två andra loggarna till `usage_metric`:** antal per dag, handling och plan. Tabellen har inget användar-id, inget konto-id, inget container-id och ingen IP. Den är anonym och sparas för evigt.

**Ingen grupp under fem räknas ut.** En rad som säger att *en* användare på en viss plan gjorde en viss sak en viss dag kan peka ut en person. Grupper under fem slås ihop med `other`. Tröskeln kan höjas men aldrig sänkas utan en ny ADR.

Jobbet måste gå innan gallringen tar raderna. Det kör därför före gallringen varje natt, och en natt som missats räknas i efterhand så länge raderna finns kvar.

### Den rättsliga spärren

**En spärr på ett konto stoppar all gallring av kontots innehåll och loggar tills den hävs.** Den sätts när en anmälan enligt DSA artikel 16 behöver utredas, när en myndighet begär det, eller när vi själva misstänker ett brott.

Spärren stoppar:

- gallringen av händelseloggen och säkerhetsloggen för kontot,
- papperskorgens gallring av kontots containrar, items och bilagor,
- kontoraderingen och gallringen av vilande konton,
- gallringen av lagrade filer som kontots bilagor pekar på.

**Spärren syns inte för användaren.** Allt fungerar som vanligt för användaren, och det som raderas hamnar i papperskorgen och försvinner ur vyerna som vanligt. Det enda som ändras är att det inte gallras. Att visa spärren kunde varna den som är föremål för en utredning.

**Spärren sätts och hävs bara från serverns kommandorad**, med ett ärendenummer och en anledning. Varje åtgärd skriver en rad i säkerhetsloggen. Spärren har ingen yta i webben. Den som kan sätta den ska inte kunna göra det av misstag.

**En hävd spärr lämnar sin rad kvar.** Den hävs genom att `lifted_at` sätts, och raden i `legal_hold` tas aldrig bort. Att en spärr en gång funnits är i sig en uppgift värd att bevara.

## Motivering

**Tre tabeller är billigare än en tabell med tre regler.** Med en tabell hade varje fråga behövt veta vilka rader den får läsa och varje gallring vilka rader den får ta bort. Med tre tabeller är svaret tabellens namn.

**Mätningen gör den långa lagringen onödig.** Utvecklingen behöver mönster, inte människor. Anonyma summor omfattas inte av GDPR, och därför kan de sparas för evigt utan att någon behöver försvara det.

**Korta lagringstider är säkra först när spärren finns.** Utan spärren finns bara två dåliga val: spara allt länge för säkerhets skull, eller riskera att bevis gallras mitt i en utredning. Spärren gör att den vanliga tiden kan vara så kort som syftet kräver.

**Främmande nycklar är fel verktyg för en logg.** En nyckel säger att raden inte får finnas utan det den pekar på. Loggens poäng är motsatsen. `subject_id` löste det redan genom att vara en sträng utan nyckel. De tre andra kolumnerna fick nycklar av vana, och det är den vanan som nu fäller gallringen.

**Läsregeln följer ägandet och inte åtkomsten.** Regel 1 är densamma som `ContainerPolicy::viewAuditLog()` redan har. I en delad container är det ägarens sak att veta vem som gjort vad. Regel 2 är ny: i dag får en gäst `403` på hela loggen, men de egna handlingarna är ingen hemlighet för gästen själv.

**Adressen behövs inte, bara att den är densamma.** Varje användning av IP-adressen i den här ADR:en är en jämförelse: samma källa, många konton; samma konto, ny källa. En jämförelse fungerar lika bra mellan pseudonymer som mellan adresser. Att spara adressen i 90 dagar för att sedan nollställa den hade kostat ett gallringssteg och gett en uppgift vi inte har någon användning för.

**Nedladdningar är den enda läsning som loggas.** Att logga varje visning vore en logg över allt alla tittar på. En nedladdning ur någon annans container är däremot när innehåll lämnar sin ägare, och det är där både dataintrång och spridning av olagligt material syns.

## Konsekvenser

- **Rättelsen går först.** Nycklarna släpps och `container.purged` skrivs innan en enda ny handling loggas. Annars gör instrumenteringen gallringsfelet universellt.
- **Skrivningarna bor ofta på två ställen.** Items, scheman och lån skapas i både webbens och API:ts controllers utan en gemensam Action. Där bryts en Action ut innan handlingen loggas, enligt [[ADR-0024 Tunna controllers och actions]]. Raden skrivs alltid i handlingens transaktion, aldrig av en modellobservatör: en observatör ser `save()` och inte handlingen, och den kan inte skilja ett klick från en gallring.
- **API-svaret för en gäst ändras.** `GET /api/containers/{container}/audit-log` ger en gäst gästens egna rader i stället för `403`.
- **[[Registerförteckning]] får tre rader:** `audit_log`, `security_log` och `legal_hold`, med gallringen ovan och berättigat intresse som grund. `usage_metric` innehåller inga personuppgifter och står inte där.
- **Backuperna måste följa samma tider.** En gallrad rad som ligger kvar i en backup är inte gallrad. [[Återläsning]] tillämpar redan raderingar igen efter en återläsning, och samma sak ska gälla loggarna.
- **Personraderingen rör inte loggarna när den byggs.** Användarraden raderas, och `user_id` blir en siffra som inte pekar på någon. En sådan rad visas som *en tidigare användare*.
- **DSA kräver mer än loggar.** Anmälningsvägen enligt artikel 16 och motiveringen enligt artikel 17 är egna funktioner med egna ytor. De har ingen issue än och står i [[Att sortera efter mockuparna]] § Ännu inte issues.
- **Dashboarden kan byggas.** Dess händelsepanel är händelseloggen med läsregeln, över alla användarens konton. Den blir en egen milstolpe efter [[M18 Loggarna]].
- **Registrerings-IP:n sparas fortfarande rå i 90 dagar**, enligt [[ADR-0017 Missbruksvektorer]]. Den kan läggas om till samma pseudonym senare, men den här ADR:en rör den inte.
- **Webbhotellets åtkomstloggar** innehåller sannolikt råa IP-adresser oavsett vad appen gör. Hur länge inleed sparar dem står inte i valvet; svaret hör hemma i [[Registerförteckning]].
- **Missbruksrapporten får en bättre källa.** Den räknar i dag ur produkttabellerna. Säkerhetsloggen ger den inloggningar, exporter och nedladdningar direkt. Rapporten förblir skrivskyddad enligt issue 50b.

## Alternativ

**En logg som sparas för evigt.** Det första utkastet. Avvisat: den bär personuppgifter utan tidsgräns, och utvecklingen behöver inte personerna.

**En logg med olika gallring per radtyp.** Avvisat: varje fråga och varje gallring måste då veta vilka rader den får röra, och ett fel i den kunskapen läcker eller raderar fel sak.

**Fast gallringstid för händelseloggen oberoende av containern.** Avvisat: användaren behöver historiken så länge det användaren äger finns, och en container kan leva i tio år.

**Behålla främmande nycklar med `ON DELETE SET NULL`.** Då överlever raden, men den skrivs om av databasen och tappar sin container. Det tömmer historiken just när den behövs, efter en radering.

**Låta gäster läsa hela containerns logg.** Avvisat: i en delad container är vetskapen om vem som gjort vad ägarens.

**Spara IP-adressen rå i 90 dagar och nollställ den sedan.** Det första beslutet. Avvisat samma dag: varje användning är en jämförelse som pseudonymen klarar, och den råa adressen hade varit en personuppgift utan användning.

**En vanlig hash av IP-adressen utan nyckel.** Avvisat: den går att vända genom att pröva alla adresser.

**En spärr som syns för användaren.** Avvisat: den kan varna den som utreds, och ingen DSA-regel kräver att en bevarad uppgift märks.
