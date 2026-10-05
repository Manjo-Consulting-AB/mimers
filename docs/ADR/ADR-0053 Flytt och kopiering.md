# ADR-0053 Flytt och kopiering

**Status:** Antagen 2026-10-05 · Preciserar `attachment.billed_account_id` i [[Filer och lagring]] och [[Planer och kvoter]] · Förutsättning för inbox-ADR:n · [[ADR-index]]

Tonys beslut 2026-10-05, vid genomgången av uppgifterna på staging (v0.20.0) och idén om en personlig inbox.

## Kontext

En bilaga eller en uppgift sitter fast på det item den skapades på. Ingen kod kan flytta en `attachment` eller ett `schedule` till ett annat item. Den som laddat upp ett kvitto på fel item får ta bort det och ladda upp det igen, och historiken för en uppgift går förlorad om den skapas om.

Inboxen gör flytten nödvändig. Det som fångas utan hemvist ska senare flyttas till ett riktigt item, ofta i en annan container och ofta i ett annat konto. Flytten behövs alltså i hela systemet, och inboxen är bara en av dem som använder den.

Två regler finns redan och står kvar. **Kvoten mäter den logiska storleken per bilaga**: summan av `byte_size` för kontots levande bilagor ([[Filer och lagring]] § Kvot kontra faktisk lagring), oavsett att samma fil bara lagras en gång på disken. **`billed_account_id` bestäms av den som skapar bilagan**, inte av vem som äger containern.

## Beslut

### 1. Vad som kan flyttas och kopieras

| | Flytta | Kopiera |
|---|---|---|
| Bilaga på ett item | ja | ja |
| Uppgift (`schedule` med sina förekomster) | ja | nej |
| Containerns bild | nej | nej |

**En uppgift kopieras inte.** En kopia med historik blir två sanningar om samma utförda arbete. Den som vill ha en likadan uppgift skapar en ny.

Målet är alltid ett item, i samma container eller i en annan. En mjukraderad bilaga eller uppgift kan inte flyttas eller kopieras, och inte heller något till ett mjukraderat item.

### 2. Behörighet

- **Flytt:** `delete` på källans item och `create` på målets item. En flytt tar bort något från källan, och då räcker inte `update` där.
- **Kopiering:** `view` på källans item och `create` på målets item.

Grindarna är de befintliga `ItemPolicy`-metoderna. Ingen ny behörighetslogik införs.

### 3. Bilagans ägarkonto

**`billed_account_id` är bilagans ägarkonto, och ägaren står för lagringen.** Ägaren bestäms när bilagan skapas där den ligger, och den blir alltid **målcontainerns ägarkonto när användaren är medlem i det, annars användarens eget konto**. Det kallas nedan *handlingens konto*.

- **En uppladdning** skapar bilagan. Ägare blir handlingens konto.
- **En kopia** skapar en ny bilaga. Ägare blir handlingens konto. Den nya bilagan belastar ägarens kvot med hela sin logiska storlek, även inom samma konto och fast filen bara finns en gång på disken.
- **En flytt till en annan container** skapar bilagan på nytt där. Ägare blir handlingens konto.
- **En flytt inom samma container ändrar aldrig ägaren.**

**Handlingens konto** räknas med samma regel som avbockningens konto ([[Scheman och uppgifter]], 63b § Beslut 4), med målcontainern som utgångspunkt. Det är alltså inte alltid användarens eget konto: en anställd som flyttar en fil till företagets container, där hon är medlem, gör företaget till ägare. Förfrågan bär kontot som `account`, och servern prövar medlemskapet, precis som vid uppladdning.

**Ägarskapet följer handlingen, inte åtkomsten.** Flyttar B en fil från A:s container till en container i B:s konto blir B:s konto ägare. Flyttar B den sedan tillbaka till A:s container är handlingens konto fortfarande B:s, eftersom B inte är medlem i A:s konto, och ägaren ändras därför inte. Ägaren går aldrig tillbaka till A bara för att A åter når filen.

### 4. Kvoten vid flytt och kopiering

**Byter ägaren prövas den nya ägarens kvot**, med `Entitlements::assertStorageWithinLimit()`. Räcker den inte nekas handlingen med `quota.storage_exceeded`, och bilagan ligger kvar på källan oförändrad. Godkänns den flyttas bytena mellan kontonas `usage_counter` i samma transaktion: minus hos den gamla ägaren och plus hos den nya.

**Byter ägaren inte**, alltså vid en flytt inom samma container eller till ett annat item med samma ägarkonto, prövas ingen kvot och ingen räknare ändras.

**En kopia** prövar alltid ägarens kvot, ökar `stored_file.reference_count` med ett och lägger på bytena i ägarens `usage_counter`.

### 5. Bilagan

