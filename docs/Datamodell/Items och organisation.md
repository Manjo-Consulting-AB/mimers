# Items och organisation

Själva innehållet. Läs [[Datamodell – översikt]] först.

Beslut bakom detta: [[ADR-0004 Fria taggar och kategorier]], [[ADR-0016 Kostnadsregistrering]], [[ADR-0089 Egna fält på items]].

## item

Grundenheten. Allt användaren vill komma ihåg är ett item — en MPPT-regulator, en garderob, ett kvitto, en genomföring.

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| container_id | FK | |
| category_id | FK NULL | **Högst en.** Se nedan. |
| name | VARCHAR(255) | |
| description | TEXT NULL | |
| notes | TEXT NULL | Markdown. Se [[ADR-0066 Itemets anteckning]] |
| manufacturer | VARCHAR(255) NULL | **Utgår**, blir ett eget fält. Se nedan |
| model | VARCHAR(255) NULL | **Utgår** |
| serial_number | VARCHAR(255) NULL | **Utgår** |
| purchased_at | DATE NULL | **Utgår** |
| warranty_until | DATE NULL | **Utgår** |
| position_note | VARCHAR(255) NULL | **Utgår** |
| icon | VARCHAR(64) NULL | Ett Lucide-namn i kebab-case. Servern kontrollerar formatet, inte att ikonen finns. Se [[ADR-0060 Itemets ikon]] |
| icon_color | VARCHAR(16) NULL | En av paletten i `Item::ICON_COLORS`. `NULL` ritas som `slate` |
| created_by_user_id | FK NULL | `NULL` när personen raderats |
| created_by_account_id | FK NULL | Vilket *konto* posten tillskrivs — varvet, inte den anställde. `NULL` när kontot raderats |
| billed_account_id | FK → account | Kontot som betalar för radens byte mot kvoten. Se [[Planer och kvoter]] § usage_counter |
| deleted_at | | |

Index: `(container_id, deleted_at)`, `(container_id, category_id, deleted_at)`, FULLTEXT på `(name, description, manufacturer, model, serial_number)`, som blir `(name, description)` när kolumnerna tas bort.

**De sex kolumnerna som utgår** blir containerfält med sina värden i `item_field_value` ([[ADR-0089 Egna fält på items]] § Riktning 5). Koden slutar använda dem i [[M68 Egna fält]] issue 420, och de tas bort i issue 425, i releasen efter.

**Position modelleras inte som en egen entitet.** "Akterruffen" är en tagg eller kategori som användaren hittat på; systemet vet inte att det är en plats. Se [[ADR-0004 Fria taggar och kategorier]]. Kartfunktionen ligger utanför MVP och kan byggas ovanpå taggarna senare utan schemaändring.

## item_version

Läget före en ändring av ett item, så att en överskrivning går att få tillbaka. Tabellen och dess regler står i [[ADR-0088 Versionshistorik för items]]. Versionerna räknas mot **containerns ägare** (Riktning 3), och skrivningar som kommer tätt blir en version (Riktning 4).

| Kolumn | Typ | Not |
|---|---|---|
| id | BIGINT PK | |
| ulid | CHAR(26) UNIQUE | |
| item_id | FK → item, cascade | |
| billed_account_id | FK → account | Containerns ägare när versionen skapades |
| name, description, notes | samma typer som på `item` | |
| field_values | JSON | Itemets fältvärden, nycklade på fältets id. Ersätter de sex kolumnerna, se [[ADR-0089 Egna fält på items]] § Riktning 9 |
| category_id | FK → category NULL, nullOnDelete | |
| cover_attachment_id | FK → attachment NULL, nullOnDelete | |
| icon, icon_color | som på `item` | |
| tag_ids | JSON | Taggarnas löpnummer, sorterade |
| replaced_by_user_id | FK → user NULL, nullOnDelete | Den som skrev ändringen som ersatte versionen |
| replaced_by_agent_id | BIGINT UNSIGNED NULL | Agenten, utan främmande nyckel, som `audit_log.agent_id` |
| replaced_at | TIMESTAMP | Senaste skrivningen i skurven |
| created_at | TIMESTAMP | |

