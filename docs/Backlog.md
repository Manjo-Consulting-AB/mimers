# Backlog

Issues i beroendeordning. Tillbaka till [[00 Index]].

**Till dig som ska implementera:** läs din issue, läs de dokument som står under **Läs**, och inget mer. Dokumentationen är uppdelad just för att du inte ska behöva gå igenom allt för att ändra en detalj.

**Till den som skapar issues i GitHub:** en rubrik per issue nedan, beskrivningen som brödtext, acceptanskriterierna som checklista. Beroenden anges med issue-nummer.

Alla issues förutsätter konventionerna i [[Datamodell – översikt]] — ULID utåt, soft delete, UTC, utf8mb4, inga ENUM, inga flyttal för pengar.

---

## M0 · Fundament

### 0. Repo, miljöer och deploy-kedja
Repot är `Manjo-Consulting-AB/mimers`. Branch protection på `main`, `AGENTS.md` med konventionerna, PR-mall med läslista, och dokumentationen inflyttad under `docs/`. Två miljöer hos inleed med varsin databas och varsin minutcron. Workflow-filerna och deploy-skriptet enligt [[Pipeline]].

**Gjort 2026-08-23:** dokumentationen ligger under `docs/`, `AGENTS.md`, PR-mallen, de tre workflow-filerna och `deploy/deploy.sh` finns i repot. På servern: katalogträden `~/mimers` och `~/mimers-staging`, document root ompåkad via symlänk, minutcron per miljö. Frågelistan i [[ADR-0018 Utvecklingsprocess och deploy]] § Verifierat hos inleed är alltså avklarad.

**Återstår:** branch protection, GitHub Environments med `DEPLOY_*`-secrets och required reviewer, `shared/.env` per miljö, DNS och sites för `staging.mimers.app` och `files.mimers.app`, samt själva genomlöpet med en tom Laravel.
**Läs:** [[Pipeline]], [[ADR-0018 Utvecklingsprocess och deploy]]
**Klart när:** en tom Laravel har gått hela vägen — grön PR, automatisk deploy till staging, release `v0.0.1` till produktion efter godkännande — och en rollback har provats genom att flippa symlänken tillbaka.

### 1. Sätt upp Laravel-projektet
Laravel på PHP 8.4, MariaDB 10.6. Kodstandard (Pint), statisk analys (PHPStan), testuppsättning (Pest eller PHPUnit). CI som kör lint, analys och tester på varje PR. Inertia, Vue 3, Tailwind och Vite installeras i samma steg — frontenden bor i den här appen, inte i ett eget projekt.

`public/.htaccess` ska innehålla `php_value`-raderna för uppladdningsgränser ur [[Pipeline]] § Uppladdningsgränser. Serverns standard är 2 MB och räcker inte för en enda manual.
**Läs:** [[ADR-0001 Stack]], [[ADR-0021 Frontendteknik]]
**Klart när:** `composer test` och `composer lint` går grönt i CI på en tom kodbas, och `npm run build` producerar `public/build` som CI också kör.
**Beror på:** 0

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

---

## M1 · Kärnmodell

### 8. Container
CRUD. `account_id` som ägare, `kind` för presentation, `template_source_id` förberedd men oanvänd.
**Läs:** [[Konton och åtkomst]], [[ADR-0002 Konto äger container]]
**Beror på:** 3

### 9. Åtkomstmodell och behörighetspolicy
`container_access` med alla fyra formerna. **En** central policy — behörighetslogik får inte spridas i controllers.
**Läs:** [[Konton och åtkomst]] § Behörighetsregler, [[ADR-0003 Åtkomstmodell]]
**Klart när:** de fem reglerna i behörighetsavsnittet har varsitt test, inklusive att `write` inte får radera containern eller hantera åtkomster, och att `read_only`-konto nekas allt skrivande.
**Beror på:** 8

### 10. Inbjudningar
Inbjudan till e-postadress utan konto. Pending tills accepterad, avvisad eller utgången. Token lagras som hash.
**Läs:** [[Konton och åtkomst]], [[ADR-0003 Åtkomstmodell]]
**Klart när:** mottagaren måste skapa konto och verifiera e-post för att acceptera.
**Beror på:** 9

