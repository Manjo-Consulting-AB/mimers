# M74 · Tvåfaktor som krav för delade containrar

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-22. Milstolpen bygger [[ADR-0092 Tvåfaktor som krav för delade containrar]]: en ägare med tvåfaktor kan kräva det av dem hen delar med, för hela kontot eller för en container.

**Ordningen:** 448 först, sedan 449, 450 och 451 i valfri ordning. Issuerna är skapade i GitHub (#1520–#1523) och sammanfattade här. GitHub-issuen är bindande.

---

### 448. En container kan kräva tvåfaktor av den som når den

GitHub #1520. Kolumnerna `account.require_two_factor` och `container.require_two_factor` läggs till, och en `container_access` ger ingenting när kravet gäller och mottagaren saknar bekräftad tvåfaktor. Regeln sitter i `ResolveItemScope`, `ContainerPolicy` och `Container::scopeAccessibleBy()`, och agenter och API-token för en sådan medlem spärras på samma sätt.

**Läs:** [[ADR-0092 Tvåfaktor som krav för delade containrar]] § 1, 2, 5, 6 och 8 · [[Konton och åtkomst]] § Behörighetsregler

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` high

**Beror på:** —

### 449. Låsta containrar syns med märket men öppnas inte

GitHub #1521. En inbjuden utan tvåfaktor kan acceptera och ser containern i översikten och på dashboarden med namn och märket *Requires two-factor authentication*, inget annat. En direktlänk ger en sida med namnet och vägen till att slå på tvåfaktor, och listorna får `includeLocked`.

**Läs:** [[ADR-0092 Tvåfaktor som krav för delade containrar]] § 3 och 9 · [[Konton och åtkomst]] § container_access

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` elevated

**Beror på:** 448

### 450. Ägaren slår på tvåfaktorkravet för kontot eller containern

GitHub #1522. Ägaren med bekräftad tvåfaktor slår på och av kravet för kontot respektive containern, efter att ha sett vilka mottagare som förlorar åtkomsten och bekräftat. Ändringen skrivs i historiken, ägaren kan inte stänga av sin egen tvåfaktor medan ett krav gäller, och inbjudningsformuläret säger att den inbjudna behöver tvåfaktor.

**Läs:** [[ADR-0092 Tvåfaktor som krav för delade containrar]] § 1, 4 och 9 · [[Konton och åtkomst]] § account

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` elevated

**Beror på:** 448

### 451. Ägarbytet respekterar tvåfaktorkravet

GitHub #1523. Ett ägarbyte till någon utan tvåfaktor nekas när kravet gäller, både vid begäran och vid accepten. Kom kravet från det gamla kontot sätts containerns egen flagga vid bytet.

**Läs:** [[ADR-0092 Tvåfaktor som krav för delade containrar]] § 7 · [[Konton och åtkomst]] § ownership_transfer

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` elevated

**Beror på:** 448
