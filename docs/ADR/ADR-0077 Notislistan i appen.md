# ADR-0077 Notislistan i appen

**Status:** Antagen 2026-11-14 · Bygger vidare på [[Notiser]] § Grundprincipen och [[ADR-0050 Desktopdesignen]] § 1 (toppraden) · [[ADR-index]]

Tonys beslut 2026-11-14, efter förslaget i GitHub #1238.

## Kontext

Notiserna har bara e-post, ICS och webhooks som utgångar. Det finns ingen lista i appen. Frågan kom upp med `container.deleted` (#1235), där medlemmarna ska få ett besked i systemet och inte bara i inkorgen.

## Beslut

### 1. En ny kanal, `in_app`

En adapter bredvid `email` i `notification_delivery`. Leveransen sker direkt: raden skrivs med status `sent` när notisen skapas, och kön behövs inte. Läst-läget är `notification_delivery.read_at` på kanalens rad.

### 2. Ytan

En klocka i toppraden bredvid avataren visar antalet olästa. Över `md:` öppnar den en panel med de cirka 10 senaste notiserna och en länk *See all* till `/notifications`. Under `md:` leder klockan direkt till sidan. Sidan visar alla notiser som finns kvar, nyast först, med sidindelning.

### 3. Läst

Att öppna panelen eller sidan markerar de visade notiserna som lästa. Det finns också *Mark all as read*. En notis med mål är en klickbar rad, och adressen per typ kommer från samma uppslag som i dag (`HandleInertiaRequests`, till exempel `transfer.requested` → `/transfers`). En notis utan mål, som `container.deleted`, är en rad utan länk med ikon, text och tid.

### 4. Kanalen kommer som tillägg, styrt per typ

Varje typ har e-post och `in_app` påslagna eller avslagna var för sig i `notification_preference`, med förval per typ i kod. Den första omgången typer med `in_app` är `container.deleted`, `transfer.requested` och `quota.warning`, alla påslagna som förval. `task.due` och `task.overdue` får kanalen senare, och då kan e-posten vara sammanfattningen medan appen visar varje notis.

### 5. Gallring

En notis visas i listan i **90 dagar** från att den skapades, läst eller inte. Det nattliga jobbet tar bort äldre `in_app`-rader. Det som ska sparas längre står redan i händelseloggen ([[ADR-0043 Tre loggar]]).

### 6. Tysta timmar gäller inte `in_app`

En rad i listan stör ingen, så den skrivs direkt.

### 7. Antalet olästa

Följer med i den delade proppen, så att klockan alltid visar rätt utan ett extra anrop.

### 8. API:et från början

`GET /api/notifications` (sidindelat, nyast först), `POST /api/notifications/{ulid}/read` och `POST /api/notifications/read-all`. Webben och API:et använder samma actions (M27).

## Alternativ som valts bort

- **Bara en panel.** Trång på mobilen och ingen historik.
- **Bara en sida.** Ett klick för mycket på skrivbordet.
- **Att `in_app` ersätter e-posten.** Den som inte loggar in får inget besked.
- **Att lästa notiser sparas för alltid.** Listan är ett flöde, inte ett arkiv.

## Konsekvenser

- [[Notiser]] beskriver kanalen, `read_at`, undantaget från tysta timmar och gallringen.
- Bygget ligger i [[M60 Notislistan i appen]] (383–386).
- Kön och veckosammanfattningen rör aldrig `in_app`-raderna, eftersom de redan filtrerar på `email`.
