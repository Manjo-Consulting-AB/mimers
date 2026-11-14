# ADR-0079 Övervakning

**Status:** Antagen 2026-11-14 · Bygger vidare på [[ADR-0043 Tre loggar]] (mätningen) och [[ADR-0030 Miljövariabler ur GitHubs secrets]] · [[ADR-index]]

Tonys beslut 2026-11-14 om hur vi får veta att något går sönder, att något har slutat köras och hur appen används. Kodarbetet ligger i M61: [[M61 Övervakning]] § 387–391.

## Kontext

Loggarna ([[ADR-0043 Tre loggar]]) svarar på vem som gjorde vad, men ingen får veta när något går sönder utan att en användare säger till. Undantag hamnar i Laravels loggfil på inleed, som ingen läser. De nattliga jobben kan sluta köras utan att någon märker det. `/up` anropas inte av någon. Det finns inga data om vilka sidor och funktioner som används.

Driften ligger på ett delat webbhotell hos inleed, där vi inte kan köra egna tjänster med processer som körs hela tiden, och VPS:en är bara till för att bygga.

## Beslut

### 1. Felrapportering med Sentry i EU-regionen

PHP (`sentry/sentry-laravel`) och webbläsaren (`@sentry/vue`) rapporterar undantag till Sentrys EU-region.

- `send_default_pii` är av: ingen IP-adress, inga e-postadresser, inga kakor, inga request-kroppar.
- Användaren skickas bara som en pseudonym, en HMAC av personens ULID med appens nyckel, så att samma fel hos samma person kan räknas utan att personen pekas ut.
- Valideringsfel, 404, 403 och 419 rapporteras inte.
- Varje rapport bär `release` (filen `VERSION`) och `environment` (`staging`, `production`).
- En gräns per fel och sampling håller sig inom gratisnivån.

### 2. Driften övervakas utifrån

- Sentrys tillgänglighetskoll anropar `/up` i båda miljöerna.
- Sentrys cron-övervakning tar emot ett hjärtslag från de viktigaste schemalagda jobben (nedgraderingen, gallringen av papperskorgen och lagrade filer, notisleveransen, mätningen, livscykeln) och larmar när ett uteblir.
- Notiskön vaktas inne i appen, som rapporterar till Sentry när en leverans har väntat för länge.
- Tillgänglighetskollen ställs in för hand i Sentry, inte i koden. Stegen står i [[Pipeline]] § Övervakning.

### 3. Prestanda i en egen logg på inleed

Förfrågningar över 1000 ms och databasfrågor över 200 ms skrivs till loggkanalen `performance` med ruttnamn, tid och antal frågor, men utan användare. En veckosammanställning mejlas till driftadressen. Sentrys prestandaspårning används inte, eftersom den förbrukar kvot.

### 4. Användningen mäts anonymt i appen

Sidvisningar per ruttnamn och användning av namngivna funktioner (till exempel snabbraden, växeln *Include child items* och *Copy*) räknas per dag i en egen tabell utan användare, konto eller IP. Nattjobbet för mätningen ([[ADR-0043 Tre loggar]] § Mätningen) flyttar dem till `usage_metric` med samma tröskel på fem och tömmer råtabellen. Inga kakor och ingen tredjepart, så ingen samtyckesbanner behövs.

### 5. Larm

Akuta larm (nytt fel, tillgänglighet, uteblivet hjärtslag) kommer från Sentry som mejl eller push. Appen skickar bara veckosammanställningen.

## Alternativ som valts bort

- **GlitchTip i egen drift.** Kräver Postgres och processer som körs hela tiden, vilket det delade webbhotellet inte tillåter, och VPS:en är ingen driftmiljö.
- **GlitchTips betaltjänst på 1000 händelser i månaden.** En spik tar slut på kvoten.
- **Google Analytics eller liknande.** Tredjepart, kakor och samtycke.
- **Att bara läsa Laravels loggfil.** Ingen gör det.

## Konsekvenser

- Sentry blir personuppgiftsbiträde för felrapporterna, med DPA. Raden står i [[Registerförteckning]].
- Hemligheter per miljö: `SENTRY_LARAVEL_DSN`, `SENTRY_JS_DSN`, `SENTRY_ENVIRONMENT` och `OPS_EMAIL` (driftadressen för veckosammanställningen), plus `SENTRY_AUTH_TOKEN` för källkartor i bygget. De står i [[Pipeline]] § Miljöer och secrets i GitHub.
- Fem kodissuer i [[M61 Övervakning]]: felrapporteringen från servern (387) och webbläsaren (388), hjärtslagen och köns vakt (389), prestandaloggen (390) och mätningen (391).
- Utan DSN är Sentry avstängt, lokalt, i testerna och i CI.
