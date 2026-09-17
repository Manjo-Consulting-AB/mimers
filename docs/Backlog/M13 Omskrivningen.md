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
