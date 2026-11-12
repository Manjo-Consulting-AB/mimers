# M56 · Registrering i vyn: Costs

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-12. Bygger [[ADR-0074 Registrering i vyn]] och tillägget om *Costs* daterat 2026-11-12 (Tonys beslut): containerns *Costs*-flik får det infällbara formuläret ovanför kostnadstabellen, med itemet valt i en enda knapp. Skalen kom i [[M52 Registrering i vyn]], och [[M54 Registrering i vyn - Items]] och [[M55 Registrering i vyn - Documents]] gäller de två andra flikarna. Issuerna skrevs direkt i GitHub (#1169, #1170) och är sammanfattade här.

**En öppen fråga som gäller också M56:** kortkommandot `N` och plusknappens mål. Plusknappen rörs inte i M56 och leder fortfarande till itemets kostnadsflik. Frågan står i [[Tankar]] § Öppet.

**Releasen:** en, när 369–370 är mergade.

---

### 369. Kostnadsformuläret som egen komponent, med returadress

GitHub #1169. **Läget:** formuläret för en ny kostnadsrad bor inne i `ItemCostSection.vue` och svarar alltid med itemets kostnadsflik.

**Lösningen:** formuläret bryts ut oförändrat till `CostEntryForm.vue` med props `containerUlid`, `itemUlid` (`null` stänger av *Save*), `costDefaults` och `returnTo`, och händelserna `created` och `dirty`. `StoreCostEntryRequest` tar emot `return`, och `CostEntryController::store()` skickar svaret dit när `TaskCreateController::safeReturn()` godkänner den, med `status=cost-created` och `created_cost`; annars gäller `?tab=costs`. `ContainerCostController` ger `costDefaults`, `costSuppliers` (optional) och `createdCost`. `ItemCostSection` ritar komponenten, och itemets flik ser ut som förut. Proven som läser formulärets källkod pekas om till den nya filen.

**Läs:** `ItemCostSection.vue`; `CostEntryController.php` (`store`); `StoreCostEntryRequest.php`; `ContainerCostController.php`; `TaskCreateController.php` (`safeReturn`)

**Klart när:** `store()` med godkänd `return` landar där med `status=cost-created` och `created_cost`, en ogiltig eller extern `return` på `?tab=costs`; grinden och valideringen är oförändrade; `ContainerCostController` ger `costDefaults`, `costSuppliers` och `createdCost`; `CostEntryForm.vue` finns och `ItemCostSection` ritar den; med `itemUlid = null` är *Save* avstängd; sviten är grön.

**Beror på:** —

### 370. Registrera en kostnad direkt på containerns Costs-flik

GitHub #1170. **Läget:** *Add cost* på containerns *Costs*-flik är en meny som leder till itemets kostnadsflik, där raden skrivs.

**Lösningen:** menyn tas bort och `InlineCreate` ritas direkt ovanför `CostTable`, under brickorna, donuten och Pro-delen, när containern har items. Överst i formuläret står en itemväljare som en `<details>`-knapp i samma form som platsknappen på *To do*; har containern ett enda item är det förvalt. Under den står `CostEntryForm` med `:return-to="page.url"`, så att filtret och sidan står kvar. Formuläret står kvar efter sparningen med datum, valuta och item, och fokus går till beloppet. Den nya raden markeras med `bg-accent-soft` i 2000 ms via propen `highlight` på `CostTable`; döljs den visas `container.costs.created_hidden`. Plusknappen rörs inte.

**Läs:** [[ADR-0074 Registrering i vyn]] § 1–3; `Containers/Costs.vue`; `CostEntryForm.vue`; `CostTable.vue`; `InlineCreate.vue`; `ContainerTaskQuickCreate.vue`

**Klart när:** `Costs.vue` saknar `<details>`-menyn i huvudet och ritar `InlineCreate` ovanför `CostTable` när `items` inte är tom; väljaren är en `<details>`-knapp och ett val laddar ingen sida; en sparning postar med `return` = `page.url` och formuläret står kvar med datum, valuta och item; nya rader får `bg-accent-soft` i 2000 ms och dolda ger `container.costs.created_hidden` med `role="status"`; Escape och *Cancel* stänger och frågar vid osparade ändringar; plusknappen leder som förut; sviten är grön.

**Beror på:** 369, 360
