# ADR-0005 Schema och förekomst

**Status:** Antagen 2026-08-03 · [[ADR-index]]

## Kontext

Ursprungstanken var **en timer per item**, och att en task är ett item med en flagga: sätts flaggan anges datum, hur ofta det ska göras och hur lång tid man har på sig innan det blir förfallet.

Två problem med det.

En livflotte har service vart tredje år, ett certifikat som går ut på ett bestämt datum, och behöver förvarning före båda — två oberoende scheman på samma item. En motor har oljebyte, impellerbyte och remspänning. Med en timer per item tvingas man skapa separata items för varje underhållsmoment, och artikellistan fylls av saker som inte är prylar.

Dessutom går det inte att uttrycka skillnaden mellan "försäkringen förnyas 1 januari oavsett när jag betalade" och "oljebyte tolv månader efter förra bytet" med ett enda nästa-datum-fält.

## Beslut

Ett item har **noll eller flera scheman**. Flaggan på item försvinner.

Ett schema genererar **förekomster**. Endast den öppna förekomsten plus historiken lagras; nästa skapas i samma transaktion som den nuvarande stängs.

Två återkommandetyper: `fixed` räknar från kalendern, `interval` räknar från senast utfört.

Varje förekomst har `visible_from` och `due_at` — samma defer/due-modell som OmniFocus, där glappet är den tid man har på sig.

## Motivering

Loggen över utförda jobb **är** de avklarade förekomsterna. Utan uppdelningen behövs en separat historiktabell ändå.

Beroenden mellan återkommande uppgifter blir obegripliga på schemanivå: om B beror på A och båda återkommer årligen — vilken A väntar B på? Med förekomster är svaret självklart.

Att inte generera serier i förväg slipper frågan om hur långt in i framtiden man ska generera, där varje svar är fel.

## Konsekvenser

- Todo-listan läser **förekomster**, aldrig items. Se [[Scheman och uppgifter]].
- `overdue` lagras aldrig som status utan härleds från `due_at < CURDATE()`. Ett lagrat tillstånd som klockan ändrar kräver ett jobb som förr eller senare missar en körning.
- Beroenden kräver cykelkontroll, både på schema- och förekomstnivå.
- Systemet är starkt säsongsbetonat — i april förfaller allt samtidigt. Därför är veckosammanfattning standard i [[Notiser]].

## Alternativ

**Task som flagga på item, en timer per item.** Ursprungsförslaget. Valdes bort av skälen ovan.

**Egen task-entitet skild från item.** Valdes bort — uppgiften hör ihop med saken, och kopplingen item → schema uttrycker det utan en tredje entitet.

## Uppföljning 2026-09-25 — vilken dag

`overdue` härleds fortfarande och lagras aldrig. Men *idag* i formeln ovan är inte längre serverns `CURDATE()`: det är användarens kalenderdag. Se [[ADR-0044 Användarens dag]].
