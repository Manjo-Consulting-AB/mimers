# ADR-0072 Kartan fyller bredden

**Status:** Antagen 2026-11-10 · Ändrar [[ADR-0061 Kartans kort och kanter]] § 7 (barnen högst fyra per rad och högst tre rader) · Ändrar [[ADR-0062 Kartans luft, listans ruta och den löpande sökningen]] § 2 (maxbredden för fyra kort) · Rör inte den smala formen i [[ADR-0063 Itemets karta i containerkartans form]] · [[ADR-index]]

Tonys beslut 2026-11-10, vid genomgången av containerkartan i v0.27.0 med Freya som mitt och sex barn.

## Kontext

Kartläget står som en smal ö mitt i panelen. Rutnätet är låst till `md:max-w-[52.5rem]`, alltså fyra kort på `w-48` (192 px) med tre mellanrum på 24 px. På en bred skärm är panelen omkring 1 200 px, så nästan en tredjedel står tom på var sida. Ytan fylls inte heller när containern växer: fler barn ger fler rader, inte bredare rader. Sidorna fylls bara av de relaterade, och bara i höjd med mitten.

Kortet är så smalt att namnen klipps redan vid tretton tecken, till exempel *Dieselvärm…*.

## Beslut

**1. Kortets storlek följer hur många barn som står på en rad.** Kartan har tre fasta storlekar. Alla noder i kartan har samma storlek: föräldrarna, barnen, de relaterade och mitten.

| Storlek | Kort | Mitten | Ikonens ruta | Mellanrum i sidled |
|---|---|---|---|---|
| stor | `w-60` (240 px) | `w-64` (256 px) | 48 px | 24 px |
| mellan | `w-48` (192 px) | `w-56` (224 px) | 40 px | 24 px |
| liten | `w-44` (176 px) | `w-56` (224 px) | 32 px | 16 px |

Mellanstorleken är dagens kort. Storleken byter i steg och glider aldrig, så att kartan inte ändras lite grann varje gång ett barn läggs till.

**2. Barnen fördelas jämnt på högst två rader.** Med *n* barn står `n` på en rad när `n ≤ 4`, och annars `ceil(n / 2)` per rad med taket sex. Raderna blir alltså lika långa eller skiljer sig med ett, och ett ensamt kort på sista raden förekommer aldrig. Storleken väljs ur antalet per rad: fyra eller färre ger stor, fem ger mellan och sex ger liten.

| Barn | Per rad | Storlek |
|---|---|---|
| 1–4 | 1–4, en rad | stor |
| 5–8 | 3 + 2 … 4 + 4 | stor |
| 9–10 | 5 + 4, 5 + 5 | mellan |
| 11–12 | 6 + 5, 6 + 6 | liten |

Storleken krymper aldrig när antalet barn minskar, och växer aldrig när det ökar. Serverns tak på tolv barn står kvar (ADR-0059 § 6).

**3. Kartan räknar med den bredd ramen har, inte med skärmens.** Panelen bredvid skalets meny är smalare än skärmen, och de relaterade på sidorna tar också plats. Vyn mäter kartans ram och väljer den första uppställning som får plats. Fler kort per rad går före större kort:
1. Antalet per rad prövas från det beslut 2 ger och nedåt, och varje antal fördelas jämnt på det antal rader det kräver.
2. För varje antal prövas storlekarna från den som antalet ger och nedåt.
3. En uppställning får plats när den bredaste av barnens rad, föräldrarnas rad och mittens rad, med de relaterade och deras luft, ryms i ramen.

Ryms inte två rader med små kort blir det fler rader. På en laptop blir tolv barn därför tre rader med fyra mellanstora kort, som i dag. Två rader är ett tak när det finns plats och ingen garanti. Innan ramen är mätt ritas uppställningen ur beslut 2.

**4. Det gäller bara från `md:`.** Under `md:` står kartan som i dag, med mellanstorleken och korten i den smala uppställningen. Den smala formen med `compact` på itemets sida (ADR-0063) rörs inte och har alltid mellanstorleken.

**5. Luften mellan raderna står kvar.** 24 px mellan barnens rader, `mt-14` mellan raderna föräldrar, mitten och barnen, och `gap-12` mellan mitten och de relaterade (ADR-0062 § 2). Bara mellanrummet i sidled följer storleken.

## Motivering

Få barn är det vanliga fallet, och det är där tomheten syns mest. Stora kort ger kartan tyngd och plats för hela namn.

Jämna rader ser balanserade ut vid varje antal. Med sex per rad och fyllning från vänster hade sju barn blivit sex och ett.

Att mäta ramen är det enda sättet att veta vad som får plats. Skärmens bredd säger inget om skalets meny eller om de relaterade. Linjerna mäter redan korten i samma ram (`ItemMapEdges`), så de följer med när korten byter storlek.

## Konsekvenser

- Issue 349 i [[M49 Kartan fyller bredden]].
- Uppställningen räknas av en ren funktion i `resources/js/support/`. `ItemMap.vue` mäter ramen och skickar storleken till varje `ItemMapNode`, och raden får sin bredd ur antalet per rad i stället för ur `md:max-w-[52.5rem]`.
- `ItemMapNode.vue` får proppen `size`, med mellanstorleken som förval. Fokuskartan och den smala formen skickar ingen.
- Servern och `ResolveItemMap` ändras inte.

## Alternativ

**Bara låta rutnätet växa till sex per rad.** Valdes bort: få barn hade fortfarande gett små kort mitt i en tom yta.

**Bara större kort, fyra per rad.** Valdes bort: tolv barn hade blivit tre rader med stora kort, och kartan hade skjutit teckenförklaringen ned under skärmkanten.

**Kort som krymper steglöst med antalet.** Valdes bort enligt beslut 1: kartan hade ändrats lite vid varje nytt barn.

**Välja uppställning efter skärmens brytpunkter.** Valdes bort enligt motiveringen.
