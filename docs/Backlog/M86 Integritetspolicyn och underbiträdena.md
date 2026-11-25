# M86 · Integritetspolicyn och underbiträdena

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-24 ur feature request #1600, nedbruten samma dag med Tonys svar. `/privacy` går från platshållaren till den godkända integritetspolicyn, och `/subprocessors` blir en fjärde informationssida med registret över underbiträden. De juridiska sidorna länkas från inloggningssidan och sidfoten, och varje informationssida får en `<meta name="description">`. Ingen av issuerna rör `app/`; texterna bor i `lang/en/pages/` enligt formen från [[M85 Om Mimers]].

Den godkända texten stämmer inte i allt med koden. Avvikelserna, och hur de löstes:

- **Händelseloggen och säkerhetsloggen gallras olika** (`config/loggar.php`). Fristtabellen i § 12 får två rader i stället för en: *Audit log*, 12 månader efter `container.purged` eller `account.deleted`, och *Security log*, 12 månader efter radens `created_at`.
- **Konto och användare är ett till ett** (Tonys regel 2026-11-18). § 13 nämner därför inga delade konton. Innehåll i andras containrar följer [[ADR-0045 Radering av konto och person]].
- **Kontaktformuläret finns inte.** Länkarna går till `/contact`, som kommer senare — samma beslut som i M85. Ingen e-postadress och ingen postadress står på sidan.
- **Paddle finns inte i koden än.** § 7 publiceras ändå som den är skriven, eftersom Paddle är på väg in (Tony 2026-11-24). Att Paddle och inte Lemon Squeezy är merchant of record står i [[ADR-0014 Prismodell]] § Tillägg 2026-11-24.

Två frågor följer av det och står i [[Tankar]]: om `/privacy` ska publiceras i produktion innan `/contact` och betalflödet finns, och om 18-årsgränsen i policyn räcker utan en kontroll vid registreringen.

