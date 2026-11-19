# M68 · Egna fält

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-11-19, efter diskussionen om att itemets sex fasta fält passar en regulator men inte en garderob. Användaren definierar egna typade fält (text, tal, datum) på containern och på enskilda items, de sex fasta fälten blir vanliga fält, och mallar bär kategorier och fält tillsammans. Beslutet och motiveringen står i [[ADR-0089 Egna fält på items]].

Nio issues. Inget arv till barnen och inga påminnelser för datumfält. Se ADR:ns Riktning 7 och § Kvar.

**Ordningen mot M67.** [[M67 Versionshistorik]] är inte byggd. Issue 412 beror på 420 här, så att `item_version` byggs med fältvärdena och inte med de sex kolumnerna. 411 kan gå före eller samtidigt.

**Releasen:** två. Den första när 417–424 är mergade. 425 tar bort kolumnerna och går ut i releasen efter, enligt expand/contract i AGENTS.md § Databaskonventioner. Issuerna är inte skapade i GitHub än.

---

### 417. Tabellerna, gränserna och fältdefinitionerna

**Läget:** ett item har bara de fasta fälten, och det finns ingen plats att definiera egna.

**Lösningen:** tabellerna `item_field` och `item_field_value` och config-nycklarna `fields.max_per_container`, `fields.max_per_item` och `fields.text_max_length`. Actions för att skapa, ändra, sortera och ta bort en definition, både på containern och på ett item. API:et får rutterna under containern och under itemet, med ULID:er. Containerns fält kräver skrivrätt i containern, och itemets egna kräver `ItemPolicy::update`. En borttagen definition går till papperskorgen som en kategori, och en återställning prövas mot gränsen. Båda tabellerna räknas i `MetadataSize` och `CountedRows`. Inga värden skrivs ännu.

**Läs:** [[ADR-0089 Egna fält på items]] § Riktning 1–4 och 9, [[Items och organisation]] § item_field, [[ADR-0008 Soft delete och papperskorg]] § Uppföljning 2026-09-26, [[ADR-0081 All lagrad data räknas mot kvoten]] § 2 och 3

**Axlar:** `ambiguity` low · `blast_radius` cross-module (kvotens räknade tabeller och papperskorgen) · `risk_class` elevated (kvoter, behörighet och radering)

**Klart när:** en användare med `write` skapar, döper om och sorterar containerns fält, och en med `view` får 403; det 26:e fältet i en container och det 11:e egna fältet på ett item avvisas med `field.limit_reached` och gränsen i `data`; en rubrik som krockar utan hänsyn till skiftläge avvisas med `field.label_taken`; ett typbyte utan värden går igenom; ett borttaget fält syns i papperskorgen och kommer tillbaka; en återställning över gränsen avvisas; en ny definition räknas i `metadata_bytes` och avstämningen stämmer; hela sviten är grön.

**Beror på:** ADR-0089 i valvet

---

### 418. Värdena på itemet

**Läget:** fälten kan definieras (417), men ett item kan inte bära ett värde.

**Lösningen:** `StoreItemRequest` och `UpdateItemRequest`, på webben och i API:et, tar `fields` som en lista med fältets ULID och värdet. Värdet valideras mot fältets typ: text upp till `fields.text_max_length`, tal som `DECIMAL(20,6)`, datum som `Y-m-d`. `CreateItem` och `UpdateItem` skriver `item_field_value` i samma transaktion som itemet. Ett tomt värde tar bort raden. `ItemResource` returnerar itemets fält i ordning, containerns först, med rubrik, typ och värde. Ett typbyte på ett fält med värden avvisas nu med `field.type_locked`. Fält i papperskorgen syns inte och tar inte emot värden.

**Läs:** [[ADR-0089 Egna fält på items]] § Riktning 3 och 9, [[Items och organisation]] § item_field_value, AGENTS.md § Felformat i API:et

**Axlar:** `ambiguity` low · `blast_radius` cross-module (`UpdateItem` läses av allt som skriver items) · `risk_class` elevated (kvoter)

**Klart när:** ett textvärde, ett tal och ett datum sparas och läses tillbaka; text i ett talfält ger ett valideringsfel på fältet; ett värde för ett fält i en annan container eller på ett annat item ger `field.not_applicable`; ett tömt värde tar bort raden och `metadata_bytes` minskar; ett typbyte efter första värdet ger `field.type_locked`; ett fält i papperskorgen syns inte i `ItemResource` och dess värde är tillbaka efter återställningen; hela sviten är grön.

**Beror på:** 417

---

### 419. Fälten i gränssnittet

**Läget:** fälten och värdena finns i API:et (417, 418) men syns inte i appen.

