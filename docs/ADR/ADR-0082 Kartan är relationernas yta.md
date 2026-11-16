# ADR-0082 Kartan är relationernas yta

**Status:** Antagen 2026-11-16 · Ändrar [[ADR-0046 Containerns karta]] § Konsekvenser (*"En graf över alla relationer byggs inte"*) och § Alternativ (den kraftbaserade grafen), [[ADR-0059 Kartan med en mitt]] § 7 (*ingen zoom*), [[ADR-0063 Itemets karta i containerkartans form]] (relationsflikens läge *Focus*) och [[ADR-0068 Itemets sida och skapandet]] § 4 (flikarna) och § 5 punkt 3 (snabbåtgärden *Add relation*) · Bygger vidare på [[ADR-0028 Åtkomst på itemnivå]], [[ADR-0040 Underträdets summor]] och [[ADR-0080 Ny förälder från kartan]] · § 4 (`depth=2`), § 5 (två steg, kraftbaserad) och § 9 (`d3-force` för *Neighbourhood*) ändrade av [[ADR-0083 Grannskapet är itemets släkt]]: hela släkten i `depth=linked`, ritad som ett radialt träd med ringar · § 6 (statusfärg, namn först vid zoom) ändrad av [[ADR-0084 Hela containern med itemets ikon]]: ikonen som nod, statusen som prick, namnen direkt under 40 noder · [[ADR-index]]

Tonys beslut 2026-11-16, efter en testares fynd om att relationsvyn under ett item är redundant. Tony bad om milstolpen [[M62 Kartan är relationernas yta]] och kodissuerna samma dag och satte dem i bygg.

## Kontext

En testare skrev 2026-11-16: *"Relationsvyn under ett item är ganska redundant då den visar samma sak som Map fast med lite mindre data och möjligheten att lägga till info."* Det stämmer.

- Från `lg:` visar högerpanelen kartan hela tiden, och fliken *Relations* ritar samma föräldrar, barn och relaterade en gång till som lista (`ItemLinkSection`, issue 155).
- Under `lg:` står kartan redan på översikten ([[ADR-0068 Itemets sida och skapandet]] § 5 punkt 5), och fliken har dessutom växeln *List/Focus*, där *Focus* är samma karta (issue 156).
- ADR-0068 § 5 säger att relationerna visas en gång på varje bredd. Det gör de inte. Det enda fliken tillför är formuläret för att lägga till och *Knyt upp* per rad.
- Fliken är också den fulla kartans fullständiga lista: *+N till* i containerns kartläge leder till `?tab=relations` (`Index.vue`, `overflowHref`). Plusmenyns rad *Relation* (`CreateTarget`) och snabbåtgärden *Add relation* (`Show.vue`, `relationsHref`) leder dit också.

Tony vill dessutom kunna zooma ut kartan till hela containern, för en grafisk översikt i stil med The Brain och Obsidian. [[ADR-0046 Containerns karta]] valde bort en kraftbaserad graf därför att den *"kräver ett layoutbibliotek, blir oläslig över ett par hundra noder och är nästan oanvändbar på en telefon"*. Containrarna är små (Tony 2026-11-16), så det andra skälet faller bort. Det första är en kostnad vi tar. Det tredje hanteras i Beslut 7.

## Beslut

**1. Relationsfliken utgår.** Flikraden blir *Overview · Documents · Costs · To do · Loans · History*. Växeln *List/Focus* utgår med fliken. En gammal länk med `?tab=relations` hamnar på översikten, eftersom en okänd flik redan faller tillbaka dit (issue 100). Ingen omdirigering byggs.

**2. Den lilla kartan är snabböversikten och ändras inte.** Kartpanelen från `lg:` och kartan på översikten under `lg:` ritar `ItemMap` med `compact` som i dag ([[ADR-0063 Itemets karta i containerkartans form]] § 1). Den bär ingen redigering. *Open in map* leder som i dag till den fulla kartan med itemet som mitt.

**3. Den fulla kartans nivå *Focus* är platsen där relationer ändras.** Det gäller bara när mitten är ett item. Kring containern finns inget att knyta.

- *Lägga till:* plusmenyns rad *Relation* på mittkortet, och snabbåtgärden *Add relation* på itemets sida, leder till `?view=map&focus=<ulid>&add=relation`. Där öppnas formuläret från `ItemLinkSection`: motpart, riktning och förklaringen vid *Related* (issue 58 § Beslut 8). Efter att relationen sparats står man kvar på kartan, och den nya noden syns.
- *Knyta upp:* varje kort runt mitten får åtgärden *Unlink*, bakom appens egen bekräftelsedialog ([[ADR-0068 Itemets sida och skapandet]] § 6). Texten säger att bara kopplingen försvinner. Grinden är densamma som *Knyt upp* har i dag, och åtgärden ritas bara för den som släpps igenom.
- *Hela gruppen:* *+N till* fäller ut gruppen till en lista med alla dess items, i kartans ram, med länk och *Unlink* per rad. Länken till `?tab=relations` utgår. Det är den här listan som gör att fliken kan tas bort utan att något försvinner. Den är också kartans textform för tangentbord och skärmläsare, som fliken har varit.

