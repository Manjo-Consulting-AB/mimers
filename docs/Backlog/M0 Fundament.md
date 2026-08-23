# M0 · Fundament

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

### 0. Repo, miljöer och deploy-kedja
Repot är `Manjo-Consulting-AB/mimers`. Branch protection på `main`, `AGENTS.md` med konventionerna, PR-mall med läslista, och dokumentationen inflyttad under `docs/`. Två miljöer hos inleed med varsin databas och varsin minutcron. Workflow-filerna och deploy-skriptet enligt [[Pipeline]].

**Gjort 2026-08-23:** dokumentationen ligger under `docs/`, `AGENTS.md`, PR-mallen, de tre workflow-filerna och `deploy/deploy.sh` finns i repot. På servern: katalogträden `~/mimers` och `~/mimers-staging`, document root ompekad via symlänk, minutcron per miljö. Frågelistan i [[ADR-0018 Utvecklingsprocess och deploy]] § Verifierat hos inleed är alltså avklarad.

**Gjort senare samma dag:** miljöerna `staging` och `production` i GitHub med samtliga sex `DEPLOY_*`-secrets och egen deploynyckel per miljö. På servern: `shared/.env` per miljö, en verifierad databas per miljö satt till `utf8mb4_unicode_ci`, webbrot ompekad även för staging, och sajter för `files.mimers.app` och `files.staging.mimers.app`.

**Genomlöpet, halva vägen 2026-08-23:** en tom Laravel gick grön PR → automatisk deploy → `https://staging.mimers.app` svarar 200 med rätt Inertia-payload, migrationerna kördes mot staging-databasen, och minutcronen kör `schedule:run` på båda miljöerna. Kedjan tog fyra försök; felen och vad de lärde oss står i [[Pipeline]] § Vägen in på servern och § Paketering.

**Återstår:** release `v0.0.1` till produktion, och en provad rollback. Branch protection och required reviewer är **medvetet uppskjutna** — kontoplanen tillåter dem inte, och med två deltagare där bara agenten pushar köper en uppgradering ingenting. Se [[ADR-0018 Utvecklingsprocess och deploy]] § Spärrarna är uppskjutna. Räkna inte den som en punkt att bocka av i den här issuen.
**Läs:** [[Pipeline]], [[ADR-0018 Utvecklingsprocess och deploy]]
**Klart när:** en tom Laravel har gått hela vägen — grön PR, automatisk deploy till staging, release `v0.0.1` till produktion efter godkännande — och en rollback har provats genom att flippa symlänken tillbaka.

### 1. Sätt upp Laravel-projektet
Laravel på PHP 8.4, MariaDB 10.6. Kodstandard (Pint), statisk analys (PHPStan), testuppsättning (Pest eller PHPUnit). CI som kör lint, analys och tester på varje PR. Inertia, Vue 3, Tailwind och Vite installeras i samma steg — frontenden bor i den här appen, inte i ett eget projekt.

`public/.htaccess` ska innehålla `php_value`-raderna för uppladdningsgränser ur [[Pipeline]] § Uppladdningsgränser. Serverns standard är 2 MB och räcker inte för en enda manual.
**Läs:** [[ADR-0001 Stack]], [[ADR-0021 Frontendteknik]]
**Klart när:** `composer test` och `composer lint` går grönt i CI på en tom kodbas, och `npm run build` producerar `public/build` som CI också kör.
**Beror på:** 0

**Gjort 2026-08-23:** Laravel 13 på PHP 8.4 med Inertia, Vue 3, Tailwind 4 och Vite. Pint, Larastan nivå 5 och Pest 5 som `composer lint` / `analyse` / `test`, alla tre i CI tillsammans med `npm run build`. Valet mellan Pest och PHPUnit avgörs i [[ADR-0022 Testramverk och statisk analys]]. Uppladdningsgränserna ligger i `public/.htaccess` och testas som fil.

### 2. Gemensamma modellkonventioner
Trait för ULID-generering, bastraits för soft delete, migrations-mall. Hjälpare för att alltid filtrera bort raderade rader i listningar.
**Läs:** [[Datamodell – översikt]], [[ADR-0008 Soft delete och papperskorg]]
**Klart när:** en exempelmodell har ULID, soft delete och tidsstämplar, och test visar att raderade rader inte kommer med i listning.
**Beror på:** 1

### 3. Konto och användare
Tabellerna `account`, `user`, `account_user`. Locale, timezone, unit_system på båda. `last_active_at` uppdateras av en middleware på **alla** autentiserade API-anrop.
**Läs:** [[Konton och åtkomst]], [[ADR-0002 Konto äger container]]
**Klart när:** ett konto kan skapas med en medlem; `last_active_at` uppdateras av ett godtyckligt API-anrop, inte bara inloggning.
**Beror på:** 2

### 4. Autentisering med lösenord
Två vägar in: **Laravels sessionsguard med CSRF** för webben, personal access tokens för mobilappar och B2B. Registrering, inloggning, utloggning, e-postverifiering. Sanctums cookie-läge (`EnsureFrontendRequestsAreStateful`) sätts **inte** upp — webben anropar inte `/api` från webbläsaren. Se [[ADR-0021 Frontendteknik]].
**Läs:** [[ADR-0011 Autentisering]], [[ADR-0021 Frontendteknik]], [[ADR-0020 Plattformsidentitet och frontendgräns]], [[Konton och åtkomst]]
**Klart när:** en webbsession kan logga in med CSRF-skydd, och en token-klient kan nå motsvarande API-endpoints direkt. Båda vägarna delar FormRequests och policies — ett test visar att samma ogiltiga indata avvisas likadant på båda ytorna. Appen genererar korrekta absoluta URL:er bakom LiteSpeed, med `TrustProxies` konfigurerat.
**Beror på:** 3

### 5. Magic link
Engångstoken, kortlivad, lagrad som hash, bunden till e-postadressen. `password_hash` får vara NULL.
**Läs:** [[ADR-0011 Autentisering]]
**Klart när:** inloggning fungerar utan lösenord; token kan inte återanvändas och går ut.
**Beror på:** 4

### 6. TOTP-tvåfaktor
Aktivering, verifiering, återställningskoder. Hemligheten krypterad i databasen.
**Läs:** [[ADR-0011 Autentisering]]
**Beror på:** 4

### 7. Rate limiting och felkodsformat
Begränsa inloggning och magic link per adress och per IP. Etablera det **maskinläsbara felformatet** som hela API:et ska använda.
**Läs:** [[ADR-0013 Språk och i18n]]
**Klart när:** varje felsvar har en stabil kod plus data, aldrig en färdig mening. Detta format används sedan överallt.
**Beror på:** 4
