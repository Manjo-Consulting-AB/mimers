# M61 · Övervakning

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-14, efter Tonys beslut om övervakning. Ingen får veta när något går sönder utan att en användare säger till, de nattliga jobben kan sluta köras utan att någon märker det, och vi vet inte vilka sidor och funktioner som används. Beslutet och motiveringen står i [[ADR-0079 Övervakning]]; det som skickas till Sentry står i [[Registerförteckning]], och stegen Tony gör för hand i [[Pipeline]] § Övervakning.

Fem issues: felrapporteringen från servern och från webbläsaren, hjärtslagen med köns vakt, prestandaloggen och mätningen av sidor och funktioner.

**Releasen:** en, när 387–391 är mergade. Issuerna skrevs direkt i GitHub (#1264, #1265, #1266, #1267, #1268) och är sammanfattade här.

---

### 387. Felrapportering från servern till Sentry

GitHub #1264. **Läget:** undantag på servern hamnar i en loggfil som ingen läser.

**Lösningen:** `sentry/sentry-laravel` med `config/sentry.php` ([[ADR-0079 Övervakning]] § 1). Utan `SENTRY_LARAVEL_DSN` är Sentry avstängt. `release` är `config('app.version')` och `environment` kommer från `SENTRY_ENVIRONMENT`. `send_default_pii` är av och en `before_send` tar bort request-kropp, kakor och IP-adress. Valideringsfel, 404, 403, 419 och andra klientfel rapporteras inte, med listan på ett ställe. Den inloggade skickas som en pseudonym, en HMAC av ULID:n med appens nyckel. `SENTRY_LARAVEL_DSN` och `SENTRY_ENVIRONMENT` transporteras till `shared/.env` av `staging.yml` och `production.yml`, och en saknad hemlighet fäller inte utrullningen.

**Läs:** [[ADR-0079 Övervakning]] § 1, [[ADR-0030 Miljövariabler ur GitHubs secrets]]

**Klart när:** `sentry/sentry-laravel` är installerat och Sentry är avstängt utan DSN; undantag rapporteras med `release` och `environment` och klientfelen rapporteras inte; ingen IP-adress, inga kakor och ingen request-kropp skickas och användaren är en pseudonym; utrullningen transporterar de två nycklarna; `FelrapporteringTest` täcker punkterna; hela sviten är grön.

**Beror på:** GitHub #1263 (valvets beslut)

### 388. Felrapportering från webbläsaren till Sentry

GitHub #1265. **Läget:** servern rapporterar sedan 387, men JavaScript-fel i webbläsaren syns ingenstans.

**Lösningen:** `@sentry/vue` med samma regler som serverns ([[ADR-0079 Övervakning]] § 1). Samma artefakt byggs i staging och rullas ut till produktionen, så DSN, miljö, release och pseudonym delas från servern i Inertia-proppen `sentry` och bakas inte in i bygget. Webbläsaren har ett eget projekt i Sentry med egen nyckel, `SENTRY_JS_DSN`. `sendDefaultPii` och `tracesSampleRate` är av, ingen Session Replay, och `beforeSend` kastar fel som inte kommer från vår egen kod. Bygget skapar dolda källkartor som laddas upp till Sentry per release med `SENTRY_AUTH_TOKEN` och sedan tas bort ur artefakten; saknas token hoppas uppladdningen över utan att bygget fälls.

**Läs:** [[ADR-0079 Övervakning]] § 1, GitHub #1264

**Klart när:** webbläsarens fel rapporteras med release, miljö och pseudonym och utan personuppgifter; DSN och miljö kommer från servern så att samma artefakt fungerar i staging och produktion; källkartor laddas upp per release och publiceras inte; `WebblasarfelTest` och källkodsprovet mot `staging.yml` finns; hela sviten är grön.

**Beror på:** 387

### 389. Hjärtslag för schemalagda jobb och vakten för notiskön

GitHub #1266. **Läget:** ett schemalagt jobb kan sluta köras, eller notiskön stå still, utan att någon märker det.

**Lösningen:** elva jobb får `->sentryMonitor(...)` med en slug och sitt schema ([[ADR-0079 Övervakning]] § 2): nedgraderingen, kontots livscykel, raderingen av vilande konton, gallringen av papperskorgen och lagrade filer, notisleveransen, uppgiftsnotiserna, veckosammanfattningen, mätningen, loggallringen och avstämningen av räknarna. Listan ligger på ett ställe och har en angiven ordning för vad som stryks om gratisnivåns kvot inte räcker. `App\Console\WatchesDeliveryQueues` körs var 15:e minut och skickar ett meddelande till Sentry när en e-post- eller webhookleverans har väntat mer än 60 minuter, med köns namn, antal och ålder men utan id:n och adresser. Vakten har själv ett hjärtslag.

**Läs:** [[ADR-0079 Övervakning]] § 2, [[Notiser]] § notification_delivery och § Kön

**Klart när:** de elva jobben skickar hjärtslag med sitt schema; `WatchesDeliveryQueues` larmar efter 60 minuter, utan id:n och adresser; `HjartslagTest` och `KovaktTest` finns; hela sviten är grön.

**Beror på:** 387

### 390. Prestandaloggen och veckosammanställningen

GitHub #1267. **Läget:** vi vet inte var appen är långsam, och Sentrys prestandaspårning förbrukar kvot.

**Lösningen:** loggkanalen `performance` på inleed, JSON med 30 dagars gallring ([[ADR-0079 Övervakning]] § 3 och 5). Förfrågningar över 1000 ms och databasfrågor över 200 ms skrivs med ruttnamn, tid och antal frågor, men utan URL med parametrar, användare, IP och bindningar. Tröskelvärdena ligger i `config/monitoring.php`. `App\Console\SummarizesPerformance` mejlar måndagar 06:00 en veckosammanställning till `OPS_EMAIL`: de tio långsammaste rutterna med median och 95:e percentil, de tio vanligaste långsamma frågorna och antalet över tröskeln jämfört med förra veckan. Utan adress eller rader skickas inget.

**Läs:** [[ADR-0079 Övervakning]] § 3 och 5

**Klart när:** förfrågningar över 1000 ms och frågor över 200 ms skrivs till `performance` utan användare, URL-parametrar och bindningar; veckosammanställningen mejlas till `OPS_EMAIL` med de långsammaste rutterna och frågorna; `PrestandaloggTest` och `PrestandasammanstallningTest` finns; hela sviten är grön.

**Beror på:** GitHub #1263 (valvets beslut)

### 391. Sidvisningar och funktioner i mätningen

GitHub #1268. **Läget:** vi vet inte vilka sidor och funktioner som används, och mätningen från [[ADR-0043 Tre loggar]] har bara källor för loggarna.

**Lösningen:** en råtabell, `usage_event_daily`, utan användare, konto och IP ([[ADR-0079 Övervakning]] § 4). Middlewaren `CountRouteView` räknar upp en lyckad Inertia-GET per ruttnamn och plan efter att svaret skickats. `CountFeatureUse` räknar namngivna funktioner, med namnen som konstanter i `Features`: snabbraden och formuläret för uppgifter, uppladdning, flytt, kopiering och inkorg för bilagor, kostnader, växeln *Include child items* och plusknappen på trädnoden. `AggregatesUsageMetrics` flyttar gårdagens rader till `usage_metric` som `route` och `feature` med tröskeln fem och samma sammanslagning som för loggarna, och tömmer de lästa raderna.

**Läs:** [[ADR-0079 Övervakning]] § 4, [[ADR-0043 Tre loggar]] § Mätningen

**Klart när:** sidvisningar räknas per rutt och plan utan personuppgifter, efter att svaret skickats; de namngivna funktionerna räknas när handlingen lyckats, på webben och i API:et; nattjobbet flyttar raderna till `usage_metric` med tröskeln fem och tömmer råtabellen; `AnvandningsmatningTest` finns; hela sviten är grön.

**Beror på:** GitHub #1263 (valvets beslut)
