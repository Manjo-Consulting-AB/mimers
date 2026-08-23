# ADR-0003 Åtkomstmodell

**Status:** Antagen 2026-08-03 · [[ADR-index]]

## Kontext

Fyra sorters relationer till en container förekommer i verkligheten:

- Sambon eller delägaren som ska kunna läsa och skriva permanent.
- **Servicevarvet** som lägger in vad de gjort under vintern, år efter år.
- **Nybyggnadsvarvet och mäklaren** som skapar pärmen och lämnar över den.
- **Charterkunden** som ska komma åt manualerna för sin båt under sin vecka.

Den svåra frågan är vem som äger containern i varvsfallet.

## Beslut

Ägaren är **båtägaren**, aldrig varvet. Varvet får en **delegerad åtkomst**.

Fyra åtkomstformer utöver ägarskap, alla i `container_access`:

| Form | Mottagare | Kännetecken |
|---|---|---|
| `member` | användare | Permanent, R eller RW |
| `managed` | **konto** | Servicerelation. Revokerbar, loggad, poster tillskrivs organisationen |
| `guest` | användare | Tidsbegränsad, självdör via `expires_at` |
| (ägare) | konto | Se [[ADR-0002 Konto äger container]] |

Delning med någon som saknar konto sker via inbjudan som ligger `pending` tills den accepteras, avvisas eller löper ut. Mottagaren måste skapa konto och verifiera sin e-post.

## Motivering

Ägde varvet pärmen vore kundens hela båthistorik gisslan i relationen — byter hon varv förlorar hon sina papper. Det gör konsumentprodukten otrovärdig, och konsumenterna är volymen. Varvet skulle förstås gärna se inlåsningen, men det är inte en produkt som ska säljas.

`managed` skiljer sig från vanlig delning på fyra sätt som alla har praktiska skäl: ägaren ser den listad och kan återkalla med ett klick, den loggas, poster tillskrivs organisationen så att de överlever personalomsättning, och bytena belastar varvets kvot.

Att inbjudna måste ha konto är avsiktligt — alla som läser något i systemet ska vara identifierade.

## Konsekvenser

- Varvet kan **skapa** en container åt en kund utan konto och sedan skjuta över ägandet, med kvarhållen åtkomst. Samma flöde som mäklarens överlämning.
- Uppladdningar räknas mot uppladdande konto, inte ägaren. Annars fyller varvet sin kunds gratiskvot. Se [[Planer och kvoter]]. **Regeln hindrar också ett varv från att driva sin verksamhet på gratiskonton — ändra den inte utan att läsa [[ADR-0017 Missbruksvektorer]].**
- När varvsrelationen upphör erbjuds ägaren att ta över filerna — vilket råkar vara en utmärkt konverteringspunkt.
- Charterbolag behöver åtkomst kopplad till **roller**, inte personer, eftersom besättning roterar konstant. Det ligger utanför MVP men modellen är förberedd.

## Alternativ

**Varvet äger, kunden får åtkomst.** Bättre inlåsning för varvet. Valdes bort av skälen ovan.

**Enbart R/RW utan `managed`.** Enklare. Valdes bort — då går det inte att tillskriva poster en organisation, och kvotmodellen fungerar inte.
