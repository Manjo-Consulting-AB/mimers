# ADR-0099 Kryssrutefält

**Status:** Antagen 2026-11-26 · Ändrar [[ADR-0089 Egna fält på items]] § Riktning 1 och § Riktning 2 · [[ADR-index]]

Tonys beslut 2026-11-26 om ja/nej-fält i valvet.

## Kontext

- [[ADR-0089 Egna fält på items]] § Riktning 1 räknar upp tre typer: text, tal och datum.
- Frågor som *Försäkrad?* eller *Servad?* går i dag bara att skriva som text, och då går de inte att filtrera på ett pålitligt sätt.
- [[ADR-0089 Egna fält på items]] § Riktning 2 döljer ett tomt fält. Ett ja/nej-fält som dolts när det inte är ikryssat går inte att kryssa i.

## Beslut

*Tony 2026-11-26:* Utformat i issue 509 och 510 i [[M94 Kryssrutefält]].

1. Ett eget fält kan, utöver text, tal och datum, vara **ja/nej** och visas som en kryssruta.
2. Ett ja/nej-fält räknas aldrig som tomt. Ett fält som inte är ikryssat betyder *nej* och visas alltid, i vyn och i formuläret, annars går det inte att kryssa i på itemet. Det är ett undantag från [[ADR-0089 Egna fält på items]] § Riktning 2.
3. Fältet har två lägen, ja och nej. "Inte besvarat" finns inte.

Detaljerna:

- Typen heter `boolean` och visas som *Yes/No*.
- Värdet lagras i den nya kolumnen `item_field_value.value_boolean`. Ett ikryssat fält är en rad med `1`. Ett fält som inte är ikryssat har ingen rad, så regeln *ett tomt värde lagras inte* i [[ADR-0089 Egna fält på items]] § Riktning 9 gäller fortfarande i databasen.
- API:et, MCP och exporterna ger alltid `true` eller `false`, aldrig `null`.
- Filtret har `eq` med `1` eller `0`, där `0` betyder att raden saknas. Sorteringen räknar en saknad rad som `0`.
- På itemets vy är kryssrutan aktiv för den som får ändra itemet. Den sparas direkt med den befintliga `PATCH` av itemet, eftersom ett val sparas direkt och text sparas med *Save*.
- Typlåset i [[ADR-0089 Egna fält på items]] § Riktning 3 gäller oförändrat.

## Alternativ som valts bort

- **Tre lägen** (ja/nej/inte besvarat): kräver ett val i stället för en kryssruta. Tony valde två.
- **Lagra 0/1 i `value_number`:** sparar en migrering men blandar ihop filter, sortering och export för två typer i en kolumn.
- **Dölja ett fält som inte är ikryssat, som andra tomma fält:** då går det inte att kryssa i på vyn (beslut 2).

## Konsekvenser

- Issue 509–510 i [[M94 Kryssrutefält]].
- Ingen ändring av kvoten: raden räknas redan med påslaget.
