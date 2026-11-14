# M59 · Profilbilden

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-14, efter Tonys beslut att användaren ska kunna ladda upp en profilbild. Den visas i avataren i toppraden, som i dag visar initialerna och leder till `/settings`. Bilden ska senare också kunna användas vid inbjudningar och för att visa vem som skapat ett item eller laddat upp en fil. De användningarna ingår inte här, men synlighetsregeln är skriven för dem. Beslutet står i [[ADR-0076 Profilbilden]].

Bilden blir en `attachment` med en nullbar `user_id`, på samma sätt som containerns bild ([[ADR-0047 Containerns bild]]), så att den får dedup, referensräkning, miniatyrer, virusskanning, kvot och leverans utan egen mekanik. Beskärningen är icke-destruktiv: en fokuspunkt och en zoom, ritade med CSS.

**Releasen:** en, när 379–382 är mergade. Issuerna skrevs direkt i GitHub (#1227, #1228, #1229, #1230) och är sammanfattade här.

---

### 379. En bilaga kan tillhöra en person

GitHub #1227. **Läget:** `attachment` kan höra till ett item eller en container, aldrig en person, och leveransens grind vet inte vem som får se en persons bild.

**Lösningen:** `attachment.user_id` (FK → `user`, `RESTRICT`, index `(user_id, deleted_at)`) och `user.avatar_attachment_id`. CHECK-villkoret kräver exakt en av `item_id`, `container_id` och `user_id`, på MariaDB och SQLite. `accessSubject()` returnerar `User` för en personbilaga och `owningContainer()` `null`. `UserPolicy::viewAvatar` släpper in personen själv, den som delar ett konto med henne och den som når en gemensam container, och leveransen frågar den. Papperskorgen, lagringsvyn, exporten, kontoraderingens flytt, `MoveAttachment` och `CopyAttachment` klarar den nya ägaren.

**Läs:** [[ADR-0076 Profilbilden]], [[ADR-0047 Containerns bild]] § Beslut och § Konsekvenser, [[Filer och lagring]] § attachment

**Klart när:** `attachment.user_id` och `user.avatar_attachment_id` finns och CHECK-villkoret kräver exakt en av tre ägare; `accessSubject()` returnerar `User` och `owningContainer()` `null`; leveransen ger 200 till personen, en kontokamrat och en medlem i en gemensam container och 403 till en främling; ingen läsare listar, exporterar, flyttar eller kopierar en personbilaga; sviten är grön.

**Beror på:** docs-issuen #1226, som skriver in beslutet

### 380. Ladda upp och ta bort profilbilden

GitHub #1228. **Läget:** datamodellen och grinden finns sedan 379, men ingen väg skriver bilden.

**Lösningen:** `SetUserAvatar` och `RemoveUserAvatar` i samma form som containerns, och ett byte rensar den gamla bilden med `PurgeAttachment`. Kontot som betalar är personens personkonto, och styckstorleken och totalkvoten prövas före lagringen; en person utan personkonto nekas med `avatar.no_personal_account`. `POST` och `DELETE /settings/profile/avatar` på webben och `POST` och `DELETE /api/me/avatar` i API:et. `DeleteUser` rensar bilden först. `AuthUserResource` får `avatar` med `ulid` och `has_thumb`.

**Läs:** [[ADR-0076 Profilbilden]], förlagan `SetContainerCover` och `ContainerCoverController`, `DeleteUser`

**Klart när:** ett byte rensar den gamla bilden; bytena belastar personkontot och prövas mot dess kvot före lagringen; webb- och API-rutterna svarar som issuen anger; `DeleteUser` klarar en person med profilbild; `AuthUserResource` bär `avatar`; sviten är grön.

**Beror på:** 379

### 381. Profilbilden på inställningssidan och i toppraden

GitHub #1229. **Läget:** personen har ingenstans att välja bilden, och avataren i toppraden ritar bara initialer.

**Lösningen:** ett avsnitt *Profile picture* på `/settings/profile` med *Upload* och *Remove*, som visar status och fel. Avataren i `AppLayout.vue` ritar miniatyren (`?variant=thumb`) i samma cirkel, och initialerna när bild saknas, miniatyr saknas eller bilden inte går att ladda. Mobilmenyn följer samma regel.

**Läs:** [[ADR-0076 Profilbilden]] § 6, `AppLayout.vue`, `Settings/Profile.vue`, `ContainerCoverSheet.vue`

**Klart när:** *Profile picture* laddar upp, byter och tar bort bilden och visar fel och status; avataren i toppraden visar miniatyren när den finns och initialerna annars, också när bilden inte går att ladda; länken till `/settings` och dess tillgängliga namn är oförändrade; sviten är grön.

**Beror på:** 380

### 382. Beskär profilbilden och välj fokuspunkt

GitHub #1230. **Läget:** bilden ritas alltid i mitten, vilket skär bort fel del av en porträttbild.

**Lösningen:** `user.avatar_focus_x`, `avatar_focus_y` och `avatar_zoom`, där paret sätts tillsammans. `SetUserAvatarCrop` och `PATCH /settings/profile/avatar/crop` och `/api/me/avatar/crop`; byte och borttagning av bilden nollställer utsnittet. Den nya komponenten `UserAvatar.vue` och räkningen i `useAvatarCrop.js` ritar utsnittet utan tom kant. Redigeraren i `Settings/Profile.vue` flyttar punkten med drag och piltangenter, zoomar med ett reglage och sparar, återställer eller avbryter. Ingen ny fil skapas.

**Läs:** [[ADR-0076 Profilbilden]] § 6 och 7, [[ADR-0047 Containerns bild]], `container.cover_focus_x/y` i [[Konton och åtkomst]]

**Klart när:** `user` har de tre kolumnerna med CHECK-villkoret för paret; webb- och API-rutten sparar utsnittet och byte eller borttagning nollställer det; `UserAvatar` ritar utsnittet utan tom kant och initialerna som reserv; redigeraren flyttar, zoomar, sparar, återställer och avbryter; ingen ny fil skapas när utsnittet ändras; sviten är grön.

**Beror på:** 381
