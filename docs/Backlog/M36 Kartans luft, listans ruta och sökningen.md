# M36 · Kartans luft, listans ruta och sökningen

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-10-18, efter genomgången av kartan i v0.23.1 (`docs/Design/findings/map_20261018_135350.png`) och av itemlistan. Det här ingår: kartans linjer utan pilspetsar, mer luft mellan korten och de relaterade på varannan sida, ögat i stället för pilen och plusmenyn inne i mittkortet, listans ruta som visar bild eller ikon men aldrig båda, och filterraden som söker medan man skriver. Besluten står i [[ADR-0062 Kartans luft, listans ruta och den löpande sökningen]].

**Ordningen:** alla fyra står fritt. 308 och 309 rör båda `ContainerkartaTest.php`, så 309 väntar på 308 för att inte krocka i testfilen.

**Detta ingår inte:** fokuskartan i itemets högerpanel (`FocusMap`), skalets sökfält och `/search` (issue 59b § Beslut 5 gäller), ikonen i trädet och kartan (den ritas som förut) och serverns sökning, som är oförändrad.

---

### 308. Kartans linjer och luft

GitHub #928. `ItemMapEdges` ritar inga pilspetsar. `<defs>` och alla `<marker>` tas bort, och ingen linje har `marker-start` eller `marker-end`. Färgerna och strecket på de relaterade står kvar. `ItemMap` får avstånden i [[ADR-0062 Kartans luft, listans ruta och den löpande sökningen]] § 2 från `md:`: `gap-6` mellan korten bland föräldrarna och barnen, 24 px mellan barnens rader, `mt-14` mellan raderna föräldrar, mitten och barnen, och `gap-12` mellan mitten och de relaterades kolumner. Maxbredden för föräldrar och barn växer så att den rymmer fyra kort (`w-48`) och tre mellanrum på 24 px. Barnen står fortfarande högst fyra per rad.

De relaterade fördelas på varannan sida i serverns ordning (§ 3): index 0, 2 och 4 till vänster och 1, 3 och 5 till höger. Den mittersta på varje sida, alltså den andra som står där, skjuts fortfarande ut. *+N till* står kvar sist i den högra kolumnen. Under `md:` är allt som i dag.

**Läs:** [[ADR-0062 Kartans luft, listans ruta och den löpande sökningen]] § 1–3, [[ADR-0061 Kartans kort och kanter]] § 5–8, `resources/js/components/ItemMap.vue` (docblocken), `resources/js/components/ItemMapEdges.vue` (docblocken)

**Klart när:** lagret har ingen `<marker>` och ingen linje har en markör; linjerna har kvar sina färger och de relaterade sitt streck; avstånden från `md:` är de i ADR-0062 § 2; fyra barn står på en rad; de relaterade står på varannan sida i serverns ordning, och en ensam relaterad står till vänster och två står en på varje sida; den mittersta på en sida skjuts ut; under `md:` är kartan oförändrad; hela testsviten är grön.

**Beror på:** —

### 309. Kartans kort: ögat och plusmenyn

GitHub #929. Rollen `open` i `resources/js/icons/index.js` byter glyf till Lucides `eye`, kopierad ur `lucide` 1.59.0 i samma nodform och med samma kommentarsrad som de andra posterna. Rollnamnet står kvar, och `aria-label` är oförändrad. Plusknappen i `ItemMapNode` flyttar in i mittkortets ram och står i det nedre högra hörnet. Ögat står kvar i det övre högra hörnet. Båda har 44 px träffyta, och tabbordningen är kortet, ögat och plustecknet. Kring containern finns inget öga, och plustecknet står ändå i det nedre hörnet. Mittkortet får så mycket utrymme i höger kant att namnet aldrig hamnar under hörnens knappar. Ingenting står utanför mittkortet på mittraden utom de relaterade.

**Läs:** [[ADR-0062 Kartans luft, listans ruta och den löpande sökningen]] § 4 och 5, [[ADR-0057 Ikoner och typsnitt]] § 1, `resources/js/components/ItemMapNode.vue` (docblocken), `resources/js/icons/index.js` (docblocken och posten `open`)

