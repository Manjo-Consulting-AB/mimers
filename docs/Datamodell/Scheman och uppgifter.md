# Scheman och uppgifter

Återkommande underhåll. Läs [[Datamodell – översikt]] först.

Beslut bakom detta: [[ADR-0005 Schema och förekomst]].

## Grundprincipen

**Schemat är regeln. Förekomsten är den enskilda gången.** Ett schema säger "var tolfte månad". En förekomst är "den som förfaller 5 maj 2027, ännu inte utförd".

Endast den **öppna** förekomsten plus **historiken** lagras. Nästa skapas i samma transaktion som den nuvarande stängs. Ingen serie genereras i förväg — annars måste någon bestämma hur långt in i framtiden, och svaret är alltid fel.

## schedule

Noll eller flera per item. En livflotte har både treårig service och ett certifikat som går ut; en motor har oljebyte, impeller och remspänning.

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| item_id | FK | |
| title | VARCHAR(255) | "Byt impeller" |
| notes | TEXT NULL | |
| recurrence_type | VARCHAR(20) | `none` \| `fixed` \| `interval` — se nedan |
| interval_unit | VARCHAR(10) NULL | `day` \| `week` \| `month` \| `year` |
| interval_count | SMALLINT UNSIGNED NULL | |
| anchor_date | DATE NULL | Startpunkt för `fixed` |
| lead_days | SMALLINT UNSIGNED | Hur många dagar innan förfall uppgiften dyker upp. Motsvarar OmniFocus defer. |
| is_active | BOOLEAN | Pausad utan att raderas |
| default_gtd_list | VARCHAR(10) NULL | `next` \| `someday` — förvalet för en ny förekomst. Null tills det lärs in, se [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] § 2 |
| deleted_at | | |

Index: `(item_id, deleted_at)`.

### De två återkommandetyperna

Skillnaden är inte kosmetisk och kan inte uttryckas med ett enda nästa-datum-fält:

| Typ | Nästa förfall räknas från | Exempel |
|---|---|---|
| `fixed` | kalendern, oavsett när jobbet gjordes | Försäkringen förnyas 1 januari. Betalar du för sent är nästa ändå 1 januari. |
| `interval` | **senast utfört** | Oljebyte tolv månader efter förra bytet, inte efter kalendern. |

`none` är en engångsuppgift som försvinner när den stängs.

## schedule_occurrence

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| schedule_id | FK | |
| visible_from | DATE NULL | `due_at` minus `lead_days`. Innan detta syns uppgiften inte i todo-listan. Null när `due_at` är null. |
| due_at | DATE NULL | Null för en uppgift utan datum. Se [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] § 3 |
| status | VARCHAR(20) | `open` \| `in_progress` \| `completed` \| `skipped` |
| gtd_list | VARCHAR(10) | `inbox` \| `next` \| `waiting` \| `someday`. Förval `next`. Se [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] § 1 |
| completed_at | TIMESTAMP NULL | |
| completed_by_user_id | FK NULL | |
| completed_by_account_id | FK NULL | Varvet, inte den anställde |
| completion_note | TEXT NULL | "Bytte även termostaten" |

Index: `(schedule_id, status)`, `(due_at, status)` för todo-listan över alla containers.

**Aktiv** är en förekomst som inte är stängd: `status` är `open` eller `in_progress`. Villkoret formuleras en gång, som `ScheduleOccurrence::scopeActive()`, och det är frågan koden ställer överallt där den menar *inte stängd* — todo-urvalet, itemets status, notiserna, ICS-flödet, beroendena och avbockningens kontroll. Se [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] § 1.

**En uppgift utan datum.** `due_at` och `visible_from` får vara null. Har en förekomst inget `due_at` är `visible_from` också null, och tas datumet bort försvinner glappet med det — `lead_days` ignoreras utan ett förfall. **Ett återkommande schema måste ha ett datum:** `recurrence_type` `fixed` och `interval` kräver `anchor_date`, och deras förekomster får alltid ett `due_at`. Bara `none` får sakna datum — utan ett första datum finns ingen serie att räkna nästa förfall ur. En förekomst utan datum syns inte i *Overdue*, *Today*, *This week* eller *Upcoming*, ger ingen notis, skrivs inte till ICS-flödet, gör aldrig ett item försenat och exporteras med tomma datum. Se [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] § 3.

