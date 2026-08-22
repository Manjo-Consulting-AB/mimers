# ADR-0021 Frontendteknik

**Status:** Antagen 2026-08-22 · [[ADR-index]]

Besvarar den öppna frågan i [[ADR-0020 Plattformsidentitet och frontendgräns]]. Kompletterar [[ADR-0011 Autentisering]] på punkten om vad webbsessionen faktiskt använder.

## Kontext

ADR-0020 fastställde *var* frontenden bor: samma origin som API:et, på `mimers.app`. Den lämnade *vad* den är byggd av öppen — serverrenderad Laravel eller en SPA som konsumerar REST-API:et.

Frågan är inte kosmetisk. Den avgör tre saker:

- **Var i18n bor.** Serverrenderat betyder en katalog i `lang/` och att servern formulerar meningarna. En SPA betyder att felkodsregeln i [[ADR-0013 Språk och i18n]] slår igenom på hela webben, och att en JavaScript-katalog måste hållas i synk med den serverkatalog som ändå behövs för mejl, ICS och PDF-pärmen.
- **Om webbsessionen behöver Sanctums cookie-läge.** Det behövs bara om webbläsaren anropar `/api`.
- **Hur många kodbaser som ska granskas.** [[ADR-0001 Stack]] valde Laravel för att konventioner begränsar hur mycket separata, delegerade implementationer kan driva isär. Tony granskar, han knackar inte. En andra kodbas med egna konventioner är en andra granskningsyta.

Produktens vyer är bild- och filtunga: itemlistor med miniatyrer, taggväljare, filter, drag-drop-uppladdning av flera filer, avbockning av uppgifter. Det är inte ett formulär per sida.

Backloggen innehåller i dag noll frontend-issues. Vad som än väljs är det nytt arbete, inte en omskrivning.

## Beslut

**Inertia med Vue 3 och Tailwind, i samma Laravel-app på samma origin.**

Laravel-controllers returnerar Inertia-svar i stället för Blade-vyer. Vue-komponenter renderar. Ingen klientrouter, ingen klientstore, inga tokens i JavaScript.

**Webben använder Laravels sessionsguard med CSRF, inte Sanctums cookie-läge.** Sanctum blir uteslutande mekanismen för personal access tokens till mobilappar och B2B. `EnsureFrontendRequestsAreStateful` behövs inte så länge webbläsaren inte anropar `/api` direkt.

**Felkodsregeln i [[ADR-0013 Språk och i18n]] gäller `/api`, inte webbsidorna.** Webbens text formuleras på servern ur `lang/`. En katalog, inte två.

**Ingen affärslogik i Inertia-controllers.** Web och API delar FormRequests, Policies och servicelager. Skiljer sig validering eller behörighet mellan de två ytorna är det en bugg, inte en designfråga.

**Assets byggs i CI, aldrig på servern.** `npm ci && npm run build` i GitHub Actions, `public/build` följer med i release-artefakten. Servern behöver fortfarande varken git, composer eller node — se [[ADR-0018 Utvecklingsprocess och deploy]].

## Motivering

Inertia behåller routing, autentisering, validering och behörigheter i Laravel. Granskningsytan förblir alltså Laravel-konventioner, vilket är exakt den avvägning ADR-0001 gjorde en gång redan. Vue-lagret sitter på lövnivå: en komponent renderar de props controllern skickade.

Alternativet rent Blade vann nästan. Det förlorade på att produktens tyngdpunkt ligger i de interaktiva vyerna, och att de i ett Blade-upplägg blir handskriven Alpine widget för widget. Konventionsfördelen gäller serversidan; i det ögonblick varje issue uppfinner sin egen uppladdnings- och filterlösning är den borta. Skillnaden mellan alternativen är liten, men den är olika fördelad i tiden: Inertias kostnad betalas i vecka ett som ett byggsteg och ett komponentlager, Blades betalas i månad tre som ostrukturerad JavaScript.

En fristående SPA mot REST-API:et valdes bort trots att den är arkitektoniskt renast. Den optimerar för att motionera ett API som byggs och testas enligt backloggen ändå, och betalar för det med en andra kodbas, en andra i18n-katalog, klientroutning, klientstate och ungefär dubbla antalet nya issues.

Att sessionsguarden räcker för webben är en förenkling värd att skriva ned. Sanctums cookie-läge *är* sessionsautentisering för `/api`-rutter; behöver webben inte de rutterna behöver den inte läget. Kvar blir Sanctum för det den faktiskt behövs till.

## Konsekvenser

- **Webben motionerar inte REST-API:et.** Det är priset. Motmedlet är det delade FormRequest-, Policy- och servicelagret plus att varje API-issue i [[Backlog]] har egna test — inte att hoppas på att någon råkar upptäcka driften.
- **`staging.yml` får ett Node-steg** före paketeringen, och `public/build` ingår i tarbollen. `node_modules` är redan exkluderad. `deploy.sh` och servern rörs inte. Se [[Pipeline]].
- **CI kör även frontendbygget** på varje PR. Ett trasigt Vue-bygge ska stoppa merge, inte upptäckas vid utrullning.
- **`php artisan view:cache` cachar fortfarande rotvyn.** Inertias enda Blade-vy är app-skalet.
- **Färdiga kategoriuppsättningar bor i frontenden** enligt [[ADR-0004 Fria taggar och kategorier]] — nu som data i Vue-lagret, seedad per språk och containertyp. API:et får fortfarande aldrig veta vad orden betyder.
- **Mobilapparna påverkas inte.** De talar med `/api` med personal access tokens, och felkodsregeln gäller där oförändrat.
- **En frontend-milstolpe tillkommer i [[Backlog]]**, avsedd att köras parallellt med M1 och M2 snarare än efter dem.
- **Issue 4 justeras:** webbinloggning verifieras mot sessionsguarden, token-vägen mot Sanctum. Båda mot samma endpoints-underlag.

## Alternativ

**Blade + Alpine.** Maximal konventionstäthet på serversidan, minsta möjliga JavaScript-yta, en i18n-katalog, snabbast till första vyn. Valdes bort — de interaktiva vyerna blir bespoke JavaScript utan gemensam struktur, och det är samma spretighet som ADR-0001 valde ramverk för att undvika. Skillnaden mot det valda alternativet är liten och beslutet är värt att ompröva om produkten visar sig vara mindre interaktiv än väntat.

**Blade + Livewire 3.** Ett språk, interaktivitet utan egen JS-arkitektur, inbyggd filuppladdning. Valdes bort — varje interaktion är en PHP-boot på delad hosting utan persistent process, vilket gör filter och listor mätbart trögare och dyrare än nödvändigt. Dessutom blandas Livewire 2 och 3 friskt i de modellers träningsdata som ska implementera issuena, vilket är fel egenskap när tre olika modeller skriver var sin del.

**Fristående SPA mot REST-API:et med Sanctums cookie-läge.** Noll duplicering, webben blir API:ets första klient och mobilappens väg är bevisad innan appen finns. Valdes bort — en andra kodbas med egna konventioner fördubblar granskningsytan för en produktägare som inte kodar, felkodsregeln slår igenom på hela webben och kräver en JS-katalog vid sidan av `lang/`, och antalet nya issues ungefär fördubblas.
