# M38 · Containerns ikon

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-10-18. Containern får en egen ikon i samma färger och med samma väljare som itemet. Ikonen syns i huvudmenyn, på trädets rotrad, i kartans mitt kring containern och i containerns ruta när bilden saknas. Besluten står i [[ADR-0064 Containerns ikon]].

**Ordningen:** 315, 316 och 317 bygger på 314. 316 väntar också på 313 i [[M37 Itemets karta]], eftersom båda rör kartans testfil. 317 väntar på 316, som ger `ItemIcon` proppen `fallback`.

**Detta ingår inte:** *Containers* i navigeringen, detaljpanelens rubrik och historikens typfilter, som behåller rollen `container`. Inte heller sökresultaten, uppgiftslistorna eller dokumentlistorna.

---

### 314. Containerns ikon i datamodellen

GitHub #939. En migration lägger till `icon VARCHAR(64) NULL` och `icon_color VARCHAR(16) NULL` på `container` ([[ADR-0064 Containerns ikon]] § 1 och 2). `Container` får båda i `Fillable`. `StoreContainerRequest` och `UpdateContainerRequest` validerar dem som `UpdateItemRequest` gör: `icon` som `nullable`, gemener, siffror och bindestreck (`^[a-z0-9]+(-[a-z0-9]+)*$`) och högst 64 tecken, och `icon_color` som `nullable` och i `Item::ICON_COLORS`. I `UpdateContainerRequest` är båda `sometimes`. `ContainerResource` bär båda och alltid, även som `null`. `UpdateContainer` loggar dem med gamla och nya värdet, som `UpdateItem` gör med `ICON_FIELDS`. `ContainerExportBuilder` tar med båda på containern.

**Läs:** [[ADR-0064 Containerns ikon]] § 1 och 2, [[ADR-0060 Itemets ikon]] § 4, 5 och 7, `app/Http/Requests/Item/UpdateItemRequest.php` (docblocken och `icon`), `app/Actions/Item/UpdateItem.php` (`ICON_FIELDS`), `app/Actions/Container/UpdateContainer.php` (docblocken)

**Klart när:** kolumnerna finns och är nullbara; ett giltigt namn och en färg ur paletten sparas genom både formuläret och `/api`; ett namn med versaler, mellanslag eller över 64 tecken avvisas; en färg utanför paletten avvisas; `null` tömmer; ett utelämnat fält i en uppdatering lämnar kolumnen orörd; `ContainerResource` bär båda; ändringen loggas med gamla och nya värdet; exporten bär båda; hela testsviten är grön.

**Beror på:** —

### 315. Väljaren under containerns inställningar

GitHub #940. `Containers/Edit.vue` får en sektion *Icon* bredvid *Cover image* (§ 3). Den visar den valda ikonen i sin ruta, eller lådan, och en knapp som öppnar `ItemIconPicker` i samma ark och med samma beteende som i `ItemForm`. Valet skickas med containerns formulär som `icon` och `icon_color`, och *Remove icon* tömmer båda. `ContainerController::edit()` skickar de nuvarande värdena. Sektionen ritas bara när användaren får uppdatera containern. Texterna återanvänder `ItemForm`:s nycklar där de passar, och nya nycklar står under `container.` i `lang/en/ui.php`.

**Läs:** [[ADR-0064 Containerns ikon]] § 3 och 4, `resources/js/components/ItemIconPicker.vue` (docblocken), `resources/js/components/ItemForm.vue` (sektionen *Icon*), `resources/js/pages/Containers/Edit.vue` (docblocken och avsnittet om bilden)

**Klart när:** sektionen finns bredvid *Cover image* och bara med rätten att uppdatera; den visar den valda ikonen i sin färg eller lådan; väljaren öppnas och fokus går tillbaka till knappen när den stängs; ett val sparas med formuläret; *Remove icon* tömmer båda; hela testsviten är grön.

**Beror på:** 314

### 316. Ikonen i huvudmenyn, trädet och kartan

GitHub #941. `ItemIcon` får proppen `fallback`, ett rollnamn ur `resources/js/icons/index.js` med `item` som förval. Den ritas när `name` är `null` eller okänt (§ 4). Containerns ikon ritas med `ItemIcon`, `tile` och `fallback="container"` på tre ställen (§ 5):

- **Huvudmenyns lista** (`ShellContainerList`), i storleken 20 före namnet. `ListShellContainers` läser `icon` och `icon_color` i samma fråga och skickar dem på varje container.
- **Trädets rotrad** (`ItemTreeTable`), i samma kolumn och storlek som i dag. `Index.vue` skickar `icon` och `icon_color` ur sidproppen `container` (`ContainerResource`, issue 314) till trädet.
- **Kartans mitt kring containern** (`ItemMapNode`). `ResolveItemMap` sätter containerns `icon` och `icon_color` på `center` när mitten är containern, och de är inte längre `null` där.

En container utan ikon ser ut som i dag på alla tre ställena.

**Läs:** [[ADR-0064 Containerns ikon]] § 4 och 5, `resources/js/components/ItemIcon.vue` (docblocken), `resources/js/components/ShellContainerList.vue`, `app/Actions/Container/ListShellContainers.php`, `resources/js/components/ItemTreeTable.vue` (rotraden), `resources/js/components/ItemMapNode.vue` (mitten kring containern), `app/Actions/Item/ResolveItemMap.php` (docblocken om ikonen)

**Klart när:** `ItemIcon` ritar `fallback` för ett tomt eller okänt namn och `item` utan proppen; huvudmenyn, trädets rotrad och kartans mitt ritar containerns ikon i dess färg; utan ikon ritas lådan som i dag; antalet frågor för skalet och kartan är oförändrat; *Containers* i navigeringen, detaljpanelens rubrik och historikens filter är oförändrade; hela testsviten är grön.

**Beror på:** 314, 313

### 317. Ikonen i containerns ruta

GitHub #942. `ContainerCover` får propparna `icon` och `color`. Utan bild ritar den `ItemIcon` med `tile`, `fallback="container"` och containerns ikon i stället för lådan i den grå rutan (§ 5). Ordningen är bild, ikon och sist lådan. Rutan behåller sin storlek och form. Containerlistan (`Containers/Index.vue`), dashboardens kort (`ContainerCard`), containerns ram (`ContainerLayout` och `ContainerHero`) och inställningarna (`Containers/Edit.vue`) skickar containerns `icon` och `icon_color`. Ramen och inställningarna läser dem ur sidproppen `container` (`ContainerResource`, issue 314), och `ListContainerSummaries` läser dem i samma fråga som namnet för listan och dashboarden.

**Läs:** [[ADR-0064 Containerns ikon]] § 5, `resources/js/components/ContainerCover.vue` (docblocken), `resources/js/components/ContainerCard.vue`, `resources/js/components/ContainerHero.vue`, `resources/js/layouts/ContainerLayout.vue` (rutan), `app/Actions/Container/ListContainerSummaries.php` (docblocken)

**Klart när:** en container med bild ritar bilden även när den har en ikon; en container utan bild men med ikon ritar ikonen i sin färg; en container utan bild och ikon ritar lådan som i dag; rutans storlek är oförändrad på alla ställena; antalet frågor för containerlistan är oförändrat; hela testsviten är grön.

**Beror på:** 316
