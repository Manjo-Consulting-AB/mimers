# M45 · Itemets sida

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-10-23. Det här ingår: itemets ord, huvudet med primärknapp och ⋯-meny, appens egen bekräftelsedialog, sju flikar utan nollor, översikten som sammanfattningar med tak, infällbara sidopaneler, en väljare för taggar och kategorier som också skapar dem, skapaformuläret i två nivåer med bild, omslaget bland miniatyrer och trädtabellens rader. Besluten står i [[ADR-0068 Itemets sida och skapandet]].

**Ordningen** är ADR:ens: ordlistan, huvudet och dialogen, flikarna och översikten, panelerna, väljaren, skapaformuläret, omslaget och listan. Nästan varje issue rör `resources/js/pages/Containers/Items/Show.vue` eller `resources/js/components/ItemForm.vue`, så de byggs **en i taget** i nummerordning, även där `Beror på` tillåter mer.

**Byggs inte av kön.** Ingen issue i milstolpen bär etiketten `Build`. De implementeras av Sonnet-subagenter som en Opus-session styr (ADR-0068 § Konsekvenser). Axlarna i GitHub-issuerna gäller som vanligt och väljer granskningens djup.

**Releasen:** en release när milstolpen är stängd.

**Detta ingår inte:** inline-redigering av itemets fält (§ 15 säger att ingen införs), raderingsdialogen för containrar och bilagor (§ 6 bygger komponenten men flyttar dem inte), datamodellen för taggar och kategorier (§ 13), papperskorgens och lagringssidans ord för bilagor, `/api`.

---

### 329. Itemets ord

GitHub #979. Strängarna i `lang/en/ui.php` följer ordtabellen i [[ADR-0068 Itemets sida och skapandet]] § 1. Nycklarna står still och byter bara värde ([[ADR-0032 Produktens ord]]).

| Nyckel | Från | Till |
|---|---|---|
| `item.form.location` | *Placement* | *Placement* (oförändrad) |
| `item.show.placements` | *Placements in the structure* | *Placements* |
| `item.show.position_note` | *Location* | *Storage location* |
| `item.form.position_note` | *Where it is* | *Storage location* |
| `item.form.purchased_at` | *Purchased* | *Purchase date* |
| `item.attachment.heading` | *Attachments* | *Documents* |
| `create.rows.attachment` | *Image or document* | *Document* |
| `item.schedule.heading` | *Tasks* | *To do* |

Kommentarerna ovanför nycklarna som nämner de gamla orden följer med. Kommentaren ovanför `item.show` får en rad som pekar på ADR-0068 § 1. Plusmenyns övriga rader (*Relation*, *Task*, *Cost*) är singular som förut, och `create.rows.attachment` följer dem. Trädtabellens kolumner (`item.tree.*`), papperskorgens filter, `storage.list_heading` och `audit.subject.*` rörs inte.

**Läs:** [[ADR-0068 Itemets sida och skapandet]] § 1, [[ADR-0032 Produktens ord]], `lang/en/ui.php` (`create.rows`, `item.show`, `item.form`, `item.attachment`, `item.schedule`)

**Klart när:** varje nyckel i tabellen har sitt nya värde; ingen sträng under `item.*` eller `create.rows` lyder *Where it is*, *Location*, *Attachments*, *Image or document*, *Purchased* eller *Placements in the structure*; itemets uppgiftsflik heter *To do*; inga nycklar har lagts till eller tagits bort; hela testsviten är grön.

**Beror på:** —

### 330. Bekräftelsedialogen och raderingen av ett item

GitHub #980. En ny komponent `UiConfirmDialog.vue` ([[ADR-0068 Itemets sida och skapandet]] § 6). Den bygger på `<dialog>` med `showModal()`, som `UiSheet`, och tar rubrik, brödtext, bekräftelsens etikett, en ton (`danger` eller neutral) och `pending`. Den skickar `confirm` och `cancel`. *Cancel* har fokus när dialogen öppnas, Escape och klick på bakgrunden är *Cancel*, och fokus går tillbaka till knappen som öppnade den. Alla strängar kommer ur `lang/en/ui.php`.