Index `(item_id, created_at)`.

## item_field

Ett fält som användaren har definierat: en rubrik och en typ. Gäller alla items i containern, eller bara ett item när `item_id` är satt. Se [[ADR-0089 Egna fält på items]].

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| container_id | FK → container | Satt även för ett items egna fält |
| item_id | FK → item NULL | `NULL` för containerns fält, satt för ett items egna |
| label | VARCHAR(100) | Unik utan hänsyn till skiftläge bland fälten som syns på ett item. Prövas i applikationen |
| type | VARCHAR(10) | `text` \| `number` \| `date` \| `boolean`, med CHECK-villkor. Låst när fältet har värden |
| position | INT | Ordningen bland containerns fält, eller bland itemets egna |
| billed_account_id | FK → account | Se [[Planer och kvoter]] § usage_counter |
| deleted_at | | Papperskorgen, som `category` |

Index: `(container_id, item_id, deleted_at, position)`, `(item_id, deleted_at)`.

Gränserna står i config: `fields.max_per_container` (25) och `fields.max_per_item` (10). Bara fält som inte är raderade räknas.

### item_field_value

Ett items värde i ett fält. En rad per item och fält, och ett tomt värde har ingen rad.

| Kolumn | Typ | Not |
|---|---|---|
| id | BIGINT UNSIGNED PK | Ingen `ulid`: värdet adresseras med item och fält |
| item_id | FK → item, CASCADE | |
| item_field_id | FK → item_field, CASCADE | |
| value_text | VARCHAR(255) NULL | Satt när fältet är `text`. Längden prövas mot `fields.text_max_length` |
| value_number | DECIMAL(20,6) NULL | Satt när fältet är `number`. Aldrig flyttal |
| value_date | DATE NULL | Satt när fältet är `date` |
| value_boolean | BOOLEAN NULL | Satt (alltid 1) när fältet är `boolean` och ikryssat. Ej ikryssat har ingen rad, se [[ADR-0099 Kryssrutefält]] |
| billed_account_id | FK → account | Se [[Planer och kvoter]] § usage_counter |
| created_at, updated_at | | |

UNIQUE `(item_id, item_field_id)`. Index: `(item_field_id, value_number)`, `(item_field_id, value_date)`, `(item_field_id, value_boolean)` för filter och sortering. FULLTEXT på `value_text`.

Ingen `deleted_at`. Ett tömt värde är bara borta, som text som suddas ut, och versionshistoriken fångar det. Värdena i ett fält som ligger i papperskorgen syns inte och kommer tillbaka med fältet.

## container_template

En mall som kontot har sparat: kategorier och fält som kopieras in i en container. Ingenting i containern pekar tillbaka på mallen. Se [[ADR-0089 Egna fält på items]] § Riktning 6. Inte samma sak som `container.template_source_id` i [[ADR-0002 Konto äger container]].

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| account_id | FK → account | |
| name | VARCHAR(255) | |
| content | JSON | Kategoriträdet och fälten med rubrik, typ och ordning. Inga items |
| billed_account_id | FK → account | Se [[Planer och kvoter]] § usage_counter |
| created_at, updated_at, deleted_at | | |

Index: `(account_id, deleted_at)`. Högst `templates.max_per_account` (50) per konto.

## category

Hierarkisk, per container. Ett item tillhör högst en kategori — det är hela skillnaden mot taggar.

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| container_id | FK | |
| parent_id | FK → category NULL | |
| name | VARCHAR(255) | |
| position | INT | Sorteringsordning bland syskon |
| billed_account_id | FK → account | Kontot som betalar för radens byte mot kvoten. Se [[Planer och kvoter]] § usage_counter |
| deleted_at | | |

Index: `(container_id, parent_id, deleted_at)`.