**Klart när:** `open` ritar `eye`; ögat sitter i kortets övre högra hörn med oförändrad `aria-label`; plustecknet sitter i mittkortets nedre högra hörn, inne i kortets ram; ingen plusknapp ritas utanför kortet; ett kort utan rader har ingen plusknapp; kring containern finns plustecknet men inget öga; tabbordningen är kortet, ögat och plustecknet; hela testsviten är grön.

**Beror på:** 308

### 310. Listans ruta: bild eller ikon

GitHub #930. `ResolveItemCover::thumbnails()` får nyckeln `chosen` på varje omslag. Den är `true` när bilden är itemets `cover_attachment_id` och `false` när den är den automatiska. Inga nya frågor. `ItemRow` ritar en enda ruta på 48 px i ordningen i [[ADR-0062 Kartans luft, listans ruta och den löpande sökningen]] § 6:
1. en vald bild med `hasThumb`
2. `ItemIcon` med `tile` i storleken 24 när `item.icon` finns
3. en automatisk bild med `hasThumb`
4. `UiIcon` `item` i `neutral`

Den andra rutan bredvid tas bort. Uppgiftslistan läser samma svar och ritas oförändrad.

**Läs:** [[ADR-0062 Kartans luft, listans ruta och den löpande sökningen]] § 6, `app/Actions/Item/ResolveItemCover.php` (docblocken och `thumbnails`), `resources/js/components/ItemRow.vue` (docblocken), `resources/js/components/ItemIcon.vue` (docblocken)

**Klart när:** `thumbnails()` bär `chosen` som är sann för en vald bild och falsk för en automatisk; raden ritar exakt en ruta; en vald bild visas även när itemet har en ikon; en ikon visas före en automatisk bild; en automatisk bild visas när ikonen saknas; ett item utan bild och ikon visar den grå rutan; en bild utan `thumb` räknas som ingen bild; rutan är 48 px i alla fyra fallen; antalet frågor för listan är oförändrat; hela testsviten är grön.

**Beror på:** —

### 311. Filterraden söker medan man skriver

GitHub #931. `ItemFilterBar` skickar filtret utan knapp ([[ADR-0062 Kartans luft, listans ruta och den löpande sökningen]] § 7). Sökfältet skickar 300 ms efter den senaste tangenttryckningen, och Enter skickar direkt och tar bort den väntande fördröjningen. Kategorin och taggarna skickar direkt när de ändras. Ett sökord som efter trimning är lika med `filter.q` skickas inte. En ny sökning avbryter en som är på väg. Fältet stängs aldrig medan svaret är på väg, och fokus och markör står kvar. Sökningar från fältet skickas med `replace: true`. Kategorin, taggarna, chipsens kryss och *Clear* lägger till en post i historiken som i dag. Knappen *Filter* tas bort, och nyckeln `item.index.filter_submit` tas bort ur `lang/en/ui.php`. `view` följer med som i dag, så att trädet och kartan stannar i sitt läge.

Raden är responsiv (§ 8). Under `sm:` tar sökfältet och kategorin hela bredden var för sig, och taggarna bryts under dem. Från `sm:` växer sökfältet för att fylla raden, och kategorin behåller sin bredd. Fälten har `min-h-11` och designsystemets tokens i stället för `slate`- och `blue`-klasserna. Skalets `SearchField` rörs inte.

**Läs:** [[ADR-0062 Kartans luft, listans ruta och den löpande sökningen]] § 7 och 8, [[ADR-0042 Designsystemet]] § Beslut, `resources/js/components/ItemFilterBar.vue` (docblocken), `resources/js/pages/Containers/Items/Index.vue` (filterradens ram)

**Klart när:** att skriva i sökfältet skickar filtret efter 300 ms utan knapp; flera tangenttryckningar inom 300 ms ger ett anrop; Enter skickar direkt; ett oförändrat sökord skickar inget; en ny sökning avbryter den förra; kategorin och taggarna skickar direkt; fältets sökningar ersätter historikposten och de andra lägger till en; fältet är aldrig inaktiverat medan svaret är på väg; knappen *Filter* och nyckeln `filter_submit` under `item.index` finns inte; `view` följer med i trädet och kartan; under `sm:` tar fälten hela bredden; skalets sökfält är oförändrat; hela testsviten är grön.

**Beror på:** —