Itemets sida använder den i stället för `window.confirm` i `destroy()`. Dialogen namnger itemet och säger att det flyttas till papperskorgen i 30 dagar: `item.destroy.confirm` byts mot en rubrik och en brödtext med `:name` och `:days`. Knappens plats ändras inte här, det gör issue 331.

Komponenten är generell så att containerns och bilagornas radering kan gå över senare, men de flyttas inte. Inget annat `window.confirm` i appen rörs.

**Läs:** [[ADR-0068 Itemets sida och skapandet]] § 6, `resources/js/components/UiSheet.vue` (dialogens mönster), `resources/js/pages/Containers/Items/Show.vue` (`destroy()`)

**Klart när:** `UiConfirmDialog` finns och bär bara tokens; *Cancel* har fokus när den öppnas; Escape och bakgrunden stänger utan att bekräfta; fokus återgår till utlösaren; itemets sida innehåller inget `window.confirm`; dialogen nämner itemets namn och 30 dagar; bekräftelsen raderar itemet som förut; `item.destroy.confirm` är ersatt; hela testsviten är grön.

**Beror på:** —

### 331. Itemets huvud

GitHub #981. Huvudet på itemets sida enligt [[ADR-0068 Itemets sida och skapandet]] § 3 och § 7.

- **Vänster:** omslagets miniatyr när itemet har ett omslag med `thumb`, annars ikonen som i dag. Namnet. Under namnet kategorin som chip och taggarna som chips, med `ItemTagList`.
- **Höger:** favoritstjärnan som i dag, primärknappen *Edit* (`UiButton`, bara med `can.update`) och en ⋯-knapp som öppnar en meny med *New item under this one* (med `can.create`) och sist *Move to trash* (med `can.delete`, öppnar dialogen ur issue 330). ⋯-knappen ritas inte när menyn är tom. Menyn har `role="menu"`, stängs med Escape och följer mönstret i `CreateMenu`.
- **Ingen fristående textlänk** för åtgärderna. Raden med *Edit*, *Delete* och *New item under this one* tas bort.
- **Den tomma taggraden** (§ 7): ett item utan taggar visar `item.show.tags_empty`, och med `can.update` länken `item.show.tags_add` till redigeringen. Utan `can.update` står bara meningen.
- **Brödsmulan** börjar med containerns namn som länk till itemfliken (`/containers/{container}/items`), följt av vägen som i dag.

Fliken *Tags* står kvar tills issue 332.

**Läs:** [[ADR-0068 Itemets sida och skapandet]] § 3 och 7, `docs/Design/struktur - item.jpeg`, `resources/js/pages/Containers/Items/Show.vue` (brödsmulan, rubriken och handlingsraden), `resources/js/components/ItemTagList.vue`, `resources/js/components/CreateMenu.vue` (menyns mönster)

**Klart när:** huvudet ritar omslagets miniatyr när den finns och annars ikonen; kategorin och taggarna står som chips under namnet; *Edit* är en primärknapp och syns bara med `can.update`; ⋯-menyn har *New item under this one* före *Move to trash*, var och en bara med sin rättighet; utan någon av dem ritas ingen ⋯-knapp; *Move to trash* öppnar bekräftelsedialogen; ingen av de tre åtgärderna står som textlänk; ett item utan taggar visar meningen, och länken bara med `can.update`; brödsmulans första led är containerns namn och leder till itemfliken; hela testsviten är grön.

**Beror på:** 330

### 332. Itemets flikar

GitHub #982. Flikraden på itemets sida enligt [[ADR-0068 Itemets sida och skapandet]] § 4: **Overview · Relations · Documents · Costs · To do · Loans · History**, i den ordningen. Flikarnas nycklar i adressen står still (`attachments`, `schedules`), bara ordningen och etiketterna följer ADR:en.