### 11. Kategorier
Hierarki per container. Djupbegränsning och **cykelkontroll vid flytt**.
**Läs:** [[Items och organisation]], [[ADR-0004 Fria taggar och kategorier]]
**Klart när:** en kategori inte kan få sin egen ättling som förälder.
**Beror på:** 8

### 12. Taggar
Platt lista per container, unikt namn per container.
**Läs:** [[Items och organisation]]
**Beror på:** 8

### 13. Item
CRUD med alla fält, högst en kategori, flera taggar. Index enligt dokumentet.
**Läs:** [[Items och organisation]]
**Beror på:** 11, 12

### 14. Relationer mellan items
`item_link` med parent/child/sibling. Relationen lagras **en gång** och motsatsen härleds. Sibling normaliseras till lägst id först. Cykelkontroll för parent/child.
**Läs:** [[Items och organisation]]
**Beror på:** 13

### 15. Sök och filtrering
Scout med databasdrivern. FULLTEXT-index, filtrering på tagg (flera med OCH), kategori inklusive underkategorier, fritext.
**Läs:** [[ADR-0012 Sök]], [[Items och organisation]] § Sök och filtrering
**Klart när:** ett test visar att sökning **aldrig** returnerar items från containers användaren saknar åtkomst till.
**Beror på:** 13

---

## M2 · Filer

### 16. Uppladdning med innehållshash
`stored_file` och `attachment`. Hash och MIME-typ bestäms **på servern**. Flödets sju steg enligt dokumentet.
**Läs:** [[Filer och lagring]], [[ADR-0006 Innehållsadresserad lagring]]
**Klart när:** ett test visar att en klientskickad hash ignoreras, och att samma innehåll uppladdat två gånger ger en `stored_file` men två `attachment`.
**Beror på:** 13

### 17. Referensräkning och fördröjd radering
Räknaren minskas när en attachment **lämnar papperskorgen**, inte vid soft delete. Fysisk radering tidigast 30 dagar efter att räknaren nått noll.
**Läs:** [[Filer och lagring]] § Radering, [[ADR-0008 Soft delete och papperskorg]]
**Beror på:** 16

### 18. Miniatyrer
Genereras vid uppladdning, inte vid visning. Räknas **inte** mot användarens kvot.
**Läs:** [[Filer och lagring]]
**Beror på:** 16

### 19. Säker filleverans
Leveransmetoden är beslutad i [[ADR-0019 Filleverans]]: intern omdirigering med `X-LiteSpeed-Location` mot en katalog under filsubdomänens webbrot, skyddad av en `.htaccess`-regel på `%{ORG_REQ_URI}`.

**Kontrollera först att LiteSpeed följer symlänkar** från webbroten in i `shared/storage`. Gör den inte det gäller beslutet fortfarande, men lagringslayouten måste ses över — stanna och flagga istället för att hitta på en egen lösning.

Därefter: egen origin för användarfiler, `Content-Disposition: attachment` som standard, behörighetskontroll före leverans. Symlänken och `.htaccess` läggs på plats av `deploy.sh`, inte för hand.
**Läs:** [[ADR-0019 Filleverans]], [[Filer och lagring]] § Säkerhet vid leverans, [[ADR-0007 Fillagring hos inleed]]
**Klart när:** tre test är gröna mot en **utrullad** staging, inte mot en handbyggd katalog — ett direkt anrop mot `/_protected/` ger 403, samma fil levereras via nedladdningsrouten efter behörighetskontroll, och en uppladdad SVG kan inte köra skript i applikationens origin. Dessutom: en nedladdning av en stor fil håller inte en PHP-process upptagen under överföringen.
**Beror på:** 16

### 20. Papperskorg
API för att lista och återställa raderat innehåll, med retention.
**Läs:** [[ADR-0008 Soft delete och papperskorg]]
**Beror på:** 17

---

## M3 · Uppgifter

### 21. Scheman
`schedule`, noll eller flera per item. Båda återkommandetyperna, `lead_days`.
**Läs:** [[Scheman och uppgifter]], [[ADR-0005 Schema och förekomst]]
**Beror på:** 13

