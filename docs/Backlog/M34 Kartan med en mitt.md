# M34 · Kartan med en mitt

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-10-18. Itemflikens läge *Karta* (issue 157) ritar strukturen som kolumner med en öppen gren per nivå. Mockupen `docs/Design/items-map.png` ritar i stället en fokuskarta i flikens fulla bredd: ett item i mitten med föräldrar, barn och relaterade runt sig, sökfältet ovanför och en väg under föräldrarnas namn. Besluten står i [[ADR-0059 Kartan med en mitt]].

**Bilden är förlagan, besluten är regeln.** Mockupens *Layout*, zoom, helskärm, pilspetsar, ikoner per item och menyn med tre punkter tas inte in (ADR-0059 § 3, 5 och 7). Mät inte ut värden ur bilden.

**Ordningen:** M34 bygger på M33, eftersom båda ändrar `Index.vue` och `ItemController::index()`. Issuerna följer på varandra: 296, 297, 298 och 299.

**Detta ingår inte:** fokuskartan i itemets högerpanel (`FocusMap`, issue 156) och relationer mellan containrar ([[Tankar]]).

---

### 296. Kartan får en mitt

GitHub #886. `App\Actions\Item\ResolveItemMap` byter svar. I stället för nivåer ur en öppen väg svarar den med en **mitt** och tre grupper runt den. Mitten är containern utan `focus` och itemet med `focus`. Föräldrarna, barnen och de relaterade kommer ur samma kanter, samma rotregel och samma omfång som i dag. `related`-kanterna läses i samma fråga som föräldrakanterna men påverkar varken rötterna eller platsräkningen. Kring containern är barnen rötterna, och föräldrar och relaterade är tomma.

Varje grupp är `{nodes, total}`. Den har högst taket antal noder i namnordning (fyra föräldrar, tolv barn, sex relaterade), och varje nod är `{ulid, name, children, placements}`. Mitten är `{kind: 'container'|'item', ulid, name, children, placements}`, där talen är `null` för containern. `ItemController::index()` läser `?focus=` i stället för `?path=` i kartläget. Ett okänt, raderat eller dolt `focus` ger containern som mitt. Statusarna räknas för mitten och de ritade noderna och inga andra. Plusknappens mål är mitten när den är ett item. Mittens menyrader (`CreateTarget::forNode`) skickas som `map.rows` och är tomma för containern.

Vyn ritar under tiden svaret med den befintliga `FocusMap`. `Index.vue` gör om grupperna till dess form och bygger adresserna: en nod leder till `?view=map&focus=<ulid>`, och *+N till* leder till itemets `?tab=relations` eller, kring containern, till listläget. `ContainerMap.vue` och `ContainerMapNode.vue` tas bort. Den nya noden ritas i 297.

**Läs:** [[ADR-0059 Kartan med en mitt]] § 1, 2, 4, 5, 6 och 11, [[ADR-0041 Itemets vy]] § Beslut, `app/Actions/Item/ResolveItemMap.php` (docblocken), `app/Http/Controllers/ItemController.php` (`index`, `map`, `nodeRows`, `requestedPath`), `app/Support/Frontend/CreateTarget.php` (`forContainer`, `forNode`), `resources/js/components/FocusMap.vue` (docblocken), `resources/js/pages/Containers/Items/Index.vue`

**Klart när:** utan `focus` är mitten containern och barnen dess rötter i omfånget; med `focus` är mitten itemet, med dess föräldrar, barn och relaterade i omfånget; ett item utanför omfånget finns inte i någon grupp och räknas inte i något `total`; ett okänt, raderat eller dolt `focus` ger containern som mitt och inget fel; en grupp över taket har taket antal noder och rätt `total`; en `related`-kant ändrar varken rötterna eller `placements`; `statuses` har poster för exakt mitten och de ritade noderna; plusknappen skapar under mitten när den är ett item och i containern annars; `map.rows` är tom kring containern; `?path=` påverkar inte kartan; antalet frågor är detsamma för 3 och 30 items; `ContainerMap.vue` och `ContainerMapNode.vue` finns inte; hela testsviten är grön.

**Beror på:** 295

### 297. Kartans noder

GitHub #887. En ny komponent, `ItemMap.vue`, ersätter `FocusMap` i kartläget och ritar ADR-0059:s form i flikens fulla bredd. Föräldrarna står ovanför, barnen under och de relaterade i två kolumner på var sin sida om mitten. Linjerna är raka: heldragna till föräldrar och barn, streckade till de relaterade, och utan pilspetsar. Noden är en egen komponent, `ItemMapNode.vue`, med namnet, slagets ord, statusen med ord, antal barn och ⇄ med antalet platser när de är fler än en.

Hela noden är en länk som gör den till mitten, och en öppna-ikon bredvid leder till itemet. Ikonen är en ny roll, `open`, kopierad från Lucide `arrow-up-right` till `resources/js/icons/index.js`. Mittnoden har ingen centreringslänk. Den bär `aria-current`, öppna-ikonen och plusmenyn (`CreateMenu` med `map.rows`), och kring containern bara namnet. Teckenförklaringen har fyra rader. *Parent*, *Child* och *Related* visar gruppens `total`, och *Same item* förklarar ⇄. Under `md:` står de relaterade under barnen, och ingenting skrollar i sidled. `FocusMap` i högerpanelen rörs inte.

