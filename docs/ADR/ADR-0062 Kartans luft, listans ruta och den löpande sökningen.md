# ADR-0062 Kartans luft, listans ruta och den löpande sökningen

**Status:** Antagen 2026-10-18 · Ändrar [[ADR-0061 Kartans kort och kanter]] § 3 (öppna-ikonen och plusmenyn), § 5 (inga pilspetsar) och § 7 (avstånden och de relaterades sidor) · Ändrar [[ADR-0060 Itemets ikon]] § 9 för listan: bild eller ikon, aldrig båda · Ändrar issue 59a § Beslut 1 för containerns filterrad: den söker medan man skriver · [[ADR-index]]

Tonys beslut 2026-10-18, vid genomgången av kartan i v0.23.1 (`docs/Design/findings/map_20261018_135350.png`) och av itemlistan.

## Kontext

Kartan från [[M35 Itemets ikon och kartans kort]] fungerar, men tre saker drar ner den.

- **Pilspetsarna tillför inget.** Föräldern står ovanför och barnen under, och linjerna har slagets färg. Pilarna ger bara mer brus vid kortens kanter.
- **Korten står trångt.** Avståndet mellan korten är 12 px, och mellan raderna är det ungefär lika mycket. Kurvorna får ingen plats att böja sig och ser kantiga ut.
- **Plustecknet står utanför mittkortet** och tar plats på mittraden.

Dessutom hamnar en ensam relaterad alltid till vänster. De tre första relaterade läggs på vänster sida, så högersidan används först vid den fjärde.

Pilen i kortets hörn (`arrow-up-right`) läses som "gå vidare" och liknar länken på kortet, som centrerar kartan. Ett öga säger tydligare att ikonen *visar* itemet.

I listan ritar `ItemRow` två rutor: omslagets miniatyr, eller den grå rutan, och bredvid den itemets ikon. Ett item utan bild och ikon får två grå rutor efter varandra.

Filterraden ovanför listan, trädet och kartan söker först när man trycker på *Filter*. Den skickar en GET (issue 59a § Beslut 1), men behöver inte vänta på någon knapp för att göra det.

## Beslut

**1. Kartans linjer har inga pilspetsar.** Ingen `<marker>` ritas. Föräldrar och barn får heldragna linjer i sina färger, och de relaterade får streckade linjer. Färgen och strecket skiljer slagen åt, och placeringen visar riktningen. Det ändrar ADR-0061 § 5. *Same item ⇄* i teckenförklaringen gäller platserna och står kvar.

**2. Kartan får luft.** Från `md:` gäller följande avstånd:
- 24 px mellan korten i sidled (`gap-6`), både bland föräldrarna och bland barnen.
- 24 px mellan barnens rader.
- 56 px mellan raderna föräldrar, mitten och barnen (`mt-14`).
- 48 px mellan mitten och de relaterades kolumner (`gap-12`).

Barnen står fortfarande högst fyra per rad. Därför växer maxbredden så att den rymmer fyra kort och tre mellanrum. Under `md:` gäller avstånden i dag.

**3. De relaterade läggs på varannan sida**, i serverns ordning: den första till vänster, den andra till höger, den tredje till vänster och så vidare. Två relaterade blir då en på varje sida. Den mittersta på en sida står fortfarande längst ut. *+N till* står kvar sist i den högra kolumnen. Serverns ordning ändras inte, den delas bara upp på ett annat sätt.

**4. Öppna-ikonen är ett öga.** Rollen `open` i `resources/js/icons/index.js` byter glyf från `arrow-up-right` till Lucides `eye`. Rollnamnet står kvar ([[ADR-0057 Ikoner och typsnitt]] § 1), och `aria-label` är oförändrad. Rollen används bara i kartans kort.

**5. Plusmenyn sitter i mittkortet.** Plustecknet står i mittkortets nedre högra hörn och ögat i det övre högra hörnet. Båda är egna mål för tangentbordet med 44 px träffyta, i ordningen kortet, ögat och plustecknet. Kring containern finns inget öga, och plustecknet står ändå i det nedre hörnet. Mittkortet är högt nog för båda hörnen. Utanför kortet står ingenting på mittraden utom de relaterade. Det ändrar ADR-0061 § 3.

**6. Listans rad har en ruta, och den visar en sak.** Rutan är 48 px och ritar det första som finns i den här ordningen:
1. en **vald** omslagsbild med miniatyr
2. itemets **ikon**, med `ItemIcon` i `tile` och storleken 24
3. den **automatiska** omslagsbilden med miniatyr, alltså den äldsta bilden
4. den grå rutan med den neutrala `item`-ikonen

