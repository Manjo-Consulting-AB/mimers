# M84 · Hjältens kostnader och lagring

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-24 ur feature requests #1591 och #1592, nedbrutna samma dag med Tonys svar. Hjälten svarar på *vad har jag lagt ut den här månaden?* och *hur mycket utrymme använder jag?*: kostnadsdonuten och kostnadsbrickorna blir en kompakt summering, och ett lagringskort ersätter kvotmodulen i toppraden. Bygger tillägget 2026-11-24 i [[ADR-0090 Dashboardens hjälte]]; [[ADR-0038 Gränsen för Pro i kostnaderna]] gäller oförändrat.

**Ordningen:** 474 först. 475 och 476 står fritt inbördes, och 477 väntar på båda. Issuerna är skapade i GitHub (#1612–#1615) och sammanfattade här. GitHub-issuen är bindande.

---

### 474. Kostnadssumman i hjälten i stället för brickorna och donuten

GitHub #1612. Hjälten ritar `HeroCosts.vue`: rubriken *Costs*, perioden *This month* och en rad per valuta med valutan utskriven. Ingen donut, ingen förklaring, ingen nedbrytning per container, ingen länk och ingenting klickbart; höjden beror bara på antalet valutor, och utan rader ritas ingenting. Summeringen ersätter kostnadsbrickorna och `CostDonut` i `AppHero` på dashboarden och alla toppnivåsidor, också inställningssidorna, och `DashboardStats` tappar kostnadsbrickorna. Under `md:` ritar dashboarden brickorna och `HeroCosts` i standardtonen under bandet. `HeroFigures` ger bara `totals`; nedbrytningen per container räknas inte längre. `CostDonut` står kvar för containerns kostnadsflik, som inte ändras.

**Läs:** [[ADR-0090 Dashboardens hjälte]] § Beslut och § Tillägg 2026-11-22, [[ADR-0038 Gränsen för Pro i kostnaderna]] § Beslut, `resources/js/components/AppHero.vue`, `resources/js/components/DashboardStats.vue`, `resources/js/components/UiStat.vue` (glaset), `formatAmount()` i `resources/js/components/CostDonut.vue`, `app/Actions/Dashboard/HeroFigures.php`, `CostReport::monthForContainers()` i `app/Support/Cost/CostReport.php`

**Klart när:** dashboarden och alla toppnivåsidor med `AppHero` ritar `HeroCosts` i stället för kostnadsbrickorna och donuten; två valutor ger två rader och ingen summa; ingen `CostDonut`, ingen länk och ingen klickhanterare; tomma totaler ritar ingenting; `HeroFigures` ger `costs.totals` och ingen `breakdown`, med samma månad, omfång och tidszon som förut; under `md:` står brickorna och summeringen under bandet; `<h1>` står på samma höjd och hjälten ändrar inte höjd när siffrorna kommer; containerns kostnadsflik ritar som förut.

**Axlar:** `ambiguity` high · `blast_radius` cross-module · `risk_class` none

**Beror på:** —

### 475. Månadens kostnader i containerns hjälte

GitHub #1613. Containerns hjälte ritar `HeroCosts` från 474 med innevarande månads totaler för just den containern, en rad per valuta, i båda formerna `large` och `compact` — på översikten, på de andra flikarna och i itemvyerna. Summeringen går inte att klicka på och länkar inte till kostnadsfliken. En ny delad propp, `containerMonthCosts`, är lat som `containerCounts` och `null` för en gäst, utanför en container och när `view` nekas. Beräkningen bor i `HeroFigures` som en publik metod och använder samma `currentMonth()`, samma `ResolveItemScope::forContainers()` och samma `CostReport`-väg som dashboardens totaler. Mottagarens omfång gäller.

**Läs:** [[ADR-0038 Gränsen för Pro i kostnaderna]] § Beslut, [[ADR-0040 Underträdets summor]], `resources/js/components/ContainerHero.vue`, `containerCounts()` i `app/Http/Middleware/HandleInertiaRequests.php`, `app/Actions/Dashboard/HeroFigures.php`, `resources/js/components/HeroCosts.vue` (issue 474)

**Klart när:** hjälten ritar månadens totaler för containern på översikten, på en annan flik och i itemvyn; en kostnad i en annan container och en kostnad i förra månaden räknas inte; månadsgränsen följer användarens tidszon; en mottagare ser inte en rad utanför sitt omfång; proppen är `null` utanför en container och för en gäst; utan rader ritas ingen `HeroCosts`; summeringen går inte att klicka på.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` elevated

**Beror på:** 474

### 476. Lagringskortet i hjälten

GitHub #1614. `HeroStorage.vue` blir ett kort i hjälten på dashboarden och alla sidor med `AppHero`, inställningssidorna inräknade. Huvudraden är förbrukat av tak, med `quota.used_unlimited` och ingen stapel när taket är obegränsat. Stapeln har accentfärgen och samma toner som `StorageQuota` vid 80 och 100 %, bredden är den faktiska andelen och den har ingen minsta bredd. Underraden visar *Files* och *Text* var för sig med `formatByteSize()`. Hela kortet är en länk till `/settings/storage` med tillgängligt namn, synlig fokusring och en diskret pil. Det läser den delade proppen `storageQuota`, som utökas med `fileBytes`, `textBytes` och `barPercent` ur `ReadPlanUsage`s `usage.storage`; ingen ny summering och ingen ny begäran. Kortet står sist i sifferraden och dess höjd ingår i radens golv. Under `md:` står det under bandet på dashboarden, inte på de andra sidorna.

**Läs:** [[ADR-0081 All lagrad data räknas mot kvoten]], [[ADR-0090 Dashboardens hjälte]] § Tillägg 2026-11-22, [[Planer och kvoter]] § usage_counter, `storageQuota()` och `storageContext()` i `app/Http/Middleware/HandleInertiaRequests.php`, `app/Actions/Plan/ReadPlanUsage.php` (`usage.storage`), `resources/js/components/StorageQuota.vue` (tonerna och nycklarna), `resources/js/components/AppHero.vue`

**Klart när:** kortet ritar förbrukat av tak, stapeln och *Files* och *Text* var för sig; `usedBytes` är `fileBytes + textBytes`, och `barPercent` för 17 MB av 10 GB är cirka 0,17; stapeln ritas med `barPercent` och inget golv; obegränsat tak ger `quota.used_unlimited` och ingen stapel; hela kortet är en länk till `/settings/storage` med tillgängligt namn och fokusring; under `md:` står kortet under bandet bara på dashboarden; `<h1>` står på samma höjd och hjälten ändrar inte höjd; `/settings/storage` och toppradens kvotmodul ritar som förut.

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` elevated

**Beror på:** 474

### 477. Containerns lagringskort i hjälten och kvotmodulen ur toppraden

GitHub #1615. `HeroStorage.vue` får en containerform: när `storageQuota.container` finns är huvudraden `quota.in_container` (*X in the container · Y of Z*) eller `quota.in_container_unlimited`, stapeln är kontots med containerns andel som ett eget segment i en starkare ton, och underraden gäller kontot. `ContainerHero.vue` ritar kortet efter brickorna och `HeroCosts` i båda formerna, men bara när `storageQuota.container` inte är `null`; en gäst med bara en grant får kontokontexten och ser ingen lagring i containerns hjälte. `<StorageQuota>` tas bort ur toppraden i `AppLayout.vue`, och `MobileMenu.vue` behåller sin instans med panelen; `storageBreakdown` står kvar för panelen. Docblocken i `StorageQuota.vue` och i `storageQuota()` skrivs om så att de säger mobilmenyn och hjältarna i stället för toppraden. Kortet länkar till `/settings/storage`.

**Läs:** [[ADR-0081 All lagrad data räknas mot kvoten]], `storageQuota()` och `storageContext()` i `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/components/StorageQuota.vue` (containerns segment), `resources/js/components/HeroStorage.vue` (issue 476), `resources/js/components/ContainerHero.vue`, `tests/Feature/Frontend/KvotmodulTest.php`

**Klart när:** containerns hjälte ritar lagringskortet med containerns andel och kontots förbrukning av taket på översikten, på en annan flik och i itemvyn; containerns segment har bredden `storageQuota.container.percent` och ingen minsta bredd; en gäst med bara en grant ser inget kort; toppraden ritar ingen `StorageQuota` och mobilmenyn ritar den med panelen som förut; kortet länkar till `/settings/storage`.

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` elevated

**Beror på:** 475, 476
