# M53 · Uppgiftens sida

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-12, efter Tonys genomgång av uppgiftens sida. Fynden: uppgiftens sida saknade containerns huvud, *Back to the item* ledde till itemets översikt i stället för tillbaka till listan, redigeringen saknade menyn och vägen tillbaka till uppgiften, och beroendena redigerades på visningssidan. Issuerna skrevs direkt i GitHub (#1158, #1159, #1160) och är sammanfattade här.

**Tonys tre beslut:**

1. **Tillbaka dit man kom ifrån.** Uppgiftens sida och redigeringen leder tillbaka till sidan användaren kom från, med itemets *To do*-flik som reserv när ingen giltig returadress finns.
2. **Samma meny på båda sidorna.** Redigeringen får samma meny som visningssidan, med länken *Back to the task* i stället för *Edit*. Finns osparade ändringar i formuläret frågar en bekräftelsedialog innan en länk eller handling i menyn kastar dem.
3. **Beroendena redigeras på *Edit*.** Visningssidan visar dem som en läsbar lista utan knappar, och ritar ingenting när uppgiften saknar beroenden.

**Releasen:** en, när 361–363 är mergade.

---

### 361. Uppgiftens sida får containerns hjälte och leder tillbaka dit man kom ifrån

GitHub #1158. **Läget:** `Schedules/Show.vue` och `Schedules/Edit.vue` saknar containerns huvud, och *Back to the item* leder alltid till itemets översikt, även när man kom från containerns *To do*.

**Lösningen:** båda sidorna ritas med `ContainerLayout` och `hero: 'compact'`. `?return=` prövas med `TaskCreateController::safeReturn()` och ger vyn `returnTo` och `back` (returadressen, annars itemets `?tab=schedules`). Tillbakalänken heter *Back* och går till `back`, och *Edit* bär `return` vidare. `TodoRow` länkar till uppgiften med `?return=` lika med `page.url` genom en ny `showHref`; förekomst- och flyttadresserna får ingen querysträng. Sparning, paus och radering behåller returadressen, och en paus med `return` känns fortfarande igen som paus.

**Läs:** `ScheduleController.php` (`show`, `edit`, `update`, `destroy`), `TaskCreateController.php` (`safeReturn`), `Schedules/Show.vue` och `Edit.vue`, `TodoRow.vue` (`scheduleHref`), `ContainerhjalteTest` § issue 1023

**Klart när:** båda sidorna ritar hjälten; `show()` och `edit()` ger `returnTo` och `back`; tillbakalänken leder till `back`, utan `?return=` till itemets *To do*-flik; `TodoRow` länkar med `?return=` medan förekomst- och flyttadresserna är oförändrade; sparning, paus och radering behåller eller landar på returadressen; en extern eller ogiltig adress faller tillbaka på itemets *To do*-flik.

**Beror på:** —

### 362. Uppgiftens meny på redigeringssidan och vägen tillbaka till uppgiften

GitHub #1159. **Läget:** menyn (*Back*, *Edit*, *Pause*, *Move…*, *Back to Inbox*, *Delete*) står bara på visningssidan. Redigeringen har *Save* och en tillbakalänk, men ingen väg tillbaka till uppgiften.

**Lösningen:** menyn bryts ut till `ScheduleActions.vue` med propen `context` (`'show'` eller `'edit'`). På redigeringen ersätts *Edit* av *Back to the task* (`item.schedule.back_to_task`), som bär `?return=`. `ScheduleForm` meddelar `dirty`, och med osparade ändringar öppnar varje länk och handling `UiConfirmDialog` med `inline_create.discard.*` före handlingen. `edit()` ger samma `can` och `containerIsInbox` som `show()`, ur en gemensam privat metod.

**Läs:** `Schedules/Show.vue` (raden med handlingar), `Schedules/Edit.vue`, `InlineCreate.vue` (frågan vid osparade ändringar), `UiConfirmDialog.vue`, `ScheduleController.php` (`show`, `edit`)

**Klart när:** `Show.vue` ritar `ScheduleActions` utan synlig skillnad; `Edit.vue` ritar den utan *Edit* och med *Back to the task*, som leder till uppgiftens sida med `?return=` när den är satt; `edit()` ger `can` och `containerIsInbox` ur samma kod som `show()`; med osparade ändringar frågar dialogen först, utan dem körs handlingen direkt.

**Beror på:** 361

### 363. Beroendena redigeras på uppgiftens redigeringssida

GitHub #1160. **Läget:** *Always waits for* och *Waiting on this time* redigeras på visningssidan.

**Lösningen:** `ScheduleController` läser beroendena i en gemensam privat metod. `edit()` ger `scheduleDependencies`, `occurrenceDependencies`, `hasOpenOccurrence` och `counterparts`; `show()` ger de tre första men inte `counterparts`. `Edit.vue` ritar de två `ScheduleDependencySection` under formuläret, med radernas normalisering i `scheduleDependencyRows.js`. `Show.vue` ritar dem med `update: false` och en sektion utan rader ritas inte. `ScheduleDependencySection` får `preserveState: true` på `post` och `delete`, så att osparade ändringar och `?return=` står kvar.

**Läs:** `ScheduleController.php` (`show`, `edit`, `scheduleDependencies`, `occurrenceDependencies`, `counterparts`), `Schedules/Show.vue`, `ScheduleDependencySection.vue`, `BeroendevyTest`

**Klart när:** `edit()` och `show()` delar metod och `show()` ger inte `counterparts`; man lägger till och tar bort beroenden på `Edit.vue`; `Show.vue` visar en läsbar lista utan formulär och borttagning, och en tom sektion ritas inte; ett nytt beroende behåller formulärets osparade ändringar och `?return=`.

**Beror på:** 362
