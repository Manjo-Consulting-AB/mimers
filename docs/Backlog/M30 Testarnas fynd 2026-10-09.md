# M30 · Testarnas fynd 2026-10-09

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-10-09, efter testarnas genomgång av v0.22.0, och tänkt att ingå i 0.22.1. Bilderna ligger i `docs/Design/findings_261009/`. Tonys beslut samma dag:

- växeln *Show upcoming tasks* gäller bara *Active* och dashboarden; varje annan flik på `/tasks` visar hela sitt urval — se [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] § Uppföljning 2026-10-09
- `/history` är en egen sida i sidopanelen, mellan *Containers* och *Search*, med containerhistorikens lista, filter och tre diagram över allt användaren får läsa — kontohändelserna utan container inräknade, under platsen *Account*

**Ordningen är 271 → 272. Resten står fritt.** Varje issue står i läget `spårad`.

---

### 267. Kodfältet vid inloggning får fokus och rätt mening

GitHub #817. `code` blir `nullable` i `LoginRequest`, så ett tomt fält inte längre faller på *must be a string* före lösenordet, och markören hamnar i kodfältet när servern ber om koden.

**Läs:** `app/Http/Requests/Auth/LoginRequest.php`, `resources/js/pages/Auth/Login.vue`

**Klart när:** ett konto utan tvåfaktor loggas in med tomt kodfält; med tvåfaktor ges `auth.totp_required`; kodfältet får fokus; hela testsviten är grön.

**Beror på:** —

### 268. Inboxens sista meny och valda filer före uppladdning

GitHub #818. ⋯-menyn på listans sista rad öppnas uppåt, och de valda filerna listas med namn och storlek innan *Add files* klickas.

**Läs:** `resources/js/pages/Inbox/Index.vue`

**Klart när:** sista radens meny öppnas uppåt i båda listorna; de valda filerna syns; hela testsviten är grön.

**Beror på:** —

### 269. Målväljarens containrar, beroendefelet och linjen på Documents

GitHub #819. Containerns namn i fetstil i `ItemTargetPicker`, *Remove the task's dependencies before this action.*, och sorteringen på `/documents` upp i rubrikraden.

**Läs:** `ItemTargetPicker.vue`, `lang/en/ui.php`, `pages/Documents/Index.vue`

**Klart när:** containernamnen är fetstil; meningen är den nya; filterkolumnen och tabellen börjar på samma linje; hela testsviten är grön.

**Beror på:** —

### 270. Växeln gäller bara Active, flikarna visar hela urvalet

GitHub #820. `ListTodo::page()` släpper `show_upcoming_tasks` på varje flik utom *Active*.

**Läs:** [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] § Uppföljning 2026-10-09

**Klart när:** *In progress*, *Next*, *Waiting*, *Someday*, *Inbox* och *Calendar* visar uppgifter med framtida datum när växeln är av; *Active* döljer dem; hela testsviten är grön.

**Beror på:** —

### 271. Global historik, servern

GitHub #821. `GET /history`, `HistoryFilterRequest` med `container` (ULID eller `account`), `forUser()` med filter, `facetsForUser()`, `statsForUser()`, och `container_ulid` i topplistan.

**Läs:** [[ADR-0043 Tre loggar]] § Händelseloggen

**Klart när:** listan och diagrammen bär rader ur alla nådda containrar och kontohändelserna; containerfiltret verkar; läsregeln är oförändrad; hela testsviten är grön.

**Beror på:** —

### 272. Global historik, sidan

GitHub #822. `pages/History/Index.vue`, raden *History* i sidopanelen, containerval med *Account activity* i `HistoryFilterBar`, och *Account* som plats för en kontohändelse.

**Läs:** `resources/js/pages/Containers/History.vue`

**Klart när:** sidopanelen har *History* mellan *Containers* och *Search*; sidan ritar lista, filter och diagram; topplistan länkar genom itemets container; hela testsviten är grön.

**Beror på:** 271