Djupet bör begränsas i applikationslagret — säg fem nivåer — och cykler måste avvisas vid flytt av en kategori. En kategori som får sin egen ättling som förälder gör listningen till en oändlig loop.

**En kategori raderas med hela sitt underträd.** Raderna mjukraderas i en transaktion och får samma `deleted_at`, och det är tidsstämpeln som binder dem samman: papperskorgen listar bara den översta kategorin, och en återställning av den tar tillbaka exakt de rader som delar tidsstämpeln. En ättling som raderades tidigare har en annan och ligger kvar. `item.category_id` rörs inte av raderingen — ett item behåller sin kategori medan den ligger i papperskorgen och får den tillbaka när den återställs; först gallringen nollställer pekaren. Se [[ADR-0008 Soft delete och papperskorg]] § Uppföljning 2026-09-26.

## tag

Platt, per container. Inga undertaggar, medvetet.

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| container_id | FK | |
| name | VARCHAR(100) | |
| color | CHAR(7) NULL | |
| billed_account_id | FK → account | Kontot som betalar för radens byte mot kvoten. Se [[Planer och kvoter]] § usage_counter |
| deleted_at | | |

UNIQUE `(container_id, name)`.

### item_tag

| Kolumn | Typ |
|---|---|
| item_id, tag_id | FK, UNIQUE tillsammans |

Index: `(tag_id, item_id)` för filtrering åt andra hållet. **Filtrering på tagg är den vanligaste operationen i hela systemet** när organisationen är helt fri — indexet är inte valfritt.

## item_link

Relationer mellan items: manualen hör till regulatorn, garderoben innehåller flytvästarna.

| Kolumn | Typ | Not |
|---|---|---|
| id | | |
| from_item_id, to_item_id | FK | |
| relation | VARCHAR(20) | `parent` \| `child` \| `sibling` |
| ulid | CHAR(26) UNIQUE | |
| deleted_at | TIMESTAMP NULL | Papperskorgen |
| agent_id, agent_deletion_batch | | Agentmärkningen, som på `item` |

UNIQUE `(from_item_id, to_item_id, relation)`.

En borttagen relation mjukraderas och ligger i papperskorgen tills den gallras. UNIQUE gäller även mjukraderade rader, så en ny länk för samma par raderar den gamla hårt först. En flytt till ny förälder och gallringen av ett item raderar hårt. Se [[ADR-0097 Borttagna relationer i papperskorgen]].

Lagra relationen **en gång** och härled motsatsen vid läsning — skriver du både `A parent B` och `B child A` får du förr eller senare två rader som säger olika saker. `sibling` är symmetrisk; normalisera till lägst id först så att samma par inte kan lagras två gånger.

Cykelkontroll krävs för `parent`/`child`. Se motsvarande resonemang i [[Scheman och uppgifter]].

**`parent`/`child` bär behörighet, `sibling` gör det inte.** En åtkomst som pekar på ett item når även itemets ättlingar via `parent`/`child`, transitivt och aldrig uppåt. `sibling` är symmetrisk och hade därför spridit behörighet åt båda håll utan gräns — motorn `sibling` masten skulle ha delat masten på köpet. En mjukraderad länk bär ingen behörighet. Att lägga till ett barn utvidgar alltså en befintlig delning, vilket delningsvyn måste visa. Att **ändra** en länk kräver `write` i båda ändar, annars kan en mottagare länka in ett item hon inte får se. Se [[ADR-0028 Åtkomst på itemnivå]].

## favorite

En favoritmarkering: en användares bokmärke på ett item. **Per användare, alltså en pivot och inte en kolumn på `item`** — en flagga på itemet hade gjort en användares markering till allas i en delad container. Se [[ADR-0042 Designsystemet]] § Konsekvenser.

| Kolumn | Typ | Not |
|---|---|---|
| id | BIGINT UNSIGNED PK | Ingen `ulid`: ingen rutt identifierar en enskild favoritrad, samma resonemang som `item_link` |
| user_id | FK → user, CASCADE | |
| item_id | FK → item, CASCADE | |
| created_at, updated_at | | |

