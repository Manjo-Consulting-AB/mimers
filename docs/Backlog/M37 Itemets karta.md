# M37 · Itemets karta

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-10-18, efter [[M36 Kartans luft, listans ruta och sökningen]]. Kartan på itemets sida, i högerpanelen och i relationsflikens läge *Focus*, ritas med containerkartans kort, färger och plusmeny i dess smala form. En länk leder till hela kartan med itemet som mitt. Besluten står i [[ADR-0063 Itemets karta i containerkartans form]].

**Ordningen:** 313 bygger på 312. 312 lägger till den nya datan bredvid den gamla, så att sidan fungerar mellan de två.

**Detta ingår inte:** containerns kartläge utan `compact`, som ritas som förut, och relationsflikens lista.

---

### 312. Itemets sida skickar containerkartans data

GitHub #937. `ItemController::show()` skickar två nya proppar bredvid den gamla `map` ([[ADR-0063 Itemets karta i containerkartans form]] § 2):

- **`itemMap`** är `ResolveItemMap::handle($user, $container, $item->ulid)` i samma form som `index()` skickar `map` i kartläget: `center`, `container`, `path`, `parent`, `child`, `related` och `rows`. `rows` är `CreateTarget::forNode()` på itemet med sidans `?path=`. `centerItem` och `drawn` följer inte med.
- **`itemMapStatuses`** är `ItemStatus::forItems()` för `drawn`, som i `index()`.

Den gamla `map` och vyerna är oförändrade. Kartan kostar ett konstant antal frågor oavsett antalet grannar.

**Läs:** [[ADR-0063 Itemets karta i containerkartans form]] § 2, `app/Http/Controllers/ItemController.php` (`index()` kring `map` och `statuses`, `show()` kring `map`), `app/Actions/Item/ResolveItemMap.php` (docblocken)

**Klart när:** `itemMap` har itemet som `center` och samma grupper, `total` och `path` som `index()` ger med `?view=map&focus=<itemet>`; `rows` bär sidans förekomst; en nod utanför omfånget finns inte i `itemMap`; `itemMapStatuses` har status för mitten och de ritade noderna och inga andra; antalet frågor är konstant när grannarna blir fler; den gamla `map` är oförändrad; hela testsviten är grön.

**Beror på:** —

### 313. Itemets karta ritas med ItemMap

GitHub #938. `ItemMap` får proppen `compact` (§ 1, 3, 5 och 6). Med `compact` gäller följande:

- Den smala formen ritas på alla bredder: inga sidokolumner, inget `ItemMapEdges` och de relaterade under barnen.
- Kortet leder till nodens sida och har inget öga. Mitten är ingen länk.
- Vägen till mitten ritas inte.
- Högst 4 föräldrar, 4 barn och 4 relaterade ritas, och resten blir *+N till* ur `total`.
- Under teckenförklaringen står länken *Open in map* (`item.map.open_full`) till `overflowHref`.

`ItemMapPanel` och relationsflikens läge *Focus* i `ItemLinkSection` ritar `ItemMap` med `compact`, `itemMap`, `itemMapStatuses` och containerns ULID. `overflowHref` är containerns kartläge med itemet som mitt. `FocusMap.vue` och `FocusMapNode.vue` tas bort. Den gamla proppen `map`, `ItemController::map()` och `nodeRows()` tas bort. Utan `compact` ritas containerkartan som förut.

**Läs:** [[ADR-0063 Itemets karta i containerkartans form]], `resources/js/components/ItemMap.vue` (docblocken), `resources/js/components/ItemMapPanel.vue`, `resources/js/components/ItemLinkSection.vue` (fokusläget), `resources/js/components/FocusMap.vue` (docblocken, för det som ska bevaras)

**Klart när:** högerpanelen och läget *Focus* ritar `ItemMap` med `compact`; i den smala formen finns inga sidokolumner, inget SVG-lager, inget öga och ingen väg; korten leder till nodernas sidor och mitten är ingen länk; bara mittkortet har plustecknet, och dess rader bär sidans förekomst; högst 4 per grupp ritas och resten är *+N till*; *Open in map* och *+N till* leder till `?view=map&focus=<itemet>`; `FocusMap.vue`, `FocusMapNode.vue`, `map`, `map()` och `nodeRows()` finns inte; containerkartan utan `compact` är oförändrad; hela testsviten är grön.

**Beror på:** 312