**Läs:** [[ADR-0059 Kartan med en mitt]] § 3, 4, 5, 6, 7 och 8, [[ADR-0057 Ikoner och typsnitt]] § 1, `resources/js/components/FocusMap.vue` och `FocusMapNode.vue` (docblocken: menyn, ytorna och teckenförklaringen), `resources/js/components/CreateMenu.vue`, `resources/js/components/UiIcon.vue`, `resources/js/icons/index.js`, `resources/js/pages/Containers/Items/Index.vue`, `docs/Design/items-map.png`

**Klart när:** kartläget ritar `ItemMap` och högerpanelen ritar `FocusMap` som förut; varje nod utom mitten är en länk till `?view=map&focus=<ulid>`; varje item-nod har en öppna-länk till itemets sida med ett `aria-label` som namnger itemet; plusknappen finns bara på mittnoden och bara när `map.rows` inte är tom; mittnoden bär `aria-current`; noden visar statusens ord, antal barn och ⇄ bara när `placements` är större än ett; linjerna till de relaterade är streckade och de andra heldragna; teckenförklaringen har fyra rader med gruppernas `total`; *+N till* visas när `total` är större än antalet noder; orden kommer ur `lang/en/ui.php`; `item.board`-nycklar som ingen använder längre är borttagna; hela testsviten är grön.

**Beror på:** 296

### 298. Vägen till mitten

GitHub #888. `ResolveItemMap` lägger till två vägar och räknar dem i minnet ur kanterna den redan har. **Mittens väg**, `map.path`, går från roten ned till mitten som `[{ulid, name}]`. Ett item med flera platser följer den första föräldern i namnordning på varje nivå, och en cykel kapas som i `countPlacements`. Kring containern är vägen tom. **Förälderns väg**, `via` på varje föräldranod, är namnen från roten ned till förälderns egen första förälder. För en rot är den tom.

Ovanför kartan står vägen som en sökväg: containerns namn först, som leder till kartan utan `focus`, sedan varje led, som gör ledet till mitten, och sist mitten utan länk. När mitten har fler platser än en följer ⇄ och antalet. Under en föräldranods namn står `via`, skilt med ›, avklippt med hela vägen i `title`.

**Läs:** [[ADR-0059 Kartan med en mitt]] § 9, `app/Actions/Item/ResolveItemMap.php` (`parentsByChild`, `countPlacements`), `resources/js/components/ItemMap.vue`, `resources/js/components/ItemMapNode.vue`, `docs/Design/items-map.png`

**Klart när:** `map.path` går från roten till mitten och slutar med mitten; ett item med två föräldrar får vägen genom föräldern som kommer först i namnordning; en cykel ger en ändlig väg; kring containern är `map.path` tom; `via` på en föräldranod är dess förfäders namn från roten, och tom för en rot; vägarna innehåller bara items i omfånget; sökvägen ovanför kartan börjar med containern och varje led utom det sista är en länk som centrerar; ⇄ står efter vägen när mitten har fler platser än en; antalet frågor är oförändrat från 296; hela testsviten är grön.

**Beror på:** 297

### 299. Filtret i kartan

GitHub #889. Filterraden ritas också i kartläget. Med ett aktivt filter kör `ItemController::index()` `ListItems` med samma filter och skickar `hits`: `{nodes: [{ulid, name}], total}`, högst tjugo noder i listans ordning. Utan filter är `hits` `null`. Mitten och grannarna påverkas inte av filtret.

Träfflistan står till vänster om kartan från `md:` och ovanför den under `md:`. Varje träff leder till `?view=map&focus=<ulid>` med filtret kvar i adressen, och den träff som är mitten är markerad och ingen länk. När `total` är större än antalet noder följer *Show all N in list*, som leder till listläget med samma filter. Ett filter utan träffar ritar listans tomma text. Växelns länkar, nodernas länkar och sökvägens länkar bär filtret.

**Läs:** [[ADR-0059 Kartan med en mitt]] § 10, [[ADR-0058 Trädet som tabell]] § 5 och 7, `app/Http/Controllers/ItemController.php` (`index`, `filter`), `app/Actions/Item/ListItems.php` (docblocken), `resources/js/pages/Containers/Items/Index.vue` (filterraden, `views` och de tomma lägena), `resources/js/components/ItemMap.vue`

**Klart när:** kartläget ritar filterraden; utan filter är `hits` `null` och ingen träfflista ritas; med filter är `hits.nodes` de första tjugo ur `ListItems` med samma filter, i samma ordning, och `hits.total` hela antalet; en träff utanför omfånget finns inte i `hits`; en träff leder till kartan med träffen som mitt och filtret kvar; mittnoden och grannarna är desamma med och utan filter; *Show all N in list* visas bara när `total` är större än antalet noder och leder till listläget med filtret; ett filter utan träffar ritar `item.index.filter_empty`; nodernas, sökvägens och växelns länkar bär filtret; hela testsviten är grön.

**Beror på:** 298