**4. Tre zoomnivåer i adressen.** Den fulla kartan får en växel *Focus · Neighbourhood · Whole container*. Nivån står i querysträngen som alla andra lägen: ingen parameter är *Focus*, `depth=2` är *Neighbourhood* och `depth=all` är *Whole container*. Nivåerna är fasta steg och ingen kontinuerlig zoom, så att det är tydligt vad man får göra i varje vy. Det som går att göra blir mindre ju längre ut man zoomar.

**5. *Neighbourhood*.** Mitten och varje item inom två steg från den, över alla tre slagen (förälder, barn och relaterad). Layouten är kraftbaserad. Linjerna har samma färg och streckning som i dag ([[ADR-0061 Kartans kort och kanter]] § 5): heldragna till föräldrar och barn, streckade till de relaterade, inga pilspetsar. Ett klick på en nod gör den till mitt på samma nivå. Fokus eller hovring visar namn och status. Ingen redigering, ingen plusmeny.

**6. *Whole container*.** Varje item i containern som läsaren når, och varje relation mellan två sådana items. Bara läsning: panorera, zooma (hjul, nyp, knappar), och sökfältet (`?q=`) tänder träffarna. Noderna färgas efter status (`ItemStatus`, samma `ok` och `overdue` som kartan redan får). Namnen ritas först när man zoomat in tillräckligt. Är ett item mitt (`focus`) markeras det. Ett klick på en nod går till *Focus* med noden som mitt. Ingen gruppering i första versionen.

**7. *Neighbourhood* och *Whole container* finns från `md:`.** Det är där linjelagret redan finns. Under `md:` ritas ingen växel, `depth` ignoreras och kartan är *Focus*. [[ADR-0046 Containerns karta]]:s argument om telefonen gäller fortfarande.

**8. En ny fråga med samma omfång.** De två nya nivåerna läses ur en egen fråga på servern som lämnar noder och kanter. Omfångsfiltret är [[ADR-0028 Åtkomst på itemnivå]]:s, prövat per item. En kant ritas bara när båda ändarna ligger i omfånget, och ingenting säger att något föll bort: ingen spöknod och inget *"N dolda"*, av samma skäl som i `ListItemLinks`. Items i papperskorgen är inte med. Som skydd lämnar frågan högst 500 noder, och över det visar *Whole container* en mening i stället för grafen. Taket väntas inte nås.

**9. Layouten är `d3-force` och ritas som SVG.** Paketet är det första tunga npm-beroendet utöver `lucide`, och det godkänns genom att ADR:en antas (AGENTS.md § Nya beroenden). SVG behåller designsystemets färger och mörka läge. Startpositionerna följer serverns ordning (namnordning), så att samma container ser likadan ut vid varje besök.

## Konsekvenser

- [[ADR-0046 Containerns karta]]:s rad om att en graf över alla relationer inte byggs, och dess avvisade alternativ, gäller inte längre. [[ADR-0059 Kartan med en mitt]] § 7 (ingen zoom) gäller bara nivån *Focus*. Relationsflikens läge *Focus* i [[ADR-0063 Itemets karta i containerkartans form]] utgår med fliken.
- Tre anropare leder i dag till `?tab=relations` och pekas om: `CreateTarget` (raden *Relation*), `Show.vue` (`relationsHref`, snabbåtgärden) och `Index.vue` (`overflowHref`, *+N till*).
- Proven som räknar itemets flikar och läser relationsfliken (bland andra `ItemflikTest` och `GenomgangTest`) ändras i kodissuerna.
- Byggs i [[M62 Kartan är relationernas yta]]: 392 är Beslut 1–3 (redigeringen i den fulla kartan och fliken bort), 393 är Beslut 4, 5, 7 och 9 (växeln, *Neighbourhood* och `d3-force`), och 394 är Beslut 6 och 8 (*Whole container* och den nya frågan). 394 beror på 393. 392 står för sig.

## Alternativ som valts bort

- **Redigeringen i en dialog på itemets sida, öppnad från kartpanelen.** Valdes bort (Tony 2026-11-16): den lilla kartan ska vara snabböversikt, och den fulla kartan har platsen.
- **Kontinuerlig zoom utan nivåer.** Då blir det oklart vad man får göra var, och sidan blir plottrig.
- **Ett djupreglage 1–5 som Obsidians lokala graf.** Två steg är grannskapet. Fler steg kan komma senare utan att något i det här beslutet ändras.
- **Canvas eller WebGL (sigma.js, graphology).** Behövs för tusentals noder. Containrarna är små (Tony 2026-11-16), och SVG behåller designsystemet.
- **Grafen på mobilen.** [[ADR-0046 Containerns karta]]:s argument gäller.
- **Gruppering efter rot eller kategori på *Whole container*.** Skjuts upp tills grafen har använts.