UNIQUE `(user_id, item_id)` — garanten för en markering per användare och item, och det är indexet och inte en läsning-i-förväg som bär den: en dubblett fångas som ett skrivfel. Index: `(item_id, user_id)` för läsningen "vem har märkt det här itemet".

**`ON DELETE CASCADE` åt båda håll** — ett medvetet undantag från konventionens `RESTRICT`: en borttagen användare eller ett hårdraderat item ska inte lämna kvar bokmärken, för en markering är ett par och ingenting mer. Ingen `deleted_at`: att ta bort en markering är en växling, inte en radering av innehåll — och en mjukraderad rad hade legat kvar i det unika indexet och blockerat en ny markering av samma item.

## loan

Utlåning. Markera en pryl som utlånad, hålla reda på vem som har den, och bli påmind när den skulle ha kommit tillbaka.

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| item_id | FK | |
| borrower_name | VARCHAR(255) | |
| borrower_email | VARCHAR(255) NULL | Kontaktuppgift, **aldrig mottagare för utskick**. Se nedan. |
| lent_at | DATE | |
| due_at | DATE NULL | |
| returned_at | DATE NULL | |
| note | TEXT NULL | |
| billed_account_id | FK → account | Kontot som betalar för radens byte mot kvoten. Se [[Planer och kvoter]] § usage_counter |

Index: `(item_id, returned_at)`.

Öppen utlåning = `returned_at IS NULL`. Påminnelser genereras mot `due_at` och går **enbart till den som lånat ut** — systemet mejlar aldrig låntagaren. `borrower_email` finns för att utlånaren ska ha adressen framme när hen själv tar kontakt. Varför det är en regel och inte en detalj: [[ADR-0017 Missbruksvektorer]] § 7. Notiserna skapas som vanliga rader i [[Notiser]] — utlåning har ingen egen leveransväg.

## cost_entry

Kostnader som hör till ett item. En rad per kostnad, precis som i en huvudbok — men det är en kostnadslogg, inte bokföring. Se [[ADR-0016 Kostnadsregistrering]].

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| container_id | FK | Denormaliserad från itemet. Varje rapportfråga scopas till en container och ska inte behöva joina items för att komma åt den. Säkert eftersom ett item aldrig byter container. |
| item_id | FK | **Obligatorisk.** Ingen kostnad utan item. |
| incurred_on | DATE | Kostnadens datum, inte registreringsdatumet |
| amount | BIGINT | Minsta valutaenhet. Får vara negativt — kreditfaktura, returnerad del, garantiersättning. |
| currency | CHAR(3) | En aktiv ISO 4217-kod, se nedan. |
| description | VARCHAR(255) | |
| supplier | VARCHAR(255) NULL | Fritext med autocomplete, se nedan |
| created_by_user_id | FK NULL | `NULL` när personen raderats |
| created_by_account_id | FK NULL | Vilket *konto* posten tillskrivs — varvet, inte den anställde. `NULL` när kontot raderats |
| billed_account_id | FK → account | Kontot som betalar för radens byte mot kvoten. Se [[Planer och kvoter]] § usage_counter |
| deleted_at | | |

Index: `(item_id, deleted_at, incurred_on)`, `(container_id, deleted_at, incurred_on)`, `(container_id, deleted_at, supplier)`.

**Valutan är en aktiv ISO 4217-kod.** Servern godtar bara koder ur en lista över aktiva valutor; historiska som `DEM` och `FRF` ingår inte. Samma regel gäller `account.currency` ([[Konton och åtkomst]]) och `container.currency`. En befintlig rad med en ogiltig kod står kvar och visas, men en ändring av den kräver en giltig kod. Dessutom gäller containerns urval, se `container_allowed_currency` nedan. Se [[ADR-0078 Valutor ur ISO 4217]].

**Ingen koppling till `schedule_occurrence`.** Kostnaden hör till itemet, inte till ett enskilt utfört jobb. Gränssnittet erbjuder att registrera en kostnad när en uppgift bockas av, med förekomstens datum förifyllt — men ingen relation lagras. Se [[Scheman och uppgifter]] och motiveringen i ADR-0016.