- Fliken *Information* och dess sektion tas bort, liksom fliken *Tags* och dess sektion. Taggarna står i huvudet sedan issue 331.
- `?tab=information` och `?tab=tags` visar översikten, som ett okänt värde gör i dag.
- **En flik med noll rader bär ingen räknare.** `count` är `null` när talet är `0`. Regeln gäller itemets flikar. `UiTabs` och containerns flikar ändras inte.
- Länken *Show all fields* på översikten pekade på Information. Den tas bort här och fältrutnätet byggs i issue 333.

`item.show.information`, `item.show.information_empty` och `item.show.all_fields` tas bort ur `lang/en/ui.php` när ingen längre läser dem. `item.show.tags`, `item.show.tags_empty` och `item.show.tags_add` står kvar: huvudet läser dem sedan issue 331. Proven i `ItemflikTest` för Information, Tags och taggflikens tomma rad går ut (ADR-0068 § Konsekvenser).

**Läs:** [[ADR-0068 Itemets sida och skapandet]] § 4 och § Konsekvenser, `resources/js/pages/Containers/Items/Show.vue` (`tabs`, `activeTab` och sektionerna), `resources/js/components/UiTabs.vue` (räknarens docblock)

**Klart när:** flikarna står i ordningen Overview, Relations, Documents, Costs, To do, Loans, History; det finns ingen flik Information eller Tags; `?tab=information` och `?tab=tags` visar översikten; en flik med noll rader ritar ingen räknare och en med rader ritar talet; containerns flikar ritar sina räknare som förut; översikten har ingen länk *Show all fields*; de borttagna nycklarna finns inte i `lang/en/ui.php`; hela testsviten är grön.

**Beror på:** 329, 331

### 333. Itemets översikt

GitHub #983. Översikten enligt [[ADR-0068 Itemets sida och skapandet]] § 5 och § 7, uppifrån och ned:

1. **Bild och fält.** Omslaget till vänster (`ItemOverviewCover`) och varje ifyllt fält i ett rutnät med två kolumner: kategori, tillverkare, modell, serienummer, inköpsdatum, garanti och förvaringsplats, med etiketterna ur `item.show.*`. Ett tomt fält ritas inte. Ingen rubrik *Quick facts*.
2. **Beskrivningen**, under fälten.
3. **Snabbåtgärder:** *Add document*, *Add task*, *Add cost* och *Add relation*, i den ordningen. Raderna och deras adresser kommer ur sidans `create`-prop (`CreateTarget::forItem`), som redan bara bär det användaren får göra. En åtgärd utan motsvarande rad ritas inte, och utan någon ritas ingen rad.
4. **En pågående utlåning** som en rad med låntagare och återlämningsdatum, bara när `openLoan` finns.
5. *Kartan under `lg:` byggs i issue 335.*
6. **Anteckningarna**, oförändrade ([[ADR-0066 Itemets anteckning]]).
7. **Senaste aktivitet**, högst fem händelser, med länken till History.

Ett item utan fält, beskrivning, anteckning och aktivitet visar `item.show.overview_empty`, och med `can.update` en länk *Edit*. `ItemQuickFacts.vue` och `itemQuickFacts()` i `itemPresentation.js` tas bort, liksom `item.show.quick_facts`. `ItemPlacementsPanel` står kvar där den står.

**Läs:** [[ADR-0068 Itemets sida och skapandet]] § 5 och 7, `docs/Design/struktur - item.jpeg`, `resources/js/pages/Containers/Items/Show.vue` (översiktens sektion), `resources/js/components/itemPresentation.js`, `resources/js/components/ItemQuickFacts.vue`, `app/Support/Frontend/CreateTarget.php` (`forItem()`)

