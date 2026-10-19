# M43 · Trädet som förval

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-10-19, efter v0.24.1. Det här ingår: itemflikens listläge tas bort, trädet blir förval och trädets rad får listans ruta med bild eller ikon. Besluten står i [[ADR-0067 Itemfliken utan listläge]].

**Ordningen:** 327 bygger på 326.

**Releasen:** M43 rullas ut tillsammans med [[M44 Fynd efter v0.24.1]] i samma release, när båda är stängda.

**Detta ingår inte:** `/api/containers/{container}/items`, itemets relationsflik med sin egen växel, översiktens itempanel och `ItemRow`.

---

### 326. Itemfliken utan listläge på servern

GitHub #968. **Läget.** `ItemController::view()` ger `map` för `?view=map` och `tree` för allt annat: inget läge, `tree`, `list` och ett okänt värde ([[ADR-0067 Itemfliken utan listläge]] § 1 och 2). Filtret läses som förut.

**Listgrenen.** `index()` hämtar inte längre listans rader (§ 8). Propparna `items`, `categories` och `covers` tas bort från `Containers/Items/Index`. `statuses` räknas bara i kartläget och är `[]` i trädet. `ListItems` anropas bara när ett filter är satt, som i dag i trädet och kartan. Hjälparen `categoryNames()` och `covers()` tas bort om ingen annan använder dem. Docblocken i `index()` säger att fliken har två lägen.

**Bilden.** `ResolveItemTreeRows` får nyckeln `cover` per item, ur `ResolveItemCover::thumbnails()` och med dess form: `{ulid, hasThumb, chosen}` eller `null` (§ 4). Frågan väljer `cover_attachment_id` med de andra kolumnerna. Upplösningen räknar exakt de items som står i trädet och kostar sex frågor oavsett trädets storlek. Containerns omslag används aldrig.

**Testerna.** Ett test som läser `items` från itemfliken läser i stället `treeRows`, vars nycklar är ULID:na för varje item i trädet. Ett test som bara prövar listläget (statusen per rad, omslaget per rad, listans frågekostnad) tas bort eller skrivs om mot trädet. Filterreglerna i `ItemfilterTest` prövas mot trädets träffar (`structure` med `match`). Ett test som nämner listan i sitt namn byter namn.

`Index.vue` rörs inte här. Dess proppar för listan blir oanvända tills issue 327, och knappen *List* landar i trädet under tiden.

**Läs:** [[ADR-0067 Itemfliken utan listläge]] § 1, 2, 4 och 8, `app/Http/Controllers/ItemController.php` (`index()`, `view()`, `covers()` och `categoryNames()`), `app/Actions/Item/ResolveItemTreeRows.php`, `app/Actions/Item/ResolveItemCover.php` (`thumbnails()`)

**Klart när:** inget läge, `?view=tree`, `?view=list` och ett okänt läge svarar alla med trädet; `?view=map` svarar med kartan; ett filter i en adress med `?view=list` gäller i trädet; sidan saknar `items`, `categories` och `covers`; `statuses` är tom i trädet och oförändrad i kartan; `treeRows` bär `cover` med `ulid`, `hasThumb` och `chosen` för ett item med bild och `null` för ett utan; en vald omslagsbild ger `chosen` sann och den automatiska falsk; containerns omslag blir aldrig en rads `cover`; ett item utanför omfånget får ingen rad; trädet kostar samma antal frågor för tre och trettio items, också med bilder; `/api/containers/{container}/items` är oförändrad; hela testsviten är grön.

**Beror på:** —

### 327. Trädet som förval och rutan i trädets rad

GitHub #969. **Växeln.** `Containers/Items/Index.vue` har två lägen, *Tree* och *Map*, i den ordningen ([[ADR-0067 Itemfliken utan listläge]] § 1). Trädet skrivs utan `view`, och kartan med `?view=map` och filtret efter. Listans gren i mallen tas bort, liksom propparna `items`, `categories` och `covers`. Filterraden får `view` `null` i trädet och `map` i kartan. *Expand all* står kvar i trädläget.

**Den tomma containern.** Ett ofiltrerat träd utan noder visar rotraden och under den `item.index.empty` (§ 6). Ett filter utan träffar visar `item.index.filter_empty`, som i dag.

**Rutan.** `ItemTreeTable` ritar en ruta mellan fällknappen och namnet i stället för ikonen (§ 3). Rutan väljer, ur radens `cover`, `icon` och `icon_color`, det första som finns:
1. en vald omslagsbild med miniatyr (`cover.hasThumb` och `cover.chosen`)
2. itemets ikon
3. den automatiska omslagsbilden med miniatyr (`cover.hasThumb`)
4. den grå rutan med den neutrala `item`-ikonen

Bilden är en `<img>` mot `/files/{ulid}?variant=thumb` med `alt=""`, 32 px (`h-8 w-8`) med `object-cover`, lika stor som ikonens ruta i dag. Ikonen ritas med `ItemIcon` i `tile` och storleken 20, som i dag. Rotraden ändras inte. Under `md:` står rutan kvar framför namnet.

**Kartan.** *+N till* kring containern leder till trädet (`overflowHref`), och länken i `ItemMapHits` lyder *Show all :count in tree* (§ 7).

**Orden.** `item.view.list` tas bort ur `lang/en/ui.php`. `item.map.show_all` blir *Show all :count in tree*, och kommentaren ovanför orden säger att växeln har två lägen. Docblocken i `Index.vue` och `ItemTreeTable.vue` följer med.

**Läs:** [[ADR-0067 Itemfliken utan listläge]] § 1, 3, 6, 7 och 9, [[ADR-0062 Kartans luft, listans ruta och den löpande sökningen]] § 6, `resources/js/pages/Containers/Items/Index.vue`, `resources/js/components/ItemTreeTable.vue`, `resources/js/components/ItemRow.vue` (rutans mall och docblock), `resources/js/components/ItemMapHits.vue`

**Klart när:** växeln visar *Tree* och *Map* och inget *List*; *Tree* är förvalet och skrivs utan `view`; filtret följer med genom växeln; ett filter som skickas i trädet stannar i trädet; trädets rad ritar en vald bild före ikonen, ikonen före den automatiska bilden och den grå rutan sist; en bild utan `thumb` ritas aldrig som `<img>`; rotraden bär containerns ikon som förut; rutan syns under `md:`; en tom container visar `item.index.empty` under rotraden; ett filter utan träffar visar `item.index.filter_empty`; kartans *+N till* kring containern och träfflistans länk leder till trädet; `item.view.list` finns inte längre; `ItemRow` ritas på översikten och inte på itemfliken; hela testsviten är grön.

**Beror på:** 326
