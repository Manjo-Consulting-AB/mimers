# M8 · Kostnadsregistrering

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

Tillagd 2026-08-04, efter att planeringsfasen avslutats. Se [[ADR-0016 Kostnadsregistrering]]. **Ligger inte i MVP-listan i [[Översikt]] § Avgränsning** — flytta in den dit om den ska med i första släppet.

### 45. Kostnadsrader
`cost_entry` med CRUD. `item_id` obligatorisk, `container_id` denormaliserad från itemet. Belopp i minsta valutaenhet, negativa belopp tillåtna. Inmatning accepterar både komma och punkt som decimaltecken och **avvisar** fler decimaler än valutan tillåter istället för att avrunda tyst. Leverantör trimmas vid sparning. Autocomplete-endpoint som ger distinkta leverantörer inom containern, sorterade på användningsfrekvens.
**Byggd som:** 45a tabellen, beloppstolkningen och CRUD-ytan, 45b leverantörsytan (autocomplete)
**Läs:** [[Items och organisation]] § cost_entry, [[ADR-0016 Kostnadsregistrering]]
**Klart när:** en kostnad inte kan skapas utan item; ett soft-raderat item tar med sig sina kostnader till papperskorgen och tillbaka vid återställning; `read_only`-konto nekas skrivning.
**Beror på:** 13

### 46. Kostnadsrapport
Gruppering per kategori inklusive underkategorier, per tagg med flera kombinerade med OCH, per item, per leverantör och per tidsperiod på `incurred_on`. Summering **per valuta**, ingen omräkning. Avrundning först vid presentation.
**Läs:** [[Items och organisation]] § Kostnadsrapporter, [[Planer och kvoter]] § Kontrollpunkter
**Klart när:** ett gratiskonto nekas rapportendpointen med felkod men kan fortfarande skapa och läsa sina egna kostnadsrader, och ett test visar att rapporten aldrig summerar rader från containers användaren saknar åtkomst till.
**Beror på:** 45, 27

### 47. Kostnadskrok vid avbockad uppgift
När en förekomst stängs erbjuder svaret att registrera en kostnad på förekomstens item, med `incurred_on` förifyllt till `completed_at`. **Ingen relation lagras** mellan kostnad och förekomst.
**Läs:** [[Items och organisation]] § cost_entry, [[Scheman och uppgifter]] § Flödet när en uppgift markeras klar
**Klart när:** kostnaden hamnar på itemet och inte på förekomsten, och avbockningen fungerar oförändrat om användaren hoppar över kostnaden.
**Beror på:** 45, 22
