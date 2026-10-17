# M32 · Formspråket

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-10-17. Containerns översikt har allt innehåll ur `docs/Design/container.jpeg` sedan [[M24 Desktopdesignen]], men ser ut som en wireframe bredvid bilden: inga ikoner, systemets typsnitt, halvfet text överallt och kort utan djup. Besluten står i [[ADR-0057 Ikoner och typsnitt]]. Jämförelsen gjordes mot `docs/Design/findings/Container_20261017_083548.png`.

**Ordningen är [[ADR-0042 Designsystemet]]s: tokens, sedan komponenter, sedan sidor.** 280–282 står fritt. 283 kräver alla tre. 284–289 kräver 283 och står fritt sinsemellan. 290 går sist.

**Containerns översikt är första sidan.** Dashboarden, itemvyn och flikarna får skuggan, typsnittet och vikten automatiskt genom tokens och `UiCard`, men sina ikoner först i en senare milstolpe. Bygg inte ikoner på dem här.

**Detta ingår inte:** bildens logga (ingen fil från designern, se ADR-0057 § Konsekvenser), ikoner per containerart (ADR-0057 § 1), att dela händelsemeningen i titel och objekt (nya översättningsnycklar per händelsetyp, ett eget arbete) och *Lägg till i denna container* (struken, se [[Att sortera efter mockuparna]] § Containervyn).

---

### 280. Typsnittet

GitHub #847. Inter som variabelt `woff2` i `resources/fonts/`, med licensen bredvid, och `@font-face` i `resources/css/app.css` enligt [[ADR-0057 Ikoner och typsnitt]] § 4. `--font-sans` börjar med Inter och behåller systemets typsnitt som reserv. `--text-title--font-weight` blir 500 enligt § 5, och `UiCard`s rubrik står kvar som halvfet.

**Läs:** [[ADR-0057 Ikoner och typsnitt]] § 4–5, `resources/css/app.css`, `resources/js/components/UiCard.vue`

**Klart när:** typsnittsfilen ligger i repot med sin licens och paketeras av `npm run build`; ingen förfrågan går till en extern typsnittsvärd; `--font-sans` börjar med Inter; `title` är 500 och kortets rubrik 600; hela testsviten är grön.

**Beror på:** —

### 281. Skuggan, glaset och de mjuka tonerna

GitHub #848. Tokens ur [[ADR-0057 Ikoner och typsnitt]] § 6: `--shadow-card`, `--color-glass`, `--color-glass-border` och de tre mjuka tonerna. `UiCard` bär skuggan.

**Läs:** [[ADR-0057 Ikoner och typsnitt]] § 6, `resources/css/app.css`, `resources/js/components/UiCard.vue`

**Klart när:** varje roll i § 6 finns som token; `UiCard` har `shadow-card` och ingen annan skugga; ingen komponent skriver ett eget skuggvärde; hela testsviten är grön.

**Beror på:** —

### 282. `UiIcon` och ikonlistan

GitHub #849. `resources/js/icons/index.js` med startlistan ur [[ADR-0057 Ikoner och typsnitt]] § 1, kopierad ur Lucide i nodformat, och Lucides `LICENSE` oförändrad bredvid. `UiIcon.vue` ritar en roll med `h()`, i tre storlekar, med linjebredd 1,75 och alltid `aria-hidden`. Proppen `tile` ritar ikonrutan i en av de fem tonerna, och tonerna står på tokens från 281.

Ett prov läser varje `.vue`-fil och fäller ett `name` på `UiIcon` som inte finns i listan.

**Läs:** [[ADR-0057 Ikoner och typsnitt]] § 1–2, [[ADR-0042 Designsystemet]] § Beslut (fokusringen och rollnamnen)

**Klart när:** alla roller i startlistan finns och var och en bär Lucide-namn och version i en kommentar; `UiIcon` ritar varje roll i 16, 20 och 24px; ikonen är `aria-hidden`; `tile` ritar alla fem toner; ingen `v-html` används; provet fäller en roll som inte finns; hela testsviten är grön.

**Beror på:** 281 (tonerna)

### 283. Kärnkomponenterna tar emot ikonen

GitHub #850. `UiCard`s rubrikrad och `UiListRow`s `icon`-slot linjerar en `UiIcon` mot texten. `UiStat` får en valfri `icon`-propp och formen `on-image`, som står på `--color-glass` med `--color-glass-border` och vit text. Tonmappningen för rader utan datum ur [[ADR-0057 Ikoner och typsnitt]] § 3 skrivs som en presentationsmodul.

**Läs:** [[ADR-0057 Ikoner och typsnitt]] § 2–3 och § Konsekvenser, `resources/js/components/UiCard.vue`, `resources/js/components/UiListRow.vue`, `resources/js/components/UiStat.vue`

**Klart när:** en ikon i kortets rubrik och i listradens slot står på samma höjd som texten; `UiStat` utan `icon` ritas som förut; `on-image` har en kontrast på minst 4.5:1 mot `--color-shell`; mappningen ger aldrig `danger` för en domän; hela testsviten är grön.

**Beror på:** 280, 281, 282

### 284. Hjälten

GitHub #851. `ContainerHero` får toningen ur [[ADR-0057 Ikoner och typsnitt]] § 6 i stället för `bg-shell/70`. Talen ritas av `UiStat` i formen `on-image` med ikonerna `item` och `task`. *Redigera container* och menyknappen står på glaset. Den låga hjälten behåller toningen men inga tal, enligt [[ADR-0050 Desktopdesignen]] § 2–3.

