# M67 · Versionshistorik

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-18, efter diskussionen om att ett item som skrivs över inte går att få tillbaka. Kontot sätter taket för antalet versioner per item (standard 3), containern kan sänka eller stänga av det, ägaren betalar, och täta skrivningar blir en version. Beslutet och motiveringen står i [[ADR-0088 Versionshistorik för items]].

Fem issues. Filer, scheman, kostnader, lån, bilagor och relationer versioneras inte, och export bär inte versionerna. Se ADR:ns Riktning 2 och 6.

**Releasen:** en, när 411–414 är mergade. 415 följer [[M66 MCP-server för agenter]] och går ut med den. Issuerna skrevs direkt i GitHub (#1404–#1408) och är sammanfattade här.

---

### 411. Inställningarna och tabellen

GitHub #1404. **Läget:** versionerna finns inte, och kontot och containern har ingen inställning för dem.

**Lösningen:** `account.versioning_max_versions` (0–20, förval 3 även för befintliga konton) och `container.versioning_max_versions` (NULL följer kontot, 0 är av), `VersionLimit::for` som räknar fram det som gäller vid varje skrivning, avsnittet *Version history* på `/settings/storage` och i containerns inställningar (bara ägarkontots medlemmar, samma grind som `ContainerPolicy::delete`), samt tabellen `item_version`. `item_version` räknas i `MetadataSize` och `CountedRows` med `billed_account_id` som betalare. Inga versioner sparas ännu.

**Läs:** [[ADR-0088 Versionshistorik för items]] § Riktning 1 och 3, [[ADR-0081 All lagrad data räknas mot kvoten]] § 2 och 3

**Axlar:** `ambiguity` low · `blast_radius` cross-module (kvotens räknade tabeller) · `risk_class` elevated (kvoter)

**Klart när:** ett nytt och ett befintligt konto har 3; `VersionLimit::for` ger kontots värde, containerns lägre värde eller 0; ett värde över 20 avvisas och containerns över kontots avvisas med `versioning.above_account`; en inbjuden med `write` kan inte ändra containerns värde; en `item_version`-rad räknas i `metadata_bytes` för `billed_account_id` och avstämningen räknar den; hela sviten är grön.

**Beror på:** GitHub #1403 (ADR-0088)

---

### 412. Versioner sparas vid varje ändring av ett item

GitHub #1405. **Läget:** en ändring av ett item skriver över det gamla läget.

**Lösningen:** `RecordItemVersion` anropas före `MetadataLedger::save` av `UpdateItem` och `UpdateItemNotes` och sparar läget före ändringen, med taggmängden. Skrivningar från samma aktör med högst 10 minuter emellan blir en version (`versioning.burst_minutes`). Taket hålls vid varje skrivning och de äldsta tas bort. Full kvot hos ägaren stoppar inte skrivningen: de äldsta versionerna tas bort för att ge plats, och ryms den ändå inte sparas ingen version och ägaren får `versioning.skipped` en gång per container och dygn. Under rättslig spärr tas inget bort.

**Läs:** [[ADR-0088 Versionshistorik för items]] § Riktning 2, 3, 4 och 6, [[ADR-0066 Itemets anteckning]] § 10, [[Notiser]] § notification

**Axlar:** `ambiguity` low · `blast_radius` cross-module (`UpdateItem` läses av allt som skriver items) · `risk_class` elevated (kvoter och rättslig spärr)

**Klart när:** en ändring sparar en version med de gamla fälten och taggarna och containerns ägare som betalare; ingen ändring och taket 0 sparar inget; tre skrivningar med 5 minuter emellan ger en version, och 11 minuter efter den sista ger nästa en ny; en annan användare inom fönstret ger en ny version; taket 3 ger aldrig fler än 3; anteckningens autosparning ger en version per arbetspass; full kvot låter skrivningen gå igenom; under spärr tas inget bort; `metadata_bytes` stämmer med avstämningen; hela sviten är grön.

**Beror på:** 411

---

### 413. Visa och återställa ett items versioner

GitHub #1406. **Läget:** versionerna sparas (412) men ingen kan se dem.

**Lösningen:** fliken *History* får avsnittet *Earlier versions*, nyast först med vem som ersatte versionen och när, och en version öppnas i en dialog. `RestoreItemVersion` skriver genom `UpdateItem` och sparar först nuläget med `breakBurst: true`, så att återställningen går att ångra. Det som inte längre finns (kategori, tagg, omslagsbild) hoppas över och räknas upp i svaret. API:et får lista, visa och återställa med ULID:er. Att se kräver `ItemPolicy::view`, att återställa kräver `update`.

**Läs:** [[ADR-0088 Versionshistorik för items]] § Riktning 4 och 5, [[ADR-0028 Åtkomst på itemnivå]]

**Axlar:** `ambiguity` low · `blast_radius` cross-module (itemsidan och API:et) · `risk_class` elevated (behörighet)

**Klart när:** en inbjuden med `view` ser versionerna men får 403 på återställning; med `write` blir itemets fält och taggar versionens; efter en återställning finns det förra läget som en ny version; raderade kategorier, taggar och omslagsbilder hoppas över och räknas upp i `meta.skipped`; en version från ett annat item ger 404; `item.updated` bär `meta.restored_from` och ingen fritext; fliken visar agentens namn när en agent ersatte versionen; hela sviten är grön.

**Beror på:** 412, 411

---

### 414. Versionernas livscykel: sänkt tak, papperskorgen och ägarbyte

GitHub #1407. **Läget:** versioner tas bara bort av taket vid en skrivning.

**Lösningen:** `TrimItemVersions` tar bort överskottet när kontots eller containerns tak sänks eller stängs av. Ett slängt item behåller sina versioner, och de frigörs när itemet, containern eller kontot raderas för gott. `AcceptOwnershipTransfer` raderar versionerna i ägarbytets transaktion och frigör dem från den förra ägaren. Under rättslig spärr raderas inget, och listan visar bara så många som taket tillåter.

**Läs:** [[ADR-0088 Versionshistorik för items]] § Riktning 6, [[ADR-0008 Soft delete och papperskorg]] § Uppföljning 2026-11-11, [[ADR-0081 All lagrad data räknas mot kvoten]] § 3

**Axlar:** `ambiguity` low · `blast_radius` cross-module (ägarbytet och papperskorgens gallring) · `risk_class` elevated (radering och kvoter)

**Klart när:** ett sänkt tak lämnar högst det antalet per item och `metadata_bytes` minskar; en containers 0 tar bara den containerns versioner; en höjning raderar inget; ett mjukraderat item behåller versionerna; ett item som raderas för gott tar dem med sig och räknaren stämmer; efter ett ägarbyte saknar items versioner och en bytet som faller lämnar dem orörda; under spärr raderas inget; hela sviten är grön.

**Beror på:** 411, 412, 413

---

### 415. Versioner för agenter och MCP-verktygen

GitHub #1408. **Läget:** en agents skrivning sparar inte agenten som aktör, och agenten kan inte se eller återställa versioner.

**Lösningen:** MCP:s skrivverktyg skickar agentens id vidare till `RecordItemVersion`, så att skurven skiljer användarens och agentens skrivningar. Verktygen `items_versions_list` (läsning) och `items_versions_restore` (skriva) lägger sig bredvid de andra och prövar agentens rättigheter som de. Kontraktsprovet uppdateras med avsikt.

**Läs:** [[ADR-0088 Versionshistorik för items]] § Riktning 4 och 5, [[ADR-0087 MCP-server för agenter]] § Riktning 3, 9, 10 och 15

**Axlar:** `ambiguity` low · `blast_radius` cross-module (MCP-verktygen och skurvens aktör) · `risk_class` elevated (agentens skrivrättigheter)

**Klart när:** en agents `items_update` sparar `replaced_by_agent_id`; användarens skrivning direkt efter agentens ger en ny version; `items_versions_list` avvisas för en agent som inte når containern; `items_versions_restore` kräver samma rättighet som `items_update`; kontraktsprovet har de två verktygen; hela sviten är grön.

**Beror på:** 412, 413 och GitHub #1390 och #1391 (läs- och skrivverktygen i [[M66 MCP-server för agenter]])
