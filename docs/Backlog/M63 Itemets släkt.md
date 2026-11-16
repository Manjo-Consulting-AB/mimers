# M63 · Itemets släkt

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-16, efter Tonys beslut om nivån *Neighbourhood*. Grannskapet visar itemets hela släkt i stället för två steg, och ritas som ett radialt träd med ringar efter avstånd. Beslutet och motiveringen står i [[ADR-0083 Grannskapet är itemets släkt]]. Samma dag beslöt Tony att *Whole container* ska rita noden likadant ([[ADR-0084 Hela containern med itemets ikon]]).

Tre issues: frågan, ritningen av grannskapet, och hela containern med samma nod.

**Releasen:** en, när 395, 396 och 397 är mergade. Issuerna 395 och 396 skrevs direkt i GitHub (#1360, #1361) och är sammanfattade här.

---

### 395. Grannskapet utan stegräkning

GitHub #1360. **Läget:** `ResolveItemNeighbourhood` stannar efter två steg (`STEPS`).

**Lösningen:** vandringen går tills inget nytt item nås, över alla tre slagen och bara genom items i omfånget, som i dag ([[ADR-0083 Grannskapet är itemets släkt]] § 1). Svaret blir `{nodes, edges, truncated}` i samma form som `ResolveContainerGraph`, med samma tak (`ResolveContainerGraph::MAX_NODES`): över det är `nodes` och `edges` tomma och `truncated` sant (§ 3). Antalet frågor är fast oberoende av släktens storlek. Varje nod får `ring`, sitt kortaste avstånd till mitten (mitten är 0), oavsett relationens slag, så att klienten inte räknar om vandringen. Mittens föräldrar, barn och relaterade står på ring 1; syskon, barnbarn och barnens andra föräldrar på ring 2. Nivån flyttar från `depth=2` till `depth=linked` (§ 2): villkoret i `ItemController::index()`, växelns adresser i `Index.vue` och nodernas länkar i `ItemGraph.vue`. `depth=2` blir *Focus* som varje annat okänt värde, utan omdirigering.

**Läs:** [[ADR-0083 Grannskapet är itemets släkt]] § 1–3, [[ADR-0082 Kartan är relationernas yta]] § 4 och 8, [[ADR-0028 Åtkomst på itemnivå]] § Beslut

**Klart när:** *Neighbourhood* lämnar varje item som går att nå från mitten genom items i omfånget, oavsett avstånd, och inga andra; ett item bakom ett dolt item nås inte via det; varje nod bär `ring`; *Neighbourhood* står i `depth=linked` och `depth=2` ger *Focus*; över taket är svaret tomt med `truncated` och ingenting räknar det som föll bort; antalet frågor är fast; `ItemetsGrannskapTest` är omskrivet och `KartansZoomTest` prövar `depth=linked`, och de täcker punkterna; hela sviten är grön.

**Beror på:** GitHub #1340 (393), #1359 (valvets beslut)

### 396. Grannskapet som radialt träd

GitHub #1361. **Läget:** `ItemGraph.vue` lägger ut *Neighbourhood* med `d3-force` och ritar noderna som prickar utan zoom.

**Lösningen:** läget `neighbourhood` räknar en radial layout utan simulering ([[ADR-0083 Grannskapet är itemets släkt]] § 4): mitten i origo, varje nod på ringen ur `ring`, ett ankare per nod bland dess grannar på ringen innanför (den första i namnordning; ett layoutbegrepp, inte relationen *parent*), sektorer på första ringen i proportion till antalet noder förankrade i dem, och de förankrade noderna i ankarets sektor. Ringarnas radie växer med antalet noder. Ringarna ritas som breda cirklar i en dämpad token. Kanterna följer `ItemMapEdges` (§ 5): raka mellan ringar, bågar utåt inom en ring. Noderna är itemets ikon i `icon_color`, med namnet under avkortat med ellips och mitten markerad med `accent` (§ 6). Panorering och zoom delas med läget `container`, och vyn börjar inpassad kring hela släkten (§ 7). Med `truncated` visas samma mening som i *Whole container*. Fokus och hovring visar namn och status, ett klick gör noden till mitt, noderna nås med Tab.

**Läs:** [[ADR-0083 Grannskapet är itemets släkt]] § 4–7, [[ADR-0061 Kartans kort och kanter]] § 5, GitHub #1341 (panoreringen och zoomen i `ItemGraph.vue`)

**Klart när:** *Neighbourhood* ritas med synliga ringar och varje nod på ringen för sitt avstånd, likadant varje gång och utan `d3-force`; varje nod står i sitt ankares sektor; linjerna följer kartans och saknar spetsar; noderna visar ikon, färg och namn och mitten är markerad; panorering och zoom fungerar med mus, nyp och tangentbord; ett klick gör noden till mitt och noderna nås med Tab; `KartansZoomTest` täcker läget; hela sviten är grön.

**Beror på:** 395

### 397. Hela containern med itemets ikon

**Läget:** `ItemGraph.vue` ritar *Whole container* som prickar färgade efter status, med namnen först från `LABEL_ZOOM`.

**Lösningen:** läget `container` ritar noden med samma komponent och mått som läget `neighbourhood` från 396: en rund platta med itemets ikon i `icon_color`, mitten (`focus`) större och fylld i `accent` ([[ADR-0084 Hela containern med itemets ikon]] § 1). Status `overdue` blir en liten prick i `danger` nere till höger med en ring i `surface`; `ok` ger ingen prick (§ 2). Namnet står under noden i `ink`, halvfett, med kontur i `surface`, i fast skärmstorlek oavsett zoom och avkortat med ellips; fullt namn och status vid fokus och hovring (§ 3). Under 40 noder ritas alla namn från början, från 40 från `LABEL_ZOOM`; mitten och sökträffarna har alltid namn (§ 4). Kanterna är raka med `ItemMapEdges` färg och streckning, utan spetsar (§ 5). `forceCollide` och länklängden växer med plattan. Servern ändras inte.

**Läs:** [[ADR-0084 Hela containern med itemets ikon]], [[ADR-0083 Grannskapet är itemets släkt]] § 6, [[ADR-0082 Kartan är relationernas yta]] § 6, [[ADR-0061 Kartans kort och kanter]] § 5

**Klart när:** *Whole container* visar varje nod med itemets ikon och färg, ritad av samma kod som *Neighbourhood*; ett försenat item har pricken och ett item utan försening saknar den; namnen har fast skärmstorlek och går att läsa ovanpå linjer; under 40 noder syns alla namn utan zoom, från 40 först vid `LABEL_ZOOM`, och mitten och sökträffarna har alltid namn; linjerna är raka och följer kartans streckning; noderna överlappar inte; `KartansZoomTest` täcker pricken och gränsen på 40; hela sviten är grön.

**Beror på:** 396
