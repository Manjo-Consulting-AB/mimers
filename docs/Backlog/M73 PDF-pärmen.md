# M73 · PDF-pärmen

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-22. Milstolpen bygger [[ADR-0091 PDF-pärmen]]: en utskrivbar pärm över en container eller ett item, byggd i exportkön med försättsblad, innehållsförteckning och sidnummer, med bilagor som sidor eller länkar efter användarens val.

**Ordningen:** 443 först. 444 och 447 väntar på 443, 445 väntar på 444 och 446 kommer sist. Issuerna är skapade i GitHub (#1514–#1518) och sammanfattade här. GitHub-issuen är bindande.

---

### 443. PDF-pärmens motor

GitHub #1514. `export` får `kind`, `item_id` och `options`, och `BinderBuilder` bygger en pärm utan bilagor i kön: försättsblad, innehållsförteckning, items i hierarkisk ordning och sidnummer genom hela pärmen. dompdf ritar delarna en och en och FPDI sätter ihop dem, inom arbetarens minne. Ingen yta i appen ännu.

**Läs:** `ContainerExportBuilder`, `BuildContainerExport`, `Export`, [[ADR-0031 Köarbetaren körs av schemaläggaren]], [[ADR-0070 Subprocesser hos inleed]]

**Axlar:** `ambiguity` medium · `blast_radius` cross-module · `risk_class` elevated

**Beror på:** —

### 444. Bilagorna i PDF-pärmen

GitHub #1515. Användaren väljer vilka bilagor som följer med per item och om de slås ihop eller läggs som länkar. PDF:er och bilder läggs in som sidor, och allt annat, oläsliga PDF:er och bilagor över taket för sammanslagning står som länkar. Pärmen misslyckas aldrig på grund av en bilaga.

**Läs:** `BinderBuilder` (från 443), `ContainerExportBuilder`, [[ADR-0007 Fillagring hos inleed]]

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` elevated

**Beror på:** 443

### 445. PDF-pärmen beställs från containerns exportsida

GitHub #1516. Exportsidan får ett avsnitt *PDF binder* med sektioner, bilagor per item och valet mellan länkar och sammanslagning, och en ny POST-rutt kontrollerar `pdf_binder` och köar bygget. Gratisplanen ser en rad om Pro, och listan visar både ZIP och pärm.

**Läs:** `ExportController`, `Containers/Export.vue`, `ExportResource`, `routes/web.php` (blocket *Issue 67c · Exporten*)

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` elevated

**Beror på:** 443, 444

### 446. PDF-pärmen för ett item

GitHub #1517. Samma motor bygger en pärm för ett enskilt item: itemets försättsblad, ingen förteckning och inga barn. Itemvyns meny får *Create PDF binder* för Pro, och dialogen återanvänder valen från 445.

**Läs:** `BinderBuilder` (från 443), komponenten för valen från 445, `Containers/Items/Show.vue`

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` none

**Beror på:** 445

### 447. Notisen när PDF-pärmen är klar

GitHub #1518. Den nya notistypen `binder.ready` skapas av jobbet när en pärm blir klar och visas bara i appen, med en länk till exportsidan. ZIP-exporten får ingen notis, och en misslyckad pärm syns på exportsidan som i dag.

**Läs:** `Notification`, `NotificationPreferences`, `NotificationRow.vue`, `notificationPresentation.js`

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` none

**Beror på:** 443
