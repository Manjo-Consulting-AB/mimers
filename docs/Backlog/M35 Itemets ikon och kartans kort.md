# M35 · Itemets ikon och kartans kort

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-10-18, efter genomgången av fynden i v0.23.0. Det här ingår: en ikon och en färg som användaren väljer per item, ikonen där itemet står som rad eller nod, trädets rotrad utan fällknapp, kartans kort och linjer enligt `docs/Design/findings/Minimalist Boat Container Map Dashboard.png` samt itemets bild på översikten. Besluten står i [[ADR-0060 Itemets ikon]] och [[ADR-0061 Kartans kort och kanter]].

**Bilderna är förlagan, besluten är regeln.** Målbildens *Layout*, zoom, helskärm och menyn med tre punkter tas inte in (ADR-0059 § 7, ADR-0061 § Kontext). Mät inte ut värden ur bilderna.

**Ordningen:** 300 och 301 står fritt. 302 kräver båda. 303, 304 och 305 kräver 300 och 301. 306 kräver 305. 307 står fritt och kan byggas när som helst.

**Detta ingår inte:** ikoner per container (`kind` är fritt, [[ADR-0057 Ikoner och typsnitt]] § 1), ikonen i sökresultaten, todo-listan och dokumentlistorna ([[ADR-0060 Itemets ikon]] § 9), att välja ikon när ett item skapas, och grupperingen av containrar i sidopanelen, som redan följer [[ADR-0036 Containerns art]].

---

### 300. `ItemIcon` och Lucide

GitHub #909. Paketet `lucide` läggs till i `package.json` (godkänt av Tony 2026-10-18). En ny komponent, `ItemIcon.vue`, ritar itemets ikon ur proppen `name` (ett Lucide-namn eller `null`) och `color` (en palettnyckel eller `null`). Ikonerna läses med `import.meta.glob('/node_modules/lucide/dist/esm/icons/*.mjs', { import: 'default' })` i lat form. Noderna ritas med `h()` som i `UiIcon`, med samma tre storlekar, linjebredden 1,75, `currentColor` och `aria-hidden`. Med `tile` ritas rutan i `--color-tint-<color>-soft` och linjen i `--color-tint-<color>`.

`null`, ett namn som inte finns och en ikon som ännu inte laddats ritar rollen `item` ur `UiIcon` i neutral ton. Rutan har samma storlek i alla tre fallen, så att raden inte hoppar när ikonen kommer. En okänd färg ritas som `slate`. En modul, `itemIconPresentation.js`, exporterar namnlistan (`iconNames()`, ur globbens nycklar, sorterad) och paletten (`ICON_COLORS`, de åtta nycklarna i ADR-0060 § 5) så att väljaren i 302 och komponenten läser samma lista. De sexton tokens läggs till i `resources/css/app.css` med en kommentar om undantaget från rollnamnen.

**Läs:** [[ADR-0060 Itemets ikon]] § 1–6, [[ADR-0057 Ikoner och typsnitt]] § 2 och 6, `resources/js/components/UiIcon.vue`, `resources/js/icons/index.js` (docblocken), `resources/css/app.css` (tokens)

**Klart när:** `lucide` står i `package.json` och `npm run build` går igenom; `ItemIcon` ritar en Lucide-ikon ur namnet utan `v-html`; `null`, ett okänt namn och en ikon som laddas ritar `item` i neutral ton i samma storlek; `tile` ger rutan i palettens färg och en okänd färg ger `slate`; ingen fil utanför `ItemIcon.vue` och `itemIconPresentation.js` importerar ur `lucide`; `UiIcon` och `resources/js/icons/index.js` är oförändrade; paletten har åtta nycklar och inget rött; hela testsviten är grön.

**Beror på:** —

### 301. Itemets ikon i datamodellen

