# M60 · Notislistan i appen

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-14, efter Tonys beslut om förslaget i GitHub #1238. Notiserna har bara e-post, ICS och webhooks som utgångar, och frågan kom upp med `container.deleted` (#1235), där medlemmarna ska få ett besked i systemet. Beslutet och motiveringen står i [[ADR-0077 Notislistan i appen]]; datamodellen i [[Notiser]].

Fyra issues: kanalen, rutterna, ytan och typernas förval med inställningarnas kolumn.

**Releasen:** en, när 383–386 är mergade. Issuerna skrevs direkt i GitHub (#1240, #1241, #1242, #1243) och är sammanfattade här.

---

### 383. Kanalen in_app

GitHub #1240. **Läget:** notiserna har ingen utgång som skrivs direkt och läses i appen.

**Lösningen:** kanalen `in_app` ([[ADR-0077 Notislistan i appen]] § 1, 5, 6 och 7). `notification_delivery.read_at` med ett index för olästa rader per person, `CHANNEL_IN_APP` och `NotificationPreferences::channelsFor()` som bär båda kanalerna med `in_app` avslagen som förval för alla typer. `CreateNotification` skriver `in_app`-raden med status `sent` direkt, också under tysta timmar. Kön och sammanfattningen rör den inte. Actionerna `ListInAppNotifications`, `MarkInAppNotificationsRead` och `CountUnreadInAppNotifications`, med ett enda uppslag för adressen per typ. Den delade proppen `notifications.unread`. `PrunesInAppNotifications` tar bort rader äldre än 90 dagar dagligen.

**Läs:** [[ADR-0077 Notislistan i appen]], [[Notiser]] § notification_delivery och § notification_preference

**Klart när:** `in_app` finns som kanal med `read_at` och raden skrivs direkt med status `sent`; förvalet är av för alla typer; kön och sammanfattningen rör inte kanalen; läs-, markera- och räkna-actionerna finns och adressen per typ har ett enda uppslag; `notifications.unread` delas för inloggade; gallringen körs dagligen; `InAppKanalTest` täcker punkterna; hela sviten är grön.

**Beror på:** —

### 384. Notislistans rutter på webben och i API:et

GitHub #1241. **Läget:** actionerna finns sedan 383, men ingen yta når dem.

**Lösningen:** `InAppNotificationResource` och tre API-rutter (`GET /api/notifications` med cursor, `POST /api/notifications/{ulid}/read` och `POST /api/notifications/read-all`) samt fyra webbrutter (`notifications.index`, `notifications.panel`, `notifications.read` och `notifications.read-all`). Webben och API:et delar actions ([[ADR-0077 Notislistan i appen]] § 2, 3 och 8). En person kan aldrig läsa eller markera någon annans notis.

**Läs:** [[ADR-0077 Notislistan i appen]] § 2, 3 och 8

**Klart när:** de tre API-rutterna och de fyra webbrutterna finns med de svar issuen anger; en person kan aldrig läsa eller markera någon annans notis; `NotislistaApiTest` och `NotislistaWebbTest` finns; hela sviten är grön.

**Beror på:** 383

### 385. Klockan, panelen och sidan /notifications

GitHub #1242. **Läget:** rutterna finns sedan 384, men personen ser ingenting i appen.

**Lösningen:** en klocka i toppraden med antalet olästa, en panel över `md:` som hämtar de 10 senaste, och sidan `/notifications` med *Load more* och *Mark all as read* ([[ADR-0077 Notislistan i appen]] § 2, 3 och 7). `NotificationRow.vue` delas av panelen och sidan: en länk när notisen har ett mål, ren text annars. Visade rader markeras som lästa, och märket uppdateras med en partiell omladdning.

**Läs:** [[ADR-0077 Notislistan i appen]] § 2, 3 och 7

**Klart när:** klockan visar antalet olästa, öppnar panelen över `md:` och leder till sidan under `md:`; raderna visar text per typ, med länk när målet finns; visade rader markeras som lästa och *Mark all as read* fungerar; sidan har *Load more* och ett tomt läge; `NotislistavyTest` täcker punkterna; hela sviten är grön.

**Beror på:** 384

### 386. Typerna får in_app, och inställningarna en kolumn för den

GitHub #1243. **Läget:** kanalen finns sedan 383 men är avslagen för alla typer, och inställningarna känner bara e-posten.

**Lösningen:** `in_app` påslagen som förval för `container.deleted`, `transfer.requested` och `quota.warning` ([[ADR-0077 Notislistan i appen]] § 4). Inställningarna på webben och i API:et läser och skriver båda kanalerna, och `digest` godtas bara för `email`. Inställningssidan för notiser får kolumnen *In app*, med en växel bara för de typer som kan ha kanalen.

**Läs:** [[ADR-0077 Notislistan i appen]] § 4

**Klart när:** `in_app` är påslagen som förval för de tre typerna och avslagen för resten; inställningarna läser och skriver båda kanalerna; *In app*-kolumnen visas för de tre typerna; en avregistrering från mejlet rör inte `in_app`; proven finns; hela sviten är grön.

**Beror på:** 383, och #1235 för att `container.deleted` ska finnas
