# ADR-0016 Kostnadsregistrering

**Status:** Antagen 2026-08-04 · [[ADR-index]]

## Kontext

Ett item svarar idag på vad saken är, var den sitter och när den senast servades — men inte på vad den har kostat. Frågorna användaren vill ställa är "vad har motorn kostat mig", "vad har jag lagt på impellerbyten" och "vad kostade båten förra säsongen", och ingen av dem går att svara på med den data systemet samlar.

Det är också en fråga med kommersiell tyngd. En mäklare som kan visa fem års kostnadshistorik på en båt har ett due diligence-argument som ingen konkurrent har, och en ägare som lagt in tre år av siffror flyttar dem inte gärna någon annanstans.

## Beslut

**Kostnader lagras som rader i en egen tabell, `cost_entry`, med obligatorisk koppling till ett item.** Ingen kostnad registreras på containernivå. Vill användaren logga hamnavgifter skapar hon ett item för hamnen, klassar det med kategori och taggar och registrerar kostnaderna där — samma process som för en impeller.

Rapportdimensionerna hämtas från den organisation användaren redan gjort. Kostnaden ärver itemets kategori och taggar vid läsning, vilket gör "vad har motorn kostat" till en rollup över kategoriträdet och "vad har servicar kostat" till en join mot `item_tag`.

### Vad som ingår

- Datum, belopp, valuta och beskrivning är obligatoriska. Leverantör är frivillig fritext.
- Beloppet lagras i minsta valutaenhet enligt husets penningkonvention. Decimaler tillåts vid inmatning men krävs inte.
- Leverantörsfältet är filtrerbart och summerbart, med autocomplete från containerns befintliga värden.
- **Registrering är fri på alla nivåer. Summering och rapportvy kräver Pro.**

### Vad som medvetet utelämnas

**Ingen koppling till `schedule_occurrence`.** Kostnaden hör till itemet, inte till ett enskilt utfört jobb. När en uppgift bockas av erbjuder gränssnittet att registrera en kostnad på itemet med förekomstens datum förifyllt — kroken finns, relationen gör det inte.

**Inget momsfält.** Se motiveringen nedan.

**Inga kvitton på kostnadsraden.** Ett kvitto är en attachment på itemet, som alla andra filer. `attachment` ändras inte.

**Ingen leverantörstabell.** Leverantör är en sträng, inte en entitet.

**Inget lagrat tillstånd för att kostnadsutrymmet är "aktiverat".** Knappen finns alltid på itemet och sektionen renderas när det finns minst en rad.

### Ägarbyte

Ingen ny mekanism. `ownership_transfer.excluded_item_ids` finns redan för items säljaren behåller, och eftersom kostnaderna hänger på items följer de det urvalet automatiskt. Undantar säljaren ett item följer dess kostnader med honom.

## Motivering

**Att kostnaden hör till itemet och inget annat** är det som gör funktionen billig. Datamodellen är en tabell och en främmande nyckel; hela rapportapparaten faller ut ur kategorier och taggar som redan finns. Hade kostnaden istället fått en egen taxonomi — kostnadsställen, konton, projekt — hade den blivit ett andra organisationssystem vid sidan av det användaren redan lärt sig.

**Koppling till förekomst valdes bort** därför att den bara tillför något när flera scheman sitter på samma item. Modellerar användaren finkornigt, med impellern som eget item, är itemet i praktiken jobbet och datumfältet räcker för att se vad bytet 2024 kostade. Modellerar hon grovt förlorar hon möjligheten att skilja oljebyten från impellerbyten i statistiken — men det är en konsekvens av hennes val av granularitet, inte av datamodellen.

**Momsfältet valdes bort** därför att ett fält som funktionellt är moms men kallas något annat ger det sämsta av båda världar: användaren behandlar det som moms och förväntar sig korrekt hantering av avrundning, ex/ink och rapportering, medan systemet inte lovar något av det. Varvets riktiga moms bor i deras bokföringssystem. Detta är dessutom det billiga hållet att ändra — en nullbar `vat_amount` kan läggas till utan att röra befintlig data, till skillnad från valutaenheten där varje rad och varje kodställe måste räknas om.

**Att registrering är fri men rapporten är Pro** följer samma logik som att items aldrig raderas vid nedgradering, se [[ADR-0009 Kvoter och livscykel]]. Datan ackumuleras på gratisnivån och blir med tiden anledningen att uppgradera; en användare med tre år av siffror hon inte kan summera har ett konkret skäl att betala. Att låsa hela funktionen bakom Pro skulle innebära att ingen data byggs upp och att det inte finns något att låsa upp.

**Leverantör som fritext med autocomplete** löser rätt problem på rätt ställe. Det som faktiskt går sönder är stavningsvarianter — "Volvo Penta" och "Volvo-Penta" blir två leverantörer — och det löses vid inmatningen genom att användaren väljer ett befintligt värde, inte genom att normalisera i schemat. Skulle leverantörer senare behöva bli riktiga poster med kontaktuppgifter är vägen dit enkel: plocka ut distinkta strängar, skapa rader, fyll i främmande nyckel. Den motsatta riktningen är den svåra.

## Konsekvenser

- **Kostnadsrader är metadata och räknas inte mot lagringskvoten.** En rad är några hundra byte, och samma resonemang som i [[ADR-0009 Kvoter och livscykel]] gäller: filerna kostar, metadatan gör det inte.
- **Kostnadsrader raderas aldrig vid nedgradering.** Kvittona kan försvinna med bilagorna, siffrorna står kvar. Det är avsiktligt och samma princip som för items.
- **Rättighetskontrollen för rapporten måste sitta i API:et**, inte i klienten. En egen frontend ska inte kunna summera raderna genom att hämta dem och räkna själv — men export förblir fri enligt [[ADR-0014 Prismodell]], och den som exporterar och summerar i kalkylark är en trolig framtida kund, inte ett läckage att täppa till.
- **Rapportvyn är arbetet, inte tabellen.** Rollup över kategoriträdet, taggkombinationer, tidsperiod och gruppering per valuta är en egen uppgift och ska inte klämmas in i samma issue som CRUD.
- **Summering sker per valuta.** Ingen omräkning görs i MVP. Avrundning sker först vid presentation, aldrig per rad före summering, annars stämmer inte totalen med raderna ovanför.
- **Soft delete följer itemet.** Raderas ett item hamnar dess kostnader i papperskorgen med det och kommer tillbaka vid återställning.

## Alternativ

**Kostnader på containernivå.** Skulle låta användaren bokföra hamnavgifter och försäkring utan att skapa items för dem. Valdes bort — det ger två skilda registreringsflöden för samma sak, och en hamn är ett fullgott item precis som "akterstuv" är en fullgod tagg. Se [[ADR-0004 Fria taggar och kategorier]].

**Koppling till `schedule_occurrence`.** Skulle ge snittkostnad per service automatiskt. Valdes bort av skälen ovan; kan läggas till som en nullbar kolumn senare utan att befintlig data påverkas.

**Kostnader som ett generellt användardefinierat fält.** Övervägdes eftersom användaren också kan vilja lägga till mätarställning eller försäkringsnummer. Valdes bort — de senare är fritext och inget annat, medan kostnader behöver flera rader per item, summering och egen rapportering. De passar illa i samma mekanism.

**Leverantör som egen tabell.** Valdes bort — leverantören behöver inga attribut i MVP, och migrationen från sträng till tabell är enkel den dag den behövs.

**Momsfält för företagskunder.** Valdes bort tills två eller tre varv säger att de behöver det.
