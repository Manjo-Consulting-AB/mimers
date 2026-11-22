Closes #1514

## Sammanfattning

Grunden för PDF-pärmen är på plats: en exportrad kan bära en pärm, och en pärm byggs i kön till en PDF med försättsblad, innehållsförteckning och sidnummer. Pint och phpstan är gröna. Hela sviten är grön i båda skärven; ett prov fällde först (se `EXPORT_KINDS` nedan) och är rättat.

**Ändrade filer**
- `composer.json` och `composer.lock`: lade till `dompdf/dompdf` och `setasign/fpdi`. FPDI kräver basklassen FPDF, så `setasign/fpdf` kom också med (se Frågor och antaganden).
- `database/migrations/2026_11_23_000000_add_binder_columns_to_export_table.php`: tre nya kolumner på `export`.
  - `kind` VARCHAR(20) NOT NULL DEFAULT `'zip'` med CHECK (`zip`, `binder`) på mysql, så befintliga rader blir `zip`.
  - `item_id` NULL FK till `item`, ON DELETE RESTRICT.
  - `options` JSON NULL.
- `app/Models/Export.php`: `KIND_ZIP`, `KIND_BINDER`, `EXPORT_KINDS` och en `options`-cast till array. Konstanten heter inte `KINDS`, eftersom `ContainerCrudTest` förbjuder det ordet i `app/`.
- `database/factories/ExportFactory.php`: de tre nya fälten med förval.
- `app/Support/Export/BinderBuilder.php` (ny): byggaren.
  - Den bygger försättsblad, innehållsförteckning, items i parent/child-ordning och sidnummer, och följer de sektioner och kostnadsval som `options` anger.
  - Omfånget är beställarens `ItemScope`. Skrivningen går till `.pdf.part` som byts namn sist.
- `app/Support/Export/ContainerExportBuilder.php`: `fieldEntries()` är nu `public` så pärmen återanvänder logiken för egna fält. Ingen annan ändring.
- `app/Jobs/BuildContainerExport.php`: väljer byggare efter `kind`. Städningen vid fel tar nu också bort `.pdf.part` och pärmens temporärmapp.
- `resources/views/export/binder/{_style,cover,toc,item}.blade.php` (nya) och `lang/en/export.php` (nya nycklar).
- `tests/Feature/Export/PdfParmTest.php` (ny): 10 prov, ett eller flera per "Klart när"-punkt.

**Beslut som följdes**
- Pärmen är en exportrad (samma lista, nedladdning och sju dagar) och räknas inte mot kvoten.
- Ingen extern process: dompdf och FPDI är ren PHP, och fjärrresurser är avstängda.
- Det finns ett jobb, inte två. Jag valde att låta `BuildContainerExport` välja byggare efter `kind` i stället för ett eget `BuildBinder`. Jobbet är bara statusmaskinen, felhanteringen och `.part`-städningen, och ett nytt jobb hade kopierat alla tre. Skälet står i docblocken.
- Försättsbladet visar `medium`-varianten som data-URI, aldrig originalet. Ägaren är kontots namn, datumet är bygget och containerns fält visas som rubrik och typ.
- Förteckningen renderas sist och renderas om om den blir längre än en sida. FPDI stämplar *Page N of M* och försättsblad och förteckning räknas med.
- Minnet: ett item i taget renderas till temporär PDF, dompdf-instansen släpps mellan delarna och temporärmappen tas bort i `finally`.
  - Hopsättningen sker i omgångar om 50 filer, eftersom FPDI måste hålla alla källfiler öppna tills utdata skrivs och tusentals items annars ger tusentals öppna filhandtag.
  - Provet med 200 items mäter toppväxten under bygget (cirka 28 MB uppmätt, gräns 56 MB, se Frågor och antaganden).

**Avvikelser**
- Typsnittet är Helvetica (PDF:ens inbyggda kärnfont), inte ett Unicode-typsnitt. DejaVu gav en toppväxt över 60 MB, vilket inte ryms i arbetarens 96 MB. Tecken utanför windows-1252 ritas alltså inte. Svenska tecken fungerar.
- Provet för "utanför omfånget" ersätter `ResolveItemScope` med en mock som ger ett begränsat omfång, i stället för att bygga upp en riktig åtkomstrad.

## Frågor och antaganden

- **Transitivt beroende:** `setasign/fpdf` kommer med som ett transitivt krav från FPDI, som behöver FPDF som basklass (`Class "FPDF" not found` utan det). Det är ren PHP utan process och ligger i `composer.lock`. Inget godkännande behövs.
- **Teckenuppsättningen:** Helvetica är ett avvägt val mellan minne och tecken. Ska Unicode-stöd gälla pärmen (DejaVu) krävs ett beslut om minnesgränsen, eller att varje del renderas i en separat process. ADR-0070 förbjuder inte det senare, men § Beslut 2 kräver en ADR för en sådan process.
- **Flera valutor:** summan på kostnader är en rad per valuta, med containerns huvudvaluta först och övriga valutor i bokstavsordning. Det finns ingen växelkurs, så valutorna räknas aldrig ihop till ett belopp.
- **Flera föräldrar:** ett item med flera föräldrar placeras under den med lägst id och syns en gång. Cykler bryts, och items som bara nås via en cykel hamnar på översta nivån.
- **Planspärren:** `assertFeature($account, 'pdf_binder')` ligger inte i byggaren eller jobbet. Beställningsvägen ligger i `app/Http/**` (Out of scope, 446), så grinden hör dit.
- **Historiken** hämtas med `ListAuditEvents::forItem` och `PresentAuditEvents`, alltså samma rader som historikfliken. Åtgärdskoden skrivs som den lagras (till exempel `item.created`), eftersom frontenden översätter den och det inte finns någon serverside-översättning.
- **Containerns egna fält på försättsbladet:** containerfält är bara definitioner (`ItemField` med `item_id` NULL) utan värden, så försättsbladet listar dem som `etikett | typ`. Det är ett tolkningsval av "egna fält på containern".
- **Utanför rutan:** inga filer. Omfångslintens varning om `ExportDownloadController` gäller en rutt som bara nämns som destination, och den rörs inte.

## Processnotering

Mest tid gick åt till att FPDI inte skriver källfilerna förrän `Output()`, så den första hopsättningen som stängde dem efter varje del kraschade med `fseek(): not a valid stream`. Minnesgränsen på 96 MB gick inte att mäta rakt i testprocessen (baslinjen där är runt 200 MB), så provet mäter toppväxt i stället. Issuen borde ha nämnt både `setasign/fpdf` och att Unicode-typsnitt inte ryms i minnesgränsen.

## Åtgärdsvarv

- Varv 1 (Sonnet, `71d7f511`): `app/Support/Export/BinderBuilder.php`, `tests/Feature/Export/PdfParmTest.php`
- Varv 1 (Sonnet, `b75c3d03`): `app/Support/Export/BinderBuilder.php`, `lang/en/export.php`, `resources/views/export/binder/item.blade.php`, `tests/Feature/Export/PdfParmTest.php`



