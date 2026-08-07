# ADR-0004 Fria taggar och kategorier

**Status:** Antagen 2026-08-03 · [[ADR-index]]

## Kontext

Ett item behöver kunna placeras i båten. Tre alternativ övervägdes i [[High level overview]]: taggar och kategorier som motsvarar utrymmen, markering på två 2D-kartor som ger en 3D-position, eller kategorier kopplade till kartpunkter.

## Beslut

**Position modelleras enbart som taggar och kategorier.** Systemet vet ingenting om vad "akterruffen" är — det är bara ett ord användaren hittat på. Ett item tillhör **högst en kategori** (hierarkisk) och **flera taggar** (platta, utan undertaggar).

Kartfunktionen ligger utanför MVP.

## Motivering

Skillnaden mellan tagg och kategori blir annars otydlig. Med ett item i högst en kategori är rollfördelningen tydlig: kategorin är var saken hör hemma, taggarna är allt annat man vill kunna filtrera på.

Det tomma pappret är dessutom det som gör produkten lika användbar för en husvagn utan en rad ny kod, och det slipper problemet med vad taggarna ska heta på varje språk.

Kartan är den enskilt dyraste delen att bygga och fyller funktionellt samma roll som en tagg vid namn "akterstuv". Den ser bra ut på hemsidan och underlättar försäljning, men den kan byggas ovanpå taggarna senare utan schemaändring.

## Konsekvenser

- **Sök och filtrering blir produkten.** När strukturen är helt fri finns ingen annan väg till innehållet. Indexet på `item_tag` är inte valfritt. Se [[Items och organisation]].
- En tom pärm vid registrering är avskräckande. Färdiga kategoriuppsättningar — "segelbåt", "husvagn" — hör hemma i respektive **frontend**, inte i backend. Då slipper API:et någonsin veta vad orden betyder eller på vilket språk.
- Kategorihierarkin kräver djupbegränsning och cykelkontroll i applikationslagret.

## Alternativ

**Fördefinierade utrymmen som systementitet.** Skulle ge bättre struktur och möjliggöra kartan direkt. Valdes bort — kräver översättning, låser produkten vid båtar, och tvingar användaren in i någon annans indelning.

**Item i flera kategorier.** Valdes bort — då blir kategori och tagg funktionellt samma sak och användaren förstår inte skillnaden.
