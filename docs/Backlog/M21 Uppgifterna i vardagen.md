# M21 · Uppgifterna i vardagen

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

Tillagd 2026-09-25, efter Tonys test på staging. Ett dagligt schema bockades av, morgondagens förekomst dök upp i listan, bockades också av — och låg sedan kvar på *Tomorrow* medan historiken fick en rad till. Varje tryck gav en ny förekomst med samma förfallodag. Det är en bugg i hur nästa förfall räknas (132). De andra kom ur samma genomgång: raden ska visa redan på knappen om uppgiften är försenad eller ligger framåt i tiden (133), användaren ska kunna välja bort de framtida (134), och *idag* ska vara användarens och inte serverns (135). Issue 136 kom ur PR:en för 135: valvet beskrev fortfarande den gamla formeln.

**Orsaken till buggen.** `OpenNextOccurrence` räknar nästa förfall utan att veta vilken förekomst som just stängdes. `interval` räknar från `completed_at`, så en förekomst som bockas av *innan* den förfaller får samma förfallodag igen: morgondagens dagliga uppgift, avbockad idag, blir idag plus en dag — imorgon. `fixed` tar första datumet i serien som är `>= idag`, så en förekomst som bockas av på sin egen förfallodag får **idag** igen, och en som bockas av i förväg får sin egen dag igen. Befintliga tester bockar bara av förekomster som redan förfallit, därför syntes det inte.

---

### 132. Nästa förfall ligger alltid efter det stängda
**Regeln:** en ny förekomst som öppnas när en annan stängs har ett `due_at` som ligger **strikt efter** den stängda förekomstens `due_at`. Det gäller båda återkommandetyperna och båda rutterna (`complete` och `skip`).

- `fixed`: första datumet i serien som är både `>= idag` och `> stängd due_at`.
- `interval`, `complete`: `completed_at` plus intervallet, som i dag. Hamnar det på eller före den stängda förekomstens `due_at`, stegas det fram med intervallet tills det ligger efter. Ett oljebyte som görs två månader i förväg räknas alltså fortfarande från bytet; en daglig uppgift som bockas av en dag i förväg hoppar till dagen efter.
- `interval`, `skip`: räknar redan från `due_at` och uppfyller regeln; ändras inte.

Regeln bor i `OpenNextOccurrence`, som är den enda vägen in i `schedule_occurrence`. `CloseOccurrence` skickar med den stängda förekomstens `due_at`. Anropen vid skapande och återaktivering har ingen stängd förekomst och påverkas inte.

**Läs:** [[Scheman och uppgifter]] § De två återkommandetyperna och § Flödet när en uppgift markeras klar, `app/Actions/Schedule/OpenNextOccurrence.php` (docblocken), `app/Actions/Schedule/CloseOccurrence.php` (docblocken)
**Klart när:** en daglig `interval`-förekomst som förfaller imorgon och bockas av idag ger nästa förfall i övermorgon; en daglig `fixed`-förekomst som bockas av på sin förfallodag ger nästa förfall imorgon; en `fixed`-förekomst som bockas av i förväg ger nästa datum i serien efter sitt eget; `skip` på `fixed` följer samma regel; en `interval`-förekomst som bockas av i förväg men där `completed_at` plus intervallet ligger efter `due_at` räknas från `completed_at`; de befintliga fallen med sen avbockning ger samma datum som i dag; efter varje avslut har schemat exakt en rad i todo-urvalet, med det nya datumet; [[Scheman och uppgifter]] § Flödet beskriver regeln; hela testsviten är grön.
**Beror på:** -

### 133. Knappen visar om uppgiften är försenad eller framtida
Avbockningsknappen på en rad i todo-listan (`TodoRow.vue`, som både `/tasks` och dashboardens panel ritar) får en diskret prick. **Försenad** ger en prick i `danger`, **framtida** — `due_at` efter idag — en prick i `accent`. En uppgift som förfaller idag har ingen prick.

**Servern avgör, klienten ritar.** `TodoEntryResource` har redan `overdue`; den får `upcoming` bredvid, räknad mot serverns datum på samma sätt. Vyn jämför inga datum själv (issue 64 § Beslut 3). **Färgen är aldrig den enda bäraren:** pricken har en visuellt dold text, *Overdue* respektive *Upcoming*, som skärmläsaren läser.

**Läs:** [[ADR-0042 Designsystemet]], `resources/js/components/TodoRow.vue` (docblocken), `app/Http/Resources/TodoEntryResource.php`, `app/Actions/Schedule/ListTodo.php` (docblocken)
**Klart när:** en försenad rad har pricken i `danger` på knappen; en framtida rad har pricken i `accent`; en rad som förfaller idag har ingen prick; `upcoming` finns i todo-posten i både webben och `/api` och räknas mot serverns datum; pricken har en dold text för skärmläsare; strängarna ligger i `lang/en/ui.php`; hela testsviten är grön.
**Beror på:** -

### 134. Växeln för framtida uppgifter
Användaren väljer om todo-listan visar **alla synliga** uppgifter — som i dag — eller **bara de som är aktuella nu**: försenade och de som förfaller idag. Valet gäller både `/tasks` och dashboardens panel och **följer användaren**, inte webbläsaren: en ny kolumn `user.show_upcoming_tasks BOOLEAN NOT NULL DEFAULT TRUE`. Standardvärdet är dagens beteende.