GitHub #910. En migration lägger till `icon VARCHAR(64) NULL` och `icon_color VARCHAR(16) NULL` på `item`. `Item` får båda i `Fillable` och en konstant `ICON_COLORS` med de åtta nycklarna. `StoreItemRequest` och `UpdateItemRequest` validerar `icon` som `nullable`, gemener, siffror och bindestreck (`^[a-z0-9]+(-[a-z0-9]+)*$`) och högst 64 tecken, och `icon_color` som `nullable` och i `Item::ICON_COLORS`. I `UpdateItemRequest` är båda `sometimes`, som de andra fälten. `ItemResource` bär båda och alltid, även som `null`. `UpdateItem` loggar dem med gamla och nya värdet under `meta.values`, eftersom ingen av dem är fritext. `ContainerExportBuilder` tar med båda på varje item.

Servern kontrollerar aldrig att namnet finns i Lucide (ADR-0060 § 4).

**Läs:** [[ADR-0060 Itemets ikon]] § 4, 5 och 7, [[Items och organisation]] § item, `app/Models/Item.php`, `app/Http/Requests/Item/StoreItemRequest.php` och `UpdateItemRequest.php` (docblockarna), `app/Http/Resources/ItemResource.php` (docblocken), `app/Actions/Item/UpdateItem.php` (docblocken), `app/Support/Export/ContainerExportBuilder.php` (`buildItems`)

**Klart när:** kolumnerna finns och är nullbara; `/api` skapar och ändrar ett item med `icon` och `icon_color`; ett `icon` med versaler, mellanslag eller över 64 tecken ger 422 med fältets felkod; ett `icon_color` utanför paletten ger 422; ett utelämnat fält i PATCH lämnar kolumnen orörd och `null` tömmer den; `ItemResource` har båda nycklarna även när de är `null`; en ändring av ikonen skriver en historikrad med gamla och nya värdet; exporten bär båda fälten; hela testsviten är grön.

**Beror på:** —

### 302. Väljaren under *Edit item*

GitHub #911. `ItemForm` får en sektion *Icon* bredvid *Cover image*, och den ritas bara i redigeringsläget. Sektionen visar den valda ikonen i sin ruta, eller den neutrala, och en knapp som öppnar väljaren i ett `UiSheet`. Väljaren har ett sökfält som filtrerar `iconNames()` på delsträng i namnet, ett rutnät med ikonerna och under det de åtta färgerna. Rutnätet ritar högst 120 ikoner åt gången, med *Show more* under, så att alla 1 900 aldrig hämtas på en gång. Ett klick på en ikon väljer den, ett klick på en färg väljer färgen, och *Remove icon* tömmer båda. Valet skickas med formuläret som `icon` och `icon_color`, och `ItemController::edit()` skickar de nuvarande värdena ur `ItemResource`.

Varje ikon i rutnätet är en knapp med namnet som `aria-label`. Varje färg är en knapp med färgens ord ur `lang/`. Det valda bär `aria-pressed`.

**Läs:** [[ADR-0060 Itemets ikon]] § 5 och 8, `resources/js/components/ItemForm.vue` (docblocken och sektionen *Cover image*), `resources/js/components/ItemIcon.vue`, `resources/js/components/itemIconPresentation.js`, `resources/js/components/UiSheet.vue`, `app/Http/Controllers/ItemController.php` (`edit`, `update`)

**Klart när:** *Edit item* visar sektionen *Icon* och *New item* gör det inte; väljaren filtrerar på namnet; rutnätet ritar högst 120 ikoner tills *Show more* trycks; en vald ikon och färg sparas och syns efter omladdning; *Remove icon* sparar `null` i båda; varje ikonknapp har ett `aria-label` och den valda bär `aria-pressed`; färgernas ord kommer ur `lang/en/ui.php`; hela testsviten är grön.

**Beror på:** 300, 301

### 303. Ikonen i listan, överdelen och fokuskartan