### 22. Förekomster och avslut
`schedule_occurrence`. Avslutsflödets fem steg i **en transaktion**. `overdue` härleds, lagras aldrig.
**Läs:** [[Scheman och uppgifter]] § Flödet när en uppgift markeras klar
**Klart när:** test visar att `fixed` räknar från kalendern och `interval` från `completed_at`, och att exakt en öppen förekomst finns per aktivt schema.
**Beror på:** 21

### 23. Beroenden mellan uppgifter
Beroenden på förekomstnivå, ärvda från schemanivå när en ny förekomst skapas. Cykelkontroll på båda nivåerna.
**Läs:** [[Scheman och uppgifter]] § occurrence_dependency
**Klart när:** en förekomst med öppna beroenden inte kan stängas, och en cykel avvisas med felkod.
**Beror på:** 22

### 24. Todo-listan
Endpoint som läser förekomster över alla åtkomliga containers, filtrerad enligt dokumentet.
**Läs:** [[Scheman och uppgifter]] § Todo-listan
**Beror på:** 23

---

## M4 · Planer och kvoter

### 25. Planer och rättigheter
`plan` med gränser i JSON, `subscription`. Gränserna för free och pro enligt dokumentet. Inget betalflöde.
**Läs:** [[Planer och kvoter]], [[ADR-0014 Prismodell]]
**Beror på:** 3

### 26. Förbrukningsräkning
`usage_counter`, uppdaterad **transaktionellt** vid uppladdning och radering. Nattligt avstämningsjobb som larmar vid avvikelse.
**Läs:** [[Planer och kvoter]] § usage_counter, [[Filer och lagring]] § Kvot kontra faktisk lagring
**Klart när:** bytena belastar `attachment.billed_account_id` — det uppladdande kontot — och test visar att en varvsuppladdning inte fyller kundens gratiskvot.
**Beror på:** 16, 25

### 27. Kontrollpunkter för rättigheter
Kontroll vid skapande av container, uppladdning (styck och totalt), inbjudan, webhook, PDF-pärm och ägarbyte. Nekande ger felkod med vilken gräns som slog i.
**Läs:** [[Planer och kvoter]] § Kontrollpunkter
**Klart när:** kontrollerna sitter server-side och kan inte kringgås av en egen klient.
**Beror på:** 26

### 28. Nedgradering
Femstegsförloppet: read_only, användarens urval sorterat på storlek, tre månaders frist, automatisk radering av **bilagor nyast först**, återgång till active.
**Läs:** [[Planer och kvoter]] § Nedgradering, [[ADR-0009 Kvoter och livscykel]]
**Klart när:** test visar att **inga items raderas** — bara bilagor.
**Beror på:** 27

### 29. Kontolivscykel
12 / 15 / 18 månader. De tre undantagen: radering går via containern med ägarskap erbjudet aktiva medlemmar, aktiv prenumeration undantar, aktivitet räknas som API-anrop.
**Läs:** [[Planer och kvoter]] § Kontolivscykel, [[ADR-0009 Kvoter och livscykel]]
**Klart när:** ett konto med en delad container som har aktiva medlemmar kan **inte** raderas utan att ägarskapet först erbjudits.
**Beror på:** 27

---

## M5 · Notiser

### 30. Notiskärna
`notification` och `notification_delivery`. Outbox, `dedupe_key`, unik `(notification_id, channel)`. `payload` innehåller data, aldrig text.
**Läs:** [[Notiser]], [[ADR-0010 Notisarkitektur]]
**Klart när:** samma logiska händelse skapad sextio gånger av minutcronen ger **en** leverans.
**Beror på:** 3

### 31. Preferenser och tysta timmar
`notification_preference` per typ och kanal. `available_at` sätts utifrån mottagarens tysta timmar och tidszon.
**Läs:** [[Notiser]] § Tysta timmar och tidszon
**Beror på:** 30

