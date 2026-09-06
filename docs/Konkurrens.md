# Konkurrens

Hur Mimers står sig mot de verktyg folk redan använder för att komma ihåg saker om sina prylar. Tillbaka till [[00 Index]].

**Vad dokumentet är:** underlag för positionering och för att avgöra vad som är värt att bygga. **Vad det inte är:** marknadsföringstext, och inte heller ett beslut. Ett beslut bor i en ADR; en obesvarad fråga bor i [[Tankar]]. Här står jämförelsen.

Ingen av de här produkterna är byggd för nischen. Det är hela poängen — och samtidigt den svåraste invändningen, för en teknisk användare *kan* bygga något som liknar Mimers i flera av dem. Frågan är aldrig "går det?" utan "vad kostar det användaren att bygga och underhålla det själv, och vad går ändå inte att göra?".

## Den gemensamma linjen

Fyra skillnader återkommer mot nästan varje konkurrent. De är strukturella, inte funktionsglapp, och de är därför det som ska bära argumentet.

**1. Tiden — systemet knackar på av sig självt.** [[Scheman och uppgifter]] skiljer på `fixed` (försäkringen förnyas 1 januari oavsett när du betalade) och `interval` (oljebyte tolv månader efter *senast utfört*). Ovanpå det ligger `lead_days`, beroenden mellan förekomster och hela [[Notiser]] — e-post, ICS-feed, webhooks, tysta timmar, veckosammanfattning. En anteckningsapp gör ingenting förrän användaren öppnar den. En båtägare i november öppnar inte sin anteckningsapp. Ett underhållssystem som kräver att användaren minns att titta har misslyckats med sin enda uppgift.

**2. Flera parter, olika behörighet, på samma objekt.** [[ADR-0002 Konto äger container]]: servicevarvet äger inte båten, det får delegerad åtkomst. Plus inbjudningar, R/RW och [[ADR-0028 Åtkomst på itemnivå]] — som byggs *före* webbfrontenden, vilket säger något om hur central den är. Konsument-anteckningsappar har delning; de har inte en behörighetsmodell där en tredje part har betalt uppdrag på någon annans innehåll. Hela B2B-tabellen i [[Översikt]] § Vem betalar är omöjlig utan den.

**3. Strukturerade fält som systemet kan räkna på.** `serial_number`, `warranty_until`, `purchased_at`, `manufacturer`, `model` är typade kolumner med index, inte konventioner varje användare hittar på själv. Plus `cost_entry` med kostnadsrapporter och `loan` med påminnelse. Skillnaden mot en databas man byggt själv i ett generellt verktyg är invarianterna: främmande nycklar, validering, och att `warranty_until` inte kan vara ett datum i en post och en sträng i nästa. "Vad kostade båten 2026?" är en fråga Mimers kan svara på och ett hemmabygge kan gissa på.

**4. Objektets historik följer objektet, inte personen.** Ägarbyte, export, revisionslogg, leveransdokument från varv, due diligence för mäklare. Pärmen är ett värdebärande dokument **vid en affär** — den överlåts med båten. Ett personligt valv eller arbetsyta är oöverlåtbart i praktiken; du kan inte sälja båten inklusive anteckningarna på ett meningsfullt sätt. Det här är förmodligen den mest kommersiellt intressanta punkten och den som är minst utvecklad i dokumentationen i övrigt.

**Där vi liknar dem mest, medvetet:** [[ADR-0004 Fria taggar och kategorier]] ger samma blanka papper som ett generellt verktyg. "Systemet vet ingenting om båtar" är rätt beslut, men det är inte ett säljargument — det är en förutsättning för att produkten ska funka för husvagnen också.

## Så här fyller du på

En rubrik per konkurrent, alltid samma fyra underrubriker. Håller vi formen går det att läsa tabellvis i huvudet, och en tom rubrik syns som ett hål istället för att bara saknas.

| Underrubrik | Vad som ska stå |
|---|---|
| **Vad de är bra på** | Ärligt, i deras egna termer. Halmgubbar hjälper ingen. |
| **Var vi skiljer oss** | Peka på de fyra ovan när de gäller, och skriv bara ut det som är specifikt för just den här konkurrenten. |
| **Var de vinner** | Det som faktiskt är sämre hos oss. Det är hit invändningen kommer i ett säljsamtal. |
| **Vad vi ska låna** | Konkreta idéer. Blir något av dem ett beslut flyttar det till en ADR eller till [[Backlog]] och stryks här. |

## Obsidian

Lokalt markdown-valv med länkar, taggar, grafvy och ett stort pluginekosystem. Den närmaste jämförelsen, eftersom en teknisk användare faktiskt bygger något Mimers-liknande i den.

