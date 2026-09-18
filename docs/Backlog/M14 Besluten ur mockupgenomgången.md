# M14 · Besluten ur mockupgenomgången

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

Tillagd 2026-09-18, efter genomgången av den första omgången mockuper. Fem beslut fattades där och fick varsin ADR eller en anteckning; den här milstolpen är deras mottagare. **Ingen av issuerna bygger en ny yta.** De rättar fyra ställen där koden säger något annat än ett skrivet beslut, plus en knapp som aldrig borde ha funnits.

Ordningen styrs av `lang/`. Tre av issuerna rör strängar och måste därför ligga **efter issue 82**, annars skrivs samma rader två gånger. De två som inte rör strängar — 83 och 86 — har inga beroenden alls och kan tas när som helst.

### 83. Containerkontexten sätts av navigeringen
Systemet behöver veta vilken container användaren arbetar i — det är därför `App\Support\Frontend\ActiveContainer` finns. Men det är bokföring, och bokföring ska inte ha en knapp. I dag möter användaren "gör aktiv" i containerlistan, trycker på den och ser en markering flytta sig i samma lista. Vad knappen bokför syns ingenstans.

**Mekanismen stannar, knappen försvinner.** Kontexten sätts av att användaren öppnar en container. Markeringen i navigeringen blir en effekt av var användaren befinner sig, inte en inställning hon gör.

`ActiveContainerController` och rutten `containers.active` utgår — det finns inget att skicka en `PUT` till när kontexten inte längre är ett val. Knappen i `resources/js/pages/Containers/Index.vue` och markeringen som är dess enda verkan utgår. `ActiveContainer::set()` anropas när en container öppnas, utöver de tre befintliga ställena: skapad container, antagen inbjudan, mottaget ägarbyte.

**Det här ändras inte:** `ActiveContainer` och sessionsnyckeln, propen `activeContainer` i `HandleInertiaRequests` — den är vad navigeringens markering kommer att läsa när navigeringen byggs — och kontrollen i `forUser()` mot `Container::scopeAccessibleBy()`. Att kontexten sätts implicit gör åtkomstkontrollen viktigare, inte mindre viktig: en container användaren mist åtkomsten till får aldrig ligga kvar som kontext.

`AktivContainerTest` prövar i dag att rutten sätter nyckeln och ska pröva att navigeringen gör det. Fyra testfiler till påstår något om beteendet: `DeladePropsTest`, `ContainervyerTest`, `ContainerpapperskorgTest`, `InbjudanMottagareTest`.

**Läs:** `app/Support/Frontend/ActiveContainer.php`, issue 51 § Beslut 4 och issue 55 i [[M10 Webbfrontend]]
**Klart när:** `PUT /containers/{container}/active` finns inte längre; att öppna en container sätter sessionsnyckeln till containerns ULID; att öppna en container användaren saknar åtkomst till lämnar kontexten orörd; propen `activeContainer` delas fortfarande ut och bär ULID:t för den senast öppnade containern; containerlistan har ingen knapp som sätter kontexten; hela testsviten är grön.
**Beror på:** -

### 84. Containerns art blir fri
`container.kind` bär ett CHECK-villkor — `kind IN ('boat', 'caravan', 'house', 'car', 'other')` — och samma lista finns som `Container::KINDS`, i två FormRequests, i två propar ur `ContainerController` och i typlistan i `Containers/Edit.vue`. [[ADR-0036 Containerns art]] frigör fältet, och [[ADR-0033 Produktens omfång]] är skälet: fem värden i ett schema är domänen inbyggd i koden.

CHECK-villkoret släpps och `Container::KINDS` utgår, och med den `Rule::in(Container::KINDS)` i `StoreContainerRequest` och `UpdateContainerRequest` — valideringen blir längd och format, inte medlemskap. Ingen rad i databasen ändras: de fem befintliga värdena är fortfarande giltiga strängar. Fältet blir dessutom **frivilligt**; att tvinga fram en art vid skapandet är att ställa en fråga användaren ännu inte kan svara på.

De två `kinds`-proparna byter innebörd, från *de tillåtna värdena* till *de värden kontot redan använt*, för autocomplete. Mönstret och motiveringen är leverantörsfältets i [[ADR-0016 Kostnadsregistrering]]: stavningsvarianter löses vid inmatningen, inte i schemat.

