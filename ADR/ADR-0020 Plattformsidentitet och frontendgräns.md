# ADR-0020 Plattformsidentitet och frontendgräns

**Status:** Antagen 2026-08-05 · [[ADR-index]]

Ändrar [[ADR-0011 Autentisering]] på punkten om vilka domäner cookie-läget gäller. Resten av ADR-0011 står oförändrad.

## Kontext

Att flera frontends ska prata med samma backend har varit ett antagande sedan planeringsfasen. [[ADR-0004 Fria taggar och kategorier]] lägger färdiga kategoriuppsättningar i frontend just för att API:et aldrig ska veta vad orden betyder, [[ADR-0013 Språk och i18n]] motiverar separata sajter med skräddarsydd SEO per marknad, och [[ADR-0011 Autentisering]] utgår från att de sajterna når API:et.

Antagandet var däremot aldrig preciserat. ADR-0011 skriver "subdomäner till yachting.earth", vilket i tysthet gör yachting.earth till både varumärke för båtfolk **och** plattformens hemvist. De två rollerna går isär så snart det finns en andra vertikal: en husvagnssajt kan inte rimligen bo under en domän som betyder segling.

Tre saker är samtidigt fastställda och begränsar lösningsrummet:

- **Ett konto ger tillgång till allt.** Registrerar någon sig på båtsajten kan hen lägga upp en husvagn. Var registreringen skedde är utan betydelse för behörigheter.
- **Notiser är varumärkesneutrala.** Samma innehåll och samma avsändare oavsett ingång.
- **Varumärkessajterna är egna registrerbara domäner**, inte subdomäner — yachting.earth mot båtfolk, caravans.earth mot husvagn och husbil.

Den sista punkten är den som spräcker ADR-0011. Cookies kan inte delas mellan två registrerbara domäner. En frontend på yachting.earth som anropar ett API på en annan domän gör ett cross-site-anrop, vilket kräver `SameSite=None` och därmed tredjepartscookies — som Safari blockerar utan undantag. Cookie-läget faller alltså i praktiken, inte bara i teorin.

## Beslut

**Plattformen får en egen, varumärkesneutral identitet, skild från alla varumärkessajter.** API, applikation och användarfiler bor där. Domännamnet är ännu inte valt; se öppen fråga nedan.

**Varje varumärkessajt är en egen origin med en tunn serversideproxy för API-anropen.** Webbläsaren pratar bara med yachting.earth respektive caravans.earth. Proxyn vidarebefordrar till kärnan. Sessionscookien blir därmed förstaparts på varje varumärkesdomän och cookie-läget i [[ADR-0011 Autentisering]] gäller oförändrat.

**Proxyn är tunn.** Den vidarebefordrar sessionscookie och CSRF-token orörda och håller ingen egen session. Kärnan förblir enda auth-auktoritet. En frontend innehåller ingen inloggningslogik utöver formulären.

**Kontot är gemensamt och varumärkeslöst.** Ingen kolumn registrerar vilken sajt en användare kom in genom. Behövs den siffran för marknadsföring hämtas den ur webbanalys, inte ur domänmodellen.

**Avsändaren för notiser och origin för användarfiler ligger på kärndomänen**, inte under något varumärke.

## Motivering

Alternativet att låta webbläsaren tala direkt med kärnans API kräver tredjepartscookies och är dött i Safari. Att i stället byta till bearer-tokens för förstapartsfrontends river upp den säkerhetsavvägning ADR-0011 gjorde med öppna ögon: en token i `localStorage` är en token som kan stjälas via XSS.

Proxyn löser det utan att något beslut behöver omprövas. Den kostar en serverdel per frontend — sajterna kan inte längre ligga på GitHub Pages — men det är en känd uppsättning hos inleed, samma mönster som filsubdomänen i [[ADR-0019 Filleverans]].

Att besökaren aldrig lämnar varumärkesdomänen är dessutom ett produktargument och inte bara ett tekniskt. Hade inloggningen legat på kärndomänen skulle användaren kastas till ett främmande namn i exakt det ögonblick förtroendet är som tunnast, mellan "det här verkar bra" och registrering. Hela poängen med separata sajter per vertikal går förlorad där.

Att proxyn hålls tunn är det som gör expansionen billig. En ny vertikal blir en ny sajt med samma proxy framför samma API — inget backend-ingrepp, ingen ny inloggningsväg att underhålla, ingen risk att två frontends hanterar sessioner olika.

