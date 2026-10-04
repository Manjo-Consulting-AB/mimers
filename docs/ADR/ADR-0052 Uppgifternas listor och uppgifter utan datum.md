# ADR-0052 Uppgifternas listor och uppgifter utan datum

**Status:** Antagen 2026-10-04 · Ersätter [[ADR-0050 Desktopdesignen]] § 16 i frågan om GTD-listorna · Ändrar [[ADR-0005 Schema och förekomst]] om att varje förekomst har ett datum · Bygger vidare på [[ADR-0044 Användarens dag]] och issue 225–227 · [[ADR-index]]

Tonys beslut 2026-10-04, efter genomgången av designerns underlag `docs/Design/Mimers_Task_Flow_GTD_Design_Decision.md` och bilderna `task-dashboard.png`, `task-new.png`, `tasks-container.png` och `tasks-item.png`.

## Kontext

[[ADR-0050 Desktopdesignen]] § 16 sköt GTD-listorna till [[Efter MVP]]. Skälet var att listorna Inbox, Next, Waiting, Calendar och Someday förutsätter fält som `schedule_occurrence` inte har.

Designerns underlag visar att det mesta av modellen redan finns. En uppgift hör till ett item. Regeln och den enskilda gången är redan åtskilda ([[ADR-0005 Schema och förekomst]]). Ett item med flera parents bär samma uppgift oavsett vägen dit. Issue 225–227 gav containern och itemet listor med datumgrupperna *Overdue*, *Today*, *This week*, *Upcoming* och *Done*, med itemets ättlingar inräknade.

Det som saknas är tre saker:

1. **En uppgift utan datum.** `due_at` och `visible_from` är `NOT NULL`. En uppgift med bara en titel går inte att skapa, och Inbox, Next och Someday är i GTD i grunden odaterade.
2. **En GTD-lista.** Ingen kolumn säger om en uppgift är obearbetad, nästa handling, väntar på någon eller ligger för en annan gång.
3. **En status mellan öppen och klar.** Förekomsten har bara `open`, `completed` och `skipped`.

Schemaändringen är billigast nu. Det finns ingen extern användare vars data måste migreras, och det är samma argument som [[ADR-0035 Relationen mellan objekt]] använde för `sibling`.

## Beslut

### 1. GTD-listan och statusen är två egenskaper

**GTD-listan lagras på förekomsten** i kolumnen `schedule_occurrence.gtd_list`, med värdena `inbox`, `next`, `waiting` och `someday`. Den får inte vara null.

**Statusen får ett fjärde värde.** `schedule_occurrence.status` blir `open`, `in_progress`, `completed` eller `skipped`. Förvalet är `open`. `in_progress` är frivilligt och krävs aldrig för att en uppgift ska kunna bockas av.

**En förekomst är aktiv när statusen är `open` eller `in_progress`.** Där koden i dag frågar efter `status = 'open'` och menar *inte stängd* ska den fråga efter aktiv. Det gäller todo-urvalet, itemets status, notiserna, ICS-flödet, beroendena och avbockningens kontroll av att förekomsten inte redan är stängd. Villkoret formuleras en gång, som ett scope på modellen.

**Följande lagras inte utan härleds:**

| Vy | Villkor |
|---|---|
| *Calendar* | aktiv och `due_at IS NOT NULL` |
| *Done* | `status = completed` |
| *Overdue* | aktiv och `due_at < User::today()` |
| *Today* | aktiv och `due_at = User::today()` |
| *This week* | aktiv och `due_at` från i morgon till och med söndag i innevarande ISO-vecka |
| *Upcoming* | aktiv och `due_at` efter innevarande ISO-vecka |
| *No date* | aktiv och `due_at IS NULL` |

`skipped` hör bara till historiken. GTD-listan och datumgruppen överlappar med flit. En uppgift i *Next* med ett datum syns både under *Next* och i sin datumgrupp, och därför summerar GTD-räkningen inte till totalen.

### 2. Var en ny förekomst hamnar

**Schemat får ett förval, `schedule.default_gtd_list`.** Det får vara null och kan bara ha värdet `next` eller `someday`.