**Klart när:** varje ifyllt fält står i rutnätet och ett tomt fält ritas inte; rutnätet har två kolumner från `md:`; beskrivningen står under fälten; snabbåtgärderna står i ordningen document, task, cost, relation och leder dit plusmenyns rader leder; en åtgärd användaren inte får göra ritas inte; den pågående utlåningen syns bara när itemet är utlånat; anteckningarna och senaste aktivitet ritas som förut, med högst fem händelser; det tomma läget visar meningen och länken bara med `can.update`; `ItemQuickFacts` och `itemQuickFacts` finns inte; hela testsviten är grön.

**Beror på:** 332

### 334. De tomma flikarna

GitHub #984. Varje flik på itemets sida som kan vara tom säger en mening och erbjuder en åtgärd, och åtgärden visas bara när användaren får utföra den ([[ADR-0068 Itemets sida och skapandet]] § 7).

| Flik | Åtgärd | Rättighet |
|---|---|---|
| Relations | *Add relation* | den som i dag visar relationsformuläret |
| Documents | *Add document* | den som i dag visar uppladdningen |
| Costs | *Add cost* | den som i dag visar kostnadsformuläret |
| To do | *Add task* | den som i dag visar *New task* |
| Loans | *Lend out* (`item.loan.form_heading`) | den som i dag visar utlåningsformuläret |
| History | ingen | — |

Åtgärden öppnar eller fokuserar flikens befintliga formulär. Inget formulär, ingen rättighet och ingen ändpunkt ändras. En flik som redan har en tom mening behåller nyckeln och får åtgärden bredvid. Tomma lägen använder `UiEmptyState`.

**Läs:** [[ADR-0068 Itemets sida och skapandet]] § 7, `resources/js/components/UiEmptyState.vue`, `resources/js/components/ItemLinkSection.vue`, `resources/js/components/ItemAttachmentSection.vue`, `resources/js/components/ItemCostSection.vue`, `resources/js/components/ScheduleListSection.vue`, `resources/js/components/ItemLoanSection.vue`

**Klart när:** varje flik i tabellen visar en mening när den är tom; åtgärden syns med rättigheten och inte utan den; åtgärden når flikens formulär; History visar en mening utan åtgärd; en flik med rader ritar inget tomt läge; hela testsviten är grön.

**Beror på:** 332

### 335. Infällbara sidopaneler och kartan på översikten

GitHub #985. Itemets sida enligt [[ADR-0068 Itemets sida och skapandet]] § 2 och § 5 punkt 5.

- **Från `lg:`** står strukturen, itemet och kartan bredvid varandra som i dag. Struktur- och kartpanelen har var sin knapp som fäller ihop panelen till en smal list, och listen fäller ut den igen med ett klick. Knappen bär `aria-expanded` och ett namn ur `lang/`.
- **Valet sparas per webbläsare** i `localStorage` under nycklarna `mimers.item.structure_panel` och `mimers.item.map_panel`, med värdet `collapsed` eller inget. Läsning och skrivning ligger i `try/catch`, och sidan ritar båda panelerna utfällda när lagringen inte går att läsa.
- **Under `lg:`** visas strukturen som en utfällbar rad ovanför itemet, hopfälld från början. Kartan i sin smala form (`ItemMapPanel`) flyttar in på översikten, mellan den pågående utlåningen och anteckningarna.
- **Relationerna visas en gång på varje bredd.** Från `lg:` står kartan i högerpanelen och översikten ritar ingen karta. Relationsfliken ändras inte.

`ItemPlacementsPanel` står kvar i högerpanelen från `lg:` och i mittkolumnen under.

**Läs:** [[ADR-0068 Itemets sida och skapandet]] § 2 och 5, [[ADR-0063 Itemets karta i containerkartans form]] § 1, `resources/js/pages/Containers/Items/Show.vue` (rutnätet och panelerna), `resources/js/components/ItemStructurePanel.vue`, `resources/js/components/ItemMapPanel.vue`

