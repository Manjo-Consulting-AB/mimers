# ADR-0038 Gränsen för Pro i kostnaderna

**Status:** Antagen 2026-09-18 · Ersätter Pro-gränsen i [[ADR-0016 Kostnadsregistrering]]; övriga beslut gäller · [[ADR-index]]

Fattat vid genomgången av dashboardmockupen. [[ADR-0016 Kostnadsregistrering]] drog gränsen vid *summering*. Den här ADR:n flyttar den till *frågan*.

## Kontext

[[ADR-0016 Kostnadsregistrering]] slår fast: **"Registrering är fri på alla nivåer. Summering och rapportvy kräver Pro."** Logiken är [[ADR-0009 Kvoter och livscykel]]s — datan ackumuleras på gratisnivån och blir med tiden anledningen att uppgradera.

Dashboardmockupen lägger två summeringar på inloggningssidan: en bricka med månadens totala kostnad och en donut med nedbrytning per container. Ingen av dem har ett lås.

Under regeln som den står måste båda antingen tas bort eller låsas. Att låsa dem betyder att den mest besökta sidan i produkten möter en gratisanvändare med en tom ruta där ett tal ska stå.

Det är fel sorts grind. En låst funktion säljer bara om användaren redan vet vad den är värd, och ett suddat tal säger ingenting om vad det suddade talet skulle ha visat. **Ett synligt tal med en låst väg vidare säljer; ett osynligt tal säljer inte.**

Ordet *summering* bär dessutom inte sin egen gräns. Är "12 items" en summering? Är antalet öppna uppgifter det? Varje yta som räknar något blir en förhandling, och en regel som måste förhandlas vid varje vy är ingen regel — den är en åsikt som granskaren får gissa sig till.

## Beslut

**Fri: en fast summering användaren inte kan ställa frågor till.**
**Pro: allt som går att fråga.**

En summering är **fast** när den är densamma varje gång den visas — ingen period att välja, inget filter att sätta, ingen gruppering att byta. Den är **frågbar** när något i utfallet styrs av användarens inmatning.

Därav:

| Yta | Nivå |
|---|---|
| Dashboardens totalsumma för innevarande månad | fri |
| Dashboardens donut, nedbruten per container | fri |
| Containerns kostnadssumma | fri |
| Containerns donut, nedbruten per kategori | fri |
| Rapportvyn: period, kategori, tagg, leverantör, gruppering | **Pro** |
| Export av kostnadsrader | fri, enligt [[ADR-0014 Prismodell]] |

**Registreringen är fortsatt fri på alla nivåer**, som [[ADR-0016 Kostnadsregistrering]] redan slagit fast. Det ändras inte.

**Rättighetskontrollen sitter kvar i API:et**, inte i klienten. Det ändras inte heller — bara vilken ändpunkt som bär grinden. De fasta summeringarna behöver ingen; rapportändpunkten behöver den lika mycket som förut.

**"Visa mer" är grinden.** Länken ur en fast summering in i rapportvyn är där gratisanvändaren möter Pro, och den är märkt som sådan innan hon klickar.

## Motivering

**Gränsen är granskningsbar.** "Tar den här ändpunkten emot en parameter som påverkar utfallet?" är en fråga med ett svar i koden. "Är det här en summering?" är det inte. En regel som en granskare kan avgöra utan att fråga är värd mer än en som beskriver avsikten bättre.

**Gratisnivån har ändå bara en container.** [[ADR-0014 Prismodell]] ger gratis *en container, 1 GB, en delad användare*. Det betyder att dashboardens donut på gratisnivån har exakt en tårtbit, och att "total kostnad över allt du äger" — det uttryckliga Pro-argumentet i ADR-0014 — **fortfarande är otillgängligt utan att någon grind behövs.** Containergränsen gör redan jobbet som summeringsregeln gjorde. Det är därför den kan släppas utan att Pro blir svagare: vi tar inte bort en grind, vi tar bort en andra grind framför samma dörr.

Det bevarar också motdraget mot flera gratiskonton i ADR-0014 § Motivering. Den som splittrar sitt ägande på fem gratiskonton kan fortfarande *aldrig se vad allt hon äger kostar tillsammans* — inte för att summeringen är låst, utan för att talen ligger i fem konton.

**Ackumuleringsargumentet blir starkare, inte svagare.** [[ADR-0016 Kostnadsregistrering]]s tes är att en användare med tre år av siffror hon inte kan summera har ett konkret skäl att betala. Men hon får aldrig tre år av siffror om hon aldrig ser att de gör något. Ett tal som växer varje månad är vad som gör att hon fortsätter mata in, och inmatningen är förutsättningen för hela resonemanget.

**Den fasta summeringen kostar oss ingenting att ge bort.** Den är en `SUM` över rader användaren redan äger, utan parametrar, cachbar, och den avslöjar inget hon inte kunde räkna ut själv genom att exportera — vilket hon får göra gratis enligt [[ADR-0014 Prismodell]].

## Konsekvenser

- **[[ADR-0016 Kostnadsregistrering]] § Vad som ingår** ersätts på en rad: *"Registrering är fri på alla nivåer. Summering och rapportvy kräver Pro."* Resten av ADR-0016 gäller oförändrat, inklusive att kostnaden hör till itemet, att rapportvyn är arbetet snarare än tabellen, och att summering sker per valuta.
- **Rapportvyn är fortfarande ett eget arbete** och ska inte klämmas in i samma issue som de fasta summeringarna. ADR-0016 sa det redan; gränsen går nu på samma ställe som issuegränsen, vilket är en förenkling.
- **De fasta summeringarna behöver en egen ändpunkt** som inte tar emot parametrar. Tar den emot en period är den inte längre fast, och grinden har flyttat sig utan att någon beslutat det. Det är testbart: en `risk_class: elevated`-issue vars test bevisar att ändpunkten ignorerar okända parametrar.
- **Valutan gäller här också.** En fast summering över blandade valutor ska grupperas, inte summeras — se [[ADR-0016 Kostnadsregistrering]] och [[ADR-0037 Valutans arv]].
- **Nedgradering rör ingenting.** Kostnadsrader raderas aldrig vid nedgradering, och de fasta summeringarna fortsätter fungera. Det som försvinner är rapportvyn, och det var sant förut också.
- **Märkningen av "Visa mer" är en gränssnittssträng** och hör till `lang/`, alltså efter [[M13 Omskrivningen]].

## Alternativ

**Behålla ADR-0016:s gräns och låsa dashboardens siffror.** Det ursprungliga läget. Valdes bort — ett suddat tal på produktens mest besökta sida säljer inte, och ordet *summering* går inte att granska utan att förhandla vid varje vy.

**Ta bort kostnaderna från dashboarden helt.** Renast mot ADR-0016 och kräver ingen ny ADR. Valdes bort — det tar bort det mockupen är bäst på, och lämnar kostnadsfunktionen utan någon yta som visar att den finns.

**Släppa hela kostnadsfunktionen fri, rapportvyn inkluderad.** Valdes bort — rapportvyn är det arbete som faktiskt kostar att bygga, och den är det enda i kostnadsdomänen som en gratisanvändare med en container skulle sakna nog för att betala för.

**Gräns på antal rader i stället.** "Summera upp till femtio kostnader gratis." Valdes bort — straffar exakt det beteende produkten vill uppmuntra, och väggen kommer efter månaders inmatning, vilket är den sämsta tidpunkten att möta en gräns. Samma resonemang som [[ADR-0014 Prismodell]] § Motivering om filstorlekar.
