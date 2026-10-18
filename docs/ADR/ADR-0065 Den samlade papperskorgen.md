# ADR-0065 Den samlade papperskorgen

**Status:** Antagen 2026-10-18 · Ändrar issue 62b § Beslut 1 och 8: `/trash/containers` leder till `/trash`, och papperskorgen nås ur skalet och inte bara ur containerlistan · Containerns egen papperskorg (issue 62a) står kvar · [[ADR-index]]

Tonys beslut 2026-10-18, efter `docs/Design/findings/trash_20261018_170118.png`.

## Kontext

Papperskorgen finns i dag på två ställen:

- **`/containers/{c}/trash`** (issue 62a) listar raderade items, bilagor, kategorier och taggar i en container.
- **`/trash/containers`** (issue 62b) listar raderade containrar och nås bara genom en länk på `/containers`.

Ingen yta svarar på frågan "vad har jag i papperskorgen?" utan att man först vet vilken container man ska leta i. Skalet har ingen post för papperskorgen.

## Beslut

**1. Skalet får posten *Trash*.** Den har ikonen `trash` och står längst ned i sidopanelen, strax ovanför avdelaren över användarens namn. Mobilens meny får posten på motsvarande plats. Posten har ingen räknare, eftersom en räknare hade kostat en fråga per sidladdning (issue 62b § Beslut 8).

**2. `/trash` är den samlade papperskorgen.** Sidan listar tre slag:
- raderade **items**
- raderade **bilagor**
- raderade **containrar**

Listan tar med allt användaren når, i alla containrar och i den egna inboxen. Kategorier och taggar tas inte med. De är containerns organisation, och de finns kvar i containerns papperskorg. Adressen lämnar plats för `/trash/items` och liknande sidor senare.

**3. Urvalet följer de regler som redan gäller.**
- Items och bilagor visas efter samma regler som i containerns papperskorg (`ListTrash`). Den med begränsat omfång ser bara de items den når och deras bilagor. Omfånget löses för alla containrar i ett anrop (`ResolveItemScope::forContainers()`), som på `/documents`.
- Containrar visas efter samma regel som i `ListTrashedContainers`, alltså bara för ägarkontots medlemmar.
- Ett item eller en bilaga i en raderad container visas inte. Containerns rad står för dem, eftersom de kommer tillbaka med containern.
- Utgånget innehåll visas inte, oavsett om gallringen hunnit köra (issue 20a § Beslut 5).

**4. Varje rad visar:**
- itemets eller containerns ikon, eller bilagans typikon
- namnet, eller filnamnet för en bilaga
- slaget (*Item*, *Attachment* eller *Container*)
- var raden hör hemma: containern för ett item, itemet och containern för en bilaga
- när den raderades och av vem
- när den försvinner för gott

Raderna sorteras med det senast raderade först.

**5. Vem som raderade läses ur händelseloggen.** Det är den senaste händelsen `item.deleted`, `attachment.deleted` eller `container.deleted` för radens subjekt. Namnet visas som i historiken ([[ADR-0043 Tre loggar]]): användarens namn, eller *a former user* när användaren är raderad eller när ett jobb raderade raden. Uppslaget är en fråga för hela sidan och inte en per rad. `audit_log` får ett index på `(subject_type, subject_id, created_at)` för det. Ingen ny kolumn läggs till, så redan raderade rader får också ett namn.

**6. Överst står ett sökfält och en växel med fyra lägen.**
- **Sökfältet** matchar namnet, eller filnamnet för en bilaga. Det söker medan man skriver, som filterraden ([[ADR-0062 Kartans luft, listans ruta och den löpande sökningen]] § 7).
- **Växeln** har lägena *All*, *Items*, *Attachments* och *Containers*.

Båda står i adressen (`/trash?q=…&type=item`), och servern gör urvalet. Tony valde en växel med tre lägen innan bilagorna kom med, så bilagorna får det fjärde.

**7. Sidan visar 50 rader åt gången, med *Load more*.** Nästa sida hämtas med en markör på `deleted_at` och ULID. Antalet frågor per sida är konstant och beror inte på hur många rader som finns.

**8. Varje rad har *Restore*,** med samma grind och samma Action som i dag:
- **Items och bilagor** återställs med `RestoreContent` och grinden i `TrashController::restore()`.
- **Containrar** återställs med `RestoreTrashedContainer` och `ContainerPolicy::delete()`.

Återställningen går genom `POST /trash/restore` med `type` och `ulid` i kroppen och leder tillbaka till `/trash` med en statuskod. Knappen ritas bara när grinden släpper igenom raden. Sidan har ingen permanent radering, eftersom gallringen sköter det.

**9. `/trash/containers` leder till `/trash?type=container`.** `POST /trash/containers/restore` och `/api` är oförändrade. Länken på `/containers` leder till `/trash`.

## Motivering

Papperskorgen är en plats man letar på när något saknas, och då vet man ofta inte var det låg. Att sidan finns i skalet och söker över allt användaren når svarar på det.

Att återanvända urvalsreglerna, Actions och grindarna gör att en rad aldrig syns på `/trash` om den inte hade syntes i containerns papperskorg, och tvärtom.

Att läsa vem som raderade ur loggen i stället för att lägga till en kolumn på tre tabeller ger svaret även för det som redan ligger i papperskorgen. Loggen är där händelsen redan finns.

## Konsekvenser

- Issue 318–320 i [[M39 Den samlade papperskorgen]].
- En ny Action, `ListAllTrash`, och en ny sida, `Trash/Index.vue`. `Trash/Containers.vue` tas bort när rutten leder vidare.
- En migration lägger till indexet på `audit_log`.
- `ShellSections` får posten `trash` och `lang/en/ui.php` får `nav.trash`.

## Alternativ

**En kolumn `deleted_by_user_id` på `item`, `attachment` och `container`.** Valdes bort: den hade krävt en återfyllnad ur loggen för det som redan ligger i papperskorgen, och loggen svarar redan på frågan.

**Två kryssrutor för slagen.** Valdes bort: med två kryssrutor kan ingen vara ikryssad, och en tom lista som valt läge är ingen hjälp. Tony valde växeln.

**Kategorier och taggar på `/trash`.** Valdes bort av Tony. De hör till containerns papperskorg.