**Kategorimallarna följer med i samma issue.** `CategoryPresetCard.vue` och `KategoriuppsattningTest` slår i dag upp presetarna på `kind`, och den nyckeln finns inte längre. Mallen blir ett val användaren gör, vilket [[ADR-0033 Produktens omfång]] § Konsekvenser redan kräver — de två går inte att skilja åt.

Kommentarerna i `Invitation` och `Schedule` pekar på `Container::KINDS` som mönster för en delad lista. De byter förlaga, inte beteende. **Fältet får fortfarande aldrig styra beteende:** ingen `match` eller `if` på `kind` någonstans, som `Container`s klasskommentar redan kräver. Ett fritt fält som styr logik är värre än en sluten lista som gör det.

Navigeringens gruppering — minst två containrar per art — hör till designarbetet och ingår **inte** här.

**Läs:** [[ADR-0036 Containerns art]], [[ADR-0033 Produktens omfång]] § Beslut, issue 8 § Beslut 5 i [[M1 Kärnmodell]]
**Klart när:** CHECK-villkoret på `container.kind` finns inte längre och en container kan sparas med en art utanför den gamla listan; `Container::KINDS` finns inte längre i koden; `kind` får utelämnas vid skapande; de två proparna bär de arter kontot redan använt, inte en fast lista; kategorimallen väljs uttryckligen och härleds inte ur `kind`; ingen `match` eller `if` på `kind` finns i `app/` eller `resources/js/`; de fem befintliga värdena är oförändrade i databasen efter migreringen; hela testsviten är grön.
**Beror på:** 82

### 85. Valutan ärvs nedåt
[[ADR-0016 Kostnadsregistrering]] gör valutan obligatorisk på kostnadsraden men säger inte varifrån värdet kommer, så varje registrering ställer användaren en fråga hon nästan alltid besvarar likadant. [[ADR-0037 Valutans arv]] svarar: kontot bär valutan, containern ärver den och kan ange en egen, kostnadsformuläret föreslår containerns och låter användaren välja en annan.

**Ett ändrat standardval gäller bara nya poster.** Byter användaren containerns valuta märks befintliga rader aldrig om — det som står i en rad är vad som betalades, inte vad containern för närvarande föreslår. Det är den regel som gör hela arvet ofarligt och den viktigaste punkten i issuen.

Schemat får en valutakolumn på `account` och en nullbar på `container`. **`cost_entry` ändras inte** — valutan står kvar per rad, obligatorisk, som i dag. Ingen valuta hamnar på `item`: itemet är stället där formuläret öppnas, inte en nivå i arvet.

Fältet i formuläret är **förifyllt och ändringsbart, inte dolt**. Ett dolt fält som ändå står i datan är ett fel som upptäcks först i en rapport. Kontots valuta sätts vid registrering med ett vettigt förval och kan ändras i inställningarna efteråt — aldrig en blockerande fråga.

Ingen omräkning sker någonsin automatiskt. Presentation av blandade valutor är ett medvetet uppskjutet problem och ingår inte här; svaret finns i [[ADR-0016 Kostnadsregistrering]] — gruppera per valuta, summera aldrig över dem.

**Läs:** [[ADR-0037 Valutans arv]], [[ADR-0016 Kostnadsregistrering]] § Vad som ingår, [[ADR-0013 Språk och i18n]] § Konsekvenser
**Klart när:** `account` bär en valuta och `container` en nullbar som faller tillbaka på kontots; en ny kostnadsrad får containerns valuta som förifyllt värde; användaren kan välja en annan valuta på den enskilda raden och den sparas; ett byte av containerns eller kontots valuta lämnar befintliga `cost_entry`-rader oförändrade; `cost_entry`-tabellen har ingen ny eller borttagen kolumn; ingen valutakolumn finns på `item`; hela testsviten är grön.
**Beror på:** 82

### 86. Fasta summeringar är fria
`CostReportController` bär i dag två grindar i ordningen behörighet, sedan plan: `Gate::authorize('view', $container)` följt av `assertFeature($container->account, 'cost_reports')`. [[ADR-0038 Gränsen för Pro i kostnaderna]] flyttar gränsen från *summering* till *fråga*: **en fast summering användaren inte kan ställa frågor till är fri, allt som går att fråga är Pro.**