**Klart när:** från `lg:` kan struktur- och kartpanelen fällas ihop och ut var för sig; knappen bär `aria-expanded`; valet läses ur och skrivs till de två nycklarna; en lagring som kastar ger två utfällda paneler och inget fel; under `lg:` är strukturen en utfällbar rad ovanför itemet; under `lg:` står kartan på översikten och från `lg:` gör den inte det; relationsfliken är oförändrad; hela testsviten är grön.

**Beror på:** 333

### 336. Väljaren för taggar och kategorier

GitHub #986. En ny komponent `TaxonomyPicker.vue`: en sökbar väljare med de valda värdena som chips ([[ADR-0068 Itemets sida och skapandet]] § 13). Den tar alternativen (`{ulid, name, color?}`), värdet och läget `single` eller `multiple`. I `single` ersätter ett val det förra och chipet kan tas bort för *ingen kategori*. Listan filtreras medan man skriver, piltangenterna flyttar markeringen, Enter väljer och Backspace i ett tomt sökfält tar bort det sista chipet. Komponenten följer combobox-mönstret (`role="combobox"`, `aria-expanded`, `aria-activedescendant`).

`ItemForm` använder den för kategorin (`single`) och taggarna (`multiple`) i stället för dagens `UiSelect` och kryssrutor. Kategoriernas alternativ byggs med `categoryOptions()` ur `categoryTree.js`, som i dag. Formulärets värden (`category` som ULID eller `null`, `tags` som lista med ULID) och vad som skickas till servern ändras inte. Länkarna bort till tagg- och kategorisidorna står kvar tills issue 337. Itemflikens filterrad byter i issue 343.

**Läs:** [[ADR-0068 Itemets sida och skapandet]] § 13, `resources/js/components/ItemForm.vue` (kategori och taggar), `resources/js/components/ParentPicker.vue` (sökningens och tangentbordets mönster), `resources/js/components/categoryTree.js`

**Klart när:** `TaxonomyPicker` finns och bär bara tokens; `single` håller ett värde och kan tömmas; `multiple` håller flera; sökningen filtrerar alternativen; pil, Enter och Backspace gör det beskrivna; komponenten bär combobox-rollerna; `ItemForm` skickar samma `category` och `tags` som förut, vid skapande och redigering; hela testsviten är grön.

**Beror på:** 329

### 337. Nya taggar och kategorier i väljaren

GitHub #987. Raden *Create "X"* i väljaren ur issue 336 ([[ADR-0068 Itemets sida och skapandet]] § 14).

- Raden visas när sökfältet har text som inte exakt (skiftlägesokänsligt) matchar ett alternativ, **och bara när användaren klarar `ContainerPolicy::update`**. Det är samma grind som `TagController::store` och `CategoryController::store` prövar. Sidorna `create` och `edit` får en boolesk `can.manage_taxonomy` ur `Gate::allows('update', $container)`, och väljaren ritar raden bara med den. En mottagare med begränsat omfång ser ingen sådan rad.
- Valet anropar de **befintliga** ändpunkterna (`POST /containers/{container}/tags` och `…/categories`) med Inertias `preserveState` och `preserveScroll`. Ändpunkternas `back()` ritar om formuläret med de nya listorna, inmatningen står kvar och det nya värdet väljs, uppslaget på namnet ur den uppdaterade propen.
- Ett valideringsfel från ändpunkten visas under väljaren.
- Länkarna *Create tags* och *Create categories* bort från formuläret tas bort, liksom `item.form.tags_empty_link` och `item.form.categories_empty_link`.

**Ingen ny ändpunkt, ingen ny grind och ingen ny behörighetsregel.** Kräver implementationen något av det: stanna och fråga.

**Läs:** [[ADR-0068 Itemets sida och skapandet]] § 14, [[Items och organisation]] § category och § tag, `app/Http/Controllers/TagController.php` (`store()`), `app/Http/Controllers/CategoryController.php` (`store()`), `app/Policies/ContainerPolicy.php` (`update()`), `app/Http/Controllers/ItemController.php` (`create()` och `edit()`)

