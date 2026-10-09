# M28 · Testarnas fynd 2026-10-07

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-10-07, efter testarnas genomgång av v0.21.0. Bilderna ligger i `docs/Design/findings_261007a/`. Tonys beslut samma dag:
- befintliga modeller och komponenter används där det går
- inga nya begrepp införs för att efterlikna en mockup
- information som mockupen visar men som vi inte har väntar på ett eget beslut

Det betyder: inga taggar eller kategorier på uppgifter, ingen tavla och ingen flervalsflytt av uppgifter.

**Fyra spår.**
- *Inboxen:* 249 → 250 → 251
- *`/tasks`:* 256 → 257 → 258, och 259
- *Dokumenten:* 254 → 255
- Resten står fritt.

Varje issue står i läget `spårad`.

**Känt och avgränsat:** en fil som raderas ur inboxen går inte att återställa i webben, eftersom inboxens container inte syns på någon papperskorgssida (250 § Beslut 4).

---

### 248. Ord och menyer

GitHub #773. *Create* blir *Add* i plusmenyn, *Maintenance only* blir *Recurring tasks only*, och *Ownership transfers* flyttar från sidopanelen till inställningarnas meny.

**Läs:** `resources/js/layouts/settingsSections.js`

**Klart när:** plusmenyn heter *Add*; sidopanelen saknar ägarbyten; inställningsmenyn har dem sist; hela testsviten är grön.

**Beror på:** —

### 249. Inboxen som plats på uppgiftsraden

GitHub #774. `in_inbox` bredvid raden, och en länk *Inbox* till `/inbox` i stället för det dolda itemet och containern.

**Läs:** [[ADR-0054 Inboxen]] § 1 och § 7

**Klart när:** en inboxuppgift bär `in_inbox` och länkar till `/inbox`; `/api` är oförändrat; hela testsviten är grön.

**Beror på:** —

### 250. Radera i inboxen

GitHub #775. *Delete* i radernas ⋯-meny och *Delete selected* för filer, med de vanliga reglerna för varje objekttyp.

**Läs:** [[ADR-0054 Inboxen]] § 2 och § 6

**Klart när:** en fil hamnar i papperskorgen; flera filer raderas alla eller ingen; något utanför den egna inboxen nekas; hela testsviten är grön.

**Beror på:** —

### 251. /inbox enligt mockupen

GitHub #776. Fångstkortet, tre brickor med *to process* och listorna med *Process…*, efter `inkorgsöversikt.png`.

**Läs:** `docs/Design/findings_261007a/inkorgsöversikt.png`

**Klart när:** brickorna säger aldrig *items*; varje rad har *Process…* och en radmeny; hela testsviten är grön.

**Beror på:** 250

### 252. Dashboardens nya uppställning

GitHub #777. Brickor och kostnadsdonut i översta raden, därunder två oberoende kolumner.

**Läs:** `resources/js/pages/Dashboard.vue`

**Klart när:** donuten står överst; containrarna och panelerna står i var sin kolumn; hela testsviten är grön.

**Beror på:** —

### 253. Plusknappen på Documents, Tasks och Costs

GitHub #778. `CreateTarget::forContainerTab()`: *New task* med inboxen förvald, och ett val av item för dokument och kostnader.

**Läs:** [[ADR-0048 Mobilen och plusknappen]] § 2

**Klart när:** de tre flikarna har knappen; en läsare får ingen på *Documents*; hela testsviten är grön.

**Beror på:** 248

### 254. Globala dokument, servern

GitHub #779. `ListAccessibleAttachments` och `GET /documents`: alla filer användaren når, inboxen inräknad, med filter för container och typ.

**Läs:** [[ADR-0054 Inboxen]] § 2 och § 7

**Klart när:** omfånget gäller per container; en annan användares inbox syns inte; sidan skapar ingen inbox; antalet frågor är konstant; hela testsviten är grön.

**Beror på:** —

### 255. Globala dokument, sidan

GitHub #780. Sidan `/documents` med en kolumn för containern, filterkolumnen och raden i sidopanelen.

**Läs:** `resources/js/pages/Containers/Documents.vue`

**Klart när:** en inboxfil länkar till `/inbox`; filtret står i adressen; hela testsviten är grön.

**Beror på:** 254

### 256. Uppgiftsraden enligt mockupen

GitHub #781. Itemets kategori som etikett och radens uppställning, efter `task-dashboard.png`.

**Läs:** `docs/Design/task-dashboard.png`

**Klart när:** raden bär kategorin; antalet frågor är oförändrat; `/api` är oförändrat; hela testsviten är grön.

**Beror på:** 249

### 257. Filter och sortering på /tasks

GitHub #782. Container, lista, status och förfallodatum upp eller ner, i adressen och genom bläddringen.

**Läs:** `docs/Design/task-dashboard.png`

**Klart när:** varje filter avgränsar; filtret som fliken bestämmer ignoreras; nästa sida behåller filtren; hela testsviten är grön.

**Beror på:** 256

### 258. Högerspalten på /tasks

GitHub #783. *Quick overview* per datumgrupp, *Lists* och *My containers*, utan *Tags*.

**Läs:** `docs/Design/task-dashboard.png`

**Klart när:** talen stämmer med listan; inboxen står inte i containerkortet; antalet frågor är konstant; hela testsviten är grön.

**Beror på:** 257

### 259. Move… från /tasks

GitHub #784. ⋯-menyn på raden med *Move…*, en uppgift i taget, genom den befintliga rutten.

**Läs:** [[ADR-0053 Flytt och kopiering]] § 6

**Klart när:** bara `/tasks` ritar menyn; en inboxuppgift får ingen; ett fel ritas på raden; hela testsviten är grön.

**Beror på:** 249, 256

### 260. Itemets Tasks-flik enligt mockupen

GitHub #785. Rubrikkortet, knappen och växeln för underliggande items, efter `tasks-item.png`. Uppgiftens egen sida rörs inte.

**Läs:** `docs/Design/tasks-item.png`

**Klart när:** fliken har rubrikkortet; reglaget är en växel som navigerar som förut; hela testsviten är grön.

**Beror på:** 256

### 261. QR-kod vid tvåstegsverifiering

GitHub #786. `uqr` och `TotpQrCode.vue` bredvid den manuella nyckeln, utan `v-html`.

**Läs:** [[ADR-0023 TOTP-bibliotek]]

**Klart när:** QR-koden ritas bredvid nyckeln; inget annat nytt paket; hela testsviten är grön.

**Beror på:** —

### 266. Kvar i listan efter Move… från /tasks

GitHub #815. Efter en flytt från `/tasks` står användaren kvar i samma flik med samma filter och sortering; klienten skickar `return: 'back'` och kontrollern svarar `back()`. Tonys beslut 2026-10-09, uppföljning av gapet i PR #805.

**Läs:** [[ADR-0053 Flytt och kopiering]] § 6

**Klart när:** en flytt med `return: 'back'` landar på samma adress; utan fältet som i dag; hela testsviten är grön.

**Beror på:** 259
