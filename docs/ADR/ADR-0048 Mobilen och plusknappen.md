# ADR-0048 Mobilen och plusknappen

**Status:** Antagen 2026-09-27 · Kompletterar [[ADR-0042 Designsystemet]] med mobilmockupen · Står fast vid [[ADR-0035 Relationen mellan objekt]] och [[ADR-0041 Itemets vy]] · [[ADR-index]]

Tonys beslut 2026-09-27, vid genomgången av `docs/Design/mobil.png` och vid planeringen av [[M23 Mobilen och kartan]].

## Kontext

Designern lämnade en femte bild 2026-09-27: tio mobilskärmar. De fyra första bilderna var desktop, och frontenden har hittills gjort mobilen genom att fälla ihop desktop under brytpunkten `md:`. En meny bakom en knapp, sökfältet på en egen rad och flikraden som den är.

Bilden bygger på tokens och komponenter ur [[ADR-0042 Designsystemet]] och säger inte emot dem. Den tillför ett skal, en knapp som skapar saker och några flöden. Den återinför också ett ord som redan är strukit.

## Beslut

### 1. Mobilskalet

Under `md:` får appen ett eget skal i stället för den hopfällda desktopraden:

- **En mörk topprad** (`--color-shell`) med sidans titel. Inne i en container visas containerns namn och bild, och en tillbakaknapp.
- **En flikrad i botten** med fem platser: *Översikt*, *Sök*, plusknappen i mitten, *Notiser* och *Meny*. Notisklockans taltuta från issue 127 följer med.
- **En sidomeny bakom *Meny*.** Den innehåller det desktopskalets sidopanel redan har, i samma ordning, plus en sektion. Överst *Översikt* och *Sök*, sedan *Nyligen besökta* enligt [[ADR-0049 Nyligen besökta]], sedan favoriterna från issue 106, därefter sidorna i den container man står i, sedan de övriga containrarna och sist *Inställningar*.

**Sidomenyn bryter inte mot [[ADR-0041 Itemets vy]].** Den har inga egna rader för Struktur, Karta, Uppgifter eller Kostnader över alla containers. Den listar containerns egna sidor, och varje länk leder in i en container. *Nyligen besökta* är genvägar till items i sina containrar, inte en navigering vid sidan av dem.

Över `md:` ändras skalet bara genom att sidopanelen får sektionen *Nyligen besökta*. Trepanelslayouten är oförändrad.

### 2. Plusknappen skapar det vyn visar

Knappen är samma på mobil och desktop. Vad den gör avgörs av var man står:

| Man står i… | Knappen |
|---|---|
| containerlistan eller dashboarden | skapar en **container** |
| en container (översikten, itemfliken, kartan) | skapar ett **item** i containern. Står man på en nod i kartan eller trädet blir noden förälder |
| ett item | öppnar en **meny** med det som kan skapas i itemet |

Menyn i ett item har fem rader: *Item under* (ett child till itemet), *Relation* (en `related`-länk till ett annat item), *Bild eller dokument* (en bilaga), *Uppgift* (ett schema) och *Kostnad* (en kostnadsrad). Anteckningen står inte där, eftersom den är ett fält som redigeras och inte något som skapas.

**Knappen visas bara för den som får skapa det den skapar.** Grindarna är de som redan finns: `ContainerPolicy::create` på kontot, `ContainerPolicy::createItem` eller `ItemPolicy::create` på föräldern, och för menyns rader samma policyer som flikarna i itemvyn redan använder. En rad man inte får använda visas inte. Blir menyn tom visas inte knappen heller.

Övriga sidor, till exempel inställningar, uppgiftslistan och sökningen, har ingen plusknapp.

### 3. Ett item skapas där man står

Formuläret för att skapa ett item får placeringen ifylld från där man kom: containern, och föräldern om man stod på ett item eller en nod. Placeringen visas som en rad med en *Ändra*-knapp, och *Ändra* öppnar en väljare med containerns träd och ett sökfält. Resten av formuläret är namnet och en lista med sådant man kan lägga till efteråt, till exempel beskrivning, taggar och bilder.

Formuläret tar redan emot `?parent` (issue 57b och 58). Det som är nytt är väljaren och att varje ingång skickar med föräldern.

### 4. *Sibling* är fortfarande struket

Bilden visar *Sibling* på två ställen: som en grupp i relationsvyn och som *Skapa sibling* i grafens meny. Det är samma sammanblandning som [[ADR-0035 Relationen mellan objekt]] rättade, och beslutet där står sig. Relationerna är tre: parent, child och related. Ordet *sibling* förekommer inte i gränssnittet.

- **Relationsvyn** visar förälder, barn och relaterade. Items som delar förälder visas inte som en egen grupp. De syns i trädet.
- **Grafens meny** har *Item under* och *Relation*, samma rader som plusknappens meny. *Skapa sibling*, alltså ett nytt item med samma förälder, finns inte. Den som vill det står på föräldern och väljer *Item under*.

## Motivering

Ett skal med en flikrad i botten är det mönster en telefonanvändare redan kan, och det gör de fyra vanligaste målen nåbara med tummen. Den hopfällda desktopraden kräver två tryck för allt och lägger sökfältet i vägen för innehållet.

En plusknapp som skapar det vyn visar behöver ingen etikett: den som står i en lista över containers väntar sig en ny container. Menyn i ett item är undantaget, eftersom ett item bär fem sorters innehåll och knappen inte kan gissa vilket.

## Konsekvenser

- Skalet är [[M23 Mobilen och kartan]] § 151, plusknappen § 152, skapandet på plats § 153 och relationsvyn och fokuskartan § 155 och § 156.
- [[ADR-0042 Designsystemet]] fick åtta kärnkomponenter, *"uttömmande för de fyra bilderna"*. Mobilbilden lägger till två: arket som glider upp nerifrån (plusknappens meny, bildvalet) och flikraden i botten. De byggs i § 151 och § 152, en gång var.
- Mockupens *Favoriter* som egen flik i containern tas inte in. Favoriterna har sin lista i skalet sedan issue 106, och på mobilen hamnar den i sidomenyn.