### 32. E-post via Postmark
Mallar på svenska och engelska, valda från mottagarens locale. SPF, DKIM, DMARC på `mimers.app`. Avregistreringslänk och inställningssida.
**Läs:** [[Notiser]] § E-post, [[ADR-0013 Språk och i18n]]
**Beror på:** 31

### 33. Studshantering
`email_suppression` matad av Postmarks webhook. Undertryckta adresser ger `suppressed` istället för leveransförsök, och kopplas till livscykeln.
**Läs:** [[Notiser]] § email_suppression
**Beror på:** 32, 29

### 34. Notisgeneratorer
Jobben i tabellen: uppgiftsnotiser var 15:e minut, utlåning dagligen, leverans varje minut, kvotvarningar dagligen, livscykel dagligen.
**Läs:** [[Notiser]] § Kön
**Beror på:** 24, 30

### 35. Veckosammanfattning
Standard för uppgiftspåminnelser. Samlar allt markerat `digest`.
**Läs:** [[Notiser]] § notification_preference
**Beror på:** 34

### 36. ICS-kalenderfeed
Hemlig prenumerationslänk per container och användare, återkallbar. Visar bara det användaren får se. Textinnehåll enligt locale.
**Läs:** [[Notiser]] § ICS-kalenderfeed
**Klart när:** feeden går att prenumerera på i Apple Calendar och Google Calendar, och en återkallad token slutar fungera.
**Beror på:** 24

### 37. Webhooks
`webhook_endpoint`, HMAC-SHA256-signatur med tidsstämpel, omförsök med backoff, automatisk inaktivering efter upprepade fel.
**Läs:** [[Notiser]] § Webhooks
**Klart när:** **SSRF-validering** avvisar privata IP-intervall, localhost och molnmetadata vid både registrering och varje leverans.
**Beror på:** 30, 27

---

## M6 · Resten av MVP

### 38. Utlåning
`loan` med låntagare, förfallodatum och återlämning. Påminnelser via notiskärnan **till den som lånat ut, aldrig till låntagaren** — `borrower_email` är en kontaktuppgift i vyn, inte en mottagaradress.
**Läs:** [[Items och organisation]] § loan, [[ADR-0017 Missbruksvektorer]] § 7
**Klart när:** ett test visar att en förfallen utlåning genererar en notis till utlånaren och noll utskick till `borrower_email`.
**Beror på:** 13, 34

### 39. Ägarbyte
`ownership_transfer`. Accept i **en transaktion** enligt dokumentet, inklusive plankontroll hos mottagaren, flytt av förbrukning, undantagna items och kvarhållen åtkomst.
**Läs:** [[Konton och åtkomst]] § ownership_transfer, [[Planer och kvoter]] § Ägarbyte och kvot
**Klart när:** ett gratiskonto **inte** kan ta emot en container som spränger dess kvot, och mottagaren får tolv månader Pro.
**Beror på:** 27, 9

### 40. Revisionslogg
`audit_log` för ägarbyten, återkallade åtkomster och andra känsliga händelser.
**Läs:** [[Konton och åtkomst]] § audit_log
**Beror på:** 39

### 41. Export
Fullständig export av en container med metadata och filer. **Fri på alla nivåer.**
**Läs:** [[ADR-0014 Prismodell]], [[Planer och kvoter]]
**Klart när:** exporten fungerar även för ett `read_only`-konto — den behövs som mest då.
**Beror på:** 16

---

## M7 · Drift

### 42. Backupscript
Daglig `mariadb-dump --single-transaction` till egen server, veckovis filsynk **utan `--delete`**, månatlig arkivkopia. Egen server **hämtar** över SSH; inga backup-credentials på produktionsservern. Krypterat, restic rekommenderat.
**Läs:** [[ADR-0015 Backup]]
**Beror på:** 1

### 43. Dead man's switch
Backupjobben pingar efter lyckad körning; utebliven ping ger larm. Samma för notiscronen.
**Läs:** [[ADR-0015 Backup]], [[Notiser]] § Kön
**Beror på:** 42

### 44. Återläsningsrunbook
Steg för steg, skriven för någon under press. Plus ett kvartalsvis återkommande återläsningstest.
**Läs:** [[ADR-0015 Backup]]
**Beror på:** 42