GitHub #912. Itemets ikon ritas med `ItemIcon` och `tile` på tre ställen. I **listan** (`ItemRow`) står den före namnet, och omslagets miniatyr står kvar där den står. I **itemets överdel** (`Show.vue`) står den före rubriken i storleken 24. I **fokuskartan** i högerpanelen (`FocusMapNode`) står den före namnet på varje nod. `ListItemLinks` hämtar `icon` och `icon_color` tillsammans med `ulid` och `name`, och `ItemController::map()` skickar dem på varje nod och på `self`.

**Läs:** [[ADR-0060 Itemets ikon]] § 6 och 9, `resources/js/components/ItemRow.vue`, `resources/js/components/FocusMapNode.vue`, `resources/js/pages/Containers/Items/Show.vue` (överdelen), `app/Actions/Item/ListItemLinks.php`, `app/Http/Controllers/ItemController.php` (`map`)

**Klart när:** listans rad, itemets överdel och fokuskartans noder ritar `ItemIcon` med itemets ikon och färg; ett item utan ikon ritar den neutrala; listan har kvar omslagets miniatyr; antalet frågor för listan och för itemets sida är oförändrat; hela testsviten är grön.

**Beror på:** 300, 301

### 304. Trädets rotrad och ikonerna i trädet

GitHub #913. `ItemTreeTable`s rotrad får ingen fällknapp. Containerns ikon (`UiIcon` `container` med `tile` `neutral`) står i den kolumn där knappen stod, och `containerOpen` utgår. Trädets rötter är alltid synliga. *Expand all* och filtret rör inte längre rotraden. På varje itemrad står `ItemIcon` med `tile` mellan fällknappen (eller platshållaren) och namnet. `ResolveItemTreeRows` hämtar `icon` och `icon_color` i samma fråga som namnet och kategorin och skickar dem i radens celler.

Rötternas fällknappar står i samma kolumn som containerns ikon, och djupet räknas från rötterna som i dag. Under `md:` gäller samma form.

**Läs:** [[ADR-0060 Itemets ikon]] § 6 och 10, [[ADR-0058 Trädet som tabell]] § 2 och 6, `resources/js/components/ItemTreeTable.vue`, `app/Actions/Item/ResolveItemTreeRows.php` (docblocken), `docs/Design/findings/itemlist_20261018_084859.png`

**Klart när:** rotraden har containerns ikon och ingen knapp med `aria-expanded`; rötterna syns utan klick; *Expand all* öppnar och stänger grenarna under rötterna men aldrig rotraden; varje itemrad ritar `ItemIcon` med itemets ikon och färg; en rot och containerns ikon står i samma kolumn; antalet frågor är detsamma för 3 och 30 items; hela testsviten är grön.

**Beror på:** 300, 301

### 305. Kartans kort

GitHub #914. `ResolveItemMap` hämtar `icon` och `icon_color` i samma fråga som namnet och lägger dem på varje nod och på mitten. Kring containern är de `null`. `ItemMapNode` ritas om enligt [[ADR-0061 Kartans kort och kanter]] § 1–4. Kortet har `ItemIcon` med `tile` i 40 px till vänster, namnet och slagets ord i slagets färg till höger, `via` under en förälder, öppna-ikonen i övre högra hörnet och ⇄ med antalet när platserna är fler än en. Statusen ritas bara som *overdue*, en prick i `danger` med ordet i `sr-only` och `title`. Antal barn ritas inte. Mitten har ikonen ovanför namnet och *Current item* under, med kant i `accent` och `accent-soft` som bakgrund. Kring containern står `container` och *Container*.

Kortets bredd är fast. Rutnätet och linjerna kommer i 306, och fram till dess står korten i dagens uppställning.

**Läs:** [[ADR-0061 Kartans kort och kanter]] § 1–4, [[ADR-0059 Kartan med en mitt]] § 3, 4 och 5, `resources/js/components/ItemMapNode.vue` (docblocken), `resources/js/components/ItemIcon.vue`, `app/Actions/Item/ResolveItemMap.php` (docblocken)