**Lösningen:** containerns inställningar får avsnittet *Fields*: lista, lägg till med rubrik och typ, döp om, sortera och ta bort. Typvalet är låst med en förklaring när fältet har värden. `ItemForm.vue` ritar containerns fält efter typ, med tomma fält ihopfällda som *More details* gör i dag, och har knappen *Add field* för itemets egna fält. Itemets vy visar bara fält som har ett värde. Felkoderna från 417 och 418 översätts i `lang/`.

**Läs:** [[ADR-0089 Egna fält på items]] § Riktning 1–4, [[ADR-0042 Designsystemet]]

**Axlar:** `ambiguity` high (formulärets utformning finns inte i mockuparna) · `blast_radius` contained · `risk_class` none

**Klart när:** ett fält som läggs till i inställningarna syns i formuläret för alla items i containern; ett eget fält som läggs till på ett item syns bara där; ett tomt fält syns inte i itemets vy; ett datumfält har en datumväljare och ett talfält tar bara tal; gränsen och rubrikkrocken visas som fel på rätt fält; `npm run build` och hela sviten är gröna.

**Beror på:** 417, 418

---

### 420. De sex kolumnerna blir fält

**Läget:** `manufacturer`, `model`, `serial_number`, `purchased_at`, `warranty_until` och `position_note` är kolumner på `item` vid sidan av de egna fälten.

**Lösningen:** en migrering ger varje befintlig container de sex som containerfält med formulärets engelska rubriker, datum för `purchased_at` och `warranty_until`, och kopierar värdena till `item_field_value`. Den räknar om `metadata_bytes`. Koden slutar läsa och skriva kolumnerna: `Item`, `UpdateItem`, `StoreItemRequest`, `UpdateItemRequest`, `ItemResource`, `ContainerExportBuilder` och exportvyn. Fritextsökningen söker i `item_field_value.value_text` vid sidan av namn och beskrivning. Kolumnerna står kvar orörda tills 425. Nya containrar får inga fält.

**Läs:** [[ADR-0089 Egna fält på items]] § Riktning 5 och 9, [[Items och organisation]] § Sök och filtrering, [[ADR-0012 Sök]], AGENTS.md § Databaskonventioner

**Axlar:** `ambiguity` low · `blast_radius` cross-module (itemets alla läs- och skrivvägar, exporten och sökningen) · `risk_class` elevated (migrering av användardata och kvoter)

**Klart när:** efter migreringen har varje befintlig container sex fält och varje icke-tomt värde finns som `item_field_value`; en container utan items får också de sex; en ny container får inga; ett sök på ett serienummer hittar itemet; exporten bär fälten; avstämningen av `metadata_bytes` stämmer efter migreringen; migreringen går igenom i migreringsvakten mot MariaDB 11.4; hela sviten är grön.

**Beror på:** 418

---

### 421. Mallarna: tabellen och API:et

**Läget:** kategoriförslagen finns bara i frontenden, och det går inte att spara en containers uppsättning för att använda den igen.

**Lösningen:** tabellen `container_template` med kontot som ägare, ett namn och innehållet i JSON: kategoriträdet och containerns fält. API:et får lista, spara från en container, döp om, ta bort, skapa en container från en mall och tillämpa en mall på en befintlig container. Tillämpningen hoppar över fält vars rubrik redan finns och avvisar hela tillämpningen med `field.limit_reached` om resultatet går över gränsen. Högst `templates.max_per_account` mallar per konto. Mallen räknas i `metadata_bytes`. Ingenting i containern pekar tillbaka på mallen.

