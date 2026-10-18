# ADR-0057 Ikoner och typsnitt

**Status:** Antagen 2026-10-17 · Ändrar [[ADR-0042 Designsystemet]] på två punkter: typskalans `title` och antalet kärnkomponenter · Kompletterad av [[ADR-0060 Itemets ikon]]: itemets ikon väljs ur hela Lucide och ritas av `ItemIcon`, den slutna listan gäller gränssnittet · Bygger vidare på [[ADR-0050 Desktopdesignen]] · [[ADR-index]]

## Kontext

Strukturen i `docs/Design/container.jpeg` är byggd. Hjälten, panelerna, rutnätet och sidopanelen kom med issue 169–173 och står på tokens och kärnkomponenter ur [[ADR-0042 Designsystemet]]. Ändå ser containerns översikt ut som en wireframe bredvid bilden. Skillnaden ligger inte i vad sidan visar, den ligger i formspråket:

- **Inga ikoner.** Bilden har en ikon på varje kortrubrik, listrad, taltuta, flik och sidopanelrad, och listraderna har dessutom en färgad ruta bakom ikonen. Frontenden har ingen ikonuppsättning. De ikoner som finns är inline-SVG:er, ritade för hand i ett tjugotal filer, med olika storlek och linjebredd.
- **Typsnittet.** `--font-sans` är systemets. På Linux blir det Noto och på Windows Segoe, och ingen av dem liknar bildens geometriska sans-serif. Typsnittet är alltså olika för varje testare.
- **Vikten.** `title` är 15px halvfet, och samma steg bär både kortets rubrik och listradens titel. En lista med sex items blir sex rubriker. Bilden har medelvikt på listraden och halvfet bara på kortets rubrik.
- **Djupet.** ADR-0042 bestämde *en enda skugga, låg och kall*, men den skrevs aldrig som token. Korten står med bara en kantlinje mot en bakgrund som knappt skiljer sig från dem.
- **Hjälten.** Talen står i ogenomskinliga vita rutor på en jämnt mörklagd bild. I bilden är de halvgenomskinliga, bär en ikon och står på en toning som släpper fram bilden åt höger.

## Beslut

### 1. Ikonerna bor i repot, under rollnamn

Ikonerna ligger i `resources/js/icons/index.js` som en sluten lista. **Nyckeln är en roll och inte ikonens namn**: `task`, `cost`, `document`, och aldrig `circle-check`, `coins` eller `file-text`. Samma skäl som tokens: byter vi ikon för kostnader ändras en rad, och ingen sida behöver röras.

Varje post är ikonens element i Lucides nodformat, `[['path', { d: '…' }], ['circle', { … }]]`, med Lucide-namnet och versionen i en kommentar. Ingen sträng med SVG-markup och ingen `v-html`.

**Källan är Lucide och bara Lucide.** En ny ikon kopieras därifrån, får ett rollnamn och läggs till i listan. En ikon som Lucide saknar ritas inte för hand i en komponent, utan blir en fråga i PR:en. Lucides licens (ISC, med Feathers MIT-del) ligger som `resources/js/icons/LICENSE`, oförändrad.

Startlistan är rollerna som containerns översikt, flikraden, hjälten och sidopanelen behöver:

| Område | Roller |
|---|---|
| Navigering | `dashboard`, `container`, `inbox`, `tasks`, `documents`, `history`, `search`, `settings`, `notifications`, `favorite` |
| Innehåll | `overview`, `item`, `task`, `schedule`, `cost`, `document`, `image`, `link`, `loan`, `tag`, `category`, `map`, `tip` |
| Handling | `add`, `edit`, `more`, `trash`, `share`, `export`, `upload`, `close`, `check` |
| Riktning | `chevron-right`, `chevron-down`, `arrow-right` |
| Tillstånd | `warning`, `overdue` |

**Containerns art får ingen ikon.** `kind` är fritt ([[ADR-0036 Containerns art]]). Bildens båt, husvagn och hus förutsätter en uppräkning som inte finns, och en ikon gissad ur fritext ger fel bild åt den som skrev något eget. Varje container ritas med `container`.

### 2. En nionde kärnkomponent: `UiIcon`

ADR-0042 räknade åtta kärnkomponenter och kallade listan uttömmande. **`UiIcon` är den nionde.** Den ritar en roll ur listan och äger det som annars glider isär: storlek, linjebredd, `currentColor` och `aria-hidden`.

- **Tre storlekar:** 16px för metarader, 20px som förval och 24px för taltutor och hjälten.
- **Linjebredden är 1,75** och inte Lucides förval på 2. Bildens linjer är tunnare.
- **Alltid `aria-hidden`.** En ikon upprepar alltid en text. En knapp med bara en ikon får sin etikett av den som ritar knappen, aldrig av `UiIcon`.
- **Ikonrutan är en propp på samma komponent:** `tile` ritar en kvadrat med `--radius-control` bakom ikonen, i en av fem toner. Tonerna är `accent`, `success`, `warning`, `danger` och `neutral`, och var och en har en mjuk bakgrund och ikonens färg ur rollen.

**Ingen `<svg>` skrivs för hand utanför `UiIcon`.** Undantaget är SVG som ritar data: diagram, donut, kartor, lagringsstapeln och QR-koden. Där är SVG:en själva innehållet och inte en ikon.

### 3. Ikonrutans ton har en betydelse

Tonen väljs aldrig för att det ska se omväxlande ut. Den följer en av två regler:

- **En rad med ett datum** får tonen ur `useRelativeDate().dueDate().state`, alltså datumregeln från issue 104. Kommande uppgifter i bilden är röd, orange, grön och neutral av just det skälet.
- **En rad utan datum** får tonen ur sin domän: dokument och bild `accent`, kostnad `warning`, uppgift `success`, allt annat `neutral`. Mappningen bor i en presentationsmodul, inte i varje rad.

`danger` ges aldrig av domänen. Rött betyder att något har passerat, och en kostnad är inte ett fel.

### 4. Typsnittet är Inter, från vår egen server

Inter som variabelt typsnitt (`woff2`), i `resources/fonts/` med sin licens (SIL OFL 1.1). Vite paketerar filen med resten av bygget, och `@font-face` står i `resources/css/app.css` med `font-display: swap`. Systemets sans-serif står kvar som reserv i `--font-sans`.

**Inte från Google Fonts eller något annat CDN.** Det vore en tredjepartsförfrågan med användarens IP-adress vid varje sidladdning, något [[Registerförteckning]] skulle behöva redovisa. Det är inte heller ett npm-paket: filen kopieras in en gång och ändras inte utan beslut.

Tre vikter används: 400, 500 och 600. Ingen sida använder 700.

### 5. Typskalans `title` blir medelfet

ADR-0042 sade `title` 15px **halvfet**. Det ändras till 15px **medelfet** (500). Kortets rubrik står kvar som halvfet, och vikten sätts där, i `UiCard`. En listradstitel och en kortrubrik är olika nivåer och ska se olika ut.

### 6. Skuggan och ytan på bild blir tokens

| Roll | Värde | Var den syns |
|---|---|---|
| `--shadow-card` | `0 1px 2px rgb(15 23 42 / 0.04), 0 2px 6px rgb(15 23 42 / 0.04)` | Varje `UiCard` |
| `--color-glass` | `rgb(255 255 255 / 0.12)` | Taltutan och knapparna ovanpå hjältens bild |
| `--color-glass-border` | `rgb(255 255 255 / 0.28)` | Kanten runt samma ytor |
| `--color-success-soft` | `#ecfdf3` | Ikonrutan i tonen `success` |
| `--color-warning-soft` | `#fff7ed` | Ikonrutan i tonen `warning` |
| `--color-danger-soft` | `#fef2f2` | Ikonrutan i tonen `danger` |

`accent` och `neutral` har redan sina mjuka ytor: `--color-accent-soft` och `--color-surface-sunken`. Dialogens djupare skugga ingår inte, den har `UiSheet` redan.

Hjältens bild får en toning från `--color-shell` till genomskinligt, vänster till höger, i stället för dagens jämna mörkläggning. Texten står där toningen är som tätast och behåller sin kontrast. Bilden syns där den inte bär text.

## Motivering

**Egna filer i stället för `lucide-vue-next`.** Bundlen blir lika stor, tree-shaking tar bort det som inte importeras. Skillnaden är ordförrådet. Med paketet kan varje issue importera vilken av 1 500 ikoner som helst, och två sidor får snart två olika ikoner för underhåll. Det är samma frihandsproblem som ADR-0042 löste för färgerna, flyttat till ikonerna. En sluten lista under rollnamn kan granskaren hålla emot, och ett nytt beroende behöver inte godkännas. Uppdateringar som byter namn på ikoner berör oss inte.

Beslutet går att ändra billigt. Växer listan förbi ett åttiotal roller och kopierandet blir ett hinder byts insidan av `UiIcon` mot paketet, och ingen sida behöver röras.

**Tonen har en regel för att färgen ska betyda något.** Ges ikonrutorna färg för omväxlingens skull lär sig användaren att färgen inget säger, och då läses inte heller den röda.

**Typsnittet från egen server** av integritets- och stabilitetsskäl. Systemtypsnittet hade varit gratis, men det är det som gör att produkten ser olika ut hos varje testare.

## Konsekvenser

- **`UiCard`, `UiListRow` och `UiStat` ritar ikonen genom `UiIcon`.** Kortets rubrikrad och listradens `icon`-slot står kvar som slots. Det är `UiIcon` som gör ikonen enhetlig, inte en propp per komponent. `UiStat` är undantaget och får en `icon`-propp, eftersom den redan tar hela sitt innehåll som proppar och inte har några slots. Den får också en form för att stå på bild.
- **De inline-SVG:er som är ikoner flyttas till listan.** Det görs i en egen issue och inte i förbifarten, och ett prov fäller en ny `<svg>` utanför `UiIcon` och undantagen.
- **Bygget blir ungefär 100 kB större** av typsnittsfilen. Den cachas med resten av bygget och hämtas en gång per version.
- **Bildens logga byggs inte här.** Det finns ingen fil från designern, och en logga ritad ur en JPEG blir en gissning. Sidopanelen behåller ordet *Mimers* tills filen finns.

## Alternativ

**`lucide-vue-next` som npm-paket.** Valdes bort enligt Motivering.

**Heroicons eller Phosphor.** Valdes bort. Bildens ikoner har Lucides form, med rundade ändar och 24px rutnät, och en annan uppsättning hade gett en annan bild.

**Behålla systemets typsnitt.** Valdes bort. Det är den enskilt största orsaken till att samma sida ser olika ut på olika datorer.

**Inter från Google Fonts.** Valdes bort enligt beslut 4.

**En `icon`-propp på varje kärnkomponent.** Valdes bort. Kortet och listraden skulle få en propp var och en egen uppfattning om storlek och placering. Med `UiIcon` i sloten finns uppfattningen på ett ställe.
