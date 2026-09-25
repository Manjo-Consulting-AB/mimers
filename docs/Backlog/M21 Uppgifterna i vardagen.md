# M21 · Uppgifterna i vardagen

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

Tillagd 2026-09-25, efter Tonys test på staging. Ett dagligt schema bockades av, morgondagens förekomst dök upp i listan, bockades också av — och låg sedan kvar på *Tomorrow* medan historiken fick en rad till. Varje tryck gav en ny förekomst med samma förfallodag. Det är en bugg i hur nästa förfall räknas (132). De två andra issuerna kom ur samma genomgång: raden ska visa redan på knappen om uppgiften är försenad eller ligger framåt i tiden (133), och användaren ska kunna välja bort de framtida (134).

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