---

## M8 · Kostnadsregistrering

Tillagd 2026-08-04, efter att planeringsfasen avslutats. Se [[ADR-0016 Kostnadsregistrering]]. **Ligger inte i MVP-listan i [[Översikt]] § Avgränsning** — flytta in den dit om den ska med i första släppet.

### 45. Kostnadsrader
`cost_entry` med CRUD. `item_id` obligatorisk, `container_id` denormaliserad från itemet. Belopp i minsta valutaenhet, negativa belopp tillåtna. Inmatning accepterar både komma och punkt som decimaltecken och **avvisar** fler decimaler än valutan tillåter istället för att avrunda tyst. Leverantör trimmas vid sparning. Autocomplete-endpoint som ger distinkta leverantörer inom containern, sorterade på användningsfrekvens.
**Läs:** [[Items och organisation]] § cost_entry, [[ADR-0016 Kostnadsregistrering]]
**Klart när:** en kostnad inte kan skapas utan item; ett soft-raderat item tar med sig sina kostnader till papperskorgen och tillbaka vid återställning; `read_only`-konto nekas skrivning.
**Beror på:** 13

### 46. Kostnadsrapport
Gruppering per kategori inklusive underkategorier, per tagg med flera kombinerade med OCH, per item, per leverantör och per tidsperiod på `incurred_on`. Summering **per valuta**, ingen omräkning. Avrundning först vid presentation.
**Läs:** [[Items och organisation]] § Kostnadsrapporter, [[Planer och kvoter]] § Kontrollpunkter
**Klart när:** ett gratiskonto nekas rapportendpointen med felkod men kan fortfarande skapa och läsa sina egna kostnadsrader, och ett test visar att rapporten aldrig summerar rader från containers användaren saknar åtkomst till.
**Beror på:** 45, 27

### 47. Kostnadskrok vid avbockad uppgift
När en förekomst stängs erbjuder svaret att registrera en kostnad på förekomstens item, med `incurred_on` förifyllt till `completed_at`. **Ingen relation lagras** mellan kostnad och förekomst.
**Läs:** [[Items och organisation]] § cost_entry, [[Scheman och uppgifter]] § Flödet när en uppgift markeras klar
**Klart när:** kostnaden hamnar på itemet och inte på förekomsten, och avbockningen fungerar oförändrat om användaren hoppar över kostnaden.
**Beror på:** 45, 22

---

## M9 · Missbruksskydd

Tillagd 2026-08-04. Se [[ADR-0017 Missbruksvektorer]]. Principen där är att mäta före att spärra — de två första issuena är undantagen, eftersom de är billiga nu och obehagliga att införa retroaktivt.

### 48. Tak för utestående inbjudningar
Ett konto får ha högst N inbjudningar i `pending` samtidigt. Nekande ger felkod med gällande gräns, inte en färdig mening. Taket ligger i `plan.limits` som alla andra gränser, inte som konstant i koden.
**Läs:** [[Konton och åtkomst]] § invitation, [[ADR-0017 Missbruksvektorer]] § 5
**Klart när:** ett konto som fyllt taket nekas fler inbjudningar tills några accepterats, avvisats eller löpt ut — och en accepterad inbjudan frigör en plats.
**Beror på:** 10, 7

### 49. Ägarbytesbonusen en gång per mottagande konto
De tolv månaderna Pro ges vid **första** mottagna ägarbytet, inte vid varje. Kontrollen sitter i acceptflödets transaktion i issue 39, inte som ett separat jobb efteråt.
**Läs:** [[Konton och åtkomst]] § ownership_transfer, [[ADR-0017 Missbruksvektorer]] § 4
**Klart när:** ett konto som redan konsumerat bonusen tar emot en andra container utan att få ytterligare Pro-tid, och själva ägarbytet går igenom oförändrat.
**Beror på:** 39

