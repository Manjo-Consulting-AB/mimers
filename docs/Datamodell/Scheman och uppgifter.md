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
| visible_from | DATE | `due_at` minus `lead_days`. Innan detta syns uppgiften inte i todo-listan. |
| due_at | DATE | |
| status | VARCHAR(20) | `open` \| `completed` \| `skipped` |
| completed_at | TIMESTAMP NULL | |
| completed_by_user_id | FK NULL | |
| completed_by_account_id | FK NULL | Varvet, inte den anställde |
| completion_note | TEXT NULL | "Bytte även termostaten" |

Index: `(schedule_id, status)`, `(due_at, status)` för todo-listan över alla containers.

**Förfallen** (`overdue`) är inte en status utan härleds: `status = 'open' AND due_at < idag`, där `idag` är **användarens kalenderdag** — `User::today()` — och inte serverns. Se [[ADR-0044 Användarens dag]] § Beslut 1. Lagra aldrig ett tillstånd som klockan kan ändra åt dig — då måste ett jobb hålla det uppdaterat, och det jobbet kommer att missa körningar.

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
status = 'open'
AND visible_from <= användarens idag (`User::today()`)
AND containern är åtkomlig för användaren
AND inga öppna beroenden
ORDER BY due_at
```

`idag` är användarens kalenderdag och inte serverns; se [[ADR-0044 Användarens dag]] § Beslut 1.

Systemet är kraftigt säsongsbetonat — i april förfaller allting samtidigt. Det påverkar notisstrategin, se veckosammanfattningen i [[Notiser]].