**Förfallen** (`overdue`) är inte en status utan härleds: **aktiv** och `due_at < idag`, där `idag` är **användarens kalenderdag** — `User::today()` — och inte serverns. Se [[ADR-0044 Användarens dag]] § Beslut 1. Lagra aldrig ett tillstånd som klockan kan ändra åt dig — då måste ett jobb hålla det uppdaterat, och det jobbet kommer att missa körningar.

**Historiken är loggen.** Avklarade förekomster är svaret på "när bytte jag impellern senast" — ingen separat historiktabell behövs.

## occurrence_dependency

En uppgift som inte kan stängas innan en annan är klar. Beroendet skrivs mellan **förekomster**, inte mellan scheman — annars går det inte att avgöra vilken omgång av det årligen återkommande jobbet som väntar på vilken.

| Kolumn | Typ |
|---|---|
| occurrence_id, depends_on_occurrence_id | FK, UNIQUE tillsammans |

När en ny förekomst skapas ärver den beroenden från sitt schema-par: om schema B beror på schema A, kopplas B:s nya förekomst till A:s nuvarande öppna förekomst. Beroendet mellan scheman behöver därför lagras också — lägg det som en rad i samma tabellstruktur fast på schemanivå, eller en separat `schedule_dependency`. Implementatören väljer, men **cykelkontroll krävs i båda fallen**: A får inte bero på B som beror på A, varken direkt eller via mellanled.

## Flödet när en uppgift markeras klar

I en transaktion:

1. Kontrollera att inga öppna beroenden finns. Finns de, neka med felkod.
2. Sätt `status = 'completed'`, `completed_at`, `completed_by_*`, ev. anteckning.
3. Om `recurrence_type != 'none'`, beräkna nästa `due_at`. Det ligger alltid **strikt efter** den stängda förekomstens:
   - `fixed`: första datumet i serien från `anchor_date` som ligger både i dag eller senare och efter den stängda förekomstens `due_at`. En förekomst avbockad på sin egen förfallodag får alltså morgondagen, inte samma dag igen.
   - `interval`: `completed_at` plus intervallet. Ligger det på eller före den stängda förekomstens `due_at` stegas det fram med intervallet tills det ligger efter — en daglig uppgift avbockad i förtid hoppar förbi sitt eget förfall, medan ett oljebyte gjort i förväg fortfarande räknas från bytet. Vid `skip` räknas i stället från den överhoppade förekomstens `due_at`, och regeln är redan uppfylld.
4. Skapa den nya förekomsten med `visible_from = due_at - lead_days`.
5. Avbryt eventuella oskickade notiser som hörde till den stängda förekomsten.

## Todo-listan

Läser **förekomster**, aldrig items:

```
aktiv (status = 'open' eller 'in_progress')
AND visible_from <= användarens idag (`User::today()`)
AND containern är åtkomlig för användaren
AND inga öppna beroenden
ORDER BY due_at
```

`idag` är användarens kalenderdag och inte serverns; se [[ADR-0044 Användarens dag]] § Beslut 1.

Systemet är kraftigt säsongsbetonat — i april förfaller allting samtidigt. Det påverkar notisstrategin, se veckosammanfattningen i [[Notiser]].

## API:et

`/api` bär samma skrivningar som webben, genom samma actions (M26 · issue 238).

| Skrivning | Rutt och kropp |
|---|---|
| Byta listan eller statusen på en aktiv förekomst | `PATCH /api/containers/{container}/items/{item}/schedules/{schedule}/occurrences/{occurrence}` — `{"gtd_list"?, "status"?}`, minst ett av fälten. `status` tar bara `open` och `in_progress`: att stänga går genom `complete` och `skip`, och en stängd förekomst svarar 422 `occurrence.not_open` |
| Sätta listan för den första förekomsten | `gtd_list` i kroppen till `POST .../schedules` — förekomsten hamnar i listan, och `next`/`someday` blir dessutom schemats förval |
| Sätta schemats förval | `default_gtd_list` i kroppen till `PATCH .../schedules/{schedule}` — `next`, `someday` eller `null` |

`gtd_list` och `default_gtd_list` är två egenskaper ([[ADR-0052 Uppgifternas listor och uppgifter utan datum]] § 1 och § 2): den första säger hur användaren tänker hantera uppgiften, den andra är regeln för nästa förekomst.

`due_at` och `visible_from` är nullbara i svaret: en uppgift utan datum svarar `null`, aldrig ett påhittat datum ([[ADR-0052 Uppgifternas listor och uppgifter utan datum]] § 3). Ett återkommande schema kräver fortfarande ett datum — bara `none` får sakna det.