### 50. Nattlig missbruksrapport
**Inte MVP** — bygg efter issue 26, den läser samma räknare. Fyra tal per vecka: nya gratiskonton, andel konton som aldrig laddat upp något, lagring per gratiskonto, utskickade mejl per konto. Plus de fyra listningarna i ADR:n: konton per registrerings-IP, konton med `managed`-åtkomst till fler än fem containers, containers skapade i kluster från samma IP, `stored_file` med hög `reference_count` över orelaterade konton.
Rapporten **larmar inte och spärrar ingenting** — den är underlag för att sätta trösklar som idag är gissningar.
**Läs:** [[ADR-0017 Missbruksvektorer]], [[Planer och kvoter]] § usage_counter
**Klart när:** rapporten kan köras på produktionsdata utan att skriva något, och registrerings-IP har en gallringsfrist och står i registerförteckningen.
**Beror på:** 26, 29

---

## M10 · Webbfrontend

Tillagd 2026-08-22. Se [[ADR-0021 Frontendteknik]] — Inertia med Vue 3 och Tailwind i samma Laravel-app, sessionsguard för webben, ingen affärslogik i Inertia-controllers.

**Körs parallellt med M1–M3, inte efter M9.** Numreringen är sekventiell men beroendena är det som gäller: varje issue nedan väntar bara på sin egen backenddel. Att bygga vyerna löpande är det enda sättet att upptäcka att en endpoint saknar ett fält innan hela API:et är fryst.

Gemensamt för alla issues i milstolpen: text formuleras på servern ur `lang/`, aldrig i JavaScript. Behörighetskontroller görs i policies, aldrig genom att dölja en knapp.

### 51. Frontendskal
Inertia-rotvy, layoutkomponent, navigation, Tailwind-uppsättning, felhanterings- och flashmeddelandemönster. Delade props: inloggad användare, kontots plan, aktiv container. Ett dokumenterat mönster för formulär med validering från FormRequests, och för att rendera props **ur samma API Resource-klasser som `/api`** — båda mönstren används av alla efterföljande issues.
**Läs:** [[ADR-0021 Frontendteknik]]
**Klart när:** en skyddad exempelvy renderar, ett formulär visar valideringsfel från servern, och `php artisan view:cache` fungerar med Inertias rotvy.
**Beror på:** 1

### 52. Språk i frontenden
Svenska och engelska. Språket kommer från användarens `locale`, i andra hand kontots — aldrig från `Accept-Language`. Strängar levereras som delade props ur `lang/`.
**Läs:** [[ADR-0013 Språk och i18n]], [[ADR-0021 Frontendteknik]]
**Klart när:** en engelsktalande medlem i ett svenskt konto får engelska vyer, och ingen användarvänd sträng är hårdkodad i en Vue-komponent.
**Beror på:** 51, 3

### 53. Inloggnings- och kontovyer
Registrering, inloggning, utloggning, e-postverifiering, magic link, TOTP-aktivering och återställningskoder. Kontoinställningar: locale, timezone, unit_system.
**Läs:** [[ADR-0011 Autentisering]], [[Konton och åtkomst]]
**Klart när:** hela vägen in fungerar i webbläsaren för alla tre inloggningssätten, och rate limiting ger ett begripligt meddelande i stället för ett tomt fel.
**Beror på:** 52, 4, 5, 6

### 54. Containervyer
Lista, skapa, redigera, välja aktiv container. `kind` styr presentation, inte logik.
**Läs:** [[Konton och åtkomst]], [[ADR-0002 Konto äger container]]
**Beror på:** 53, 8

### 55. Delning och inbjudningar
Bjuda in med R/RW, se och återkalla utestående inbjudningar, acceptflödet för mottagaren. Åtkomstformerna presenteras med sina faktiska konsekvenser — ett varv som får `managed` ska se att det inte äger pärmen.
**Läs:** [[ADR-0003 Åtkomstmodell]], [[Konton och åtkomst]] § invitation
**Beror på:** 54, 9, 10

### 56. Kategorier och taggar
CRUD för båda. **Färdiga kategoriuppsättningar** per språk och containertyp bor här, som frontenddata — API:et får aldrig veta vad orden betyder.
**Läs:** [[ADR-0004 Fria taggar och kategorier]], [[Items och organisation]]
**Klart när:** ett nyskapat konto erbjuds en uppsättning på sitt språk vid registrering och kan tacka nej utan att fastna.
**Beror på:** 54, 11, 12

