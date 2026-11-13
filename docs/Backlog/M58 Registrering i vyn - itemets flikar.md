# M58 · Registrering i vyn: itemets flikar

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-13. Bygger [[ADR-0074 Registrering i vyn]] och tillägget om itemets flikar daterat 2026-11-13 (Tonys beslut): itemets *Documents*, *To do* och *Costs* får samma registrering i vyn som containerns flikar fick i [[M54 Registrering i vyn - Items]], [[M55 Registrering i vyn - Documents]] och [[M56 Registrering i vyn - Costs]], och itemets plusmeny och snabbknappar landar på fliken med formuläret öppet. Sidorna för nytt och redigerat item får containerns låga hjälte. Issuerna skrevs direkt i GitHub (#1209–#1212 och #1214) och är sammanfattade här.

**Releasen:** en, när 374–378 är mergade.

---

### 374. Lägg till dokument direkt på itemets Documents-flik

GitHub #1209. **Läget:** på itemets *Documents*-flik står rubriken *Upload files* och uppladdningskön alltid öppna ovanför listan, medan containerns flik använder det infällbara formuläret.

**Lösningen:** rubriken och den öppna kön i `ItemAttachmentSection.vue` ersätts av `InlineCreate` med etiketten *Add document* (`container.documents.add`) före listan, med `AttachmentUploadQueue` inuti och utan itemväljare. `dirty` är köns `busy`. Formuläret står kvar efter uppladdningen, och nya rader får `bg-accent-soft` i 2000 ms genom jämförelse före och efter `uploaded`. Knappen i det tomma läget fäller ut formuläret med fokus. Allt ritas bara bakom `can.create`. `item.attachment.upload_heading` tas bort om den inte används någon annanstans, och proven som läser rubriken eller den öppna kön pekas om.

**Läs:** [[ADR-0074 Registrering i vyn]] § 1–3; `ItemAttachmentSection.vue`; `Containers/Documents.vue` (`InlineCreate` och `onUploaded`); `InlineCreate.vue`; `AttachmentUploadQueue.vue`

**Klart när:** rubriken *Upload files* är borta och `InlineCreate` med *Add document* står före listan med kön inuti; formuläret står kvar efter uppladdningen och nya rader får `bg-accent-soft` i 2000 ms; Escape och *Cancel* stänger och frågar vid pågående uppladdning; knappen i det tomma läget fäller ut formuläret; en läsare utan `can.create` ser varken knappen eller kön; sviten är grön.

**Beror på:** —

### 375. Snabbraden på itemets To do-flik

GitHub #1210. **Läget:** på itemets *To do*-flik leder *New task* till `/tasks/create?item=…&return=…`, medan containerns flik använder snabbraden.

**Lösningen:** `ContainerTaskQuickCreate.vue` får propen `item` (`{ ulid, name }` eller `null`). När den är satt ritas ingen platsknapp, platsen är alltid itemet och raden postar till `/containers/{c}/items/{item}/schedules` med `recurrence_type: 'none'`, `gtd_list` och `return: page.url`. Utan `item` är beteendet oförändrat. I `ScheduleListSection.vue` tas *New task* i huvudet och i det tomma läget bort, med `createUrl`, och snabbraden ritas som rad noll före grupperna bakom `can.create`. Nya rader markeras med `bg-accent-soft` i 2000 ms, och hamnar uppgiften utanför listan visas komponentens `hidden`-text. Snabbraden skapar ingen återkommande uppgift; den som vill ha ett schema ändrar uppgiften efteråt (Tonys beslut tills vidare), och fliken behåller ingen länk till hela formuläret.

**Läs:** [[ADR-0074 Registrering i vyn]] § 1–3 och 6; `ScheduleListSection.vue`; `ContainerTaskQuickCreate.vue`; `Containers/Tasks.vue` (`onCreated` och markeringen); `QuickCreate.vue`

**Klart när:** itemets *To do*-flik har ingen *New task*-knapp och ingen länk till `/tasks/create`; snabbraden står före grupperna utan platsknapp och Enter skapar en uppgift på itemet utan sidbyte; raden står kvar med tomt fält och fokus och den nya uppgiften får `bg-accent-soft` i 2000 ms; containerns snabbrad beter sig som förut; en läsare utan `can.create` ser ingen snabbrad; sviten är grön.

**Beror på:** —

### 376. Registrera en kostnad direkt på itemets Costs-flik

GitHub #1211. **Läget:** på itemets *Costs*-flik står rubriken *Register a cost* och formuläret alltid öppna ovanför listan, medan containerns flik använder det infällbara formuläret.

**Lösningen:** rubriken och det öppna formuläret i `ItemCostSection.vue` ersätts av `InlineCreate` med etiketten *Add cost* (`item.show.add_cost`) före listan, med `CostEntryForm` inuti, med samma props som i dag och utan itemväljare. Stängning och *Cancel* med osparade ändringar frågar med samma dialog som `Containers/Costs.vue` (`setOpen`, `cancel`, `discard`, `formKey`). Formuläret står kvar efter sparningen med fokus i beloppet, och den nya raden får `bg-accent-soft` i 2000 ms genom jämförelse av ULID:erna före och efter `created`. Knappen i det tomma läget fäller ut formuläret med fokus. `item.cost.form_heading` tas bort om den inte används någon annanstans, och proven som läser rubriken eller det öppna formuläret pekas om.

**Läs:** [[ADR-0074 Registrering i vyn]] § 1–3; `ItemCostSection.vue`; `Containers/Costs.vue` (`InlineCreate`, `setOpen`, `cancel`, `discard`); `CostEntryForm.vue`; `InlineCreate.vue`

**Klart när:** rubriken *Register a cost* är borta och `InlineCreate` med *Add cost* står före listan med `CostEntryForm` inuti; formuläret står kvar med fokus i beloppet och den nya raden får `bg-accent-soft` i 2000 ms; Escape och *Cancel* stänger och frågar vid osparade ändringar; knappen i det tomma läget fäller ut formuläret; en läsare utan `can.create` ser varken knappen eller formuläret; sviten är grön.

**Beror på:** —

### 377. Plusmenyn och snabbknapparna på itemet öppnar flikens formulär

GitHub #1212. **Läget:** itemets plusmeny och snabbknapparna på översikten (`ItemQuickActions`) läser samma rader ur `CreateTarget::forItem()`. *Add document* och *Add cost* leder bara till fliken, och *Add task* leder till en annan sida, `containers.items.schedules.create`.

**Lösningen:** `forItem()` ger `tabHref(…)` följt av `&create=1` för `attachment` (`attachments`), `schedule` (`schedules`, i stället för `containers.items.schedules.create`) och `cost` (`costs`); `item` och `relation` är oförändrade och grindarna likaså. Rutten `containers.items.schedules.create` och dess sida finns kvar. `Containers/Items/Show.vue` läser `create=1` ur `page.url` och skickar `:start-open="true"` till den aktiva flikens sektion, och bevakar värdet så att ett val på samma item öppnar formuläret utan ny montering. Sektionerna får propen `startOpen` (förval `false`): `ItemAttachmentSection` och `ItemCostSection` fäller ut `InlineCreate` med fokus, och `ScheduleListSection` fokuserar snabbraden via `focus()` som `ContainerTaskQuickCreate` exponerar.

**Läs:** [[ADR-0074 Registrering i vyn]] § 1–3 och 6; `CreateTarget.php` (`forItem`, `forNode`, `tabHref`); `Containers/Items/Show.vue` (`activeTab` och sektionerna); `ItemQuickActions.vue`; sektionerna efter 374–376

**Klart när:** `forItem()` ger `?tab=attachments&create=1`, `?tab=schedules&create=1` och `?tab=costs&create=1` (med `path` när den finns) och ingen rad pekar på `/schedules/create`; *Add document* och *Add cost* landar på fliken med `InlineCreate` utfällt och fokus i det; *Add task* landar på *To do* med fokus i snabbraden; ett val i plusmenyn på samma item öppnar formuläret också utan ny montering; utan `create=1` är flikarna stängda; sviten är grön.

**Beror på:** 374, 375, 376

### 378. Sidorna för nytt och redigerat item får containerns låga hjälte

GitHub #1214. **Läget:** *Nytt barnitem* från strukturpanelen på itemets sida leder till `/containers/{c}/items/create?parent=…`, och sidan för att redigera ett item ligger på `/containers/{c}/items/{i}/edit`. Båda ritar bara containerns namnrad, inte containerns huvud.

**Lösningen:** vägen står kvar som en egen sida (Tonys beslut 2026-11-13), men `Items/Create.vue` och `Items/Edit.vue` deklarerar layouten `[ContainerLayout, { hero: 'compact', container: props.container }]`, samma som `Items/Show.vue`. Hjälten läser containerns tal ur den delade proppen `containerCounts`, så servern rörs inte. Formulären, `createChildUrl`, *Edit*-länken och skrivvägarna är oförändrade. `ContainerhjalteTest` flyttar båda sidorna till listan som kräver hjälten, och ett prov kräver att båda rutterna ger `containerCounts` som inte är `null`.

**Läs:** `Containers/Items/Create.vue` och `Edit.vue` (`defineOptions`); `ContainerLayout.vue` (`hero`); `ContainerhjalteTest.php` § issue 1023 och 1158

**Klart när:** `Items/Create.vue` och `Items/Edit.vue` deklarerar `[ContainerLayout, { hero: 'compact', container: props.container }]`; sidorna ritar den låga hjälten över `md:` och flikraden som på itemets sida, och under `md:` ritas toppraden som förut; `ContainerhjalteTest` kräver hjälten för båda; formulären, barnitemsknappen och *Edit* fungerar som förut; sviten är grön.

**Beror på:** —