**Läs:** [[ADR-0057 Ikoner och typsnitt]] § 6, [[ADR-0050 Desktopdesignen]] § 2–3, `docs/Design/container.jpeg`, `resources/js/components/ContainerHero.vue`

**Klart när:** bilden syns utan mörkläggning i hjältens högra del; namnet, arten och beskrivningen har en kontrast på minst 4.5:1; talen bär ikoner och är fortfarande länkar med fokusring; utan bild ritas den neutrala ytan ur [[ADR-0047 Containerns bild]] som förut; under `md:` är ingenting ändrat; hela testsviten är grön.

**Beror på:** 283

### 285. Itemraden blir en komponent

GitHub #852. Itemlistans rad, med miniatyr, namn, beskrivning och pil, lyfts ur `pages/Containers/Items/Index.vue` till `ItemRow.vue`. Översiktens itempanel ritar samma komponent, i två kolumner över `lg:`. `ContainerController::show()` skickar `covers` för de sex items panelen visar, ur samma uppslag som itemlistan. Statusen står inte i raden (issue 212).

**Läs:** `resources/js/pages/Containers/Items/Index.vue` (raden och docblocken om statusen), `resources/js/pages/Containers/Overview.vue`, `app/Http/Controllers/ContainerController.php` (`show` och itemlistan), `docs/Design/container.jpeg`

**Klart när:** itemlistan och översikten ritar samma `ItemRow`; itemlistan ser ut som förut; översiktens rader har miniatyr eller `item`-ikonen i en neutral ruta; `covers` gäller bara items användaren når; antalet frågor för översikten växer inte med antalet items; hela testsviten är grön.

**Beror på:** 283

### 286. Händelseraden får sin ikonruta

GitHub #853. `HistoryRow` ritar en ikonruta per händelsetyp, med tonen ur domänmappningen från 283. Meningen står kvar som den är och får vikten från 280. Typerna utan egen ikon får `history` i tonen `neutral`.

**Läs:** [[ADR-0057 Ikoner och typsnitt]] § 3, [[ADR-0043 Tre loggar]] (händelsetyperna), `resources/js/components/HistoryRow.vue`

**Klart när:** varje händelsetyp har en roll och en ton; översikten, historikfliken och dashboardens panel ritar rutan; en okänd typ ritas med `history` utan fel; hela testsviten är grön.

**Beror på:** 283

### 287. Uppgiftspanelen får sina ikonrutor

GitHub #854. `ContainerTasksPanel` ritar en ikonruta per rad, `schedule` för ett återkommande schema och `task` för resten, med tonen ur `dueDate().state` enligt [[ADR-0057 Ikoner och typsnitt]] § 3. Datumet till höger får samma ton.

**Läs:** [[ADR-0057 Ikoner och typsnitt]] § 3, `resources/js/composables/useRelativeDate.js`, `resources/js/components/ContainerTasksPanel.vue`

**Klart när:** en förfallen rad är `danger`, en rad som närmar sig `warning` och en avlägsen `neutral`; rutan och datumet har alltid samma ton; tonen kommer ur `useRelativeDate` och räknas inte i panelen; hela testsviten är grön.

**Beror på:** 283

### 288. Översiktens rutnät utan hål, och de tomma lägena

GitHub #855. När kostnadspanelen eller bildpanelen inte ritas står ingen panel ensam med en tom kolumn bredvid sig. Rutnätet placerar panelerna efter vilka som finns, inte efter fasta rader. Varje panel med ett tomt läge ritar det med `UiEmptyState`, och kortens rubriker får sina ikoner.

**Läs:** `resources/js/pages/Containers/Overview.vue` (docblocken om rutnätet), `resources/js/components/UiEmptyState.vue`, `docs/Design/findings/Container_20261017_083548.png`

**Klart när:** över `lg:` lämnar ingen kombination av saknade paneler en tom kolumn bredvid en panel; under `lg:` är ordningen oförändrad; varje panelrubrik bär en ikon ur listan; inga tomma lägen är ren text; hela testsviten är grön.

**Beror på:** 283

### 289. Sidopanelens ikoner

GitHub #856. Raderna i `ShellSections` och containerlistan i `ShellContainerList` får ikoner ur listan. Mobilens sidomeny visar samma sektioner och får samma ikoner ([[ADR-0048 Mobilen och plusknappen]] § 1).

**Läs:** [[ADR-0057 Ikoner och typsnitt]] § 1, `resources/js/components/ShellSections.vue`, `resources/js/components/ShellContainerList.vue`, `resources/js/components/MobileMenu.vue`

**Klart när:** varje rad i sidopanelen bär en ikon; varje container ritas med `container` oavsett art; sidomenyn och sidopanelen har samma ikon på samma rad; ikonerna är läsbara mot `--color-shell`; hela testsviten är grön.

**Beror på:** 283

### 290. De handritade ikonerna flyttar till listan

GitHub #857. Varje inline-`<svg>` som är en ikon ersätts med `UiIcon`, och rollen som saknas läggs till i listan. SVG som ritar data står kvar: `CostDonut`, diagrammen, kartorna, `StorageBar` och `TotpQrCode`. Ett prov fäller en ny `<svg>` utanför `UiIcon` och de filer som är undantag.

**Läs:** [[ADR-0057 Ikoner och typsnitt]] § 2, `resources/js/icons/index.js`

**Klart när:** ingen `.vue`-fil utanför undantagen innehåller `<svg`; provet fäller en ny; varje ny roll bär Lucide-namn och version; fokusringen och etiketterna på knappar med bara en ikon finns kvar; hela testsviten är grön.

**Beror på:** 283–289
