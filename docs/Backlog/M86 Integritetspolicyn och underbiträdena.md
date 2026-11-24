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
