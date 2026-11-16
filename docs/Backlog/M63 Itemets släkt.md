# M63 · Itemets släkt

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-16, efter Tonys beslut om nivån *Neighbourhood*. Grannskapet visar itemets hela släkt i stället för två steg, och ritas som ett radialt träd med ringar efter avstånd. Beslutet och motiveringen står i [[ADR-0083 Grannskapet är itemets släkt]].

Två issues: frågan, och ritningen.

**Releasen:** en, när 395 och 396 är mergade. Issuerna skrevs direkt i GitHub (#1360, #1361) och är sammanfattade här.

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