**Kvitton är vanliga attachments på itemet.** `attachment` har ingen koppling till en kostnadsrad. Se [[Filer och lagring]].

**Ingen kolumn för att kostnadsutrymmet är aktiverat.** Sektionen renderas när det finns minst en rad; knappen för att lägga till den första finns alltid. Ett lagrat `has_costs` skulle kunna hamna i otakt med raderna.

Raderas ett item följer dess kostnader med i papperskorgen och återställs med det. Kostnadsrader räknas **inte** mot lagringskvoten och raderas **aldrig** vid nedgradering — de är metadata, samma resonemang som för items i [[ADR-0009 Kvoter och livscykel]].

### Leverantörsfältet

Fritext, men filtrerbart och summerbart. Problemet är inte datatypen utan stavningsvarianter: "Volvo Penta", "Volvo-Penta" och "Volvo Penta AB" blir tre leverantörer i rapporten.

Tre åtgärder, alla vid inmatningen:

- **Autocomplete** från `SELECT DISTINCT supplier` inom containern, sorterat på användningsfrekvens. Användaren väljer ett befintligt värde istället för att skriva om det.
- **Trimma blanktecken** vid sparning.
- **Index** enligt ovan — utan det blir filtreringen en full scan.

Versaler är redan lösta av `utf8mb4_unicode_ci`: "volvo penta" och "Volvo Penta" matchar varandra i både jämförelser och `GROUP BY`.

### Kostnadsrapporter

Rapporten är **Pro**, registreringen är fri. Kontrollen sitter server-side, se [[Planer och kvoter]].

Rapporten läser `cost_entry` och grupperar på det itemen redan är klassade med:

- per kategori, **inklusive underkategorier** — "vad har motorn kostat"
- per tagg, flera kombinerade med OCH — "vad har servicar kostat"
- per item — "vad har impellern kostat"
- per leverantör
- per tidsperiod, på `incurred_on`

**Summera alltid per valuta.** Ingen omräkning görs i MVP. Avrunda först vid presentation, aldrig per rad före summering — annars visar totalen inte samma sak som raderna ovanför.

## container_allowed_currency

Vilka valutor ägaren tillåter vid registrering av kostnader i en container. Se [[ADR-0078 Valutor ur ISO 4217]] § Beslut 4.

| Kolumn | Typ | Not |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| container_id | FK → container | |
| currency | CHAR(3) | En aktiv ISO 4217-kod |
| created_at, updated_at | | |

UNIQUE `(container_id, currency)`. Ingen `ulid` och ingen `deleted_at`: raden syns aldrig i API:et som egen resurs och är inget innehåll att återställa.

**Ingen rad betyder alla valutor.** Containerns egen `currency` (och, när den är `NULL`, kontots) ingår alltid och behöver inte stå här. Urvalet gäller **nya rader och ändrade valutor**: en `cost_entry` i en valuta som senare tagits bort ur urvalet står kvar och kan redigeras på andra fält. Servern upprätthåller regeln med 422 `currency.not_allowed`.

Raderna rensas med containern (`PurgeContainer`) och följer med i exporten.

## Sök och filtrering

Byggs mot Laravel Scout med databasdrivern i MVP, se [[ADR-0012 Sök]]. Men det som faktiskt avgör upplevelsen är filtreringen, och den är vanlig SQL:

- efter tagg — flera taggar ska kunna kombineras med OCH
- efter kategori, inklusive underkategorier
- efter uppgiftsstatus och förfallodatum, se [[Scheman och uppgifter]]
- efter utlåningsstatus
- fritext över namn, beskrivning och textfältens värden
- efter ett fälts värde: lika med för text och ja/nej, intervall för tal och datum

MariaDB FULLTEXT saknar svensk stemming — "batteri" hittar inte "batterier". Accepterat i MVP, löses av Meilisearch när det finns en VPS.
