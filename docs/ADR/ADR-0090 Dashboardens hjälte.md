# ADR-0090 Dashboardens hjälte

**Status:** Antagen 2026-11-21 · Bygger vidare på [[ADR-0076 Profilbilden]], [[ADR-0047 Containerns bild]] och [[ADR-0050 Desktopdesignen]] · Ändrar [[ADR-0087 MCP-server för agenter]] § Riktning 7 (*Inga bilder som rensas direkt*) · [[ADR-index]]

Tonys beslut 2026-11-21, efter testarnas fynd samma dag och en mockup av dashboarden med en bild av havet i solnedgång. Det som står som *utformad av Claude* är detaljer som Claude satte när ADR:n skrevs och som Tony ännu inte har godkänt uttryckligen. Allt annat är Tonys.

## Kontext

- Containern har en hjälte med bild, toning, namn och tal (`ContainerHero.vue`, [[ADR-0050 Desktopdesignen]] § 2–3). Dashboarden har en rubrik i text och brickorna och kostnadsdonuten under den. De två huvudsidorna ser ut som två olika appar.
- Testarnas fynd 2026-11-21: dashboarden ska ha samma huvud som en container. Mockupen visar hela övre raden inne i en bild: hälsningen, rubriken, de tre brickorna (containrar, uppgifter, kostnader i månaden) och donuten med sin förklaring.
- Profilbilden ([[ADR-0076 Profilbilden]]) har redan en bilaga som tillhör en person och en redigerare med fokuspunkt och zoom. Containerns bild har en fokuspunkt men ingen zoom och ingen redigerare med drag.

## Beslut

**1. Dashboarden får en hjälte.** Över `md:` ritas den översta raden inne i en bild: *Welcome back, {förnamn}*, rubriken *Dashboard*, en undertitel, de tre brickorna och kostnadsdonuten med förklaringen. Talen och donuten är desamma som i dag (`DashboardStats`, `CostDonut`). De flyttas in i hjälten utan nya beräkningar och utan en andra väg till samma tal. Saknas kostnader ritas ingen donut, som i dag. Texten står på toningen ur [[ADR-0057 Ikoner och typsnitt]] § 6, som i containerns hjälte, och brickorna ritas i formen `on-image`.

**2. Personen har en egen hjältebild.** `user.hero_attachment_id` pekar på en av personens egna bilagor, med `hero_focus_x`, `hero_focus_y` och `hero_zoom`. Den följer profilbilden i allt, alltså [[ADR-0076 Profilbilden]] § 1–5: bara bilder, högst en levande, en ny bild ersätter den gamla och den gamla rensas direkt med `PurgeAttachment`, bytena räknas mot personkontots kvot och `DeleteUser` rensar den. Bara personen själv ser den. Den laddas upp, beskärs och tas bort på `/settings/profile`, i ett eget avsnitt under profilbilden.

**3. Utan egen bild ritas en inbyggd standardbild.** Bilden levereras med appen under `public/images/`. Den är ingen bilaga, har ingen rad i `attachment` och räknas inte mot någon kvot. Tony tillhandahåller bilden och står för rätten att använda den. Containerns hjälte utan bild är oförändrad: den ritar fortfarande den neutrala ytan ur [[ADR-0047 Containerns bild]].

**4. Utsnittet väljs i en och samma redigerare.** Redigeraren från profilbilden ([[ADR-0076 Profilbilden]] § 7: dra för att placera, reglage för zoom, fokuspunkt och zoom sparas, ingen ny fil) blir en delad komponent. Den används för profilbilden, hjältebilden och containerns bild. Ramen i redigeraren har samma form som ytan bilden ritas i: rund för profilbilden och bred för de två hjältarna.

**5. Containerns bild får zoom.** `container.cover_zoom` (procent 100–300, `NULL` = 100) läggs till bredvid `cover_focus_x`/`cover_focus_y` och nollställs med dem när bilden byts eller tas bort. Containerns bild ritas med fokuspunkt och zoom överallt där den beskärs: i hjälten, på korten och i kartans mitt. En befintlig bild utan värden ritas som i dag.

**6. På mobilen är hjälten låg.** Under `md:` ritas bilden som ett lågt band med hälsningen och rubriken. Brickorna och donuten står under den, som de gör i dag.

**7. Agenten rör inte hjältebilden.** Den rensas direkt som profilbilden, och Riktning 7 i [[ADR-0087 MCP-server för agenter]] gäller den på samma sätt: agenten får inte byta eller ta bort den.