**Ordningen:** 479 och 480 bygger båda på 478 ([[M85 Om Mimers]]) och kan tas i valfri ordning. Issuerna är skapade i GitHub (#1619 och #1620) och sammanfattade här. GitHub-issuen är bindande.

**489 lades till 2026-11-24, efter att M87 tagit 481–488**, ur feature request #1646: användarvillkoren på `/terms`, samma form som 479. Numret ligger därför utanför milstolpens sammanhängande intervall.

**494 lades till 2026-11-25** av samma skäl: `/terms` och `/privacy` länkade till Mail Shields återbetalningspolicy på `manjo.me`, och Mimers ska ha en egen på `/refund-policy`.

---

### 479. Integritetspolicyn på /privacy

GitHub #1619. `lang/en/pages/privacy.md` bär policytexten ordagrant ur #1600 § *Approved Privacy Policy copy*, från `**Last updated: 23 November 2026**` till den avslutande `https://mimers.app`. Ingen ny kod: `InfoPage` och `Info.vue` från 478 ritar filen, och tabellerna ritas av `TableExtension` i `HelpMarkdown`. Fem ändringar mot den godkända texten, alla med skäl i koden eller i formen:

1. `### Privacy Policy` stryks, eftersom sidans `<h1>` kommer ur `info.privacy.title`, och de numrerade `####`-rubrikerna blir `##`.
2. De tre ställena som hänvisar till kontaktformuläret (§ 2, § 14 och § 18) länkar orden *contact form* till `/contact`.
3. `https://mimers.app/subprocessors` i § 10 blir en länk till `/subprocessors`.
4. Raden *Audit and security logs* i § 12 blir två rader, enligt ovan.
5. § 13 nämner inga delade konton. Båda meningarna kontrolleras mot ADR-0045; säger den något annat följer texten ADR:en och avvikelsen skrivs i PR:en.

Allt annat i texten är kontrollerat mot koden 2026-11-24. Inga fler fakta ändras.

**Läs:** [[ADR-0045 Radering av konto och person]] § Beslut, `resources/js/pages/Info.vue` och `app/Support/InfoPage.php` som 478 lämnade dem, `lang/en/pages/about.md`, `config/loggar.php`, #1600 § Approved Privacy Policy copy
**Klart när:** `GET /privacy` ger `Info` med de 18 numrerade rubrikerna som `<h2>` och raden *Last updated: 23 November 2026*; ändamålstabellen i § 4 och fristtabellen i § 12 ritas som `<table>`, och fristtabellen har en rad för *Audit log* och en för *Security log* men ingen *Audit and security logs*; `body` innehåller *Merchant of Record*, länken till Paddles köparvillkor, *Manage billing* och länken till återbetalningspolicyn, och Paddle står inte som underbiträde i § 10; `body` nämner aldrig *Resend* eller *Amazon SES*; *18 years old* och *Integritetsskyddsmyndigheten (IMY)* med länken till `https://www.imy.se` finns; varje *contact form* är en länk till `/contact`, och `body` har ingen `mailto:` och ingen e-postadress; länken till underbiträdena går till `/subprocessors`; `body` innehåller varken *shared accounts* eller *If an account is shared*; gäst och inloggad får `200`; `/about` och `/terms` ritar som förut; bygget och sviten är gröna.
**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none
**Beror på:** 478

### 480. Underbiträdena på /subprocessors och de juridiska länkarna

GitHub #1620. `/subprocessors` blir en fjärde informationssida i samma form som de andra tre: `'subprocessors'` i listan i `routes/web.php` (ruttnamnet `info.subprocessors`), en titelnyckel och `lang/en/pages/subprocessors.md`. Ingen ny `.vue`-sida. Tabellen har tre rader — Inleed (hosting, Sverige och Frankrike), Mailgun (e-post, EU) och Sentry (felövervakning, EU) — och stycket under tabellen säger att Paddle är merchant of record, inte underbiträde, med en länk till `/privacy`.

Därtill får varje informationssida en `description` i `ui.php` som `Info.vue` ritar som `<meta name="description">` med `head-key`. Inloggningssidans rad får *Subprocessors* efter *Terms of use*, och sidfoten i `AppLayout.vue` (`md:`) får en rad med länkar till `/about`, `/privacy`, `/terms` och `/subprocessors` ovanför copyrightraden. Mobilskalet ändras inte. Båda raderna läser `auth.landing.links.*`, så inga nycklar dubbleras.

**Läs:** `routes/web.php` (blocket för informationssidorna, issue 202), `resources/js/pages/Info.vue` och `app/Support/InfoPage.php` som 478 lämnade dem, sidfoten i `resources/js/layouts/AppLayout.vue`, raden med `auth.landing.links` i `resources/js/pages/Auth/Login.vue`, `tests/Feature/Frontend/InfosidorTest.php`
**Klart när:** `GET /subprocessors` ger `Info` med `page = subprocessors` och `200` för gäst och inloggad, och `route('info.subprocessors')` är `/subprocessors`; `body` ritar en `<table>` med raderna *Inleed*, *Mailgun* och *Sentry* och raden *Last updated: 23 November 2026*; tabellen har ingen rad för *Paddle*, `body` nämner aldrig *Resend* eller *Amazon SES*, och Paddle nämns under tabellen med en länk till `/privacy`; `info.subprocessors.title` är *Subprocessors* och varje informationssida har en `description`; `Info.vue` ritar `<meta name="description">` ur `info.<sida>.description`; inloggningssidans rad och sidfoten länkar till de fyra sidorna med texterna ur `auth.landing.links.*`; `/about`, `/privacy` och `/terms` ritar som förut; bygget och sviten är gröna.
**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none
**Beror på:** 478

### 489. Användarvillkoren på /terms

GitHub #1647. `lang/en/pages/terms.md` bär skribentens första utkast ur #1646 § *Full proposed page content*, ordagrant från `**Last updated: 24 November 2026**` till den avslutande raden `Sweden`. Ingen ny kod: `/terms` är redan en av de fyra informationssidorna, och `InfoPage` och `Info.vue` ritar filen. Designen — bredare behållare och 16 px brödtext — kommer av #1645 och ärvs av sig själv; issuen bygger inte på dagens `Info.vue`. Fem ändringar mot utkastet:

1. `# Terms of Use` stryks, eftersom sidans `<h1>` kommer ur `info.terms.title`; de arton numrerade `##`-rubrikerna står kvar.
2. *Contact form* länkas till `/contact` i § 2 och § 18, och den nakna adressraden i § 18 stryks. Ingen e-postadress eller postadress.
3. Prisändringsmeningen i § 7 blir *We will always notify you by email at least one month before your next renewal.* Skribentens mening lät som att en löpande prenumerations pris kan ändras, vilket [[ADR-0086 Pro, Pro+ och grundarpris]] § 5 inte medger (Tony 2026-11-24).
4. Raden *Corporate identity number* i `privacy.md` blir *Company registration number*, som i villkoren. Det är den enda ändringen i `privacy.md`.
5. Allt annat står som skrivet. § 8 och § 9 är kontrollerade mot [[Planer och kvoter]] § Nedgradering och § Kontolivscykel och stämmer.

Det som #1646 räknar upp utöver texten — `/contact`, vägen att rapportera olagligt innehåll och DSA före öppen beta, den juridiska granskningen och datumet *Last updated* — hör inte till issuen. Skribentens granskningsanteckningar får inte hamna på sidan. `/terms` ritar efteråt inte längre platshållaren, så de tre testerna som väntade `null` skrivs om.

**Läs:** #1646 § Full proposed page content, #1645, `lang/en/pages/privacy.md`, `tests/Feature/Frontend/IntegritetspolicyTest.php`, [[Planer och kvoter]] § Nedgradering och § Kontolivscykel, [[ADR-0086 Pro, Pro+ och grundarpris]] § 5
**Klart när:** `GET /terms` ger `Info` med `page = terms` och en `body` med de arton numrerade rubrikerna som `<h2>`, raden *Last updated: 24 November 2026* och ingen `<h1>`; `/terms` och `/privacy` säger *Company registration number: 556813-8464* och ingen säger *Corporate identity number*; § 7 har den nya meningen och inte *before the new price applies*; `body` innehåller *at least 18 years old*, *Merchant of Record*, *Malmö District Court*, planerna *Free*, *Pro* och *Pro+* samt länkarna till Paddles köparvillkor och återbetalningspolicyn; § 8:s borttagningsordning är en `<ol>` med fyra punkter; länkarna till `/privacy`, `/subprocessors` och `/contact` finns och varje *contact form* länkar till `/contact`; `body` har ingen `mailto:`, ingen e-postadress, ingen naken `https://mimers.app/contact` och varken *Legal review*, *Acceptance criteria*, *Product and policy decisions* eller *DSA*; gäst och inloggad får `200` med samma `body`; `InfosidorTest`, `OmsidanTest` och `UnderbitradenTest` väntar sig inte längre `null` för `/terms`; `/about` och `/subprocessors` ritar som förut, `/privacy` bortsett från orgnummerraden; bygget och sviten är gröna.
**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none
**Beror på:** #1645

### 494. Återbetalningspolicyn på /refund-policy

GitHub #1668. `/terms` och `/privacy` länkade till `https://manjo.me/refund-policy.php`. Den sidan är Mail Shields och nämner Lifetime-köp, sticky addresses, inbjudningsbonusar och `support@manjo.me`. Efteråt är `/refund-policy` en femte informationssida, `lang/en/pages/refund-policy.md`, med texten ordagrant ur #1668, och båda sidorna länkar dit. Tonys beslut 2026-11-25:

1. **Samma garanti som Mail Shield**: full återbetalning inom 30 dagar från varje betalning, utan skäl, för årsabonnemangen på Pro och Pro+, för varje förnyelse och för grundarpriset.
2. **Kontaktvägen är formuläret på `/contact`** med kategorin *Billing & Subscription*. Ingen e-postadress.
3. **Mail Shields allmänna text om hur återbetalningen går till står kvar.** Det gäller Paddle som *Merchant of Record*, kvittolänken och paddle.net. Det som bara gäller Mail Shield ersätts med Mimers regler: [[Planer och kvoter]] § Nedgradering, [[ADR-0086 Pro, Pro+ och grundarpris]] § 2 om grundarpriset och [[ADR-0045 Radering av konto och person]] om att en radering inte ger återbetalning.

Sidan nämner **Manage billing**, som `privacy.md` § 7 redan gör. Knappen finns inte än, och att den måste byggas när Paddle kopplas på står i [[Tankar]]. Länken *Refund policy* läggs till i sidfoten och på inloggningssidan.

**Läs:** #1668, `lang/en/pages/terms.md`, `lang/en/pages/privacy.md` § 7, blocket för informationssidorna i `routes/web.php`, `tests/Feature/Frontend/VillkorssidanTest.php`
**Klart när:** `GET /refund-policy` ger `Info` med `page = refund-policy`, fem `<h2>`, raden *Last updated: 25 November 2026* och ingen `<h1>`, med `200` för gäst och inloggad; `body` länkar till `/contact`, `/terms`, `/privacy` och paddle.net men har ingen `mailto:`, ingen e-postadress, inget `manjo.me` och varken *Lifetime*, *sticky*, *Regular* eller *Mail Shield*; `/terms` och `/privacy` länkar till `/refund-policy` och inte längre till `manjo.me`; länken finns i sidfoten och på inloggningssidan; bygget och sviten är gröna.
**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` none
**Beror på:** —
