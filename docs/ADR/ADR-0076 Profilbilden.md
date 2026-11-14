# ADR-0076 Profilbilden

**Status:** Antagen 2026-11-14 · Bygger vidare på [[ADR-0047 Containerns bild]], [[ADR-0019 Filleverans]] och [[ADR-0045 Radering av konto och person]] · [[ADR-index]]

Tonys beslut 2026-11-14, vid planeringen av [[M59 Profilbilden]].

## Kontext

Användaren ska kunna ladda upp en profilbild som visas i avataren i toppraden, den som i dag visar initialerna och leder till `/settings`. Bilden ska senare också kunna användas vid inbjudningar och för att visa vem som skapat ett item eller laddat upp en fil. De användningarna ingår inte i M59, men synlighetsregeln i Beslut 4 är skriven för dem.

Avataren visar initialer eftersom datamodellen saknar en bild (`AppLayout.vue`, issue 169). Det är samma fråga som [[ADR-0047 Containerns bild]] svarade på för containern: allt som gör en fil säker och billig, alltså dedup, referensräkning, miniatyrer, virusskanning, kvot och leverans, hänger på `attachment`.

## Beslut

**1. En bilaga kan tillhöra en person.** `attachment` får en nullbar `user_id` (FK → `user`, `ON DELETE RESTRICT`). CHECK-villkoret vidgas från *exakt en av `item_id` och `container_id`* till *exakt en av `item_id`, `container_id` och `user_id`*. Indexet blir `(user_id, deleted_at)`. `user.avatar_attachment_id` är en nullbar pekare till en bilaga vars `user_id` är personens egen. Att bilagan är *egen* kan ett CHECK-villkor inte uttrycka, så det upprätthålls i den action som sätter pekaren, som för containern.

**2. Alltid en bild, högst en levande per person.** Bara det som `StoreAttachment` klassar som `kind = image` godtas. Den som byter bild ersätter den gamla, och den gamla rensas direkt med `PurgeAttachment`, inte genom papperskorgen ([[ADR-0008 Soft delete och papperskorg]] § Uppföljning 2026-09-26). Dedup, miniatyrer, virusskanning och storleksgräns är desamma som för andra bilagor.

**3. Kvoten.** `billed_account_id` är personens personkonto (`account.type = 'personal'`, samma uppslag som `ActingAccount`), och bytena räknas mot dess kvot ([[Planer och kvoter]]). En person utan personkonto har ingen kvot att belasta och kan inte ladda upp en profilbild. Hon ser initialerna och en förklaring.

**4. Synligheten.** Bilden får ses av personen själv och av **den som delar minst ett konto eller en container med henne**. Att dela en container betyder att båda når containern, genom kontomedlemskap eller en grant på container- eller itemnivå ([[ADR-0028 Åtkomst på itemnivå]]). Regeln står i en policymetod, `UserPolicy::viewAvatar`, och leveransens grind frågar den när `user_id` är satt. Den ska täcka inbjudningar, uppladdare och skapare utan att ändras. En inbjudan till någon som ännu inte är medlem är en egen fråga för den milstolpe som bygger den.

**5. Livscykeln.** `DeleteUser` rensar bilden med `PurgeAttachment`. Personens bild följer aldrig med en container vid kontoradering eller överlåtelse. Containerexporten tar inte med den, eftersom den hör till personen.

**6. Ytan.** Ett avsnitt *Profile picture* på `/settings/profile` med *Upload* och *Remove*. Avataren i toppraden visar miniatyren (`?variant=thumb`) beskuren till en cirkel med `object-cover`, och initialerna när bild saknas eller inte har laddats. Beskärningen och fokuspunkten står i Beslut 7.

**7. Beskärning och fokuspunkt.** Personen kan beskära bilden och välja vad som ska stå i mitten, så att den passar rutan den visas i. Det görs i en och samma redigerare: hon drar bilden för att placera den och zoomar med ett reglage. Det som sparas är en **fokuspunkt** (`avatar_focus_x`, `avatar_focus_y`, procent 0–100 av originalet, `NULL` = mitten) och en **zoom** (`avatar_zoom`, procent 100–300, `NULL` = 100). Punkten är det som ska stå i rutans mitt, så långt bilden räcker, och zoomen är hur mycket som skärs bort runt den. Samma två värden fungerar i en rund avatar, en kvadrat och en bredare ruta längre fram.

Beskärningen är **icke-destruktiv**: originalet lagras orört, och ytan ritar utsnittet med CSS ur miniatyren. Ingen ny fil skapas, kvoten påverkas inte, och personen kan ändra utsnittet utan att ladda upp bilden igen. Förlagan är containerns fokuspunkt (`container.cover_focus_x/y`, [[ADR-0047 Containerns bild]]): kolumnerna sätts tillsammans (CHECK-villkor för paret), och alla tre nollställs när bilden byts eller tas bort.

## Konsekvenser

- Varje läsare av `attachment` som antar att bilagan har ett item eller en container måste klara `user_id`, bland annat `accessSubject()`, `owningContainer()`, leveransens grind, papperskorgen, lagringsvyn under `/settings/storage`, exporten, kontoraderingens flytt av `billed_account_id`, `MoveAttachment` och `CopyAttachment`.
- Ändringen rör filleverans och är `risk_class: elevated`. Datamodellen, grinden och leveransen är [[M59 Profilbilden]] § 379, skrivvägen § 380, ytan § 381 och beskärningen § 382.
- [[Filer och lagring]] § attachment och [[Konton och åtkomst]] § user är uppdaterade.

## Alternativ

**`user.avatar_stored_file_id`, en pekare direkt till bytena.** Valdes bort av samma skäl som i [[ADR-0047 Containerns bild]]: den ger `reference_count` två källor, och varje väg som räknar referenser, alltså kvoten, exporten och kontoraderingen, skulle behöva en andra.

**Beskärning i klienten före uppladdningen.** Valdes bort: canvas kan inte läsa HEIC i de flesta webbläsare, och ett nytt utsnitt skulle kräva en ny uppladdning, med ny kvotförbrukning.
