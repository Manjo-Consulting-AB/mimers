# ADR-0039 Containerns översikt

**Status:** Antagen 2026-09-18 · Ersätter containerns förstasida i issue 57a § Beslut 1 och exportens placering i issue 67c § Beslut 1 · [[ADR-index]]

Fattat vid genomgången av containermockupen. Issue 57a gjorde itemlistan till containerns förstasida. Den här ADR:n sätter en översikt där i stället och flyttar listan till en egen URL.

## Kontext

`GET /containers/{container}` är i dag itemlistan, renderad av `ItemController::index()`. Beslutet togs i issue 57a § Beslut 1 med motiveringen *"itemen är containern"*, och det var rätt när containern bestod av items och ingenting annat.

Sedan dess har containern fått kostnader (issue 46), bilagor (60 och 61), scheman (63), delning (55), export (67c) och en historik (40). Ingenting av det syns på förstasidan. Den som öppnar sin container möter en bokstavslista över items och får leta i en sektionsmeny med nio rader efter allt annat.

Containermockupen svarar med en översiktssida: ett huvud med containerns namn och art, fyra räknande brickor, en flikrad, och paneler för kommande underhåll, kostnader, bilder, items, historik och containerns egna uppgifter. Itemlistan blir en flik bland sju.

Det är samma flytt som dashboarden gör med todo-vyn: en sida som var hela produkten blir en del av den. Skillnaden är att den här flytten går emot ett skrivet beslut och därför måste skrivas ned.

## Beslut

**`GET /containers/{container}` är översikten. Itemlistan flyttar till `GET /containers/{container}/items`.** Ruttnamnet `containers.show` följer med översikten — det är containerns sida, och det var det hela tiden.

**Varje tal på sidan räknar det användaren själv når.** Brickan som säger *12 Items* räknar de items hon når, och är därmed exakt lika lång som listan på Items-fliken. Samma regel för uppgifter, för kostnader och för allt annat som räknar. Ingen totalsumma, ingen *av N*, ingen upplysning om att något dolts — [[ADR-0028 Åtkomst på itemnivå]] och issue 73 § Beslut 6 gäller oförändrat.

**Uppgifter och underhåll är en sak.** En bricka, en flik, en lista. `schedule` har inget fält som skiljer dem åt, och det ska den inte få: skillnaden mellan *en uppgift* och *ett underhåll* är domänen, och [[ADR-0033 Produktens omfång]] håller domänen utanför schemat. Mockupens två brickor och två flikar är en teckning, inte ett krav.

**Containern får `description`.** En fritextbeskrivning, nullbar, vid sidan av `name` och `kind`. Inget kortnamn och ingen tredje namnrad: mockupens formaterade underrubrik — modell och årtal med en punkt emellan — utgår, eftersom den formen bara går att generera ur fält som inte finns och inte ska finnas.

**Arten visas som sitt eget värde, under etiketten *Kind*.** Mockupen kallar fältet *Kategori*, och det ordet är upptaget av kategoriträdet på items. Ett ord, en betydelse ([[ADR-0032 Produktens ord]]). Eftersom [[ADR-0036 Containerns art]] gör fältet fritt finns det ingen översättningsnyckel att slå upp: vyn skriver ut strängen användaren matat in, rakt av.

**Händelsepanelen är reserverad yta och byggs inte nu.** `audit_log` skrivs i dag på exakt två ställen — `container.transferred` och `access.revoked`. Panelens innehåll kräver att hela applikationen instrumenteras, och det är ett eget arbete med egna beslut. Ytan finns i layouten, tom, och fylls när instrumenteringen finns.

**Exporten flyttar in under containerns inställningar.** Issue 67c § Beslut 1 la den i sektionsnavigeringen med motiveringen *"en utgång ingen hittar är samma sak som en inlåsning"*. Med en flikrad på sju poster och en inställningssida bakom dem är inställningarna den logiska platsen, och utgången är fortfarande skyltad.

## Motivering

**En förstasida ska svara på *hur står det till*, inte på *vad finns här*.** Bokstavslistan svarar på den andra frågan, och den frågan har en flik. Den som öppnar sin container efter tre veckor vill veta vad som förfaller, vad det kostat och vad som hänt — inte läsa sextio radnamn i alfabetisk ordning.