**Klart när:** raden *Create "X"* syns för en användare som klarar `ContainerPolicy::update` och inte för en som inte gör det; den syns inte när texten matchar ett befintligt alternativ; en ny tagg och en ny kategori skapas genom de befintliga ändpunkterna och väljs i formuläret; formulärets övriga inmatning står kvar efter skapandet; ett valideringsfel visas vid väljaren; en mottagare med begränsat omfång får ingen rad och ändpunkterna nekar den som förut; inga rutter har lagts till; de två länkarna och nycklarna finns inte; hela testsviten är grön.

**Beror på:** 336

### 338. Skapaformuläret i två nivåer

GitHub #988. `ItemForm` i skapandeläget enligt [[ADR-0068 Itemets sida och skapandet]] § 8.

- **Direkt synliga:** namn, plats i strukturen, kategori, taggar och ikon. Ikonen väljs med `ItemIconPicker` som i redigeringen, och `icon` och `icon_color` skickas med. `StoreItemRequest` tar redan emot dem.
- **Under en utfällbar *More details*:** beskrivning, tillverkare, modell, serienummer, inköpsdatum, garanti, förvaringsplats och, när användaren är medlem i fler än ett konto, kontot. Avsnittet är hopfällt från början och fälls ut av sig självt när servern returnerar ett fel på ett fält i det.
- **Anteckningar finns inte i skapaformuläret.** `notes` skickas inte och `item.form.notes` tas bort när ingen läser den. `StoreItemRequest` ändras inte.

Redigeringsläget behåller sina fält i dagens ordning, utan `notes` som förut.

**Läs:** [[ADR-0068 Itemets sida och skapandet]] § 8, [[ADR-0066 Itemets anteckning]], `resources/js/components/ItemForm.vue`, `resources/js/components/ItemIconPicker.vue`, `app/Http/Requests/Item/StoreItemRequest.php` (reglerna)

**Klart när:** skapandet visar namn, plats, kategori, taggar och ikon utan att något fälls ut; de övriga fälten står under *More details*, som är hopfällt; ett fel på ett fält under *More details* fäller ut avsnittet; kontot står under *More details* bara med fler än ett konto; ett item skapas med vald ikon och färg; skapandet skickar inget `notes`; `item.form.notes` finns inte; redigeringen är oförändrad; hela testsviten är grön.

**Beror på:** 337

### 339. Cancel och Save and add another

GitHub #989. Skapaformulärets knappar enligt [[ADR-0068 Itemets sida och skapandet]] § 9.

- ***Cancel*** leder till förälderns sida när formuläret har en förälder och annars till itemfliken (`/containers/{container}/items`).
- ***Save and add another*** skickar formuläret med `another: true`. `StoreItemRequest` får regeln `another` (`sometimes`, `boolean`). `ItemController::store()` leder då till `containers.items.create` med `?parent=` (när itemet har en förälder) och `?category=` (när det har en kategori), och flashar itemets namn och ULID som `created_item`.
- `ItemController::create()` läser `?category=`. En ULID för en kategori i containern blir formulärets förval, och ett okänt värde ignoreras. `create()` läser också `created_item` ur sessionen och skickar den som propen `created` (`{ulid, name}` eller `null`). Den delade flashen i `HandleInertiaRequests` rörs inte.
- Formuläret visar raden överst: *Created :name*, med en länk till itemet. Övriga fält är tomma.

Ingen ny rutt. Grindarna i `create()` och `store()` ändras inte.

**Läs:** [[ADR-0068 Itemets sida och skapandet]] § 9, `app/Http/Controllers/ItemController.php` (`create()` och `store()`), `app/Http/Requests/Item/StoreItemRequest.php`, `resources/js/components/ItemForm.vue`, `resources/js/pages/Containers/Items/Create.vue`

