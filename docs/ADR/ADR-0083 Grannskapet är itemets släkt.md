# ADR-0083 Grannskapet är itemets släkt

**Status:** Föreslagen 2026-11-16 · Ändrar [[ADR-0082 Kartan är relationernas yta]] § 4 (`depth=2`), § 5 (*två steg*, *kraftbaserad*) och § 9 (`d3-force` för *Neighbourhood*) · Bygger vidare på [[ADR-0028 Åtkomst på itemnivå]] och [[ADR-0061 Kartans kort och kanter]] · [[ADR-index]]

Tonys beslut 2026-11-16, efter att ha sett nivån *Neighbourhood* byggd i [[M62 Kartan är relationernas yta]] § 393.

## Kontext

*Neighbourhood* visar i dag mitten och varje item inom två steg, utlagt med `d3-force`. Två saker skaver. Gränsen på två steg klipper släkten mitt itu: det som ligger tre steg bort hänger lika mycket ihop med itemet, och användaren har ingen annan vy som visar just itemets hela sammanhang — *Whole container* visar allt, också det som inte hänger ihop med itemet alls. Och en kraftlayout säger ingenting om avstånd: en nod två steg bort kan hamna närmare mitten än en granne, och bilden blir lätt rörig.

## Beslut

**1. *Neighbourhood* är itemets släkt.** Mitten och varje item som går att nå från den genom en kedja av relationer, över alla tre slagen (förälder, barn och relaterad), utan gräns för antalet steg. Är hela containern sammanbunden visas hela containern; det är rätt svar och inget fel. Vandringen går bara genom items i läsarens omfång ([[ADR-0028 Åtkomst på itemnivå]]), som i dag: ett item bakom ett dolt item nås inte via det, och ingenting säger att något föll bort.

**2. Adressen blir `depth=linked`.** *Neighbourhood* står i `depth=linked`, bredvid `depth=all`: de items som är kopplade till mitten mot alla items. `depth=2` utgår. Ett tal hade sagt *två steg* fast nivån inte längre räknar steg, och hade upptagit värdet om ett djupreglage kommer (ADR-0082 § Alternativ). En gammal adress med `depth=2` blir *Focus*, eftersom ett okänt värde redan är det (ADR-0082 § 4); ingen omdirigering byggs. Systemet är i förproduktion, så det finns inga delade länkar att värna.

**3. Samma tak som *Whole container*.** Släkten lämnar högst 500 noder ([[ADR-0082 Kartan är relationernas yta]] § 8). Över det visas samma mening utan antal i stället för grafen. Antalet frågor är fast oberoende av släktens storlek: kanterna i omfånget läses en gång och vandringen sker i minnet.

**4. Layouten är ett radialt träd med synliga ringar.** Mitten står i origo. Varje item står på ringen för sitt kortaste avstånd till mitten, och ringarna ritas ut som breda, dämpade cirklar — utan dem blir bilden rörig, och det är ringarna som gör avståndet läsbart. Ringen följer bara avståndet, inte relationens slag: mittens föräldrar, barn och relaterade står alla på ring 1; barnbarn, mor- och farföräldrar, syskon, barnens andra föräldrar och det som är relaterat till något på ring 1 står på ring 2, om de inte redan nås närmare.

För sektorerna förankras varje nod på ring *n* i en av sina grannar på ring *n − 1*, dess **ankare**: den första i namnordning bland dem. Ankaret är ett layoutbegrepp och har ingenting med relationen *parent* att göra; ett barn till mitten är förankrat i mitten, och mittens förälder likaså. Varje nod på ring 1 får en vinkelsektor i proportion till hur många noder som är förankrade i den, direkt eller längre ut, och de förankrade noderna läggs i ankarets sektor. Har en nod grannar på ring *n − 1* utöver ankaret ritas de kanterna ändå, som längre linjer över till den andra sektorn. En rings radie växer med antalet noder på den, så att namnen får plats. Layouten är ren beräkning utan simulering och ger samma bild varje gång. `d3-force` används inte för *Neighbourhood*; *Whole container* behåller det (ADR-0082 § 9 gäller där).

Av kortaste avstånd följer att en kant bara kan gå mellan två noder på samma ring eller på två ringar intill varandra. Kanter mellan ringar ritas raka; kanter inom en ring ritas som bågar utåt.

**5. Linjerna är oförändrade.** [[ADR-0061 Kartans kort och kanter]] § 5 gäller: heldragna till föräldrar och barn, streckade till de relaterade, inga pilspetsar.

**6. Noden är itemets ikon.** Varje nod ritas med itemets valda ikon i dess färg (`icon`, `icon_color`, som kartan redan får), med namnet under, avkortat med ellips. Mitten markeras med `accent`. Fokus eller hovring visar fullt namn och status, som i ADR-0082 § 5. Ett klick på en nod gör den till mitt på samma nivå.

**7. Vyn går att zooma och panorera.** Eftersom släkten kan vara stor får *Neighbourhood* samma panorering och zoom som *Whole container*: dra, hjul, nyp, knapparna `+` och `−` och tangentbordet. Vyn börjar inpassad så att hela släkten syns, centrerad på mitten. Namnen ritas från samma zoomnivå som i *Whole container*.

## Konsekvenser

- [[ADR-0082 Kartan är relationernas yta]] § 5 gäller inte längre i sina ord *två steg* och *kraftbaserad*; resten av paragrafen står. § 9 gäller bara *Whole container*. I § 4 ersätts `depth=2` av `depth=linked`.
- `ResolveItemNeighbourhood` förlorar `STEPS` och får taket; `ItemGraph.vue` får en radial layout för läget `neighbourhood` och delar panorering och zoom med läget `container`.
- `ItemetsGrannskapTest` prövar i dag gränsen på två steg och skrivs om. `KartansZoomTest`, växelns adresser i `Index.vue`, nodernas länkar i `ItemGraph.vue` och villkoret i `ItemController::index()` byter `depth=2` mot `depth=linked`.
- Itemets översikt i en ruta vid hovring är föreslagen men pausad tills grafen är klar. Den kräver fler fält i svaret (bild, kategori, beskrivning, antal) och ett beslut om pekskärmar.
- Byggs i [[M63 Itemets släkt]]: 395 är Beslut 1–3 (frågan), 396 är Beslut 4–7 (ritningen). 396 beror på 395.

## Alternativ som valts bort

- **Behålla två steg, eller höja till tre.** Ringarna hade gjort ett tredje steg läsbart, men varje gräns klipper släkten någonstans, och vyns syfte är att visa hela sammanhanget kring itemet.
- **Behålla `d3-force` med en radiell kraft per ring.** Ger ringarna men inte sektorerna: en nod hamnar inte vid sitt ankare, och bilden beror på simuleringen. Den rena beräkningen är enklare och stabilare.
- **Behålla `depth=2` som nyckel för nivån.** Sparar ett par rader i dag men låter värdet säga fel sak och låser talen för ett framtida djupreglage. Se Beslut 2.
