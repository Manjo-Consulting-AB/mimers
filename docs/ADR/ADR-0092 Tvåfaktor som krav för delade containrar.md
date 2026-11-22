# ADR-0092 Tvåfaktor som krav för delade containrar

**Status:** Antagen 2026-11-22 · Bygger vidare på [[ADR-0003 Åtkomstmodell]], [[ADR-0011 Autentisering]] och [[ADR-0087 MCP-server för agenter]] · [[ADR-index]]

Tonys beslut 2026-11-22. Tony svarade på sex frågor samma dag; svaren är besluten nedan. Tony godkände § 1 (ägarens egen tvåfaktor), § 7 (flaggan följer med) och § 9 den 2026-11-22.

## Kontext

- Tony 2026-11-22: en ägare som själv har tvåfaktor ska kunna kräva det av dem hen delar med, för hela kontot eller för en container.
- Tvåfaktor finns redan som val för den enskilda användaren (`user.totp_confirmed_at`, [[ADR-0011 Autentisering]]). Det som saknas är att ägaren kan göra det till ett villkor för åtkomsten.

## Beslut

1. **Två nivåer.** `account.require_two_factor` gäller alla kontots containrar, `container.require_two_factor` en enskild. Kravet gäller när någon av dem är satt; en container kan aldrig ha ett lägre krav än kontot. Bara en ägare med bekräftad tvåfaktor kan slå på det, och hen kan inte stänga av sin egen tvåfaktor medan ett krav gäller.
2. **Vad som spärras.** En `container_access` (container-bred eller itemgrant) ger ingenting när kravet gäller och mottagaren saknar bekräftad tvåfaktor. Ägarkontots medlemmar och inboxen berörs inte. Regeln sitter i `ResolveItemScope`, `ContainerPolicy` och `Container::scopeAccessibleBy()` och ingen annanstans.
3. **Inbjudan och översikten.** En inbjuden utan tvåfaktor kan acceptera och ser containern i översikten och på dashboarden med namn och märket *Requires two-factor authentication* — inget annat. Inga notiser, inga mejl, inget i ICS-flödet, sökningen, historiken eller `/tasks`.
4. **Innan kravet slås på** ser ägaren vilka mottagare som saknar tvåfaktor och bekräftar. De förlorar åtkomsten direkt.
5. **Den som stänger av sin tvåfaktor** förlorar åtkomsten omedelbart. Ingen notis, varken till henne eller ägaren.
6. **Agenter och API-token** för en medlem utan tvåfaktor är spärrade på samma sätt som hon själv — annars går kravet att kringgå genom MCP ([[ADR-0087 MCP-server för agenter]]).
7. **Ägarbyte** till någon utan tvåfaktor nekas när kravet gäller, både vid begäran och vid accepten. Kom kravet från det gamla kontot sätts containerns egen flagga vid bytet.
8. **Alla planer.** Kravet är en säkerhetsfunktion, inte en Pro-funktion.
9. **Det ägaren och den låsta ser.** En direktlänk till en låst container ger en sida med namnet och vägen till att slå på tvåfaktor, inte en ren 403. Att slå på och av kravet skrivs i containerns respektive kontots historik. Inbjudningsformuläret säger att den inbjudna behöver tvåfaktor.

## Konsekvenser

- Åtkomstvillkoret får en ny gren på tre ställen som redan bär kommentaren *formulera inte detta en andra gång* — det är där den ska sitta.
- Listorna över containrar behöver en väg som tar med låsta containrar (`includeLocked`); allt annat utesluter dem.
- Ägaren kan låsa ute en befintlig mottagare med ett klick. Bekräftelsen i § 4 är skyddet.
- `account` och `container` får varsin kolumn `require_two_factor`; [[Konton och åtkomst]] beskriver dem och behörighetsregeln.
- Byggs i M74, issue 448–451, i [[M74 Tvåfaktor som krav för delade containrar]].