**Klart när:** *Cancel* leder till föräldern när den finns och annars till itemfliken; *Save and add another* skapar itemet och öppnar ett nytt formulär med samma plats och samma kategori; övriga fält är tomma; raden överst nämner det skapade itemet och länkar till det; `?category=` med en främmande eller okänd ULID ger inget förval; *Save* leder till itemets sida som förut; inga rutter har lagts till; hela testsviten är grön.

**Beror på:** 338

### 340. Varningen innan inmatning går förlorad

GitHub #990. [[ADR-0068 Itemets sida och skapandet]] § 10. När `ItemForm` är ändrat (`form.isDirty`) och användaren lämnar sidan utan att spara frågar appen först, med `UiConfirmDialog` ur issue 330.

- En Inertia-navigering avbryts i `router.on('before')`, dialogen öppnas och *Leave* gör om besöket. *Cancel* stannar.
- En omladdning eller en stängd flik får webbläsarens egen fråga genom `beforeunload`. Den kan inte ersättas av appens dialog.
- Ett sparande, *Save and add another*, väljarens *Create "X"* ur issue 337 och *Cancel* efter bekräftelse frågar inte. Bara en navigering bort från formuläret (`GET` till en annan adress) frågar. Lyssnarna tas bort när formuläret avmonteras.

Gäller skapandet och redigeringen.

**Läs:** [[ADR-0068 Itemets sida och skapandet]] § 10, `resources/js/components/ItemForm.vue`, `resources/js/components/UiConfirmDialog.vue`

**Klart när:** ett ändrat formulär frågar innan en länk lämnar det; *Cancel* i dialogen stannar och *Leave* lämnar; ett oändrat formulär frågar inte; att spara frågar inte; att skapa en tagg i väljaren frågar inte; `beforeunload` är satt bara när formuläret är ändrat; lyssnarna tas bort när formuläret avmonteras; detsamma gäller redigeringen; hela testsviten är grön.

**Beror på:** 330, 339

### 341. Bilden vid skapandet

GitHub #991. Skapaformuläret får ett fält *Image* ([[ADR-0068 Itemets sida och skapandet]] § 11). Det tar en bild och ingen annan fil.

1. Vid *Save* skapas itemet först, som i dag.
2. I `onSuccess` står sidan på itemet. Formuläret laddar då upp bilden med `router.post` mot itemets **befintliga** ändpunkt (`containers.items.attachments.store`) med samma fält som `ItemAttachmentSection` skickar, `file` och `account`. `account` är kontot formuläret skapade itemet med, så att kvoten räknas på det uppladdande kontot.
3. Bilden blir omslag genom regeln i [[ADR-0041 Itemets vy]]: utan ett val är omslaget itemets äldsta bild. Inget `cover` skickas.
4. Misslyckas uppladdningen, till exempel med `quota.storage_exceeded`, finns itemet ändå. Användaren står på itemets sida med fliken Documents öppen (`?tab=attachments`) och felet synligt där.

*Save and add another* ur issue 339 laddar upp bilden innan nästa formulär öppnas på samma sätt.

**Ingen ny ändpunkt, ingen fil utan item och ingen ändring i deduplicering, referensräkning eller kvot.** Kräver implementationen något av det: stanna och fråga.

**Läs:** [[ADR-0068 Itemets sida och skapandet]] § 11, [[ADR-0041 Itemets vy]] (omslagets regel), [[Filer och lagring]] § Uppladdningsflödet och § Kvot kontra faktisk lagring, `resources/js/components/ItemAttachmentSection.vue` (uppladdningens anrop), `app/Http/Controllers/AttachmentController.php` (`store()`), `resources/js/components/ItemForm.vue`

**Klart när:** skapandet har fältet *Image*, som tar bara bilder; ett item skapat med bild har bilden som bilaga och som omslag; uppladdningen går mot `containers.items.attachments.store` och debiteras det konto itemet skapades med; en uppladdning som nekas av kvoten lämnar itemet skapat, öppnar fliken Documents och visar felet; ett item utan bild skapas som förut; inga rutter har lagts till; hela testsviten är grön.

**Beror på:** 339

