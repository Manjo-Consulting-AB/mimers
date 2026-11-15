# ADR-0080 Ny förälder från kartan

**Status:** Föreslagen 2026-11-15 · Ändrar [[ADR-0048 Mobilen och plusknappen]] § 4 (grafens meny) · Bygger vidare på [[ADR-0059 Kartan med en mitt]] § 4, [[ADR-0063 Itemets karta i containerkartans form]] § 4, [[ADR-0028 Åtkomst på itemnivå]] och [[ADR-0069 Flytta ett item inom containern]] · [[ADR-index]]

Förslag från 2026-11-15 om att kunna skapa en ny förälder från kartan och plusmenyn. Det är inte beslutat. Tony ändrar statusen till *Antagen* när han bestämmer sig. Kodarbetet ligger i issue 1298.

## Kontext

Plusmenyn på kartans mittkort har *Item under* och *Relation* (`CreateTarget::forNode`). [[ADR-0048 Mobilen och plusknappen]] § 4 bestämde de raderna och valde bara bort *Skapa sibling*. Att skapa en förälder prövades aldrig. Raderna ärvdes från plusknappen, som bara skapar nedåt.

Relationsfliken kan redan koppla ett **befintligt** item som förälder (`StoreItemLinkRequest` tar emot `parent`). Men den som ser att det saknas en nivå ovanför måste i dag skapa itemet någon annanstans, gå tillbaka och koppla det.

## Beslut

**1. Raden *Item above*.** Itemets plusmeny får raden *Item above*, efter *Item below*. Eftersom `forNode` filtrerar ut kartans rader ur samma lista får kartans mittmeny den också, både i containerkartan och i itemets karta. Raden leder till formuläret för att skapa ett item med `?child=<itemets ULID>`.

**2. Formuläret tar emot `child`.** Formuläret och `POST /containers/{c}/items` (och motsvarande rutt under `/api`) tar emot `child`, ett item i samma container som inte är raderat. Det nya itemet skapas, och kanten `nytt → child` skapas i samma transaktion genom `LinkItems`. I formuläret står barnet som en rad, *Above: <namn>*, med en knapp som tar bort det. Ingen väljare.

**3. Placeringen.** Det nya itemet hamnar där placeringsraden säger, alltså på toppnivån i containern om man inte väljer en förälder med *Ändra*. Barnets befintliga placeringar ändras inte. Har barnet redan en förälder får det en placering till (⇄). Ingen flytt sker.

**4. Grinden.** Samma som för att skapa (`createItem` på containern, eller `create` på den valda föräldern) och dessutom `update` på barnet. Det är samma grind som när en länk skapas på relationsfliken, där båda ändarna skrivs. Raden visas bara när båda grindarna släpper igenom.

**5. Felen.** Cykler och självlänkar fångas av `LinkItems` som förut (`item_link.cycle`, `item_link.self`), och hela skapandet rullas tillbaka. På webben blir felet ett fältfel på `child`.

## Motivering

Kartan visar var det saknas en nivå, så det är där man vill kunna lägga till den. Att skapa och koppla i samma transaktion tar bort omvägen över relationsfliken, utan att någon ny operation på grafen behövs: skapandet och `LinkItems` finns redan, med sina regler och sina fel. Att barnet behåller sina placeringar gör att raden bara lägger till och aldrig flyttar något. Flytten har redan sin plats i [[ADR-0069 Flytta ett item inom containern]].

## Konsekvenser

- Grafens meny har tre rader i stället för två: *Item below*, *Item above* och *Relation*. *Sibling* är fortfarande struket ([[ADR-0048 Mobilen och plusknappen]] § 4).
- Barnet får en ny förälder och ärver därmed dess åtkomst ([[ADR-0028 Åtkomst på itemnivå]] regel 1). Det är samma effekt som när en länk skapas eller ett item flyttas ([[ADR-0069 Flytta ett item inom containern]] § 7). Skapas den nya föräldern under en delad förälder delas barnets underträd med förälderns mottagare. Någon varning i gränssnittet ingår inte.
- Ett barn som redan har en förälder får två placeringar. Den som ville lägga in den nya nivån mellan förälder och barn flyttar barnet efteråt med *Move*.

## Alternativ

**Infoga mellan**, alltså att det nya itemet tar barnets plats under dess förälder och barnet flyttas ned. Överkurs för tillfället (Tony 2026-11-15): det räcker att kunna skapa en relation, ett barn eller en förälder, och flytten finns redan i [[ADR-0069 Flytta ett item inom containern]].

**Förifylla placeringen med barnets förälder.** Valdes bort. Det ger barnet två placeringar som ligger bredvid varandra.

**En plusmeny på varje nod.** Valdes redan bort i [[ADR-0059 Kartan med en mitt]] och [[ADR-0063 Itemets karta i containerkartans form]].
