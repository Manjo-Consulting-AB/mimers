# ADR-0036 Containerns art

**Status:** Antagen 2026-09-17 · Kompletterar [[ADR-0033 Produktens omfång]] · [[ADR-index]]

Fattat vid genomgången av den första omgången mockuper. [[ADR-0033 Produktens omfång]] slog fast att ingen domän byggs in i koden. Den här ADR:n städar upp efter det stället där domänen redan är inbyggd.

## Kontext

`container.kind` bär ett CHECK-villkor i databasen:

```sql
CHECK (kind IN ('boat', 'caravan', 'house', 'car', 'other'))
```

Listan finns också som `Container::KINDS` i modellen, och därifrån i `StoreContainerRequest`, `UpdateContainerRequest`, två propar ur `ContainerController` och typlistan i `Containers/Edit.vue`.

Fältets avsikt är oklanderlig. Modellens egen kommentar säger att *"`kind` styr bara presentation och mallval — systemet beter sig aldrig olika beroende på värdet"*, och ingen `match` eller `if` på `kind` finns i koden. Fältet gör alltså redan det [[ADR-0004 Fria taggar och kategorier]] kräver: det påverkar inte beteendet.

Men värdemängden gör något annat. Den påstår att världen består av båtar, husvagnar, hus, bilar och *övrigt*. Ett kundprojekt är `other`. En fastighetsförvaltning är `other`. Ett bandprojekt är `other`. Produkten [[ADR-0033 Produktens omfång]] beskriver — *en båt, bil, fastighet, kund eller ett projekt* — får plats i det här fältet bara om man räknar de tre sista som skräp.

Det är den bokstavliga definitionen av inbyggd domän: fem värden i ett schema som säger vad systemet tycker att saker är. Jag såg det inte när ADR-0033 skrevs, därför att jag läste datamodellens dokument och inte dess migreringar.

Mockuparna gör problemet till en gränssnittsfråga också. De visar navigeringen grupperad — *Mina containers* och *Projekt* som två rubriker — vilket är rätt idé med fel mekanism, eftersom ett projekt är en container och inte en andra sorts sak.

## Beslut

**`kind` är ett fritt textfält som användaren själv namnger.** CHECK-villkoret och `Container::KINDS` utgår.

**Inmatningen sker med autocomplete på de värden kontot redan använt.** Samma mönster och samma motivering som leverantörsfältet i [[ADR-0016 Kostnadsregistrering]]: det som faktiskt går sönder är stavningsvarianter, och det löses vid inmatningen genom att användaren väljer ett befintligt värde — inte genom att normalisera i schemat.

**Navigeringen grupperar på `kind`, men bara när en art bär minst två containrar.** En art med en enda container ligger löst i listan. Utan den regeln får den som äger en båt, ett hus och en bil tre rubriker med ett objekt under varje.

**Fältet påverkar fortfarande aldrig beteendet.** Regeln i `Container`s klasskommentar står oförändrad och blir viktigare, inte mindre viktig: ingen `match` eller `if` på `kind` någonstans. Ett fritt fält som styr logik är värre än en sluten lista som gör det.

**Kategorimallarna kopplas loss från `kind`.** De väljs uttryckligen av användaren, som [[ADR-0033 Produktens omfång]] redan beslutat, och härleds inte ur vad hon råkade kalla sin container.

## Motivering

Ett fritt fält är den enda formen som överlever [[ADR-0033 Produktens omfång]]. Varje sluten lista är ett påstående om vilka sammanhang som finns, och det påståendet blir fel i samma sekund som någon använder produkten till något vi inte tänkt på — vilket är hela poängen med att bygga den generisk.

Att göra listan längre löser ingenting. Lägger vi till `project`, `customer` och `property` har vi åtta värden i stället för fem och exakt samma problem: den nionde användaren är fortfarande `other`. Och en längre lista måste dessutom översättas, vilket [[ADR-0004 Fria taggar och kategorier]] valde bort av precis det skälet.

Fältet är dessutom redan ofarligt att frigöra. Det styr inte beteende, det har ingen främmande nyckel, och ingen kod förgrenar sig på det. Det som håller emot är ett CHECK-villkor och en konstant — inte en arkitektur.

Grupperingsregeln på minst två är inte kosmetik. Navigeringen är den yta där en tom struktur gör mest skada: en lista med rubriker som var och en innehåller ett objekt ser ut som ett fel i programmet, och den som möter den slutar lita på indelningen.

## Konsekvenser

- **Migrering:** CHECK-villkoret släpps. Ingen rad ändras — de fem befintliga värdena är fortfarande giltiga strängar.
- **`Container::KINDS` utgår**, och med den `Rule::in(Container::KINDS)` i `StoreContainerRequest` och `UpdateContainerRequest`. Validering blir längd och format, inte medlemskap.
- **De två `kinds`-proparna ur `ContainerController` byter innebörd** — från *de tillåtna värdena* till *de värden kontot redan använt*, för autocomplete. `Containers/Edit.vue` följer med.
- **`CategoryPresetCard.vue` och `KategoriuppsattningTest` tappar sin nyckel.** Presetarna kan inte längre slås upp på `kind`. Det är samma arbete som [[ADR-0033 Produktens omfång]] redan kräver — mallen blir ett val användaren gör — och de två hör ihop i samma issue.
- **Referenserna i `Invitation` och `Schedule` till `Container::KINDS`** är kommentarer som pekar på konstanten som mönster. De byter förlaga, inte beteende.
- **Tomma tillstånd får ett nytt fall:** en användare som lämnar `kind` tomt. Fältet bör vara frivilligt — att tvinga fram en art vid skapandet är att ställa en fråga användaren ännu inte kan svara på.
- **Navigeringens gruppering hör till designarbetet**, inte hit. Den här ADR:n bestämmer regeln, inte utseendet.

## Alternativ

**Utöka listan med `project`, `customer` och `property`.** Minsta möjliga ändring, behåller valideringen. Valdes bort — flyttar gränsen utan att ta bort den, och kräver översättning av varje nytt värde.

**Ta bort `kind` helt och låta taggar göra jobbet.** Renast mot [[ADR-0004 Fria taggar och kategorier]], och tekniskt fullt möjligt. Valdes bort — navigeringens gruppering behöver exakt ett värde per container, och en tagg kan vara flera. Det är samma rollfördelning som mellan kategori och tagg på itemet: **arten är var containern hör hemma, taggarna är allt annat.**

**Behålla listan och lägga till en fritextvariant bakom `other`.** Valdes bort — ger två fält som betyder samma sak och en sorteringsordning ingen kan förklara.
