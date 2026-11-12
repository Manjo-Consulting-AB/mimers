# M54 · Registrering i vyn: Items

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-12. Bygger [[ADR-0074 Registrering i vyn]] § 7 för *Items*, den andra milstolpen i ordningen *To do*, *Items*, *Documents*. Skalen kom i [[M52 Registrering i vyn]]. På containerns *Items*-flik fälls ett formulär ut ovanför trädet med namnet i raden och övriga fält bakom *More details*, och varje trädnod får ett plus som skapar ett barn. Issuerna skrevs direkt i GitHub (#1162, #1163, #1164) och är sammanfattade här.

Kortkommandot `N` och plusknappens mål bestäms först när *Documents* är byggd (ADR-0074 § 7). Tills dess finns `/items/create` kvar för plusknappen och för itemets *Add child*.

**Två antaganden i issuerna som Tony ska se:**

1. **Bilden vid skapandet ritas inte i det inbäddade formuläret.** `useCreationUpload` (issue 341) används inte när `ItemForm` står i trädet. Bilden läggs till på itemets sida.
2. **Plusset på en nod syns vid hover eller fokus från `md:`, och alltid på mobil.** En pekskärm har ingen hover, så knappen kan inte vara dold där.

**Releasen:** en, när 364–366 är mergade.

---

### 364. Itemets skrivväg tillbaka till fliken

GitHub #1162. **Läget:** `ItemController::store()` leder alltid till itemets sida, och `index()` ger inget underlag för ett formulär på fliken.

**Lösningen:** `store()` tar emot `return` och går dit när `TaskCreateController::safeReturn()` godkänner adressen, med `status` = `item-created` och `created_item` = `{ulid, name, parent}`. `another` går före `return`, och en ogiltig adress ger dagens svar. `index()` ger formulärets underlag (`categories`, `can.manage_taxonomy`, `preselected`) och `can_create` per nod i trädet, men bara när användaren får skapa något, så att en läsare inte får något extra. Grindarna är oförändrade. Provet ligger i `tests/Feature/Item/RegistreringIVynTest.php`.

**Läs:** [[ADR-0074 Registrering i vyn]] § 1–3 och 7; `ItemController.php` (`index`, `create`, `store`, `createdItem`, `abilities`, `pickableStructure`); `StoreItemRequest.php`; `TaskCreateController.php` (`safeReturn`)

**Klart när:** POST med giltig `return` landar på fliken med `created_item`, och en ogiltig adress landar på itemets sida; `another` går före `return`; en användare med en itemgrant men utan `createItem` får 403 utan `parent` och 302 med en `parent` hon får skapa på; `index()` ger `can_create` per nod och tomma listor åt en läsare.

**Beror på:** 356

### 365. Nytt item i raden ovanför trädet

GitHub #1163. **Läget:** knappen *New item* på *Items*-fliken leder till en egen sida, `/items/create`.

**Lösningen:** `ItemForm` får ett inbäddat läge (`inline`, `returnTo`): sparningen skickar `return` med `preserveScroll` och `preserveState`, formuläret återställs, fokus går till namnet, *Save and add another* ritas inte, *Cancel* sänder `cancel`, och bilden vid skapandet ritas inte. `Containers/Items/Index.vue` ritar `InlineCreate` mellan filterraden och trädet i trädläget, när användaren får skapa, och länken *New item* i huvudet tas bort. Den nya raden markeras med `highlight` i `ItemTreeTable` i 2000 ms och dess förfäder fälls ut. Döljer filtret itemet visas beskedet `item.index.created_hidden`. Sidan `/items/create` finns kvar.

**Läs:** [[ADR-0074 Registrering i vyn]] § 1–3 och 7; `InlineCreate.vue`; `ItemForm.vue`; `Containers/Items/Index.vue` och `Create.vue`; `ContainerTaskQuickCreate.vue` (förlagan för markeringen)

**Klart när:** `Index.vue` ritar `InlineCreate` före `ItemTreeTable` och länkar inte till `/items/create`; `ItemForm` med `inline` skickar `return` och saknar *Save and add another*; formuläret står kvar efter sparning med namnet tomt och fokus i det; `highlight` ger `bg-accent-soft`; kartläget ritar ingen rad; GenomgangTest och SprakTest är gröna.

**Beror på:** 356, 364

### 366. Plus på varje trädnod skapar ett barn

GitHub #1164. **Läget:** ett barn skapas bara från itemets sida (*Add child*), inte i trädet.

**Lösningen:** varje nod med `can_create` får en plusknapp sist i namncellen, `min-h-11 min-w-11`, med `aria-label` `item.tree.add_child`. Den syns vid hover eller fokus från `md:` och alltid på mobil, och ligger över radens länkyta (`relative z-10`). Ett tryck fäller ut noden och ritar en tabellrad under den med `ItemForm inline` och noden som förälder. Bara ett formulär är öppet åt gången, raden ovanför trädet inräknad, och ett byte med osparade ändringar frågar först. `Index.vue` äger vilket formulär som är öppet. Escape eller *Cancel* stänger det och fokus går tillbaka till plusknappen. Kartläget ritar inga plus.

**Läs:** [[ADR-0074 Registrering i vyn]] § 1–3 och 7; `ItemTreeTable.vue` (raden, fällknappen och länkytan från issue 343); `Containers/Items/Index.vue` (efter 365); `InlineCreate.vue`

**Klart när:** en nod med `can_create` ritar plusknappen och en utan ritar den inte; knappen har `aria-label` och `relative z-10`; formuläret får nodens `parent`; bara ett formulär är öppet och ett byte frågar vid osparade ändringar; `ItemtradTest` är anpassad där plusset eller formulärraden kräver det.

**Beror på:** 365
