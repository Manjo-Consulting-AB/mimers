# M74 · Tvåfaktor som krav för delade containrar

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-22. Milstolpen bygger [[ADR-0092 Tvåfaktor som krav för delade containrar]]: en ägare med tvåfaktor kan kräva det av dem hen delar med, för hela kontot eller för en container.

**Ordningen:** 448 först, sedan 449, 450 och 451 i valfri ordning. Issuerna är skapade i GitHub (#1520–#1523) och sammanfattade här. GitHub-issuen är bindande.

---

### 448. En container kan kräva tvåfaktor av den som når den

GitHub #1520. Kolumnerna `account.require_two_factor` och `container.require_two_factor` läggs till, och en `container_access` ger ingenting när kravet gäller och mottagaren saknar bekräftad tvåfaktor. Regeln sitter i `ResolveItemScope`, `ContainerPolicy`, `Container::scopeAccessibleBy()` och `ItemAudience`, och agenter och API-token för en sådan medlem spärras på samma sätt.

**Läs:** `app/Actions/Access/ResolveItemScope.php` (docblocken), `app/Policies/ContainerPolicy.php` (`view`, `hasContainerAccess`), `app/Models/Container.php` (`scopeAccessibleBy`, `scopeListable`), `app/Support/Agent/ItemAudience.php` (docblocken) och [[ADR-0028 Åtkomst på itemnivå]]

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` high

**Beror på:** —

### 449. Låsta containrar syns med märket men öppnas inte

GitHub #1521. En inbjuden utan tvåfaktor kan acceptera och ser containern i översikten och på dashboarden med namn och märket *Requires two-factor authentication*, inget annat. En direktlänk ger en sida med namnet och vägen till att slå på tvåfaktor, och listorna får `includeLocked`.

**Läs:** `app/Actions/Container/ListContainerSummaries.php`, `app/Actions/Container/ListShellContainers.php`, `resources/js/components/ContainerCard.vue`, `resources/js/components/ShellContainerList.vue` och `app/Models/Container.php` (`scopeAccessibleBy` efter 448)

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` elevated

**Beror på:** 448

### 450. Ägaren slår på tvåfaktorkravet för kontot eller containern

GitHub #1522. Ägaren med bekräftad tvåfaktor slår på och av kravet för kontot respektive containern, efter att ha sett vilka mottagare som förlorar åtkomsten och bekräftat. Ändringen skrivs i historiken, ägaren kan inte stänga av sin egen tvåfaktor medan ett krav gäller, och inbjudningsformuläret säger att den inbjudna behöver tvåfaktor.

**Läs:** `app/Http/Controllers/Settings/SecurityController.php`, `app/Http/Controllers/ContainerSharingController.php`, `resources/js/pages/Settings/Security.vue`, `resources/js/pages/Containers/Sharing.vue`, `app/Http/Controllers/Auth/TotpController.php` och `app/Http/Controllers/Api/Auth/TotpController.php` (`destroy`)

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` elevated

**Beror på:** 448

### 451. Ägarbytet respekterar tvåfaktorkravet

GitHub #1523. Ett ägarbyte till någon utan tvåfaktor nekas när kravet gäller, både vid begäran och vid accepten. Kom kravet från det gamla kontot sätts containerns egen flagga vid bytet.

**Läs:** `StoreOwnershipTransferRequest.php`, `AcceptOwnershipTransfer.php` (docblockarna)

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` elevated

**Beror på:** 448
