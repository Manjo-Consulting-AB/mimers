# ADR-0084 Hela containern med itemets ikon

**Status:** Föreslagen 2026-11-16 · Ändrar [[ADR-0082 Kartan är relationernas yta]] § 6 (*noderna färgas efter status*, *namnen ritas först när man zoomat in*) · Bygger vidare på [[ADR-0083 Grannskapet är itemets släkt]] § 6 och [[ADR-0061 Kartans kort och kanter]] § 5 · [[ADR-index]]

Tonys beslut 2026-11-16, efter att ha jämfört *Whole container* med en förlaga där varje nod är en rund platta med itemets ikon och namnet bredvid.

## Kontext

*Whole container* ritar i dag varje item som en liten prick, färgad efter status, och namnen syns först när man zoomat in till `LABEL_ZOOM`. Bilden säger var det finns items men inte vilka de är: man måste hovra eller zooma för att känna igen något. *Neighbourhood* får itemets ikon som nod ([[ADR-0083 Grannskapet är itemets släkt]] § 6). Två nivåer av samma karta som ritar samma item olika är svårare att läsa än en.

## Beslut

**1. Noden är samma som i *Neighbourhood*.** Varje nod ritas som en rund platta med itemets valda ikon i dess färg (`icon`, `icon_color`, som frågan redan lämnar), med samma komponent och samma mått som *Neighbourhood*. Är ett item mitt (`focus`) ritas det större, som en fylld platta i `accent`. Alla noder är lika stora i övrigt: storleken bär ingen mening och ska inte se ut att göra det.

**2. Statusen är en prick.** Noden färgas inte längre efter status, eftersom färgen nu är ikonens. Ett item med status `overdue` får en liten prick i `danger` nere till höger på plattan, med en ring i `surface` så att den syns mot alla ikonfärger. Status `ok` får ingen prick. Fokus och hovring visar fullt namn och status som i dag.

**3. Namnen ska vara tydliga.** Namnet står under noden i `ink`, halvfett, med en kontur i `surface` bakom texten så att det går att läsa ovanpå linjer. Det ritas i samma storlek på skärmen oavsett zoom, så att det aldrig krymper till oläsligt, och avkortas med ellips efter en fast bredd. Fullt namn visas vid fokus och hovring.

**4. Namnen syns direkt i en liten container.** Har grafen färre än 40 noder ritas alla namn från början. Från 40 noder ritas de från zoomnivån `LABEL_ZOOM` som i dag, så att de inte lägger sig på varandra. Mitten och sökträffarna har alltid namn, oavsett antal.

**5. Linjerna är raka.** Kanterna i *Whole container* ritas raka, med [[ADR-0061 Kartans kort och kanter]] § 5:s färg och streckning: heldragna till föräldrar och barn, streckade till de relaterade, inga pilspetsar. Krökta linjer valdes bort, se nedan.

**6. Resten av ADR-0082 § 6 står.** Layouten är fortfarande `d3-force` (§ 9), sökfältet tänder träffarna, ett klick går till *Focus* med noden som mitt, panorering och zoom är oförändrade.

## Konsekvenser

- [[ADR-0082 Kartan är relationernas yta]] § 6 gäller inte längre i orden *noderna färgas efter status* och *namnen ritas först när man zoomat in tillräckligt*; resten av paragrafen står.
- Servern ändras inte: `ResolveContainerGraph` lämnar redan `icon`, `icon_color` och `status`.
- Noden som [[M63 Itemets släkt]] § 396 bygger för *Neighbourhood* blir gemensam. Kraftlayoutens avstånd (`forceCollide`, länklängden) växer med plattan, så att noderna inte överlappar.
- Byggs i [[M63 Itemets släkt]] som 397, efter 396.

## Alternativ som valts bort

- **Krökta linjer, som i förlagan.** Mjuka med tio noder, ett nystan med hundratals, och det blir svårare att se vilka två noder en linje binder ihop.
- **Namn på alla noder oavsett antal.** Över ett par dussin noder lägger namnen sig på varandra och ingenting går att läsa.
- **Noden färgad efter status, ikonen ovanpå.** Då bär färgen två saker och ikonens färg går förlorad. En prick syns lika bra och stör inte ikonen.
- **Nodens storlek efter antal relationer.** Ger en betydelse som inte är uppenbar, och gör små items svåra att träffa.