### 57. Itemvyer
Lista med miniatyrer, detaljvy, skapa och redigera. Kategori, taggar, fritext.
**Läs:** [[Items och organisation]]
**Beror på:** 56, 13

### 58. Relationer mellan items
Koppla ihop items och navigera relationerna från detaljvyn.
**Läs:** [[Items och organisation]] § relationer
**Beror på:** 57, 14

### 59. Sök och filter
Fritextsök plus filtrering på kategori, tagg och container. Tomt resultat säger vad som filtrerades bort.
**Läs:** [[ADR-0012 Sök]], [[Items och organisation]]
**Beror på:** 57, 15

### 60. Uppladdning
Drag-drop, flera filer samtidigt, framdrift per fil, miniatyrer när de finns. Kvotfel visas som gräns och värde, inte som ett rått felmeddelande.
**Läs:** [[Filer och lagring]], [[ADR-0006 Innehållsadresserad lagring]]
**Klart när:** en avbruten uppladdning lämnar inget halvt tillstånd i vyn, och en kvotöverskridning förklaras med vilken gräns som slog i.
**Beror på:** 57, 16, 18

### 61. Filvisning och nedladdning
Visning av bilder och PDF:er, nedladdningslänkar mot filoriginet.
**Läs:** [[ADR-0019 Filleverans]]
**Klart när:** användarfiler serveras från `files.mimers.app` och inget innehåll därifrån kan köra skript i appens origin.
**Beror på:** 60, 19

### 62. Papperskorg
Lista raderat innehåll, återställ, se hur lång tid som återstår.
**Läs:** [[ADR-0008 Soft delete och papperskorg]]
**Beror på:** 57, 20

### 63. Scheman och uppgifter
Skapa scheman på item, se förekomster, bocka av, hantera beroenden. Försenat visas som härlett tillstånd.
**Läs:** [[Scheman och uppgifter]], [[ADR-0005 Schema och förekomst]]
**Beror på:** 57, 21, 22, 23

### 64. Todo-vyn
Startsidan efter inloggning: förekomster över alla åtkomliga containers, filtrerad enligt dokumentet.
**Läs:** [[Scheman och uppgifter]] § Todo-listan
**Beror på:** 63, 24

### 65. Notisinställningar
Kanalval, tysta timmar, ICS-länk att prenumerera på, webhooks för den som vill.
**Läs:** [[Notiser]], [[ADR-0010 Notisarkitektur]]
**Beror på:** 53, 31, 36, 37

### 66. Plan, förbrukning och gränser
Visa aktuell plan, förbrukning mot gränser, och vad som händer vid nedgradering **innan** den sker.
**Läs:** [[Planer och kvoter]], [[ADR-0009 Kvoter och livscykel]]
**Beror på:** 53, 25, 26, 27, 28

### 67. Utlåning, ägarbyte och export
De tre flödena i M6 som behöver en yta: markera utlånat med mottagare, initiera och acceptera ägarbyte, begära export.
**Läs:** [[Items och organisation]] § utlåning, [[Konton och åtkomst]] § ownership_transfer
**Beror på:** 57, 38, 39, 41

### 68. Mobilanpassning och tillgänglighetsgenomgång
En genomgång, inte en ny funktion: vyerna används på telefon i en hamn med dålig uppkoppling. Tangentbordsnavigering, fokusordning, kontrast, träffytor, och att långsamma svar syns som något annat än en död sida.
**Klart när:** de fem vanligaste flödena — logga in, hitta ett item, ladda upp en fil, bocka av en uppgift, dela en container — går att genomföra på en telefon och med enbart tangentbord.
**Beror på:** 64, 61

---

## Efter MVP

Ligger utanför scope men noterat så att ingen bygger sig i hörnet: PDF-pärmen (Pro), 2D-kartor, betalflöde via merchant of record, B2B-funktioner (flottvy, personalroller, white-label, containermallar), OCR-sökning, web push, Meilisearch, virusskanning, binlogs för point-in-time recovery.

Se [[Översikt]] § Avgränsning för motiveringar.
