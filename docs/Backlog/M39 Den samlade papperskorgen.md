# M39 · Den samlade papperskorgen

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-10-18, efter `docs/Design/findings/trash_20261018_170118.png`. Det här ingår: skalets post *Trash* och sidan `/trash`, som listar raderade items, bilagor och containrar över allt användaren når. Sidan har ett sökfält, en växel med fyra lägen, vem som raderade och *Restore* på varje rad. Besluten står i [[ADR-0065 Den samlade papperskorgen]].

**Ordningen:** 319 bygger på 318. 320 står fritt.

**Detta ingår inte:** containerns egen papperskorg (`/containers/{c}/trash`), kategorier och taggar på `/trash`, permanent radering från sidan och `/api`.

---

### 318. Den samlade papperskorgens urval

GitHub #945. En ny Action, `App\Actions\Trash\ListAllTrash`, ger papperskorgens rader över allt användaren når ([[ADR-0065 Den samlade papperskorgen]] § 2–5 och 7). Den tar `type` (`item`, `attachment`, `container` eller inget), `q` och en markör, och svarar med högst 50 poster, senast raderad först, och en markör till nästa sida eller `null`.

Varje post bär:
- `type`, `ulid` och `label`
- `icon` och `icon_color` för items och containrar
- `container` och `item` som `{ulid, name}` där de finns
- `deleted_at`, `expires_at` och `deleted_by`

`deleted_by` är namnet från den senaste händelsen `*.deleted` i `audit_log` för subjektet, eller `null`. Svaret bär också de laddade raderna nycklade på ULID, så att kontrollern kan pröva grinden per rad utan en ny fråga, som `ListTrash` och `ListTrashedContainers`.

Urvalet följer `ListTrash` för items och bilagor och `ListTrashedContainers` för containrar, med omfånget ur `ResolveItemScope::forContainers()` och den egna inboxen som på `/documents`. En migration lägger till indexet `(subject_type, subject_id, created_at)` på `audit_log`.

**Läs:** [[ADR-0065 Den samlade papperskorgen]] § 2–5 och 7, `app/Actions/Trash/ListTrash.php` (docblocken), `app/Actions/Trash/ListTrashedContainers.php` (docblocken), `app/Actions/Attachment/ListAccessibleAttachments.php` (docblocken), `app/Actions/Audit/PresentAuditEvents.php` (docblocken om den handlande användaren)

**Klart när:** raderade items, bilagor och containrar listas med det senast raderade först; den med begränsat omfång ser bara de items den når och deras bilagor; containrar syns bara för ägarkontots medlemmar; inget ur en raderad container listas för sig; utgånget innehåll och kategorier och taggar finns inte; inboxens raderade items finns med; `type` och `q` begränsar urvalet; markören ger nästa 50 utan dubbletter och utan luckor; `deleted_by` är namnet eller `null`; antalet frågor är konstant när raderna och containrarna blir fler; hela testsviten är grön.

**Beror på:** —

### 319. Sidan `/trash`

GitHub #946. `GET /trash` ritar `Trash/Index.vue` ur `ListAllTrash` (§ 4–9). Överst står sökfältet och växeln *All*, *Items*, *Attachments* och *Containers*. Sökfältet söker medan man skriver, som `ItemFilterBar`. Varje rad visar det som står i § 4 och *Restore* när grinden släpper igenom den. *Load more* hämtar nästa sida och lägger den under. Tom papperskorg och ett filter utan träffar har var sin text.

`POST /trash/restore` tar `type` och `ulid` i kroppen, återställer med samma Action och grind som `TrashController::restore()` respektive `ContainerTrashController::restore()` och leder tillbaka till `/trash` med en statuskod. `GET /trash/containers` leder till `/trash?type=container`, och länken på `/containers` leder till `/trash`. `Trash/Containers.vue` tas bort.

**Läs:** [[ADR-0065 Den samlade papperskorgen]] § 4 och 6–9, [[ADR-0062 Kartans luft, listans ruta och den löpande sökningen]] § 7, `app/Http/Controllers/ContainerTrashController.php` (docblocken), `app/Http/Controllers/TrashController.php` (`restore()`), `resources/js/pages/Trash/Containers.vue` (docblocken), `resources/js/components/ItemFilterBar.vue` (docblocken)

**Klart när:** `/trash` ritar raderna med ikon, namn, slag, var raden hör hemma, när, av vem och när den försvinner; sökfältet söker medan man skriver; växeln har fyra lägen och står i adressen; *Load more* lägger nästa sida under; *Restore* ritas bara när grinden släpper igenom raden och återställer med samma Action som förut; en återställning leder tillbaka till `/trash`; `/trash/containers` leder till `/trash?type=container`; `POST /trash/containers/restore` och `/api` är oförändrade; hela testsviten är grön.

**Beror på:** 318

### 320. *Trash* i skalet

GitHub #947. `ShellSections` får posten `trash`, med ikonen `trash` och ordet `nav.trash`, i en egen `part` som `AppLayout` ritar längst ned i sidopanelen strax ovanför avdelaren över användarens namn ([[ADR-0065 Den samlade papperskorgen]] § 1). Posten står i samma lista `allSections` som de andra. Posten markeras som aktiv på `/trash` och under den. `MobileMenu` får posten på motsvarande plats. Posten har ingen räknare.

**Läs:** [[ADR-0065 Den samlade papperskorgen]] § 1, `resources/js/components/ShellSections.vue` (docblocken och `partKeys`), `resources/js/layouts/AppLayout.vue` (sidopanelens nederdel), `resources/js/components/MobileMenu.vue`, `docs/Design/findings/trash_20261018_170118.png`

**Klart när:** posten *Trash* med soptunnan står strax ovanför avdelaren i sidopanelen; den leder till `/trash` och är aktiv på `/trash` och under den; mobilens meny har posten; ingen räknare och ingen ny fråga; hela testsviten är grön.

**Beror på:** —