## Konsekvenser

- **Kärnans lista över betrodda origins får en post per varumärkesdomän.** Sanctums `stateful`-konfiguration och CORS-inställningarna räknar upp proxyarnas värdar. Det är konfiguration, inte kod — en ny vertikal ändrar en miljövariabel.
- **Laravel måste konfigureras för att köras bakom proxy.** `TrustProxies` och `X-Forwarded-*` sätts från början. Utan det genererar ramverket absoluta URL:er och omdirigeringar utifrån proxyns värdnamn, vilket ger trasiga länkar i mejl och omdirigeringsloopar vid inloggning. Detta är den vanligaste konkreta fällan i uppsättningen.
- **Absoluta URL:er i utgående e-post genereras från kärnans konfiguration**, inte från inkommande `Host`. En magic link måste peka tillbaka till den frontend användaren faktiskt använder, vilket betyder att anropet till kärnan behöver bära med sig vilken frontend det kom ifrån. Det är en parameter i anropet, inte en kolumn på kontot.
- **Proxyn får bara nå kärnans API-prefix.** Vitlista sökvägen. En vidareförmedlare som accepterar godtycklig destination är en öppen relä och en SSRF-vektor; hör ihop med [[ADR-0017 Missbruksvektorer]].
- **Proxyn skriver inte om `Set-Cookie` annat än domänattributet** och buffrar inte svarskroppar. Filnedladdningar går utanför proxyn, direkt mot filoriginet, enligt [[ADR-0019 Filleverans]].
- **Frontends flyttar från GitHub Pages till egna siter hos inleed.** Lagt till bland miljöfrågorna i [[ADR-0018 Utvecklingsprocess och deploy]].
- **Filoriginet byter domän.** `files.yachting.earth` i [[ADR-0019 Filleverans]] och [[Filer och lagring]] ska läsas som platshållare tills kärndomänen är vald. Fil-URL:er är långlivade — byte i efterhand betyder omdirigeringar för all framtid.
- **Postmark sätts upp på kärndomänen.** SPF, DKIM och DMARC på det nya namnet, inte på yachting.earth. Sändarryktet börjar om från noll och behöver mogna före lansering, så uppsättningen bör göras tidigt även om utskicken kommer sent. Se [[ADR-0010 Notisarkitektur]].
- **Acceptanskriteriet för issue 4 i [[Backlog]] är omformulerat** — inloggning sker från en egen origin bakom proxy, inte från en subdomän.
- **B2B-integrationer och framtida mobilappar berörs inte.** De använder personal access tokens och talar direkt med kärnan.

## Öppen fråga

**Kärndomänens namn är inte valt.** Beslutet här är att identiteten ska vara neutral och skild från varumärkena — inte vilket namn den får. Namnet bakas in i fil-URL:er och byggt e-postrykte och behöver därför kunna hållas i tio år. Bör avgöras före issue 0, eftersom miljöuppsättningen refererar till det. Se [[Tankar]].

## Alternativ

**Allt under yachting.earth som subdomäner.** Det ADR-0011 förutsatte. Enklast tekniskt — cookie-läget fungerar utan proxy. Valdes bort: gör plattformens identitet till ett båtvarumärke, vilket omöjliggör caravans.earth som jämbördig ingång och binder e-postrykte och fil-URL:er till fel namn permanent.

**Bearer-tokens för alla frontends.** Undviker proxyn helt och fungerar cross-site. Valdes bort — återinför exakt den XSS-exponering ADR-0011 avvisade, och gör det för de klienter som har minst behov av den.

**Delad inloggning på kärndomänen.** Användaren skickas till plattformens egen sajt för att registrera sig och arbeta, varumärkessajterna blir rena marknadsföringssidor. Tekniskt oproblematiskt. Valdes bort — bryter varumärket vid registreringen och reducerar vertikalsajterna till landningssidor, vilket tar bort det mesta av värdet i [[ADR-0013 Språk och i18n]].

**Full BFF med egen session per frontend.** Varje sajt håller sin egen användarsession och talar med kärnan via maskintoken. Vanligt mönster i större organisationer. Valdes bort — flyttar inloggningslogik ut i varje frontend, vilket är precis det som gör en ny vertikal dyr, och skapar två ställen där sessioner kan gå isär.