**Klart när:** varje nod och mitten i `map` har `icon` och `icon_color`; kortet ritar ikonen, namnet och slagets ord; öppna-ikonen sitter i hörnet och har kvar sitt `aria-label`; en nod som är *overdue* har pricken med ordet för skärmläsare, och en nod som är *ok* har ingen; antal barn ritas inte; ⇄ syns bara när `placements` är större än ett; mitten bär *Current item* och kring containern *Container*; plusmenyn sitter kvar bara på mitten; antalet frågor är oförändrat; hela testsviten är grön.

**Beror på:** 300, 301

### 306. Kartans linjer

GitHub #915. `ItemMap` ställer korten i ett rutnät med fasta platser från `md:` ([[ADR-0061 Kartans kort och kanter]] § 7). Föräldrarna står på en rad ovanför mitten. Barnen står under mitten med högst fyra per rad. De relaterade står i en båge, tre på varje sida, där den mittersta står längst ut. Ett SVG-lager under korten ritar en kubisk kurva från mittens kant till varje kort. Föräldrar och barn får heldragna linjer, föräldrar i `accent` och barn i `success`. De relaterade får streckade linjer i `ink-subtle`. Pilspetsarna är `<marker>`: mot föräldern, mot barnet och åt båda hållen mot en relaterad. Lagret läser kortens lägen med `getBoundingClientRect()` relativt kartans ram och ritar om vid `ResizeObserver` och när `map` byts. Lagret är `aria-hidden`.

Teckenförklaringen får samma färger som linjerna. Under `md:` ritas inget SVG-lager, och korten står i en kolumn med de relaterade under barnen, som i dag.

**Läs:** [[ADR-0061 Kartans kort och kanter]] § 5–8, [[ADR-0059 Kartan med en mitt]] § 6 och 8, `resources/js/components/ItemMap.vue` (docblocken), `resources/js/components/ItemMapNode.vue`, `docs/Design/findings/map_20261018_093613.png`

**Klart när:** från `md:` står korten i rutnätet och ett SVG-lager ritar en linje per ritad nod; linjerna till föräldrar och barn är heldragna och i sina färger, och linjerna till de relaterade streckade; pilspetsarna pekar enligt ADR-0061 § 5; linjerna ritas om när fönstret ändrar storlek och när mitten byts; SVG-lagret är `aria-hidden`; under `md:` finns inget SVG-lager och ingenting skrollar i sidled; teckenförklaringens prickar har linjernas färger; inget nytt npm-paket; hela testsviten är grön.

**Beror på:** 305

### 307. Itemets bild på översikten

GitHub #916. `ItemController::show()` skickar `cover`: itemets omslag ur `ResolveItemCover::handle()` som `{ulid, variants}`, eller `null`. Det är samma regel som listan använder, så den automatiska bilden visas när ingen bild är vald. På översikten står bilden till vänster om *Quick facts* från `md:`, och under `md:` står den ovanför. Bilden skalas med `object-contain` inom en ruta med fast maxhöjd och beskärs aldrig. Adressen byggs med `coverUrl(cover, 'medium')` ur `coverPresentation.js`. Utan bild tar *Quick facts* hela bredden, och ingen tom ram ritas. Bildens `alt` är itemets namn.

**Läs:** [[ADR-0041 Itemets vy]] § Beslut, `app/Actions/Item/ResolveItemCover.php` (docblocken), `resources/js/components/coverPresentation.js`, `resources/js/components/ItemQuickFacts.vue`, `resources/js/pages/Containers/Items/Show.vue` (översikten), `app/Http/Controllers/ItemController.php` (`show`, `variants`)

**Klart när:** ett item med en vald bild visar den på översikten; ett item med bilder men utan val visar den äldsta; ett item utan bild visar *Quick facts* i hela bredden och ingen ram; bilden har `object-contain` och beskärs inte; `medium` används när varianten finns och annars originalet; under `md:` står bilden ovanför *Quick facts*; `alt` är itemets namn; antalet frågor för itemets sida ökar med högst en; hela testsviten är grön.

**Beror på:** —
