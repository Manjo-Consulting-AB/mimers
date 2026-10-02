# M25 · Itemets vy enligt mockupen

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

Tillagd 2026-10-02, efter testarnas genomgång av staging på `40dfeac` — hela [[M24 Desktopdesignen]] utrullad. Skärmdumparna ligger i `docs/Design/Findings-261002/`. Jämförelsen mot `docs/Design/struktur - item.jpeg` och `docs/Design/main.jpeg` visade skillnader som M24 inte täckte.

**Fynden som inte kräver något beslut har redan issues och hör inte hit:** buggarna 183–188 (GitHub #626–#631), förekomstpanelen 189 (#633) och containerlistan 190 (#634). Precis som 161–167 finns de bara i GitHub.

**Ingenting här är beslutat.** Varje avsnitt nedan är en fråga som måste besvaras innan det blir en issue. Svaret skrivs in här, och issuen får sitt nummer först därefter. **Bilden är förlagan, besluten är regeln** — se [[M24 Desktopdesignen]].

---

## Frågor att avgöra

### Itemets överdel

Mockupen har bild, namn, märket *Aktiv*, modellen som undertitel, knappen *Redigera* och en meny *…*. På staging finns bara textlänkarna *Edit*, *Delete* och *New item under this one*.

**Redan avgjort:** märket är det härledda *OK* — noll kvarvarande uppgifter i underträdet ([[ADR-0041 Itemets vy]] § Beslut, [[ADR-0040 Underträdets summor]]). Det finns ingen `status`-kolumn, och det ska inte komma någon. Omslagsbilden finns sedan [[M16 Itemets vy]] § 93.

**Öppet:**
- Är *OK* redan räknat för ett enskilt item i `Items/Show`, eller bara för containerns översikt?
- Ska *Delete* och *New item under this one* ligga i menyn *…*, eller ska *New item* gå via plusknappen ([[ADR-0048 Mobilen och plusknappen]] § 2) och därmed strykas här?

### Snabbåtkomsten

Mockupen har fem rutor: *Lägg till dokument*, *Lägg till bild*, *Skapa uppgift*, *Lägg till kostnad* och *Ny anteckning*.

**Öppet:**
- Plusknappen gör redan samma sak på samma sida. [[ADR-0050 Desktopdesignen]] § 5 strök raden *"Lägg till i denna container"* av just det skälet. Gäller samma skäl här, så att rutorna stryks?
- Behålls rutorna, vad gör då *Ny anteckning*? Anteckningen är ett enda fält (issue 96). Flera daterade anteckningar skulle kräva en tabell och en ADR — se [[Att sortera efter mockuparna]] § Itemvyn.
- *Dokument* och *bild* är båda bilagor. Ska det vara två rutor till samma flik eller en?

### Flikraden på en rad

Itemet har nio flikar: Overview, Information, Relations, Attachments, Schedules, Loans, Tags, Costs och History. Mockupen har sju: Översikt, Detaljer, Relationer, Dokument, Kostnader, Uppgifter och Historik. Över `md:` bryts raden till två rader (`UiTabs`, issue 151). Med märken får nio flikar inte plats i mittenkolumnen, hur tätt de än packas — **det här är inte en layoutfråga.**

**Öppet — välj en:**
- **Färre flikar.** Taggarna flyttar till *Information* eller till överdelen, och utlåningen till *Overview* eller till en meny. Det motsvarar mockupen.
- **Raden skrollar i sidled även över `md:`.** Det rör `UiTabs`, som också containern använder, och går emot motiveringen i issue 151.
- **Behåll två rader.**

### Kartans lägen

Mockupen har *Graf*, *Lista* och *Fokus*, zoom, helskärm och en teckenförklaring med räknare. Staging har bara fokuskartan ([[M23 Mobilen och kartan]] § 156). *Samma item* i teckenförklaringen är redan förekomsterna (189).

**Öppet:**
- Behövs grafläget, eller räcker fokuskartan plus containerns karta ([[ADR-0046 Containerns karta]])? Ett fritt utlagt grafläge är det största momentet i hela milstolpen och behöver i så fall en egen ADR.
- *Lista* är i praktiken relationsfliken. Ska den vara ett läge i panelen eller en länk dit?

### Skalet

Mockupen har sökfältet till vänster med ⌘K, ikoner på varje rad i sidopanelen och användaren längst ned med plan. På staging ligger sökfältet till höger. *Tony Me* och *Log out* står bland raderna, och raderna saknar ikoner.

**Redan avgjort:** raderna är exakt de som `ShellSections` har, och toppraden bär plusknappen, sökfältet, klockan och avataren ([[ADR-0050 Desktopdesignen]] § 1). Placeringen inom toppraden är inte avgjord.

**Öppet:**
- Ska sökfältet flytta till vänster, och ska ⌘K ingå? Issue 187 (#630) rättar centreringen i dagens placering. Den kan byggas nu och flyttas sedan, eller vänta på det här svaret.
- Ska användaren flytta från raderna ned till sidopanelens fot, med *Log out* i en meny där? Det ändrar `ShellSections`, som också mobilens sidomeny läser ([[ADR-0048 Mobilen och plusknappen]] § 1).
- Ikonerna: ett eget ikonset eller inline-SVG som i dag?
