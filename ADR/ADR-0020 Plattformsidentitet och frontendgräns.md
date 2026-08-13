# ADR-0020 Plattformsidentitet och frontendgräns

**Status:** Antagen 2026-08-05 · Omskriven 2026-08-13 sedan multi-frontend-strategin övergavs · [[ADR-index]]

Ändrar [[ADR-0011 Autentisering]] på punkten om vilka domäner cookie-läget gäller. Resten av ADR-0011 står oförändrad.

## Kontext

Att flera frontends ska prata med samma backend var ett antagande sedan planeringsfasen. Tanken var ett varumärkeslöst API som flera separata sajter når över nätverket — en mot båtfolk, en mot husvagn, en mot hus — vilket skulle ge skräddarsydd SEO och egna säljargument per marknad utan att backend dupliceras.

**Det antagandet är övergivet.** Produkten är en produkt, inte en plattform med vertikaler, och den är fristående — den hör inte till någon befintlig sajt eller något befintligt varumärke. Skälet till multi-vertikal var marknadsföring; kostnaden var en serversideproxy per sajt, en site per domän hos leverantören, en plattformsidentitet utan varumärkesvärde vid sidan av varumärkena, och en inloggningsväg som passerar två system. Med en produkt bär inte den kostnaden.

Det API som behövs i förlängningen är ett annat: **mobilappar**. De är klienter utanför webbläsaren, på en helt annan sorts avstånd från servern än en webbsajt på samma domän.

Den tidigare versionen av den här ADR:n löste ett problem som därmed inte finns kvar. Cookies kan inte delas mellan två registrerbara domäner, så en frontend på en varumärkesdomän som anropar ett API på en annan domän gör ett cross-site-anrop — vilket kräver `SameSite=None` och därmed tredjepartscookies, som Safari blockerar utan undantag. Med frontend och API på samma origin uppstår situationen aldrig.

## Beslut

**Produkten bor på en egen domän med frontend och API på samma origin.** Ingen proxy, inga varumärkessajter, ingen separat kärndomän vid sidan av produkten, ingen plattformstillhörighet. Domännamnet är ännu inte valt; se öppna frågor nedan.

**Sessionscookien är förstaparts utan konstruktioner.** Cookie-läget i [[ADR-0011 Autentisering]] gäller därmed rakt av — samma origin är det enklaste fall det läget är byggt för.

**API:et förblir en klientneutral kontrakt-yta.** Det är den mobilapparna kopplar på när de byggs. Personal access tokens enligt [[ADR-0011 Autentisering]] är deras väg in, och regeln i [[ADR-0013 Språk och i18n]] om maskinläsbara felkoder gäller hela API:et.

**Kontot är gemensamt och varumärkeslöst.** Ingen kolumn registrerar var en användare kom in. Behövs den siffran för marknadsföring hämtas den ur webbanalys, inte ur domänmodellen.

**Avsändaren för notiser ligger på produktdomänen.** Användarfiler har fortfarande en egen origin, av säkerhetsskäl som inte har med den här ADR:n att göra.

## Motivering

Cross-site-problemet upphör att existera när frontend och API delar origin. Den enklaste lösningen blev tillgänglig i samma stund som kravet som uteslöt den — flera registrerbara varumärkesdomäner — togs bort. Ingen proxy behöver byggas, granskas eller hållas tunn, och ingen inloggningsväg passerar två system.

Att API:et ändå hålls fritt från användarvänd text är inte längre ett multi-frontend-argument utan ett mobilapp-argument. En app kan inte ärva serverns språkval: den har sin egen lokalisering, sin egen utgivningstakt och en installerad bas som ligger flera versioner efter servern. Returnerar API:et färdiga meningar blir appen låst vid backendens språk, och den regeln måste finnas innan appen byggs — inte skruvas in efteråt när endpointsen redan har konsumenter.

Att hålla API:et som en egen yta även när webbfrontenden ligger bredvid det kostar dessutom nästan ingenting nu, och är det som gör mobilappen till ett klientarbete i stället för ett backendprojekt.

