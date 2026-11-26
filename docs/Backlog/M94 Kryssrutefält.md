# M94 · Kryssrutefält

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-26. Efter milstolpen kan ett eget fält vara ja/nej, och det visas som en kryssruta. Besluten står i [[ADR-0099 Kryssrutefält]], som ändrar [[ADR-0089 Egna fält på items]] § Riktning 1 och 2:

- **Tonys beslut 2026-11-26:** Ett eget fält kan, utöver text, tal och datum, vara **ja/nej** och visas som en kryssruta.
- Ett ja/nej-fält räknas aldrig som tomt. Ett fält som inte är ikryssat betyder *nej* och visas alltid, i vyn och i formuläret, annars går det inte att kryssa i på itemet.
- Fältet har två lägen, ja och nej. "Inte besvarat" finns inte.

**Ordningen:** 509, sedan 510. Issuerna är skapade i GitHub (#1721–#1722) och sammanfattade här. GitHub-issuerna är bindande.

---

### 509. Ja/nej-fält i databasen, API:et och exporterna

GitHub #1721. Efteråt finns typen `boolean` och kolumnen `item_field_value.value_boolean`, och API:et, MCP och exporterna ger alltid `true` eller `false`.

Ett ikryssat fält är en rad med `1`; ett fält som inte är ikryssat har ingen rad. Filtret har `eq` med `1` eller `0`, där `0` betyder att raden saknas. Sorteringen räknar en saknad rad som `0`. Indexet `(item_field_id, value_boolean)` läggs till.

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` elevated

**Beror på:** -

### 510. Ja/nej-fältet i formuläret, på itemets vy och i filtret

GitHub #1722. Efteråt kan användaren skapa ett ja/nej-fält, se det som en kryssruta på itemets vy och filtrera på det.

Kryssrutan visas alltid och är aktiv för den som får ändra itemet. Den sparas direkt med den befintliga `PATCH` av itemet.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none

**Beror på:** 509
