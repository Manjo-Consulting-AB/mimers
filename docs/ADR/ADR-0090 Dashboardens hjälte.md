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
