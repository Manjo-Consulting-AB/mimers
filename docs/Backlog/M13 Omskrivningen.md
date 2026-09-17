# M13 · Omskrivningen

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

Tillagd 2026-09-17, efter att M12 stängts och två beslut fattats som ingen text ännu följer: [[ADR-0033 Produktens omfång]] och [[ADR-0034 Engelska vid lansering]].

Milstolpen bär omskrivningen av den text produkten består av. Den delas i två, och ordningen är inte godtycklig: **valvets prosa först, strängarna användaren möter sedan.** Prosan är källan implementeraren läser när hon skriver en sträng, så skrivs strängarna först är de skrivna mot en beskrivning som är på väg att ändras.

Bara den första issuen ligger här. Resten skrivs när stegen efter den är avgjorda.

### 81. Valvets prosa följer de nya besluten
Fem ytor beskriver fortfarande produkten som en nisch, och alla fem säger emot [[ADR-0033 Produktens omfång]]: `README.md` och [[00 Index]] öppnar båda med *"ägaren av en båt, husvagn, stuga eller bil"* och avslutar med *"byggd för en nisch"*, [[Översikt]] § Kärnidén definierar containern som *"ett ägt ting: en båt, husvagn, stuga eller bil"*, [[Konkurrens]] argumenterar genomgående utifrån båtägaren, och projektbeskrivningen i `composer.json` säger *"dokumentationsvalv för båtar, husvagnar, stugor och bilar"*.

Dessutom listar [[Översikt]] § Avgränsning *"Svenska och engelska"* under **Ingår i MVP**, vilket [[ADR-0034 Engelska vid lansering]] ändrat.

**Det här är en omskrivning av prosa, inte av analys.** Konkurrensargumenten håller oförändrade — tiden som knackar på, flera parter med olika behörighet, strukturerade fält, historiken som följer objektet. Inget av dem blir svagare av en bredare produkt; det är exemplen omkring dem som ska spänna över bredden i stället för att upprepa samma fordon fyra gånger.

**Två saker rörs inte.** Segmenten i [[Översikt]] § Vem betalar står kvar — de är den första marknaden, inte definitionen av produkten, och det ska framgå av texten i stället för att tabellen skrivs om. Äldre ADR:er och stängda milstolpars backlogfiler behåller sina båtexempel: de är historik, och regeln står i [[ADR-0032 Produktens ord]] § Beslut.

Grinden är ett test. Den smala formuleringen kröp in över månader just för att ingenting fångade den, och samma sak händer igen om ordvalet bara lämnas åt omdömet. Testet läser de framåtriktade dokumenten och ska **aldrig** läsa `docs/ADR/` eller `docs/Backlog/` — historiken ska falla igenom det, inte fastna i det.

**Läs:** [[ADR-0033 Produktens omfång]], [[ADR-0034 Engelska vid lansering]] § Beslut, [[ADR-0032 Produktens ord]] § Beslut
**Klart när:** `README.md` och [[00 Index]] beskriver produkten enligt [[ADR-0033 Produktens omfång]] § Beslut och påstår ingenstans att den är byggd för en nisch; [[Översikt]] § Kärnidén definierar containern som ett sammanhang som kan vara en båt, en bil, en fastighet, en kund eller ett projekt; [[Översikt]] § Avgränsning säger engelska under *Ingår i MVP*; [[Översikt]] § Vem betalar säger att segmenten är den första marknaden och inte produktens definition, med tabellen oförändrad; [[Konkurrens]]s fyra argument står kvar med exempel som spänner över bredden; projektbeskrivningen i `composer.json` är generisk; ett test faller om den smala formuleringen kommer tillbaka i något av de dokumenten, och samma test passerar oförändrat på `docs/ADR/` och `docs/Backlog/`; hela testsviten är grön.
**Beror på:** -

### 82. Strängarna användaren möter
Hela `lang/` skrivs om en gång, mot den prosa issue 81 lämnat efter sig. Två beslut möts i samma filer och ska därför inte tas i två omgångar: [[ADR-0033 Produktens omfång]] gör copyn generisk, [[ADR-0034 Engelska vid lansering]] gör engelska till enda levererade språk.

**Copyn.** Varje sträng användaren möter beskriver produkten enligt ADR-0033 § Beslut. Exempel får förekomma — de ska spänna över bredden, aldrig avgränsa till fordon och fritidshus. Det gäller särskilt tomma tillstånd och onboarding: den första skärmen en ny användare möter får inte be henne lägga till sin båt. Det är den yta där en smal formulering gör mest skada och är svårast att upptäcka i efterhand.

**Språket.** `lang/sv/` utgår, men **efter** att `lang/en/` är komplett — den svenska filen är i praktiken nyckelinventariet, och diffen ska kunna visa att ingen nyckel tappats på vägen. Standardspråket är `en` för alla, oavsett `Accept-Language`. Ingen språkväljare byggs; ADR-0034 § Beslut gör frånvaron till ett beslut och lägger ytan i den issue som en dag lägger till det andra språket. Blandspråket i inloggningsmejlet försvinner som bieffekt av att mallarna blir enspråkiga, inte som en egen rättning.

**Grinden.** Ett test faller om en användarvänd sträng står utanför `lang/` — inte i en Vue-komponent, inte i en mejlmall, inte i en Blade-vy. Det testet är den bestående delen av hela milstolpen: utan det är löftet om fler språk en avsikt, och avsikter överlever ungefär tre issues här. Vad testet hittar rättas i samma issue.

**Två rester tas med.** `lang/en/ui.php` säger fortfarande *binder* i ett tjugotal kommentarer — issue 77b tog prosan i koden men missade den filen. Den städas här, eftersom det är samma fil som ändå skrivs om. Undantaget är `pdf_binder`, som [[ADR-0032 Produktens ord]] § Konsekvenser håller kvar tills PDF-pärmen byggs.

**Läs:** [[ADR-0033 Produktens omfång]] § Beslut, [[ADR-0034 Engelska vid lansering]], [[ADR-0032 Produktens ord]] § Beslut, [[AGENTS.md]] § Språk i koden
**Klart när:** `lang/en/` bär copy som följer [[ADR-0033 Produktens omfång]] § Beslut, och inget tomt tillstånd eller onboardingsteg ber användaren lägga till ett fordon; `lang/sv/` är borttagen och varje nyckel som fanns där har en motsvarighet i `lang/en/`; standardspråket är `en` oavsett `Accept-Language`; ingen mejlmall, ICS-sammanfattning eller vy blandar två språk; ordet *binder* förekommer inte i `lang/en/ui.php`, varken som värde eller i en kommentar, utom `pdf_binder`; ett test faller om en användarvänd sträng står utanför `lang/`, och det testet är grönt på den kod issuen lämnar; hela testsviten är grön.
**Beror på:** 81
**Byggd som:** 82a copyn och språket — `lang/`, mallarna och de tester som påstår något om en sträng; 82b grinden — testet mot hårdkodade strängar och de strängar det hittar. Två sessioner därför att de har olika förlagor och olika testfiler: 82a skrivs mot ADR:erna och rör bara text, 82b skrivs mot kodbasen och kan behöva röra vilken vy som helst.
