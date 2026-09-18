# ADR-0033 Produktens omfång

**Status:** Antagen 2026-09-17 · [[ADR-index]]

Fattat efter M10, i samma genomgång som gav [[ADR-0032 Produktens ord]]. Där avgjordes *vilka ord* produkten bär. Här avgörs *vad den är till för*.

## Kontext

Systemet är byggt generiskt och beskrivet smalt.

**Koden vet ingenting om båtar.** Det är ett medvetet val som fattades tidigt och har hållits sedan dess: taggar och kategorier är ett blankt papper ([[ADR-0004 Fria taggar och kategorier]]), containern är en ägd enhet utan egenskaper ([[ADR-0002 Konto äger container]]), åtkomstmodellen talar om konton och roller, inte om varv ([[ADR-0003 Åtkomstmodell]]). Ingen tabell, kolumn eller regel i datamodellen nämner en båt.

**Texten säger något annat.** Projektbeskrivningen i `composer.json` lyder *"dokumentationsvalv för båtar, husvagnar, stugor och bilar"*. [[Översikt]] § Kärnidén öppnar med *"En container är ett ägt ting: en båt, husvagn, stuga eller bil"*. Den engelska gränssnittstexten sålde fram till ADR-0032 *"The binder for the boat, the caravan, the house and the car."* [[Konkurrens]] jämför mot Obsidian och Notion — verktyg utan en enda båtanvändare.

Frågan har diskuterats men aldrig avgjorts skriftligt. Det är därför glappet vuxit: varje ny sträng har skrivits mot det exempel som redan stod i filen, och efter M10 finns ett helt gränssnitt formulerat kring fordon och fritidshus ovanpå en datamodell som aldrig bett om det.

## Beslut

**Mimers är en generell plats för information om sådant användaren äger, använder eller arbetar med.** Följande är produktens beskrivning och källan till all användarvänd text:

> Mimers hjälper dig att samla, strukturera och hålla ordning på information om sådant du äger, använder eller arbetar med.
>
> Skapa en **container** för exempelvis en båt, bil, fastighet, kund eller ett projekt. Lägg till **objekt** och samla dokument, bilder, anteckningar, serienummer, kostnader och annan information på rätt plats. **Koppla ihop objekt** för att beskriva hur de hör samman. Skapa **uppgifter** och återkommande underhåll så att viktiga saker inte glöms bort. **Sök** snabbt fram information när du behöver den, oavsett var i containern den finns. **Dela** en container eller enskilda objekt med andra och styr vad de får se eller ändra — med kunder, leverantörer eller andra som behöver tillgång till samma information. När ett projekt eller ägande tar slut kan containern **lämnas vidare** till nästa person.
>
> Mimers ger dig en flexibel plats där information, arbete och historik kan följa det du arbetar med eller äger över tid.

Av det följer fyra bindande regler:

**Containern är ett sammanhang, inte ett fysiskt ting.** Den kan vara en båt, en bil, en fastighet, en kund eller ett projekt. [[ADR-0032 Produktens ord]] definierade containern som *det övergripande sammanhanget* — den definitionen gäller ordagrant, inte som en abstraktion över fordon.

**Exemplen är exempel, aldrig avgränsningen.** Ingen text användaren möter får påstå att produkten är till för fordon och fritidshus. Där exempel behövs ska de spänna över bredden — ett ägt ting, en kund, ett projekt — inte tre varianter av samma sak.

**Ingen domän byggs in i koden.** [[ADR-0004 Fria taggar och kategorier]] gäller oförändrat och förstärks: det blanka pappret är inte en förenkling som ska fyllas i senare, det är produkten.

**De färdiga kategoriuppsättningarna blir valbara mallar.** De är i dag det enda stället där en domän läckt in i produkten. De får finnas kvar som en genväg användaren aktivt väljer, aldrig som en förvald struktur systemet antar.

## Motivering

Omskrivningen kostar text, inte arkitektur. Datamodellen är redan den generella produkten — varje gång domänen kunde ha byggts in valdes den bort med flit. Att fortsätta sälja smalt vore att kasta bort det arbetet.

En smalare positionering är lättare att marknadsföra, och det är det starkaste argumentet mot det här beslutet. Men den smala positioneringen är redan motbevisad av systemet själv: det finns ingen båtfunktion att sälja, bara ett båtexempel att skriva om.

Tidpunkten är det avgörande. All användarvänd text ska ändå skrivas om, designen är inte påbörjad, och ingen extern användare har läst en enda sträng. Varje månad beslutet skjuts upp växer antalet strängar, mejlmallar, mockuper och tomma tillstånd som formulerats mot fel premiss.

## Konsekvenser

- **`lang/` skrivs om i sin helhet, i ett svep.** En sträng i taget går inte — tonen och exemplen måste vara en enda uppsättning. Arbetet slås ihop med språkbytet i [[ADR-0034 Engelska vid lansering]], eftersom det är samma filer.
- **Tomma tillstånd och onboarding bär bredden.** Den första skärmen en ny användare möter får inte be henne lägga till sin båt. Det är den yta där en smal formulering gör mest skada och är svårast att upptäcka i efterhand.
- **Kategoripresetarna blir en mall bakom ett val** vid containerskapande. Det är den enda kodkonsekvensen av beslutet och hör hemma i en egen issue.
- **Projektbeskrivningen, [[Översikt]] § Kärnidén och [[Konkurrens]] skrivs om.** Konkurrens.md blir mer trovärdig av bytet, inte mindre — jämförelsen mot Obsidian, Evernote och Notion har hela tiden varit en jämförelse mellan generella verktyg.
- **Prismodellen rörs inte.** Segmenten i [[Översikt]] § Vem betalar och i [[ADR-0014 Prismodell]] — varv, mäklare, charterbolag — är den första marknaden, inte definitionen av produkten. De beskriver vem som betalar först, och det är fortfarande sant.
- **Skrivna beslut skrivs inte om.** Regeln från [[ADR-0032 Produktens ord]] gäller: äldre ADR:er och stängda milstolpars backlogfiler behåller sina båtexempel. De är historik.
- **PDF-pärmen** är kvar som funktionsnamn tills den byggs, som ADR-0032 redan slagit fast.

## Alternativ

**Behålla båtpositioneringen och sälja bredden senare.** Snabbast till lansering. Valdes bort av samma skäl som [[ADR-0013 Språk och i18n]] valde bort *svenska först*: texten skrivs ändå en gång, och varje månad den smala formuleringen står kvar ökar mängden som ska bytas.

**Bygga två produkter — en för fordon, en för projekt.** Valdes bort. Samma kod, samma datamodell, samma API — två varumärken att underhålla utan att en rad skiljer dem åt.

**Bygga in domänen på riktigt: båtspecifika fält, förvald struktur, kända underhållsintervall.** Det hade gjort den smala positioneringen sann och produkten bättre för en båtägare. Valdes bort — det river [[ADR-0004 Fria taggar och kategorier]], gör det blanka pappret till en lögn, och låser produkten vid en marknad innan någon vet om det är rätt marknad.