Issuen lägger till fasta summeringsändpunkter utan plangrind — en per container och en för kontot, den senare underlaget för dashboardens totalsumma och donut. De tar **inga parametrar**: ingen period, inget filter, ingen gruppering. Tar en ändpunkt emot en period är den inte längre fast, och grinden har flyttat sig utan att någon beslutat det.

**`CostReportController` rörs inte.** Den parametriserade rapporten behåller `cost_reports` precis som i dag, och ordningen mellan grindarna — behörighet före plan, så att en användare utan åtkomst får `auth.forbidden` och inte `plan.feature_unavailable` — gäller oförändrat och av samma skäl.

Åtkomstfiltret på itemnivå gäller lika mycket här: en fast summering får aldrig räkna in rader från items användaren inte når. `App\Support\Cost\CostReport` och `ResolveItemScope` är förlagan, och upplösningen sker en gång per request.

Valutan gäller också: en fast summering över blandade valutor grupperas, den summeras inte. Se [[ADR-0037 Valutans arv]].

Märkningen av "Visa mer" är en gränssnittssträng på en yta som ännu inte finns och ingår **inte** här.

**Läs:** [[ADR-0038 Gränsen för Pro i kostnaderna]], [[ADR-0016 Kostnadsregistrering]] § Konsekvenser, issue 46 § Beslut 2 i [[M8 Kostnadsregistrering]], issue 74 § Beslut 5 och 10 i [[M11 Åtkomst på itemnivå]]
**Klart när:** en gratisanvändare får containerns fasta kostnadssummering utan 403; en gratisanvändare får kontots fasta summering utan 403; de fasta ändpunkterna ignorerar okända parametrar och returnerar samma utfall med och utan dem; `GET /containers/{container}/costs/report` kräver fortfarande `cost_reports` och svarar `auth.forbidden` före `plan.feature_unavailable` för den utan åtkomst; ingen fast summering räknar in rader från items användaren inte når; blandade valutor grupperas och summeras inte; hela testsviten är grön.
**Beror på:** -

### 87. Relationen heter related
`item_link.relation` tillåter `parent`, `child` och `sibling`. De två första beskriver vad de gör; det tredje påstår en delad förälder som relationen aldrig haft. [[ADR-0035 Relationen mellan objekt]] döper om värdet till `related` — **i databasen, inte bara i etiketten**, av skälet i [[ADR-0032 Produktens ord]]: ett ordförråd, och noll avstånd mellan vad användaren ser och vad utvecklaren läser.

CHECK-villkoret byter värde och befintliga rader med `sibling` skrivs om. **Namnbytet är allt som händer.** Ingen regel om hur en relation skrivs, läses, normaliseras eller raderas ändras, och åtkomstregeln står oförändrad: delning når nedåt längs `parent`/`child`, en `related`-länk delar ingenting.

Kodställena är `ItemLink`, `Item`, `LinkItems`, `ItemLinkController`, `ItemController`, `StoreItemLinkRequest`, `ItemLinkSection.vue`, `ResolveItemScope` och migreringen. Språkfilerna byter både nyckel och värde: `relation_sibling` i `export.php` och de fyra ställena i `ui.php`. Meningen om delning kan skrivas rakare när ordet är bytt — *"related items share nothing"* behöver ingen bortförklaring.

`ResolveItemScope` ligger i åtkomstlagret. Ett namnbyte ändrar ingen logik, men filen gör issuen `risk_class: elevated` — inte för att bytet är farligt, utan för att granskningen ska läsa läslistan.

**Läs:** [[ADR-0035 Relationen mellan objekt]], [[ADR-0032 Produktens ord]] § Beslut, issue 14 § Beslut 3 och 4 i [[M1 Kärnmodell]], [[ADR-0028 Åtkomst på itemnivå]]
**Klart när:** `item_link.relation` accepterar `related` och inte `sibling`; inga rader med `sibling` finns kvar efter migreringen; ingen förekomst av `sibling` finns i `app/`, `resources/js/`, `lang/` eller `routes/`; normaliseringen till lägst `id` först fungerar oförändrat för `related`; delning når fortfarande nedåt längs `parent`/`child` och en `related`-länk delar ingenting; `ItemRelationTest`, `ItemrelationvyTest`, `OmfangsupplosningTest`, `ItemgrindTest` och `ItematkomstTest` prövar det nya ordet; hela testsviten är grön.
**Beror på:** 82
