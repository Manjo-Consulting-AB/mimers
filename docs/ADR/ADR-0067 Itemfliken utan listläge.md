# ADR-0067 Itemfliken utan listläge

**Status:** Antagen 2026-10-19 · Ändrar [[ADR-0046 Containerns karta]]: växeln har två lägen och trädet är förval · Ändrar [[ADR-0058 Trädet som tabell]] § 7: huvudet är gemensamt för två lägen · Ändrar [[ADR-0059 Kartan med en mitt]] § 6 och 10: *+N till* och träfflistans länk leder till trädet · Ändrar [[ADR-0062 Kartans luft, listans ruta och den löpande sökningen]] § 6: rutan flyttar till trädets rad · Bygger vidare på [[ADR-0060 Itemets ikon]] · [[ADR-index]]

Tonys beslut 2026-10-19, efter v0.24.1.

## Kontext

Itemfliken har tre lägen: *List*, *Tree* och *Map*. Listan är förvalet. Den visar varje item platt i namnordning, med en ruta som bär bild eller ikon (ADR-0062 § 6) och med beskrivningen under namnet.

Sedan [[ADR-0058 Trädet som tabell]] visar trädet samma items, med kategori, taggar, dokument, uppgifter och senast ändrad i egna kolumner. Det tar också samma filter, och träffarna är desamma som i listan, men trädet visar dessutom vägen ned till varje träff. Listans argument var sökningen, och den har trädet tagit över. Det som återstår för listan är bilden i rutan, beskrivningen och att allt syns utan att man fäller upp något.

Tony ser inget syfte med två lägen som visar samma sak, där det ena är sämre.

## Beslut

**1. Listläget tas bort.** Växeln har två lägen, *Tree* och *Map*, i den ordningen. Trädet är förvalet och skrivs utan `view` i adressen. Kartan bär `?view=map`, som i dag.

**2. En gammal adress landar i trädet.** `?view=list`, `?view=tree`, ett okänt läge och inget läge alls ger trädet. Filtret i adressen gäller som vanligt. Det blir ingen omdirigering och inget fel, eftersom ett bokmärke inte är ett misstag (samma regel som för filtret, issue 59a § Beslut 3).

**3. Trädets rad får listans ruta.** Rutan står där ikonen står i dag, mellan fällknappen och namnet. Den ritar det första som finns, i samma ordning som ADR-0062 § 6:
1. en vald omslagsbild med miniatyr
2. itemets ikon
3. den automatiska omslagsbilden med miniatyr
4. den grå rutan med den neutrala `item`-ikonen

Rutan är 32 px, alltså lika stor som ikonens ruta i trädet i dag, så att raderna inte blir högre. Rotraden ändras inte och bär containerns ikon ([[ADR-0064 Containerns ikon]]). Under `md:` står rutan kvar framför namnet.

**4. Bilden kommer ur samma upplösning som listans.** `ResolveItemTreeRows` får nyckeln `cover` per item, med samma form som `ResolveItemCover::thumbnails()` ger: `{ulid, hasThumb, chosen}` eller `null`. Den räknas på exakt de items som står i trädet, så att ett item utanför omfånget aldrig får en bild ([[ADR-0028 Åtkomst på itemnivå]]). Upplösningen kostar en fråga till, alltså konstant oavsett trädets storlek. Containerns eget omslag används aldrig för en rad.

**5. Beskrivningen visas inte i trädet.** Den står på itemets översikt. En undertext på varje rad hade gjort tabellen dubbelt så hög.

**6. Den tomma containern säger att den är tom.** Ett ofiltrerat träd utan items visar rotraden och under den listans mening om att containern är tom (`item.index.empty`). Meningen är densamma för ägaren och för en mottagare med begränsat omfång, som i listan (issue 73 § Beslut 6).

**7. Kartans länkar leder till trädet.** Kring containern leder *+N till* till trädet. Träfflistans sista länk heter *Show all N in tree* och leder till trädet med samma filter.

**8. Sidan hämtar inte längre listans data.** Propparna `items`, `categories` och `covers` försvinner från `Containers/Items/Index`, och `statuses` räknas bara i kartläget. `ListItems` anropas bara när ett filter är satt, som i trädet och kartan i dag.

**9. `ItemRow` står kvar.** Översiktens itempanel ritar den.

## Motivering

Två lägen som visar samma items med samma filter är ett val utan innehåll. Trädet vinner på det som går att jämföra: det visar strukturen, kolumnerna och vägen ned till en träff. Det listan hade och trädet saknade var bilden, och den flyttar med.

Rutan följer listans ordning och inte en egen, eftersom regeln *ett val slår en gissning* (ADR-0062) gäller oavsett var raden står.

## Konsekvenser

- Issue 326–327 i [[M43 Trädet som förval]].
- `ItemController::index()` förlorar listgrenen, och `view()` känner bara `tree` och `map`.
- `ResolveItemTreeRows` kostar sex frågor i stället för fem.
- Tester som läser `items` från itemfliken läser i stället `treeRows`, vars nycklar är ULID:na för varje item i trädet.
- Orden `item.view.list` försvinner ur `lang/en/ui.php`, och `item.map.show_all` säger *tree*.
- `/api/containers/{container}/items` ändras inte. Den är en lista och förblir det.
- Itemets egen växel på relationsfliken (`Show.vue`, *List* och *Map*) ändras inte. Den är en annan vy.

## Alternativ

**Behåll listan men gör trädet till förval.** Valdes bort. Ett läge få väljer är ändå kod att underhålla och ett ord i växeln att förstå.

**Ett platt läge i trädet, en knapp som tar bort indraget.** Valdes bort. Det vore listan igen, under ett annat namn.

**Rutan i 48 px, som i listan.** Valdes bort. Trädraderna är täta med flit, och kolumnerna bär det listan bar i rutans storlek.

**Beskrivningen som undertext under namnet.** Valdes bort, se § 5.