- `item_id` byts. Raden är densamma vid en flytt och ny vid en kopia. ULID:n är kvar vid en flytt, så en länk till filen fungerar fortfarande.
- `uploaded_by_user_id` står kvar vid en flytt. Vid en kopia blir det den som kopierade, eftersom det är hon som skapade bilagan.
- Är bilagan källitemets omslag (`item.cover_attachment_id`) töms pekaren vid en flytt. Itemets omslag faller då tillbaka på regeln i `ResolveItemCover`.
- *Senast öppnade* (`attachment_open`) står kvar. Raderna filtreras vid läsning ([[ADR-0051 Senast öppnade filer]]), så den som inte når filens nya plats ser den inte längre där.

### 6. Uppgiften

- `schedule.item_id` byts. Förekomsterna följer med, både den öppna och historiken, eftersom de hör till uppgiften och inte till platsen.
- **Beroenden skrivs bara inom en container.** En uppgift med beroenden, på schema- eller förekomstnivå och i någon riktning, kan flyttas inom containern men inte till en annan. Det nekas med `schedule.has_dependencies` (422). Användaren tar bort beroendena först.
- **Kostnadsrader som avbockningen skapat stannar på sitt item.** De bokfördes där.
- **Oskickade notiser för den öppna förekomsten avbryts och dess deduplikationsnycklar frigörs**, som när förfallet flyttas (issue 222). Nästa körning skapar notiserna på nytt för dem som når uppgiftens nya plats.
- `completed_by_account_id` på avklarade förekomster ändras inte. Det var det kontot som utförde arbetet.

### 7. Händelseloggen

Varje flytt och kopiering skriver en rad i målcontainerns logg, och en flytt mellan containrar skriver också en rad i källcontainerns logg. Typerna är `attachment.moved`, `attachment.copied` och `schedule.moved`, med `item_id` satt till itemet i den egna containern.

**En logg nämner aldrig den andra containern.** I källans logg står *moved out of the container* och i målets *moved into the container*, utan namn eller ULID för motparten. Läsregeln i [[ADR-0043 Tre loggar]] gäller per container, och den som läser källans logg ska inte få veta vart något tog vägen om hon inte når dit.

### 8. Ytorna

Rutter för flytt och kopiering av bilagor och för flytt av uppgifter, i webben och i `/api`, en yta per issue. Webben använder samma actions som API:et. Gränssnittet för att välja målitem byggs en gång och återanvänds av inboxen.

## Motivering

**Ägaren står för det den äger.** En regel som binder kostnaden till den som laddade upp gör att en anställd fortsätter betala för en fil hon för länge sedan lämnat över till företaget. En regel som följer åtkomsten flyttar kostnaden varje gång någon delar eller drar tillbaka en åtkomst, och ingen kan förutse vem som betalar. Att låta handlingens konto avgöra ger ett svar som går att läsa ut vid handlingen och som inte ändras i efterhand.

**Den logiska storleken skiljer kundens kvot från vår disk.** Dedupliceringen är vår besparing och inte kundens rabatt. Det gör det också möjligt att senare ge till exempel obegränsade interna kopior som en förmån, utan att lagringsmodellen ändras.

**Flytten kräver `delete` på källan**, eftersom den tar bort något därifrån. En `write`-mottagare som inte får radera ska inte kunna tömma ett item genom att flytta allt.

**Uppgifter kopieras inte**, eftersom historiken är svaret på *när gjordes det senast*. Två uppgifter med samma historik svarar två gånger, och den ena svarar fel.

## Konsekvenser

- [[Filer och lagring]] beskriver `billed_account_id` som bilagans ägarkonto och lägger till flytt och kopiering. [[Planer och kvoter]] får samma precisering.
- Raderingen av ett konto ([[ADR-0045 Radering av konto och person]]) flyttar redan bilagor till containerns ägarkonto. Det är samma sorts ägarbyte och kan använda samma kod för räknarna.
- `LegalHold` påverkar inte flytten. Spärren stoppar gallring och kontoradering, inte en flytt.
- Massflytt (flera bilagor åt gången) ingår inte här. Inboxen kommer att behöva det, och det tas där.
- Att flytta ett item med allt det bär mellan containrar är en annan fråga och ingår inte.

## Alternativ

**Kostnaden följer den som laddade upp.** Det var den hittills underförstådda regeln. Valdes bort, eftersom en fil som lämnats över till någon annan fortsätter belasta den som en gång laddade upp den.

**Kostnaden följer containerns ägare.** Valdes bort. Den bryter mot regeln att den som lägger något i en annans container står för det, och ett varv som laddar upp servicebilder hos en kund hade fyllt kundens kvot.

**Kostnaden byts när den nuvarande ägaren förlorar åtkomsten.** Valdes bort, eftersom ägaren då ändras av händelser som inte har med filen att göra.

**Kopior är gratis inom samma konto, eftersom disken inte växer.** Valdes bort. Kvoten ska mäta det kunden upplever sig lagra, inte vår besparing.
