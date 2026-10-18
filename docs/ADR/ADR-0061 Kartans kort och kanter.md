# ADR-0061 Kartans kort och kanter

**Status:** Antagen 2026-10-18 · Ändrar [[ADR-0059 Kartan med en mitt]] § 3 (var öppna-ikonen sitter), § 5 (vad noden visar) och § 7 (linjerna) · Bygger vidare på [[ADR-0060 Itemets ikon]] · § 3, 5 och 7 ändrade av [[ADR-0062 Kartans luft, listans ruta och den löpande sökningen]]: ögat i stället för pilen, plusmenyn i mittkortet, inga pilspetsar, mer luft och de relaterade på varannan sida · [[ADR-index]]

Tonys beslut 2026-10-18, vid genomgången av fynden i v0.23.0: `docs/Design/findings/map_20261018_082238.png` jämförd med `Minimalist Boat Container Map Dashboard.png` i samma katalog, och vad som avses i `map_20261018_093613.png`.

## Kontext

Kartan från [[M34 Kartan med en mitt]] fungerar men ritas som en wireframe. Den står liten och centrerad i en bred panel. Noderna är små rutor med centrerad text och tre rader (namn, slag, status med antal barn), och linjerna är korta kantlinjer på en `div` som knappt syns. Målbilden har samma struktur, med mitten, föräldrarna ovanför, barnen under och de relaterade på sidorna. Skillnaden ligger i hur kartan är ritad:

- Korten är större, med skugga, en ikon till vänster och namn och slag till höger. Under en förälder står vägen dit.
- Linjerna är böjda och har pilspetsar, och de har en färg per slag.
- De relaterade sprids i en båge på varje sida, och kartan fyller panelens bredd.

Det som står runt kartan i målbilden (*Layout*, zoom, helskärm och menyn med tre punkter) gäller inte det här beslutet. ADR-0059 § 7 och § Alternativ står kvar på den punkten.

## Beslut

**1. Kortet har ikonen till vänster.** Itemets ikon ritas enligt [[ADR-0060 Itemets ikon]] i en ruta på 40 px. Till höger står namnet och under det slagets ord (*Parent*, *Child*, *Related*) i slagets färg. Under en förälders slag står `via`, som i dag. Kortet har `--shadow-card`, `--radius-card` och en fast bredd, så att kartans rader står i linje.

**2. Mitten är större och centrerad.** Ikonen står ovanför namnet, och under namnet står *Current item* i `accent`. Mitten har en kant i `accent` och `accent-soft` som bakgrund. Kring containern ritas ikonen `container` och ordet *Container*.

**3. Öppna-ikonen sitter i kortets övre högra hörn**, där målbilden har sina tre punkter. Den är fortfarande ett eget mål för tangentbordet, med samma `aria-label` (ADR-0059 § 3). Plusmenyn står kvar på mittnoden (§ 4).

**4. Noden visar inte längre antal barn.** Statusen visas bara när den är *overdue*, som en prick i `danger` vid namnet. Pricken har texten i `sr-only` och i `title`. Ett item som är *ok* har ingen prick, eftersom det bara säger att ingenting har hänt. ⇄ och antalet platser visas som i dag, när platserna är fler än en. Det ändrar ADR-0059 § 5.

**5. Linjerna är böjda och har pilspetsar.** De ritas i ett SVG-lager under korten, som kubiska kurvor från kant till kant. Föräldrar och barn får heldragna linjer, föräldrar i `accent` och barn i `success`. De relaterade får streckade linjer i `ink-subtle`. Pilen pekar från mitten mot noden: uppåt mot en förälder, nedåt mot ett barn och åt båda hållen mot en relaterad, eftersom en relation inte har någon riktning. Teckenförklaringens prickar får samma färger, och slagen skiljs fortfarande åt med ord och plats (ADR-0059 § 8).

**6. Linjernas ändar mäts i det ritade.** Korten står i ett rutnät med fasta platser. SVG-lagret läser kortens lägen med `getBoundingClientRect()` och ritar om vid `ResizeObserver` och när mitten byts. Ingen layoutalgoritm och inget bibliotek behövs, eftersom platserna redan är givna och bara linjerna behöver koordinater.

**7. Kartan fyller panelen.** Föräldrarna står på en rad ovanför mitten, med högst fyra. Barnen står under mitten, med högst fyra per rad och högst tre rader. De relaterade står i en båge, tre på varje sida. Den mittersta på en sida står längst ut och de två andra närmare mitten, så att linjerna inte korsar varandra. Taken i ADR-0059 § 6 gäller som förut.

**8. Under `md:` står kartan som i dag.** Korten får ikonen och den nya formen, men de står i en kolumn utan SVG-lager, och de relaterade står under barnen. Bågen kräver bredd, och en smal skärm skrollar aldrig i sidled.

## Motivering

Målbildens struktur är redan byggd. Det som skiljer är linjerna och korten, och de kräver inte det som ADR-0059 valde bort. Platserna är fasta, så linjerna kan mätas i stället för att räknas fram, och ingen graf behöver lösas.

Pilspetsarna valdes bort i ADR-0059 § 7, eftersom placeringen redan visar riktningen. Med böjda linjer och en båge av relaterade är det inte längre lika tydligt, och pilen mot en relaterad i båda ändar säger det placeringen inte kan säga: att relationen saknar riktning.

Antal barn ger lite på kartan, eftersom ett klick på noden visar barnen. Statusen ger mer, men bara när något är försenat.

## Konsekvenser

- Issue 305 och 306 i [[M35 Itemets ikon och kartans kort]].
- `ResolveItemMap` skickar `icon` och `icon_color` på varje nod och på mitten. `children` står kvar i svaret men ritas inte.
- `ItemMapNode.vue` ritas om. `ItemMap.vue` får rutnätet och SVG-lagret.
- `FocusMap` i itemets högerpanel får ikonen ([[ADR-0060 Itemets ikon]] § 9), men inte de böjda linjerna. Den har sin egen form för en smal panel.

## Alternativ

**Räkna fram linjernas ändar ur rutnätet utan att mäta.** Valdes bort: kortens höjd beror på `via` och på teckenstorleken, och en linje som slutar några pixlar bredvid kortet ser trasig ut.

**Ett grafbibliotek för linjerna.** Valdes bort av samma skäl som i ADR-0046 och ADR-0059. Det finns ingen layout att räkna ut.

**Behålla antal barn på kortet.** Valdes bort enligt motiveringen.