### 342. Omslaget bland miniatyrer

GitHub #992. [[ADR-0068 Itemets sida och skapandet]] § 12.

- **Redigeringen:** omslagsväljaren med filnamn i `ItemForm` ersätts av ett rutnät med itemets bilder som miniatyrer och rutan *No cover* först. Rutnätet är en radiogrupp: ett val i taget, piltangenterna flyttar och valet syns med mer än färg. `images` i `ItemController::edit()` får `hasThumb` per bild, och en bild utan miniatyr ritas som en ruta med filnamnet. Värdet som skickas (`cover` som ULID eller `null`) ändras inte.
- **Fliken Documents:** varje bild som inte redan är omslaget får raden *Use as cover*, bara med `can.update`. Den skickar `PATCH` mot itemets befintliga uppdatering (`containers.items.update`) med bara `cover`, med `preserveScroll`. `UpdateItemRequest` validerar redan `cover` ensamt, men `ItemController::update()` skriver i dag `category` och `tags` också när nycklarna saknas, och hade då rensat dem. `update()` rör därför `category` och `tags` bara när requesten har nyckeln (`$request->has()`), som den redan gör för `cover`. Redigeringsformuläret skickar alltid båda, så dess beteende ändras inte.

Ingen ny rutt.

**Läs:** [[ADR-0068 Itemets sida och skapandet]] § 12, [[ADR-0041 Itemets vy]] (omslaget), `resources/js/components/ItemForm.vue` (omslagets fält), `app/Http/Controllers/ItemController.php` (`edit()`, `imageOptions()` och `update()`), `app/Http/Requests/Item/UpdateItemRequest.php` (`cover`), `resources/js/components/ItemAttachmentSection.vue`

**Klart när:** redigeringen visar itemets bilder som miniatyrer och *No cover* först; valet skickar samma `cover` som förut; rutnätet går att använda med tangentbordet; en bild utan miniatyr ritas utan `<img>`; *Use as cover* syns på bilderna i Documents med `can.update` och inte utan; den syns inte på bilden som redan är omslaget; ett tryck gör bilden till omslag och lämnar kategori, taggar och övriga fält orörda; redigeringen som skickar `tags: []` tar fortfarande bort itemets taggar; inga rutter har lagts till; hela testsviten är grön.

**Beror på:** 341

### 343. Trädtabellens rader

GitHub #993. Itemflikens trädtabell och filterrad enligt [[ADR-0068 Itemets sida och skapandet]] § 16.

- **Hela raden är klickbar** och leder dit namnets länk leder. Fällknappen och andra kontroller i raden behåller sitt eget klick. Namnet är fortfarande en `Link`, så tangentbordet och skärmläsaren har samma väg som förut.
- **Dokument- och uppgiftskolumnerna är tomma** i stället för `0`.
- **Fällknappens kolumn** har samma bredd på varje rad, så att namnen står i linje. På en löv-rad står ett tomt utrymme lika brett som knappen, utan synlig ruta.
- **Filterradens taggar** väljs med `TaxonomyPicker` ur issue 336 i läget `multiple`, utan raden *Create "X"*. Filtrets adress och regler ändras inte.
- **Sökfältet** har en platshållare i stället för en egen synlig etikett, och behåller ett tillgängligt namn (`aria-label`).

**Läs:** [[ADR-0068 Itemets sida och skapandet]] § 16, `resources/js/components/ItemTreeTable.vue`, `resources/js/components/ItemFilterBar.vue`, `resources/js/components/TaxonomyPicker.vue`

**Klart när:** ett klick var som helst på raden öppnar itemet; fällknappen fäller utan att öppna; en kolumn med noll dokument eller uppgifter är tom; namnen på löv och föräldrar står i linje; löv-raden ritar ingen ruta där knappen står; filterradens taggar väljs i väljaren och ger samma adress som förut; sökfältet har platshållare och `aria-label` men ingen synlig etikett; hela testsviten är grön.

**Beror på:** 336