Det användaren uttryckligen valt går före det automatiska. En vald ikon skyms inte av ett foto som råkar vara äldst, men en vald omslagsbild vinner över ikonen. En bild utan `thumb` räknas som ingen bild, som i dag (issue 61b § Beslut 1). `ResolveItemCover::thumbnails()` får nyckeln `chosen`, som är sann när bilden är itemets valda omslag och falsk när den är den automatiska. Det ändrar ADR-0060 § 9 för listan. Trädet och kartan ritar fortfarande bara ikonen.

**7. Filterraden söker medan man skriver.** Raden ovanför listan, trädet och kartan gör följande:
- **Sökfältet** skickar filtret 300 ms efter den senaste tangenttryckningen.
- **Kategorin och taggarna** skickar filtret direkt när de ändras.
- **Enter** skickar direkt och tar bort den väntande fördröjningen.
- **Knappen *Filter* försvinner.**

Ett sökord som efter trimning är detsamma som det servern redan tillämpat skickas inte. En ny sökning ersätter en som fortfarande är på väg, så att ett äldre svar aldrig skriver över ett nyare. Fältet stängs aldrig medan svaret är på väg, och fokus och markör står kvar. Sökningar från fältet ersätter den senaste posten i historiken (`replace`), så att bakåtknappen inte går tillbaka en bokstav i taget. Kategorin, taggarna, chipsens kryss och *Clear* lägger till en post som i dag.

Adressen är densamma som förut (`?q=…&tags[]=…&category=…`), och servern ändras inte. Skalets sökfält och `/search` söker fortfarande vid Enter (issue 59b § Beslut 5), eftersom de söker i allt och ritar en hel sida.

**8. Filterraden är responsiv.** Under `sm:` tar sökfältet och kategorin hela bredden var för sig, och taggarna bryts under dem. Från `sm:` växer sökfältet för att fylla raden, och kategorin behåller sin bredd. Fälten har 44 px höjd och designsystemets tokens ([[ADR-0042 Designsystemet]]) i stället för radens gamla `slate`- och `blue`-klasser.

## Motivering

Pilspetsarna togs in i ADR-0061 för att en böjd linje inte visar riktningen lika tydligt. Med mer luft står raderna så tydligt isär att placeringen räcker igen.

Varannan sida ger kartan balans redan vid två relaterade, och det är det vanligaste fallet. Att fylla vänster sida först gav en tom högersida i nästan alla kartor.

Listans ordning följer en enkel regel: ett val slår en gissning. Den automatiska bilden är systemets gissning, ikonen och den valda bilden är användarens val.

300 ms räcker för att ett ord ska hinna skrivas klart innan anropet går. Servern söker med `LIKE` i en container, och det klarar ett anrop per ord.

## Konsekvenser

- Issue 308–311 i [[M36 Kartans luft, listans ruta och sökningen]].
- `ItemMapEdges.vue` förlorar sina `<defs>`. `ItemMap.vue` får nya avstånd och fördelningen på varannan sida.
- `ItemMapNode.vue` flyttar in plusknappen i kortet. `resources/js/icons/index.js` byter glyf för `open`.
- `ResolveItemCover::thumbnails()` får en nyckel till. Uppgiftslistan läser samma svar och ignorerar den.
- `ItemFilterBar.vue` förlorar sin submitknapp, och `item.index.filter_submit` tas bort ur `lang/en/ui.php`.
- `FocusMap` i itemets högerpanel rörs inte. Den har sin egen form för en smal panel.

## Alternativ

**Behålla pilarna på de relaterade, åt båda hållen.** Valdes bort: en ensam dubbelpil på bara ett slag är svårare att läsa än inga pilar alls.

**Fylla vänster sida först, som i dag.** Valdes bort enligt motiveringen.

**Bilden vinner alltid över ikonen i listan.** Regeln är enklare att förklara, men en vald ikon hade skymts av den äldsta bilden. Tony valde ordningen i beslut 6.

**Visa både bild och ikon i listan, som i dag.** Valdes bort: två rutor per rad gör listan rörig, och två grå rutor säger ingenting.

**Söka i klienten utan anrop.** Valdes bort: servern äger urvalet (issue 59a § Beslut 2), och taggar, kategorier och behörigheter filtreras där.