**Vad de är bra på.** Fritextskrivande utan friktion. Länkar mellan idéer. Data är dina egna filer på din egen disk, utan prenumeration och utan leverantör som kan försvinna. Pluginekosystemet gör att nästan allt går att sätta ihop: Dataview för strukturerade frågor, Tasks och Reminder för förfallodatum, Templater för mallar.

**Var vi skiljer oss.** Alla fyra i den gemensamma linjen, men två sticker ut:

- **Tiden.** Obsidian är passivt. Reminder-plugins kan visa en påminnelse i appen, men de kan inte mejla dig i november när appen är stängd. Skillnaden mellan `fixed` och `interval` går att uttrycka i Dataview men bara som beräkningar användaren skriver själv, en gång per underhållstyp.
- **Flera parter.** Obsidian Sync är en person och deras enheter. Det finns ingen behörighetsmodell, för det finns ingen andra part. Att ge varvet läsrätt på tre items är inte svårt i Obsidian — det är omöjligt.

**Var de vinner.** Offline-first. Ingen prenumeration för att komma åt sitt eget innehåll. Pluginekosystemet. Och en teknisk användare kommer runt ungefär 60 % av Mimers med Dataview + Tasks + Templater — de underhåller bygget själva för alltid, men de har det redan och det kostade dem noll.

**Vad vi ska låna.** Länkbarheten. `item_link` finns men är underutnyttjad i produkttänket — "vad hänger ihop med vad" är en fråga ägare ställer oftare än vi antagit. Och exporten (issue i [[M6 Resten av MVP]]) bör ge något som är läsbart utan Mimers, av samma skäl som gör Obsidians filformat till ett säljargument.

**Pitchen i en mening.** Obsidian glömmer aldrig vad du skrivit. Mimers påminner dig om det du inte skrev — och låter varvet se just den delen.

## Evernote

*Ej genomarbetad.* Kärnfrågan: [[00 Index]] beskriver produkten som "en blandning av Evernote och OmniFocus", så jämförelsen är inte fientlig utan definierande. Vad är det Evernote gör som vi ska göra minst lika bra — inskanning, OCR, klippa in från webben — och var går gränsen till det vi medvetet inte bygger (OCR ligger utanför MVP, se [[Översikt]] § Avgränsning)?

**Vad de är bra på.**

**Var vi skiljer oss.**

**Var de vinner.**

**Vad vi ska låna.**

## Notion

*Ej genomarbetad.* Kärnfrågan: Notion är den enda konkurrenten som har både strukturerade fält, relationer och delning med behörigheter — alltså tre av våra fyra. Argumentet måste därför vila på tiden (Notion påminner inte utifrån) och på att användaren bygger och underhåller sitt eget schema. Var går gränsen där "gör det själv i Notion" slutar vara rimligt?

**Vad de är bra på.**

**Var vi skiljer oss.**

**Var de vinner.**

**Vad vi ska låna.**

## The Brain

*Ej genomarbetad.* Kärnfrågan: The Brain är associativt — allt hänger ihop med allt, och navigeringen är grafen. Mimers är hierarkiskt (container → item, kategori med `parent_id`) med länkar som komplement. Är det en svaghet för vår nisch, eller är hierarkin rätt för fysiska objekt som faktiskt sitter inuti varandra?

**Vad de är bra på.**

**Var vi skiljer oss.**

**Var de vinner.**

**Vad vi ska låna.**

## GTD och OmniFocus

*Ej genomarbetad.* En metod, inte en produkt — jämförelsen är därför av annan sort. `lead_days` motsvarar redan OmniFocus defer, vilket [[Scheman och uppgifter]] skriver ut. Kärnfrågan: hur mycket GTD-vokabulär ska produkten ärva (kontexter, nästa åtgärd, veckogenomgång) innan den slutar vara begriplig för en båtägare som aldrig hört talas om GTD? Veckosammanfattningen i [[Notiser]] är i praktiken en veckogenomgång — är det medvetet?

**Vad de är bra på.**

**Var vi skiljer oss.**

**Var de vinner.**

**Vad vi ska låna.**

## Branschsystem

*Ej genomarbetad.* Varvens befintliga verksamhetssystem och de marina underhållsapparna. Kärnfrågan är en annan än för de generella verktygen: här är konkurrenten redan i nischen, och frågan är om vi konkurrerar eller integrerar. Konsekvensen av [[ADR-0002 Konto äger container]] — att kunden äger innehållet och varvet lånar åtkomst — är sannolikt den skarpaste skillnaden mot ett system varvet äger.

**Vad de är bra på.**

**Var vi skiljer oss.**

**Var de vinner.**

**Vad vi ska låna.**