- **En uppgift som skapas manuellt börjar i `inbox`**, om användaren inte väljer en annan lista i formuläret.
- **Första gången en förekomst lämnar `inbox` till `next` eller `someday` sätts schemats förval** till den listan, men bara om förvalet fortfarande är null.
- **`waiting` blir aldrig förval.** Att vänta gäller en enskild gång, inte regeln.
- **Om förvalet fortfarande är null när en förekomst stängs**, oavsett om den bockas av eller hoppas över, sätts det till `next`.
- **En ny förekomst av ett återkommande schema** får schemats förval, eller `inbox` om förvalet är null.
- **Senare flyttar av en förekomst ändrar inte förvalet.**
- **Förvalet visas och kan ändras på schemats redigeringssida.** Det får inte finnas någon inlärning som användaren inte kan se.

### 3. En uppgift utan datum

**`schedule_occurrence.due_at` och `visible_from` får vara null.** Har en förekomst inget `due_at` är `visible_from` också null. Tas datumet bort försvinner `visible_from` med det.

**`schedule.lead_days` förblir `NOT NULL` med förvalet 0.** Utan `due_at` ignoreras den. Får uppgiften ett datum igen räknas `visible_from` ut med det `lead_days` som redan står där.

**`lead_days` är påminnelsen.** Det finns ingen separat inställning för när uppgiften blir synlig. En uppgift syns från att den skapas, som issue 220 (#698) slog fast. Mockupens två fält, *Reminder* och *Start X days before*, är därför ett och samma fält.

**Ett återkommande schema måste ha ett datum.** `recurrence_type` `fixed` eller `interval` kräver `anchor_date`, och förekomsterna får alltid ett `due_at`. Bara `none` får sakna datum. Utan ett första datum finns ingen serie att räkna nästa förfall ur.

**En uppgift utan datum:**

- syns inte i *Overdue*, *Today*, *This week* eller *Upcoming*
- står i *No date*, som är sista gruppen i varje fullständig lista, efter *Upcoming* och före *Done*
- ger ingen notis om `task.due` eller `task.overdue`
- skrivs inte till ICS-flödet
- räknas inte i *Tasks due*, som räknar försenat och idag
- kan aldrig göra ett item försenat enligt [[ADR-0040 Underträdets summor]]
- exporteras med tomt `due_at` och `visible_from`

**Ordningen inom *No date*** är `ulid` stigande, alltså den ordning uppgifterna skapades i. En uppgift utan datum jämförs aldrig som om den hade ett.

### 4. Migreringen

- Varje befintlig förekomst får `gtd_list = next`. Det är ett tekniskt värde och ingen bedömning. Stängda förekomster visas fortfarande efter sin status.
- Varje befintligt schema får `default_gtd_list = next`. Användaren har redan bearbetat dem.
- `status` ändras inte. Det finns inga rader med `in_progress`.
- `due_at` och `visible_from` görs nullbara utan att någon rad ändras.

### 5. Dashboarden

Datumdiagrammet räknar bara datumgrupperna. GTD-räkningen är en egen panel. De två axlarna blandas inte i samma diagram.

## Motivering

**Listan och statusen svarar på olika frågor.** Listan säger hur användaren tänker hantera uppgiften. Statusen säger hur långt den har kommit. *Next* och *Pågår* är båda sanna samtidigt för samma uppgift. Hade de lagrats i en kolumn hade varje kombination behövt ett eget värde.

***Calendar* och *Done* lagras inte, eftersom de redan står i andra fält.** En lagrad *Calendar* kan hamna på en uppgift utan datum, och en lagrad *Done* på en öppen uppgift. Det är samma regel som [[ADR-0005 Schema och förekomst]] tillämpar på `overdue`: lagra aldrig ett tillstånd som andra fält redan avgör.

**Listan sitter på förekomsten och inte på schemat**, eftersom *Waiting* gäller en enskild gång. Hade den suttit på schemat hade nästa års service väntat på förra årets offert. Förvalet på schemat gör att ett årligt underhåll bara behöver bearbetas en gång.

**Inbox betyder obearbetad, inte utan plats.** Uppgiften hör fortfarande till ett item. Utan item har den varken ägare, behörighet eller plats i strukturen, och varje ställe som läser åtkomsten hade behövt ett undantag ([[ADR-0028 Åtkomst på itemnivå]]).

***No date* är en restgrupp.** Utan den hade en aktiv uppgift i *Next* utan datum saknats i listan över aktiva uppgifter. Den vyn heter *Active* och inte *All tasks*: de avbockade står under *Done*, och en etikett som lovar alla får inte utelämna några.

## Konsekvenser

- **Totalordningen och markören på `/tasks`.** Daterade förekomster sorteras på `due_at` stigande och sedan `ulid` stigande. Odaterade förekomster kommer efter alla daterade och sorteras på `ulid` stigande. Markören bär därför en uttrycklig sektion: `d_{due_at}_{ulid}` för en daterad rad och `n_{ulid}` för en odaterad. **Ett null-`due_at` jämförs aldrig med ett datum.**
  - *Framåt* från en daterad markör: daterade rader efter `(due_at, ulid)`, och därefter alla odaterade.
  - *Framåt* från en odaterad markör: bara odaterade rader med större `ulid`.
  - *Bakåt* gäller samma regler spegelvänt: från en odaterad markör först odaterade med mindre eller lika `ulid` och sedan alla daterade, och från en daterad markör bara daterade.
  - Frågan byggs som två delar, `due_at IS NOT NULL` och `due_at IS NULL`, och aldrig som en jämförelse där ett null-värde ingår.
  - En markör i dagens form `{due_at}_{ulid}` läses som `d_…`. En adress som redan är sparad fungerar då fortfarande.
- **Växeln `show_upcoming_tasks` gäller de globala sammanställningarna:** `/tasks`, dashboardens uppgiftspanel och containeröversiktens uppgiftspanel (`ListTodo::handle()`, `page()` och `forContainer()` utan `onlyCurrent: false`). Där döljer den bara grupperna med ett framtida datum, *This week* och *Upcoming*. *Overdue*, *Today* och *No date* visas alltid. En uppgift utan datum ligger inte i framtiden: en uppgift i *Next* utan datum är något användaren ska göra nu, och den får inte försvinna när hon döljer det som ligger framåt. **Containerns och itemets Tasks-flikar visar alltid alla grupper**, enligt issue 225 och 227. Räknarna (*Tasks due*) följer inte växeln och räknar som förut försenat plus idag.
- **`/api` ändras.** `due_at` och `visible_from` blir nullbara i `ScheduleOccurrenceResource` och `TodoEntryResource`, och `gtd_list` och `in_progress` tillkommer. Det bryter kontraktet för en klient som räknar med ett datum.
- **Notisgeneratorn, ICS-flödet, `ItemStatus`, `OpenNextOccurrence`, `UpdateSchedule` och exporten** behöver hantera null. Varje ställe som anropar `->due_at->` på en förekomst är en krasch som väntar.
- **Formuläret för ett schema** får `anchor_date` frivilligt för `none`, och fälten *GTD list* och, vid redigering, förvalet.
- **Ordet.** Kolumnen heter `gtd_list` och inte `gtd_context`. I GTD är en kontext något annat (*@phone*, *@home*), och det ordet hade lovat något schemat inte håller. Det är samma fel som `sibling` var ([[ADR-0035 Relationen mellan objekt]]). I gränssnittet heter fältet *List* och värdena *Inbox*, *Next*, *Waiting* och *Someday*.
- **[[Scheman och uppgifter]]** skrivs om: `due_at` och `visible_from` blir nullbara, och de nya kolumnerna och scopet för en aktiv förekomst läggs till.
- **[[Efter MVP]]** stryker posten *GTD-tavlan i containerns uppgiftsflik*. Tavlan som vy är fortfarande ett senare steg, men modellen är det inte längre.

**Utanför den här ADR:n:** taggar på uppgifter, ansvarig, flera items per uppgift, en påminnelse med klockslag, vyerna tavla och kalender, och snabbåtgärder.

## Alternativ

**Lagra alla sex listorna, *Calendar* och *Done* inräknade.** Följer designens lista bokstavligt. Valdes bort, eftersom två av värdena då kan säga emot `due_at` och `status`.

**GTD-listan på schemat.** En kolumn i stället för två. Valdes bort, eftersom *Waiting* då hade gällt varje framtida förekomst.

**Inbox som uppgifter utan item.** Ligger närmast GTD:s inkorg. Valdes bort, eftersom en uppgift utan item saknar ägare och behörighet.

**En separat inställning för när uppgiften blir synlig, vid sidan av påminnelsen.** Det var mockupens två fält. Valdes bort, eftersom issue 220 (#698) redan gjort uppgifter synliga från att de skapas, och testarna tog upp osynliga uppgifter som ett fel.

**Låta uppgifter utan datum sakna grupp.** Valdes bort, eftersom *Active* då inte visar alla aktiva uppgifter.