**Läs:** [[ADR-0089 Egna fält på items]] § Riktning 6 och 9, [[ADR-0033 Produktens omfång]], [[Items och organisation]] § container_template

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` elevated (kvoter)

**Klart när:** en mall som sparas från en container bär dess kategorier och fält men inga items; en container som skapas från mallen har samma kategorier och fält; en ändring i mallen efteråt rör inte containern; en tillämpning på en container med ett fält med samma rubrik hoppar över det; en tillämpning över gränsen ändrar ingenting; den 51:a mallen avvisas; en annan användare når inte mallen; hela sviten är grön.

**Beror på:** 417

---

### 422. Mallarna i gränssnittet och de inbyggda förslagen

**Läget:** mallarna finns i API:et (421) men inte i appen, och kategoriförslagen i `categoryPresets.js` bär inga fält.

**Lösningen:** att skapa en container får valet att utgå från en egen mall eller ett inbyggt förslag. Containerns inställningar får *Save as template* och *Apply template*. Kontot får en lista över sina mallar där de kan döpas om och tas bort. `categoryPresets.js` blir inbyggda mallar som kan bära fält, till exempel *Manufacturer*, *Model*, *Serial number* och *Warranty until* för elektronik, och tillämpas genom samma API som en egen mall utan att servern sparar dem.

**Läs:** [[ADR-0089 Egna fält på items]] § Riktning 6, [[ADR-0004 Fria taggar och kategorier]] § Konsekvenser, [[ADR-0034 Engelska vid lansering]]

**Axlar:** `ambiguity` high (var mallarna bor i inställningarna finns inte i mockuparna) · `blast_radius` contained · `risk_class` none

**Klart när:** en container skapad från ett inbyggt förslag får förslagets kategorier och fält; *Save as template* följt av en ny container från mallen ger samma uppsättning; en mall som tas bort försvinner ur valet; `tests/Feature/Frontend/SprakTest.php` är grönt; `npm run build` och hela sviten är gröna.

**Beror på:** 421, 419

---

### 423. Fälten och mallarna för agenter

**Läget:** MCP-verktygen känner bara de sex kolumnerna och vet ingenting om egna fält eller mallar.

**Lösningen:** `items_get` returnerar itemets fält med rubrik, typ och värde. `items_create` och `items_update` tar `fields` och tappar parametrarna `manufacturer`, `model`, `serial_number`, `purchased_at`, `warranty_until` och `position_note`, utan avvecklingstid eftersom MCP inte är utrullat. `items_search` söker i textvärdena. Nya verktyg: `fields_list`, `fields_create`, `fields_update` och `fields_delete` i rättighetsgruppen `AgentGroup::Items`, som kategorierna, och `templates_list`. `containers_create` tar ett valfritt `template`, och en mall kan tillämpas på en befintlig container. Ingen agent får spara eller ta bort en mall. `fields_delete` räknas mot raderingstaket och syns i notisen. Kontraktsprovet uppdateras med avsikt.

**Läs:** [[ADR-0089 Egna fält på items]] § Riktning 8, [[ADR-0087 MCP-server för agenter]] § Riktning 1, 7, 8, 10, 13 och 15

**Axlar:** `ambiguity` low · `blast_radius` cross-module (MCP-verktygen och kontraktsprovet) · `risk_class` elevated (agentens skriv- och raderingsrättigheter)

**Klart när:** `items_get` visar fälten; `items_update` med ett värde av fel typ ger samma felkod som API:et med en `hint`; `fields_create` avvisas för en agent utan skrivrätt i gruppen; `fields_delete` räknas mot taket och lägger fältet i papperskorgen; `containers_create` med `template` ger mallens kategorier och fält; det finns inget verktyg som sparar eller tar bort en mall; kontraktsprovet har de nya verktygen och saknar de sex parametrarna; hela sviten är grön.

**Beror på:** 418, 420, 421

---

### 424. Sortera och filtrera på fält

**Läget:** värdena är typade (418) men listan och sökningen kan bara filtrera på tagg, kategori, uppgifter och utlåning.

**Lösningen:** sökningen i API:et och på webben tar ett filter per fält: lika med för text, intervall för tal och datum. Listan kan sorteras på ett containerfält. Ett filter på ett fält i en annan container ger `field.not_applicable`. Listans filterpanel får fälten. `items_search` i MCP får samma filter som en valfri parameter.

**Läs:** [[ADR-0089 Egna fält på items]] § Riktning 1, [[Items och organisation]] § Sök och filtrering, [[ADR-0012 Sök]]

**Axlar:** `ambiguity` high (filterpanelens utformning för fält finns inte i mockuparna) · `blast_radius` cross-module (sökningen och listan) · `risk_class` none

**Klart när:** ett talintervall ger bara items inom intervallet och 9 sorteras före 10; ett datumintervall som "inom 30 dagar" fungerar på ett datumfält; items utan värde hamnar sist vid sortering; ett filter på ett fält i papperskorgen ignoreras inte tyst utan ger ett fel; frågan använder index enligt `EXPLAIN` mot MariaDB; hela sviten är grön.

**Beror på:** 418, 419

---

### 425. Kolumnerna tas bort

**Läget:** efter 420 läser ingen kod `manufacturer`, `model`, `serial_number`, `purchased_at`, `warranty_until` eller `position_note`, men kolumnerna finns kvar på `item`.

**Lösningen:** en migrering tar bort de sex kolumnerna och bygger om FULLTEXT-indexet till `(name, description)`. Kräver att releasen med 420 redan är ute i produktion.

**Läs:** [[ADR-0089 Egna fält på items]] § Riktning 5, AGENTS.md § Databaskonventioner

**Axlar:** `ambiguity` low · `blast_radius` contained · `risk_class` elevated (destruktiv migrering)

**Klart när:** kolumnerna finns inte; ingen kod i `app/`, `resources/` eller `database/factories/` nämner dem; migreringen går igenom i migreringsvakten mot MariaDB 11.4; hela sviten är grön.

**Beror på:** 420, och att releasen med 420 är utrullad
