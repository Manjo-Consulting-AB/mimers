# M33 · Trädet som tabell

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-10-18. Itemflikens läge *Träd* (issue 154) visar bara namnen. Mockupen `docs/Design/items-tree.png` ritar samma träd som en tabell med containern som rotrad, en kolumn per egenskap, *Expand all* och filterraden ovanför. Besluten står i [[ADR-0058 Trädet som tabell]].

**Ordningen:** 291 och 292 står fritt. 293 kräver båda. 294 kräver 292 och 293. 295 går sist.

**Detta ingår inte:** ikonerna i mockupen, radens meny (de tre punkterna), underträdets summor i kolumnerna (ADR-0058 § Alternativ) och filter i kartläget.

---

### 291. Ett huvud för de tre lägena

GitHub #879. Rubriken *Items*, *New item* och växeln List/Tree/Map står på en rad i alla tre lägena, och filterraden står under den i listläget. Trädläget får filterraden i 293. Ingenting ändras i vad lägena visar.

**Läs:** [[ADR-0058 Trädet som tabell]] § 7, `resources/js/pages/Containers/Items/Index.vue`, `resources/js/components/ItemViewSwitch.vue`, `resources/js/components/ItemFilterBar.vue`, `docs/Design/items-tree.png`

**Klart när:** rubriken, *New item* och växeln står i en rad i alla tre lägena, i den ordningen; *New item* ritas bara med `can.create`; filterraden ritas i listläget och inte i träd- eller kartläget; listans filter, tomma lägen och rader är oförändrade; hela testsviten är grön.

**Beror på:** —

### 292. Trädets kolumner ur servern

GitHub #880. En ny upplösning, `App\Actions\Item\ResolveItemTreeRows`, svarar för varje item i trädet med kategori, taggar, antal dokument, antal aktiva uppgifter och `updated_at`. `ItemController::index()` skickar svaret som `treeRows` (ULID → rad) i trädläget och `null` i de andra. `ResolveItemTree` rörs inte, så strukturpanelen och föräldraväljaren betalar ingenting.

**Läs:** [[ADR-0058 Trädet som tabell]] § 1, 3 och 4, [[ADR-0028 Åtkomst på itemnivå]] § Konsekvenser, `app/Http/Controllers/ItemController.php` (`index` och `structure`), `app/Actions/Item/ResolveItemTree.php` (docblocken), `app/Models/ScheduleOccurrence.php` (`ACTIVE_STATUSES`)

**Klart när:** `treeRows` har en post per item i `structure` och inga andra; kategorin är namnet eller `null`; taggarna är `{ulid, name, color}` i namnordning; dokumenten räknar itemets egna levande bilagor; uppgifterna räknar förekomster i `open` och `in_progress` på itemets levande scheman; ett item med två föräldrar har en post; en mottagare med itemgrant får bara poster för items i sitt omfång; antalet frågor är detsamma för 3 och 30 items; hela testsviten är grön.

**Beror på:** —

### 293. Filtret gäller trädet

GitHub #881. Trädläget tar samma filter som listan. `ResolveItemTree` får ett valfritt urval av träffar, och trädet visar då varje träff och vägen från roten ned till den, och inget annat. Varje nod bär `match`. Filterraden ritas i trädläget, och ett filter utan träffar ger listans tomma text.

**Läs:** [[ADR-0058 Trädet som tabell]] § 5 och 7, [[ADR-0041 Itemets vy]] § Beslut, [[M11 Åtkomst på itemnivå]] § 73, `app/Actions/Item/ResolveItemTree.php`, `app/Http/Controllers/ItemController.php` (`index`, `filter`, `structureNodes`)

**Klart när:** utan filter är trädet oförändrat, och panelen och väljaren likaså; med filter innehåller trädet exakt träffarna ur `ListItems` och deras förfäder i omfånget; `match` är sann för en träff och falsk för en ren väg, och sann på varje nod utan filter; en träff med två föräldrar står på båda ställena; en träff vars förälder ligger utanför omfånget står som rot; `treeRows` följer det filtrerade trädet; trädläget ritar filterraden; ett filter utan träffar ritar `item.index.filter_empty`; antalet frågor växer inte med antalet träffar; hela testsviten är grön.

**Beror på:** 291, 292

### 294. Trädet blir en tabell

GitHub #882. En ny komponent, `ItemTreeTable.vue`, ritar trädläget som en tabell med kolumnerna *Name*, *Category*, *Tags*, *Documents*, *Tasks* och *Last updated*, och med containern som en öppen rotrad. `ItemStructureTree` står kvar för panelen och väljaren. Med ett filter är träffarna halvfeta och grenarna dit öppna. Under `md:` visas bara namnet med kategorin under.

**Läs:** [[ADR-0058 Trädet som tabell]] § 1, 2, 3, 5 och 8, `resources/js/components/ItemStructureTree.vue` (docblocken: förekomster, `?path=`, fällknappen), `resources/js/components/ItemTagList.vue`, `resources/js/composables/useRelativeDate.js` (`eventDate`), `resources/js/pages/Containers/Items/Index.vue`, `docs/Design/items-tree.png`

**Klart när:** trädläget ritar `ItemTreeTable`, och panelen och väljaren ritar `ItemStructureTree` som förut; rubrikerna kommer ur `lang/en/ui.php`; första raden är containerns namn, öppen och utan länk; fällknappen finns bara på rader med barn och bär `aria-expanded`; namnet länkar med hela ledet i `?path=`; kolumnerna läses ur `treeRows`; högst två taggar och sedan `+N`; *Last updated* ritas med `eventDate()`; under `md:` bara namn och kategori; med filter är träffarna halvfeta och grenarna till dem öppna; hela testsviten är grön.

**Beror på:** 292, 293

### 295. Expand all

GitHub #883. En knapp *Expand all* i trädlägets filterrad öppnar alla grenar, och en andra tryckning (*Collapse all*) återgår till utgångsläget: rotraden öppen, och med filter grenarna till träffarna. Tillståndet bor i vyn och inte i adressen.

**Läs:** [[ADR-0058 Trädet som tabell]] § 6, `resources/js/components/ItemTreeTable.vue`, `resources/js/pages/Containers/Items/Index.vue`, `docs/Design/items-tree.png`

**Klart när:** knappen finns bara i trädläget; den öppnar varje rad med barn; en andra tryckning återgår till utgångsläget; knappens text och `aria-pressed` följer läget; en ny filtrering börjar i utgångsläget; adressen ändras inte; hela testsviten är grön.

**Beror på:** 294