## Konsekvenser

- **Sanctums `stateful`-konfiguration och CORS blir triviala.** En origin. Ingen uppräkning av betrodda värdar, ingen miljövariabel som växer med antalet sajter.
- **`TrustProxies` och `X-Forwarded-*` sätts ändå från början**, eftersom LiteSpeed står framför PHP hos inleed. Det är normal Laravel-uppsättning här, inte den proxyfleet-fälla den tidigare versionen av den här ADR:n varnade för.
- **Absoluta URL:er i utgående e-post genereras från appens egen konfiguration.** En magic link pekar tillbaka till samma domän användaren står på. Den parameter som skulle bära "vilken frontend anropet kom ifrån" utgår helt.
- **Användarfiler har fortfarande en egen origin**, `files.<domän>`. Kravet kommer från [[ADR-0019 Filleverans]] och [[ADR-0007 Fillagring hos inleed]] — en uppladdad SVG eller HTML-fil ska inte kunna köra skript i appens domän — och påverkas inte av att app och API samlas på ett namn.
- **Postmark sätts upp på produktdomänen.** SPF, DKIM och DMARC på ett nytt namn, vilket betyder att sändarryktet börjar om från noll och behöver mogna före lansering. Uppsättningen bör göras tidigt även om utskicken kommer sent. Se [[ADR-0010 Notisarkitektur]].
- **Antalet siter hos inleed blir tre:** staging, produktion och filoriginet. Se miljöfrågorna i [[ADR-0018 Utvecklingsprocess och deploy]].
- **Frontenden kan ligga i samma Laravel-app eller vara en separat SPA på samma origin.** Båda uppfyller beslutet. GitHub Pages är ute i båda fallen, eftersom origin ska delas med API:et.
- **Acceptanskriteriet för issue 4 i [[Backlog]] är omformulerat** — inloggning sker från samma origin, utan proxy.
- **B2B-integrationer och mobilappar talar direkt med API:et** med personal access tokens, precis som tidigare.

## Öppna frågor

**Produktens namn och domän är inte valda.** Beslutet här är att produkten är fristående och bor på ett eget namn — inte vilket namn det blir. Namnet bakas in i fil-URL:er, deploy-sökvägar och byggt e-postrykte och behöver därför kunna hållas i tio år. Bör avgöras före issue 0, eftersom miljöuppsättningen refererar till det. Se [[Tankar]].

**Frontendtekniken är inte bestämd.** SPA på samma origin eller serverrenderad Laravel. Valet avgör om felkodsregeln i [[ADR-0013 Språk och i18n]] gäller hela webben eller bara mobil-API:et, och om webbsessionen alls behöver Sanctums cookie-läge. Se [[Tankar]].

## Alternativ

**Flera varumärkessajter med en tunn proxy var.** Det den tidigare versionen av den här ADR:n beslutade. Varje vertikal fick en egen registrerbar domän med en serversideproxy framför API-anropen, så att sessionscookien blev förstaparts på varje domän medan kärnan förblev enda auth-auktoritet. Det löste cookie-problemet utan att röra säkerhetsavvägningen i [[ADR-0011 Autentisering]], och gav skräddarsydd SEO per marknad. Valdes bort — inte för att konstruktionen var fel, utan för att strategin den bar upp övergavs. Utan flera vertikaler betalar man en proxy, en extra site och en varumärkeslös kärndomän för ingenting.

**Bearer-tokens för webbfrontenden.** Undviker cookies helt och fungerar oavsett origin. Valdes bort — återinför exakt den XSS-exponering [[ADR-0011 Autentisering]] avvisade, och gör det för den klient som har minst behov av den. En token i `localStorage` är en token som kan stjälas.

**Frontend på en domän, API på en annan.** Skulle hålla API:et synligt som egen produkt. Valdes bort — det är cross-site-anropet igen, med tredjepartscookies och Safari-problemet, och köper ingenting när det bara finns en webbklient.