**Urvalet formuleras i modellen.** `ListTodo` formulerar inget eget `where` (issue 122). Villkoret `due_at <= idag` blir en scope på `ScheduleOccurrence` som `ListTodo` lägger på när användarens flagga är falsk. Pagineringen på `/tasks` går över samma fråga och påverkas inte.

Växeln står i rubrikraden på `/tasks` och på panelen, och är en `PUT` till en egen rutt som sparar flaggan och gör `back()`. **Dashboardens brickor ändras inte** — de räknar det de räknar i dag.

**Läs:** [[Konton och åtkomst]] § user, `app/Actions/Schedule/ListTodo.php` (docblocken), `app/Models/ScheduleOccurrence.php` (`scopeTodoFor`), `app/Http/Controllers/DashboardController.php`, `database/migrations/2026_09_24_030000_add_notifications_read_at_to_user_table.php` (förlagan för kolumnen)
**Klart när:** med flaggan på visar `/tasks` och panelen samma rader som i dag; med flaggan av visas inga rader med `due_at` efter idag, på någon av sidorna; flaggan sparas på användaren och gäller i en ny session; växeln kräver inloggning; pagineringen på `/tasks` fungerar med flaggan av; brickorna räknar samma sak med flaggan av som på; migreringen följer konventionerna och går på MariaDB; [[Konton och åtkomst]] § user har kolumnen; strängarna ligger i `lang/en/ui.php`; hela testsviten är grön.
**Beror på:** -

### 135. Idag är användarens idag
Todo-listan räknar *idag* som serverns datum, alltså UTC. Mellan midnatt och klockan två svensk tid är det fortfarande gårdagen för servern. En uppgift som förfaller idag ligger då under *Upcoming*, en från igår är inte försenad, och det som blir synligt idag syns inte. Tidszonen finns redan: `user.timezone`, med `account.timezone` som reserv ([[Konton och åtkomst]] § user). Den används bara inte här.

Regeln *användarens tidszon, annars kontots, annars appens* finns i dag som två privata kopior, i `DashboardController` och i `QuietHours`. Den flyttas till `User::preferredTimezone()`, bredvid `preferredLocale()`. `User::today()` ger användarens kalenderdatum. **Datumet jämförs som datum, inte som ögonblick:** `due_at` och `visible_from` är `DATE`, och midnatt i Stockholm är ett annat ögonblick än midnatt i UTC. Jämförelsen görs därför med `toDateString()` eller mot ett datum som byggts om i appens tidszon.

Det här gäller todo-urvalet, grupperingen och `overdue` på förekomsterna. Nästa förfall i `OpenNextOccurrence` (132), notisjobben, ICS-flödet, itemträdets förfallomarkering och utlåningen räknar vidare i serverns datum. De får en egen issue om det behövs.

**Läs:** [[Konton och åtkomst]] § user, `app/Http/Controllers/DashboardController.php` (`timezoneFor()`), `app/Models/ScheduleOccurrence.php` (`scopeTodoFor`), `app/Actions/Schedule/ListTodo.php` (docblocken)
**Klart när:** `User::preferredTimezone()` ger användarens tidszon, annars kontots, annars appens; `User::today()` ger användarens kalenderdatum också när UTC-datumet är ett annat; klockan 01:30 svensk tid ligger en förekomst som förfaller samma dag under *today* på `/tasks` och i `/api/todo`; en förekomst från dagen innan är `overdue` i båda förekomstformerna; en förekomst med `visible_from` samma dag syns; en användare i `America/New_York` får sitt eget datum; mitt på dagen är utfallet detsamma som i dag; dashboardens månad räknas som förut; hela testsviten är grön.
**Beror på:** -

### 136. Valvet vet att idag är användarens idag
Issue 135 gjorde todo-urvalet, grupperingen och `overdue` på förekomsterna till användarens kalenderdatum, `User::today()`. [[Scheman och uppgifter]] § schedule_occurrence och § Todo-listan skriver fortfarande `CURDATE()`, och docblocken i `ItemStatus` påstår att itemträdet och `ScheduleOccurrenceResource` bär samma formel. Valvet ska säga att det finns **två** datum: användarens i todo-listan och förekomstformerna, och serverns i `ItemStatus`, `OpenNextOccurrence`, notisjobben, ICS och utlåningen. Beslutet i [[ADR-0005 Schema och förekomst]] står fast, eftersom `overdue` fortfarande härleds. Ändringen skrivs därför som en daterad uppföljning i ADR:en och inte som en ny ADR. Beteendet i `ItemStatus` ändras inte. Om itemträdet ska följa användarens datum är en egen fråga.

**Läs:** [[Scheman och uppgifter]] § schedule_occurrence och § Todo-listan, [[ADR-0005 Schema och förekomst]], `app/Models/User.php` (`preferredTimezone`, `today`), `app/Support/Item/ItemStatus.php` (docblocken), `tests/Feature/Dokumentation/DatamodellTest.php`
**Klart när:** § Todo-listan jämför `visible_from` mot användarens idag och nämner `User::today()`; § schedule_occurrence definierar `overdue` mot båda datumen med vägarna som använder respektive datum; ingen fil under `docs/Datamodell/` bär `CURDATE()`; ADR-0005 har `## Uppföljning 2026-09-25 — idag är användarens idag` sist och § Konsekvenser är oförändrad; ingen ny ADR; docblocken i `ItemStatus` stämmer och bara kommentarsrader är ändrade; ett prov under `tests/Feature/Dokumentation/` faller om `CURDATE()` kommer tillbaka i datamodellen eller uppföljningen försvinner; hela testsviten är grön.
**Beror på:** 135
