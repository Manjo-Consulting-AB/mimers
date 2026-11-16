# M62 · Kartan är relationernas yta

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-16, efter Tonys beslut om kartan. En testare skrev att relationsvyn under ett item är redundant, eftersom den visar samma sak som kartan fast med mindre data och möjligheten att lägga till. Tony vill dessutom kunna zooma ut kartan till hela containern, för en grafisk översikt i stil med The Brain och Obsidian. Beslutet och motiveringen står i [[ADR-0082 Kartan är relationernas yta]].

Tre issues: relationsfliken bort och redigeringen i den fulla kartan, zoomnivåerna *Neighbourhood* med `d3-force`, och *Whole container* med den nya frågan.

**Releasen:** en, när 392–394 är mergade. Issuerna skrevs direkt i GitHub (#1339, #1340, #1341) och är sammanfattade här.

---

### 392. Relationsfliken bort, relationer läggs till och knyts upp i den fulla kartan

GitHub #1339. **Läget:** fliken *Relations* ritar samma föräldrar, barn och relaterade som kartan, och är samtidigt platsen där relationer läggs till, knyts upp och där hela gruppen listas.

**Lösningen:** fliken, växeln *List/Focus* och `ItemLinkSection` tas bort från `Show.vue`, och flikraden blir *Overview · Documents · Costs · To do · Loans · History* ([[ADR-0082 Kartan är relationernas yta]] § 1). `CreateTarget` (raden *Relation*), `Show.vue` (*Add relation*) och `Index.vue` (*+N till*) slutar leda till `?tab=relations`; de två första leder till `?view=map&focus=<ulid>&add=relation`. Med `add=relation`, ett item som mitt och `update` öppnas formuläret, utbrutet till `ItemLinkForm.vue`, över kartan, och webbens `store` och `destroy` omdirigerar till kartan. *+N till* fäller ut hela gruppen som en lista i kartans ram, och varje kort runt mitten och varje rad får *Unlink* bakom bekräftelsedialogen, bara med `update` på båda ändarna och aldrig i `compact`. Den lilla kartan ändras inte.

**Läs:** [[ADR-0082 Kartan är relationernas yta]] § 1–3, [[ADR-0063 Itemets karta i containerkartans form]] § 1 och 3, [[ADR-0068 Itemets sida och skapandet]] § 4–6

**Klart när:** flikraden har sex flikar och växeln *List/Focus* finns inte; de tre anroparna leder inte längre till `?tab=relations`; med `add=relation` öppnas formuläret över den fulla kartan och en sparad relation syns efter omdirigeringen; *+N till* fäller ut hela gruppen; *Unlink* finns på kortet och i listan bakom en bekräftelse, bara med `update` på båda ändarna och aldrig i den kompakta kartan; inget item utanför omfånget syns; `RelationerIKartanTest` täcker punkterna; hela sviten är grön.

**Beror på:** GitHub #1338 (valvets beslut)

### 393. Kartans zoomnivåer och grannskapet med d3-force

GitHub #1340. **Läget:** den fulla kartan har bara nivån *Focus*, ett item och dess närmaste grannar.

**Lösningen:** en växel *Focus · Neighbourhood* från `md:`, med nivån i `depth` (ingen parameter är *Focus*, `depth=2` är *Neighbourhood*, andra värden *Focus*). Växeln visas bara när mitten är ett item, och under `md:` ritas ingen växel ([[ADR-0082 Kartan är relationernas yta]] § 4 och 7). `App\Actions\Item\ResolveItemNeighbourhood` lämnar noder och kanter för mitten och varje item inom två steg över alla tre slagen, med ADR-0028:s omfång prövat per item, utan spöknoder och utan papperskorgen. `ItemController::index()` skickar proppen `neighbourhood` bara med `view=map`, ett item som mitt och `depth=2`. `ItemGraph.vue` ritar layouten som SVG med `d3-force` (bara det paketet, godkänt genom ADR-0082 § 9), med samma linjer som kartan och utan pilspetsar; startpositionerna följer namnordningen, ett klick på en nod gör den till mitt på samma nivå, och noderna går att nå med Tab. Ingen redigering och ingen plusmeny.

**Läs:** [[ADR-0082 Kartan är relationernas yta]] § 4, 5, 7, 8 och 9, [[ADR-0061 Kartans kort och kanter]] § 5, [[ADR-0028 Åtkomst på itemnivå]] § Beslut

**Klart när:** växeln *Focus · Neighbourhood* finns från `md:` när mitten är ett item och läget står i `depth`; *Neighbourhood* visar mitten och items inom två steg över alla tre slagen, ritat med `d3-force` som SVG, likadant varje gång; en kant till ett item utanför omfånget lämnas inte och ingenting räknar det som föll bort; ett klick på en nod gör den till mitt och noderna går att nå med tangentbordet; under `md:` finns ingen växel; `ItemetsGrannskapTest` och `KartansZoomTest` finns; hela sviten är grön.

**Beror på:** GitHub #1338 (valvets beslut)

### 394. Hela containern som graf

GitHub #1341. **Läget:** det finns ingen översikt över hela containern och hur dess items hänger ihop.

**Lösningen:** nivån *Whole container* med `depth=all`, med och utan `focus` ([[ADR-0082 Kartan är relationernas yta]] § 6 och 8). `App\Actions\Item\ResolveContainerGraph` lämnar `{nodes, edges, truncated}` med samma form som grannskapets: omfånget prövas per item, en kant lämnas bara när båda ändarna ligger i omfånget, ingen spöknod, ingen papperskorg. Taket är 500 noder; över det är `nodes` och `edges` tomma, `truncated` sant och frågan läser inte in alla items. Antalet frågor är fast oberoende av containerns storlek. `ItemGraph.vue` återanvänds med ett läge för hela containern: panorera, zooma med hjul, nyp, knapparna `+` och `−` och tangentbordet, noder färgade efter status, namn först inzoomat, mitten markerad, sökningen (`?q=`) tänder träffarna, och ett klick leder till *Focus* med noden som mitt. Med `truncated` visas en mening utan antal i stället för grafen. Bara läsning.

**Läs:** [[ADR-0082 Kartan är relationernas yta]] § 6 och 8, [[ADR-0028 Åtkomst på itemnivå]] § Beslut och § Konsekvenser, GitHub #1340 (formen på `nodes` och `edges`, `ItemGraph.vue`)

**Klart när:** `depth=all` visar varje item läsaren når och varje relation mellan dem, och inga andra; ingenting säger att något föll bort; panorering och zoom fungerar med mus, nyp och tangentbord och namnen syns först inzoomat; sökningen tänder träffarna och ett klick går till *Focus*; över 500 noder visas en mening och frågan läser inte in alla items; antalet frågor är fast; `ContainergrafTest` finns och `KartansZoomTest` täcker `depth=all`; hela sviten är grön.

**Beror på:** GitHub #1338 (valvets beslut), 393