## Konsekvenser

- `user` får tre kolumner och `container` en. [[Konton och åtkomst]] § user och § container beskriver dem.
- `ContainerHero` och den nya dashboardhjälten delar bildlagret, toningen och tutornas form. Den som ändrar det ena ser det andra (*utformad av Claude*: bildlagret blir en gemensam komponent i stället för två kopior).
- Den inbyggda bilden kommer med i varje bygge. Den ska vara komprimerad och ha en bredd som räcker för en bred skärm, men inte mer (*utformad av Claude*: högst 2400 px bred och högst 400 kB).

## Alternativ som valts bort

**Den neutrala ytan som standard, som för containern.** Valdes bort av Tony: dashboarden är det första en ny person ser, och en tom mörk yta ger ett sämre första intryck än en bild.

**Ingen beskärning, `object-cover` mitt i bilden.** Valdes bort av Tony: en bred hjälte skär bort det mesta av en stående bild, och personen behöver kunna välja vad som syns. Samma skäl gäller containerns bild, och därför får den samma redigerare.

**Hela hjälten också på mobilen.** Valdes bort: brickorna och donuten staplade inne i bilden gör hjälten högre än skärmen.

## Tillägg 2026-11-22

*Tony 2026-11-22:* Hjälten ritas på alla sidor i AppLayout som saknar en egen hjälte: `/containers`, `/containers/create`, `/inbox`, `/tasks`, `/tasks/create`, `/documents`, `/history`, `/search`, `/trash`, `/notifications`, `/transfers` och inställningssidorna (en hjälte i SettingsLayout). Den är identisk med dashboardens: samma höjd, bild, brickor och donut. Bara rubriken och undertiteln skiljer sig, och hälsningen står bara på dashboarden. Rubriken står på samma höjd i bilden på varje sida, så att hjälten inte byter storlek och texten inte flyttar sig när man går mellan sidorna. Under `md:` är hjälten det låga bandet, och siffrorna står under det bara på dashboarden. Det ersätter den globala historikens beslut att inte ha någon hjälte (issue 272), och motiveringen att "en hjälte hade påstått att historiken var en container" gäller inte längre. Siffrorna räknas av en delad action och hämtas som en valfri propp, så att de bara räknas på sidor som visar dem. Byggs i issue 437 och 438.

## Tillägg 2026-11-24

*Tony 2026-11-24:* Hjälten svarar på två frågor, inte tre: *vad har jag lagt ut den här månaden?* och *hur mycket utrymme använder jag?*. Det ändrar § 1 och § 6 och byggs i issue 474–477.

**1. Kostnadsdonuten blir en kompakt summering.** Kostnadsbrickorna och `CostDonut` i § 1 ersätts av `HeroCosts`: rubriken *Costs*, perioden *This month* och en rad per valuta med valutan utskriven. Summeringen har ingen donut, ingen förklaring, ingen nedbrytning per container, ingen länk och ingen knapp, och ingenting i den går att klicka på. Höjden beror bara på antalet valutor. Den ritas på dashboarden och på alla sidor med `AppHero`, och också i containerns hjälte med containerns månad. En länk in i rapporten övervägs först när Pro-rapporten för kontot finns; den fasta summeringen är fri enligt [[ADR-0038 Gränsen för Pro i kostnaderna]].

**2. Hjälten får ett lagringskort som länkar till `/settings/storage`.** På sidorna med `AppHero` visar det kontots förbrukning av taket, med filer och text var för sig. Inne i en container — alla flikar och itemvyerna — visar containerns hjälte containerns andel av ägarkontots kvot, med kontots förbrukning som stapel och containerns andel som ett eget segment. Stapeln har ingen minsta bredd: en liten andel får synas knappt eller inte alls, och siffrorna bär beskedet. Dashboarden svarar fortfarande på *hur mycket använder jag?*, och `/settings/storage` svarar på *vad tar utrymmet, och vad kan jag göra åt det?*.

**3. Kvotmodulen från issue 459 lämnar toppraden.** Den står kvar i mobilmenyn, där ingen hjälte visar siffror.

**4. Under `md:` står brickorna, summeringen och lagringskortet under dashboardens band.** Det ändrar § 6. De andra sidorna lämnar telefonens listor i fred, som i dag.

**5. [[ADR-0038 Gränsen för Pro i kostnaderna]] ändras inte.** De fasta summeringarna är fria, och tabellens rad om dashboardens donut gäller en yta som inte längre ritas.
