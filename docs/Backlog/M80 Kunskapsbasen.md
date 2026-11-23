# M80 · Kunskapsbasen

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-23 ur #1568. Milstolpen bygger [[ADR-0094 Hjälpcentret]]: hjälpen blir markdownfiler med front matter, en fil per artikel och fråga, med kategorisidor, artikelsidor och en startsida.

**Ordningen:** 460 först, sedan 461. 462 och 463 väntar på 461, 464 väntar på 462 och 463, och 465 väntar på 462. Issuerna är skapade i GitHub (#1574–#1579) och sammanfattade här. GitHub-issuen är bindande.

---

### 460. Hjälpbiblioteket: innehållsmodellen, valideringen och cachen

GitHub #1574. Ett hjälpbibliotek som läser, validerar och cachar hjälpinnehållet i den nya formen ur [[ADR-0094 Hjälpcentret]] § 1: `categories.yaml`, `synonyms.yaml` och en markdownfil med front matter per artikel. Biblioteket används inte av någon sida än; det prövas mot en testkatalog, och den riktiga flytten av texterna görs i 461. `symfony/yaml` läggs till i `composer.json`, godkänt av Tony 2026-11-23. Dagens `/help` och `LoadHelpArticles` ändras inte.

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` none

**Beror på:** #1573 (ADR-0094 ska finnas i valvet)

### 461. Hjälptexterna som en fil per fråga, och /help ur biblioteket

GitHub #1575. Hjälptexterna flyttas från nio kategorifiler till en fil per fråga i formen från 460, och `/help` läser dem ur `HelpLibrary` i stället för `LoadHelpArticles`. Sidan ser ut och beter sig som i dag: en sida, nio kategorier, alla frågor med samma ankare, och ingen mening i svaren skrivs om. Kategorierna, synonymerna och de tretton sammanhangen får sitt innehåll i samma flytt, och resultatet är 66 artikelfiler.

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` none

**Beror på:** 460

### 462. Kategorisidan och de gamla ankarna

GitHub #1576. Varje kategori får en egen sida, `/help/{kategori}`, med kategorins frågor som utfällbara rader. En fråga går att länka direkt som `/help/{kategori}#{id}`, och den öppnas och scrollas fram när sidan laddas. De gamla adresserna `/help#{id}` och `/help#{kategori}` skickas vidare i webbläsaren, eftersom fragmentet aldrig når servern. Startsidan blir tills vidare en lista med kategorierna.

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` none

**Beror på:** 461

### 463. Hjälpartiklarnas rendering: faktarutor, bilder, video och ankare

GitHub #1577. `HelpMarkdown` ritar allt en hjälpartikel behöver enligt [[ADR-0094 Hjälpcentret]] § 4: ankare på rubrikerna, faktarutor i GitHubs form, interna `help:`-länkar, bilder ur `public/help-media/` och YouTube-videor som laddas först vid klick. `HelpValidator` får proven för trasiga interna länkar, saknade bilder och ankare som inte finns. Inga nya paket, och ingen ny sida — 464 ritar resultatet.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none

**Beror på:** 461

### 464. Artikelsidan med relaterat, navigering och sidhuvudet från servern

GitHub #1578. Varje artikel, också varje fråga, får en egen sida på `/help/articles/{id}`: brödsmula, rubrik, typ, datum, innehåll, innehållsförteckning för längre artiklar, relaterat, föregående och nästa i kategorin, och en videoruta som laddar YouTube först vid klick. Servern skriver sidans titel, beskrivning och kanoniska adress i HTML:en, så att en delad länk får rätt förhandsvisning.

**Axlar:** `ambiguity` low · `blast_radius` cross-module · `risk_class` none

**Beror på:** 462, 463

### 465. Hjälpcentrets startsida med kategorikort och populära artiklar

GitHub #1579. `/help` blir hjälpcentrets startsida: en rubrik, en plats för sökningen som 467 fyller, kategorierna som kort med ikon, beskrivning och antal, och *Popular articles* ur `featured`. Sidan fungerar på mobil först, och 462:s omdirigering av de gamla ankarna står kvar.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none

**Beror på:** 462
