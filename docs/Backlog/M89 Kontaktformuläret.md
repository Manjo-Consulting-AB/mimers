# M89 · Kontaktformuläret

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-24 och nedbruten samma dag med Tonys svar. `/contact` finns inte i dag, men `/about` och `/privacy` länkar dit. Efter milstolpen finns sidan, och ett meddelande därifrån når `support@mimers.app` som ett mejl personalen kan svara på. Besluten står i [[ADR-0096 Kontaktformuläret]]:

- **Inget sparas i databasen.** Meddelandet skickas med mejl genom Mailgun, och konversationen förs i brevlådan hos inleed. Undantaget är kategorin Data & Privacy, som även öppnar ett integritetsärende.
- **Inget kvitto** går till avsändaren, och personens adress står bara i `Reply-To`.
- **Skickas i begäran, inte i kön**, så att ett misslyckande syns för den som skrev.

**Förutsättning utanför koden:** `mg.mimers.app` och `mg-staging.mimers.app` saknade SPF, DKIM och MX i DNS 2026-11-24 (ADR-0096 § Konsekvenser). Tony lägger in Mailguns poster hos inleed. Issuerna kan byggas och testas utan dem, men acceptansprovet på staging i 491 kan inte göras förrän de finns.

**Ordningen:** 491 först. 492 och 493 bygger på den, och 492 dessutom på `OpenPrivacyRequest` ur 481 i [[M87 Integritetsbegäranden]]. Issuerna är skapade i GitHub (#1655–#1657) och sammanfattade här. GitHub-issuerna är bindande.

---

### 491. Kontaktsidan och mejlet till supporten

GitHub #1655. Efteråt finns `/contact` för gäst och inloggad. Den som skickar formuläret ser en bekräftelse, och `support@mimers.app` får ett mejl att svara på.

`GET /contact` ritar sidan `Contact` med kategorierna ur en lista i koden, i ADR-0096 § 2:s ordning. Är personen inloggad får sidan även hennes namn och adress, som visas låsta. `POST /contact` tar emot `category`, `message` (högst 5 000 tecken) och, bara för en gäst, `name` och `email`. Valideringsfelen följer `AGENTS.md` § Felformat i API:et, med en kod per fält. För en inloggad läses namn och adress från `user` och aldrig från begäran.

Mejlet är en `Mailable` som skickas synkront till `config('support.address')` (förval `support@mimers.app`, i en ny `config/support.php`). Avsändaren är `MAIL_FROM_ADDRESS`, `Reply-To` är personens adress, och ämnet och kroppen är enligt ADR-0096 § 3: vanlig text, ingen IP-adress, prefixet `[<miljö>]` utanför produktion och märkningen *Not signed in — identity not verified* för en gäst. Ett undantag från Mailer ger ett felsvar som sidan visar, med meddelandet kvar i fälten. Undantaget loggas på felnivå utan meddelandets text. Inget mejl går till avsändaren.

Skyddet följer § 6: ett dolt honeypot-fält och ett signerat fält med tidpunkten då sidan ritades. En träff, eller ett formulär som skickas tidigare än tre sekunder efter att sidan ritades, ger samma bekräftelse som ett riktigt ärende men skickar inget. Begränsaren `contact` i `AppServiceProvider` ger fem i timmen per IP för en gäst och tio i timmen per person för en inloggad. Kategorierna bor i `App\Support\Contact\ContactCategory` och mejlet heter `ContactMessageMail`. Rutterna ligger utanför `auth` och `guest`. Länken *Contact* läggs till i sidfoten i `AppLayout.vue` och på inloggningssidan, bredvid de juridiska länkarna. Etiketterna står i `lang/en/`, och `Contact.vue` har ingen engelsk copy.

**Läs:** [[ADR-0096 Kontaktformuläret]], `AGENTS.md` § Felformat i API:et, blocket för informationssidorna och för `help-feedback` i `routes/web.php`, begränsaren `help-feedback` i `app/Providers/AppServiceProvider.php`, `resources/js/pages/Info.vue`, länkarna `auth.landing.links.*` i `resources/js/layouts/AppLayout.vue`, `app/Mail/NotificationMail.php` som förlaga för en `Mailable`

**Klart när:** `GET /contact` ger `Contact` med de tio kategorinycklarna i ordning, med `200` för gäst och inloggad, och för en inloggad dessutom namn och adress från kontot. Ett giltigt `POST` från en gäst skickar exakt ett mejl till `support@mimers.app` med `From` = `MAIL_FROM_ADDRESS`, `Reply-To` = den angivna adressen, ämnet med kategorins etikett och namnet, och kroppen med meddelandet och märkningen *not verified*, men utan IP-adress. Ett `POST` från en inloggad bär hennes namn, adress och ULID:er även när begäran innehåller ett annat `name` eller `email`. Ingen rad skrivs i någon tabell. Mejlet skickas inte genom kön (`Mail::fake()` ser det som skickat, inte köat), och inget mejl går till avsändaren. Ett ifyllt honeypot-fält och ett formulär som skickas efter mindre än tre sekunder ger bekräftelsen utan mejl. Ett manipulerat tidsfält räknas som en träff. Saknad kategori, okänd kategori, tomt meddelande, för långt meddelande och, för en gäst, saknat namn eller ogiltig adress ger `422` med en kod per fält. Den sjätte inskickningen inom en timme från samma IP ger `429` för en gäst. Ett fel från Mailer ger ett felsvar och en loggrad på felnivå utan meddelandets text. Ämnet har `[staging]` när miljön är `staging`. Länken *Contact* finns i sidfoten och på inloggningssidan. Bygget och sviten är gröna. **Manuellt på staging, när DNS-förutsättningen är uppfylld:** ett testmejl når brevlådan med `spf=pass`, `dkim=pass` och `dmarc=pass` i `Authentication-Results`.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none

**Beror på:** —

### 492. Data & Privacy öppnar ett integritetsärende

GitHub #1656. Efteråt blir ett meddelande i kategorin Data & Privacy dessutom ett ärende i `privacy_request`, och mejlet till supporten bär ärendets referens.

När `category` är `privacy` anropar kontrollern `OpenPrivacyRequest` innan mejlet skickas. Typen är `other`, och anropet får namnet, adressen och meddelandet som beskrivning, med `user_id` när personen är inloggad. Ämnet får referensen, `[Contact] Data & Privacy — <namn> — PR-XXXXXXXX`, och kroppen en rad med referensen. Ärendet och mejlet hör ihop: misslyckas mejlet står ärendet kvar, eftersom det är beviset på att begäran togs emot. Felsvaret till personen är detsamma som i 491. Ingen annan kategori skapar ett ärende. Honeypot- och tidsträffar skapar inget ärende.

**Läs:** [[ADR-0096 Kontaktformuläret]] § 7, [[ADR-0095 Integritetsbegäranden]] § 2 och § 3, `app/Actions/Privacy/OpenPrivacyRequest.php` som 481 lämnade den, kontrollern och testet från 491

**Klart när:** ett `POST` i kategorin `privacy` skapar exakt en `privacy_request` med typen `other`, statusen `received`, namnet, adressen och meddelandet, och med `user_id` när personen är inloggad men utan det för en gäst. Mejlets ämne och kropp bär ärendets `PR-`-referens. Ett fel från Mailer lämnar ärendet kvar och ger samma felsvar som i 491. Kategorin `general` skapar inget ärende. En honeypot-träff i kategorin `privacy` skapar varken ärende eller mejl. Bygget och sviten är gröna.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none

**Beror på:** 491, 481

### 493. Supportadressen spärras aldrig

GitHub #1657. Efteråt kan en studs eller en spamanmälan för `support@mimers.app` inte stänga formuläret. Den syns i stället som ett fel i loggen.

`MailgunWebhookController` jämför `recipient` med `config('support.address')` utan hänsyn till skiftläge. Vid träff skrivs ingen rad i `email_suppression`. I stället skrivs `mailgun.support_address_failed` på felnivå med händelsens typ och allvarlighetsgrad, så att den når Sentry ([[ADR-0079 Övervakning]]). Signaturprövningen och svaret är oförändrade: `200` med tom kropp. Andra adresser hanteras som förut.

**Läs:** [[ADR-0096 Kontaktformuläret]] § 8, `app/Http/Controllers/Api/MailgunWebhookController.php`, [[Notiser]] § email_suppression, testet för webhooken under `tests/`

**Klart när:** en signerad `permanent_fail`, `complained` eller `unsubscribed` för `support@mimers.app`, och för `Support@Mimers.app`, skriver ingen rad i `email_suppression`, loggar `mailgun.support_address_failed` på felnivå och svarar `200`. Samma händelser för en annan adress skriver raden som förut. En osignerad begäran ger fortfarande `401`. Bygget och sviten är gröna.

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none

**Beror på:** 491
