# ADR-0097 Borttagna relationer i papperskorgen

**Status:** Antagen 2026-11-25 · Ändrar issue 14 § Beslut 10 (hård radering av `item_link`) · [[ADR-index]]

Tonys beslut 2026-11-25, på frågan *Relationer som tas bort går inte att få tillbaka* ur [[Tankar]]. Kodarbetet ligger i [[M92 Relationer i papperskorgen och MariaDB-sviten]].

## Kontext

`UnlinkItems` raderar en relation hårt. Varken papperskorgen ([[ADR-0065 Den samlade papperskorgen]]) eller [[ADR-0088 Versionshistorik för items]] fångar det, så en relation som tagits bort av misstag är borta.

Samma sak gäller en agent via [[ADR-0087 MCP-server för agenter]]: en relation som agenten tar bort följer inte med agentens batch och kan inte ångras med batchåterställningen.

## Beslut

**1. En borttagen relation mjukraderas och hamnar i papperskorgen, som items, och gallras efter samma tid.** Versionshistorik för relationer valdes bort av Tony. Utformningen står i issue 502 och 503.

**2. `item_link` får `deleted_at`, `ulid` och agentmärkningen** (`agent_id`, `agent_deletion_batch`), som `item`.

**3. Ingen läsning och ingen behörighet ser en mjukraderad relation.** En mjukraderad `parent`/`child` ger ingen åtkomst nedåt.

**4. Länkas samma par igen raderas den mjukraderade raden hårt, och den nya ersätter den.** UNIQUE gäller även mjukraderade rader.

**5. En flytt till ny förälder och gallringen av ett item raderar fortfarande hårt.** En flytt är ingen borttagen relation.

**6. Papperskorgen får slaget `link`, och `/trash` får läget *Relations*.** Raden syns för den som har `update` på båda ändarna, och bara när båda ändarna är levande.

**7. Återställningen prövar samma regler som `LinkItems`** och skriver `item_link.restored`.

**8. En borttagen relation räknas mot agentens tak** och följer med batchåterställningen, efter items.

## Motivering

Papperskorgen är där användaren letar när något saknas. En relation är data som tar tid att bygga upp, särskilt i kartan, och förlusten syns först långt efteråt.

## Alternativ

**Relationerna versioneras i itemets historik** ([[ADR-0088 Versionshistorik för items]]). Valdes bort av Tony: historiken hör till ett item, en relation har två, och användaren letar efter något borttaget i papperskorgen.

## Konsekvenser

- Issue 502–503 i [[M92 Relationer i papperskorgen och MariaDB-sviten]].
- `item_link` ändras, se [[Items och organisation]] § item_link.
- [[ADR-0065 Den samlade papperskorgen]] får ett fjärde slag och ett femte läge, se dess Tillägg 2026-11-25.