**Räkneregeln räddar brickorna utan att öppna läckan.** Ett tal som säger *hur många du når* är längden på användarens egen lista, och avslöjar därför ingenting hon inte redan ser. Ett tal som säger *hur många som finns* avslöjar exakt det [[ADR-0028 Åtkomst på itemnivå]] § Konsekvenser stänger: att det finns något hon inte får se. Skillnaden är en `WHERE`-klausul och hela poängen.

**Beskrivningen är det minsta fält som gör huvudet användbart.** Mockupen vill visa något mer än namnet, och alternativen är ett fält användaren fyller i eller flera fält systemet påstår sig veta — modell, årtal, tillverkare. Det senare är att bygga in domänen i containern, vilket [[ADR-0033 Produktens omfång]] förbjuder på samma sätt som det förbjöd artens värdelista.

**Den tomma händelsepanelen är ärligare än en påhittad.** Att bygga panelen mot två händelsetyper ger en ruta som säger *ingenting har hänt* åt en användare som just lagt in trettio items. Att först instrumentera appen är rätt ordning, och att göra det inuti en vy-issue är fel ställe.

## Konsekvenser

- **Fyra länkar och en omdirigering pekar om.** `TodoRow.vue`, `Search.vue`, `Containers/Index.vue` och `containerSections.js` länkar i dag till `/containers/{ulid}` och menar itemlistan; `ItemController::destroy()` omdirigerar dit efter radering. Tre tester slår fast URL:en och följer med.
- **Sektionsmenyn ersätts av en flikrad.** De nio raderna i `containerSections.js` blir sju flikar — översikt, items, dokument, uppgifter, kostnader, historik och en till — plus en inställningssida som bär kategorier, taggar, delning, kalender, export, papperskorg och överlåtelse. Fördelningen är designarbete; att ingen rad får försvinna är ett krav.
- **`container` får en kolumn**, `description`, nullbar text. `ContainerResource` bär den, och de två FormRequests validerar längd.
- **Dokumentfliken och bildpanelen behöver inget nytt fält.** `attachment.kind` är redan `image`, `document` eller `other`. Båda ytorna är unionen över containerns items inom omfånget, och filerna levereras enligt [[ADR-0019 Filleverans]].
- **Historikfliken har redan sitt index.** `audit_log` är indexerad på `(container_id, created_at)`, vilket är exakt den frågan — till skillnad från dashboardens användarfiltrerade panel, som behöver ett nytt.
- **Containerns foto har fortfarande ingen plats.** `attachment.item_id` är `NOT NULL`, så en container kan inte äga en fil. Mockupens hjältebild kräver antingen en nullbar `container_id` på `attachment` eller ett eget fält, och frågan avgörs inte här.
- **Kostnadsdonutens indelning avgörs i [[ADR-0040 Underträdets summor]].** `cost_entry` har ingen kategorikolumn, och mockupens fyra tårtbitar finns inte i datan.
- **Informationsrutan är dashboardens.** Samma komponent, samma fyra krav — dolt tillstånd på användaren och inte i webbläsaren, per meddelande, bestämd ordning, strängar i `lang/`.
- **Texten hör till `lang/`** och därmed efter [[M13 Omskrivningen]], som allt annat användaren läser.

## Alternativ

**Behålla itemlistan som förstasida och lägga översikten på en egen URL.** Ingen flytt, inget brutet beslut, inga omdirigeringar. Valdes bort — då är översikten en sida användaren måste hitta, och den sida hon faktiskt landar på är den som svarar sämst på varför hon kom.

**Lägga översiktens paneler ovanför itemlistan på samma sida.** Sparar en URL. Valdes bort — sidan blir två sidor staplade, listan hamnar under vikningen på varje besök, och filtret i querysträngen (issue 59a § Beslut 1) skulle dela adress med en översikt som inte har något med filtret att göra.

**Dela uppgifter och underhåll med en ny kolumn på `schedule`.** Ger mockupens två brickor. Valdes bort — det är domänen i schemat, och skillnaden mellan orden är användarens och inte systemets. Samma svar gavs redan vid dashboardgenomgången.

**Ge containern strukturerade fält för modell, årtal och tillverkare.** Hade gett mockupens underrubrik exakt. Valdes bort av samma skäl som värdelistan i [[ADR-0036 Containerns art]]: en båt har modell och årsmodell, ett kundprojekt har det inte, och fälten blir tomma kolumner som påstår vad en container är.

**Bygga händelsepanelen mot de två befintliga händelsetyperna.** Valdes bort — panelen hade varit tom för alla utom den som just överlåtit en container eller fått en åtkomst indragen, vilket är två av produktens mest sällsynta handlingar.
