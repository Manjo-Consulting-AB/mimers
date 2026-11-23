# ADR-0093 Ägarbytets bonus till nya kunder

**Status:** Antagen 2026-11-22 · Ersätter § 7 i [[ADR-0086 Pro, Pro+ och grundarpris]] och § 4 i [[ADR-0017 Missbruksvektorer]] · [[ADR-index]]

Tonys beslut 2026-11-22, efter en genomgång av vad ett ägarbyte gör.

## Kontext

- Bonusen gav tolv månader Pro till varje mottagare: den förlängde en befintlig Pro- eller Pro+-plan och gav Pro till ett Free-konto. Varken en betalande kund eller en Free-användare behöver övertygas, eftersom båda redan känner systemet. Bonusen är en marknadsföringskostnad och ska bara betalas för användare vi inte redan har.
- Med den gamla regeln öppnades en kedja: ett betalt år på konto A, sedan ett ägarbyte till en ny adress B som får Pro, B skickar vidare till C innan året är slut, och så vidare. Ett betalt år räckte för Pro för alltid.

## Beslut

**1. Bara nya kunder får bonusen.** Mottagaren får tolv månader Pro bara om ingen hade ett konto med adressen när ägarbytet skickades (`to_account_id` är `NULL`). Det avgörs i det ögonblick säljaren skickar och kan inte påverkas genom att registrera sig vid rätt tidpunkt. Ett befintligt konto, med Free, Pro eller Pro+, får ingenting: ingen bonus och ingen förlängning.

**2. Bara betald Pro ger bonus.** Avsändarens plan måste komma från en betalning (`subscription.source = 'paid'`). Pro genom en tidigare bonus, en voucher eller internt Pro kan lämna över containrar, men utan bonus. Det bryter kedjan: varje bonus har kostat minst ett betalt år hos någon.

**3. Högst två bonusar per avsändare på tolv månader.** Väntande ägarbyten med bonus och de som accepterats de senaste tolv månaderna räknas. Återkallade, avvisade och utgångna frigör platsen. Ägarbyten utöver taket går igenom utan bonus. Det är rullande tolv månader, inte kalenderår, så att man inte kan skicka två i december och två till i januari.

**4. Beslutet fattas vid erbjudandet och sparas på raden** (`ownership_transfer.grants_bonus`), så att båda parter vet före accepten vad som gäller. Vid accepten ges bonusen bara om mottagarkontot fortfarande saknar en betald plan, till exempel om hen registrerade sig med en Pro-kod, och om spärren en bonus per konto inte redan är förbrukad.

**5. Kvoten för en mottagare som får bonusen prövas mot Pro.** Alla andra prövas som förut mot sin nuvarande plan. Skälet till den gamla ordningen, att en bonus per konto gör en Pro-prövning orättvis mot ett andra ägarbyte, gäller inte ett konto som per definition aldrig fått någon. En Free-användare som redan har en container får uppgradera för att ta emot en pärm. Det är en säljväg, inte ett brutet löfte.

**6. Mät i stället för att spärra hårdare.** Den nattliga rapporten får utdelade bonusar per avsändare, i linje med [[ADR-0017 Missbruksvektorer]].

**7. B2B** tar vi inte höjd för. Det finns inga B2B-planer. När de kommer bör de ha inget tak, eftersom ett varv eller en mäklare är just den avsändare bonusen är till för.

## Konsekvenser

- **Ingen bonus ges innan betalningen är kopplad.** Det finns ingen `paid`-rad förrän dess. Under den slutna betan spelar det ingen roll.
- **Känd begränsning under betan:** ett väntande ägarbyte släpper inte in registreringen i läget `invite_only` ([[ADR-0055 Inbjudningskoder och stängd registrering]]), till skillnad från en containerinbjudan. Det är medvetet och ska inte rättas: när registreringen öppnas finns betalningen, och då behövs ingen särskild väg in.
- **Missbruk som finns kvar och bärs medvetet:** en Pro-användare kan ge två egna adresser ett gratis år per betalt år. Det är i praktiken en gåva, vilket är det marknadsföringen vill uppnå.
- **Ny kolumn `subscription.source`** (`paid` \| `transfer_bonus` \| `voucher` \| `internal`), och två nya kolumner på `ownership_transfer`, `grants_bonus` och `bonus_granted_at`. Se [[Planer och kvoter]] och [[Konton och åtkomst]].

## Alternativ

- **Krav på innehåll i containern.** Lätt att fylla med skräp.
- **Gränser för kontots ålder eller jämförelse av adressernas domän.** Slår mot ärliga användare och går lätt att runda.
- **Betalkortets fingeravtryck.** Det äger merchant of record, inte vi.
