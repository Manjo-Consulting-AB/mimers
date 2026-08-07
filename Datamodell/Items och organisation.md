# Items och organisation

Själva innehållet. Läs [[Datamodell – översikt]] först.

Beslut bakom detta: [[ADR-0004 Fria taggar och kategorier]], [[ADR-0016 Kostnadsregistrering]].

## item

Grundenheten. Allt användaren vill komma ihåg är ett item — en MPPT-regulator, en garderob, ett kvitto, en genomföring.

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| container_id | FK | |
| category_id | FK NULL | **Högst en.** Se nedan. |
| name | VARCHAR(255) | |
| description | TEXT NULL | |
| manufacturer | VARCHAR(255) NULL | |
| model | VARCHAR(255) NULL | |
| serial_number | VARCHAR(255) NULL | Sökbart — folk letar efter serienummer |
| purchased_at | DATE NULL | |
| warranty_until | DATE NULL | |
| position_note | VARCHAR(255) NULL | Fritext, t.ex. "bakom panelen i akterruffen" |
| created_by_user_id | FK | |
| created_by_account_id | FK | Vilket *konto* posten tillskrivs — varvet, inte den anställde |
| deleted_at | | |

Index: `(container_id, deleted_at)`, `(container_id, category_id, deleted_at)`, FULLTEXT på `(name, description, manufacturer, model, serial_number)`.

**Position modelleras inte som en egen entitet.** "Akterruffen" är en tagg eller kategori som användaren hittat på; systemet vet inte att det är en plats. Se [[ADR-0004 Fria taggar och kategorier]]. Kartfunktionen ligger utanför MVP och kan byggas ovanpå taggarna senare utan schemaändring.

## category

Hierarkisk, per container. Ett item tillhör högst en kategori — det är hela skillnaden mot taggar.

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| container_id | FK | |
| parent_id | FK → category NULL | |
| name | VARCHAR(255) | |
| position | INT | Sorteringsordning bland syskon |
| deleted_at | | |

Index: `(container_id, parent_id, deleted_at)`.

Djupet bör begränsas i applikationslagret — säg fem nivåer — och cykler måste avvisas vid flytt av en kategori. En kategori som får sin egen ättling som förälder gör listningen till en oändlig loop.

## tag

Platt, per container. Inga undertaggar, medvetet.

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| container_id | FK | |
| name | VARCHAR(100) | |
| color | CHAR(7) NULL | |
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

UNIQUE `(from_item_id, to_item_id, relation)`.

Lagra relationen **en gång** och härled motsatsen vid läsning — skriver du både `A parent B` och `B child A` får du förr eller senare två rader som säger olika saker. `sibling` är symmetrisk; normalisera till lägst id först så att samma par inte kan lagras två gånger.

Cykelkontroll krävs för `parent`/`child`. Se motsvarande resonemang i [[Scheman och uppgifter]].

## loan

Utlåning. Från [[Tankar]]: kunna markera en pryl som utlånad, påminna låntagaren, och fråga ägaren om den kommit tillbaka.

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| item_id | FK | |
| borrower_name | VARCHAR(255) | |
| borrower_email | VARCHAR(255) NULL | |
| lent_at | DATE | |
| due_at | DATE NULL | |
| returned_at | DATE NULL | |
| note | TEXT NULL | |

Index: `(item_id, returned_at)`.

Öppen utlåning = `returned_at IS NULL`. Påminnelser genereras mot `due_at` och går till både låntagaren, om e-post finns, och ägaren. Notiserna skapas som vanliga rader i [[Notiser]] — utlåning har ingen egen leveransväg.

## cost_entry

Kostnader som hör till ett item. En rad per kostnad, precis som i en huvudbok — men det är en kostnadslogg, inte bokföring. Se [[ADR-0016 Kostnadsregistrering]].

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| container_id | FK | Denormaliserad från itemet. Varje rapportfråga scopas till en container och ska inte behöva joina items för att komma åt den. Säkert eftersom ett item aldrig byter container. |
| item_id | FK | **Obligatorisk.** Ingen kostnad utan item. |
| incurred_on | DATE | Kostnadens datum, inte registreringsdatumet |
| amount | BIGINT | Minsta valutaenhet. Får vara negativt — kreditfaktura, returnerad del, garantiersättning. |
| currency | CHAR(3) | |
| description | VARCHAR(255) | |
| supplier | VARCHAR(255) NULL | Fritext med autocomplete, se nedan |
| created_by_user_id | FK | |
| created_by_account_id | FK | Vilket *konto* posten tillskrivs — varvet, inte den anställde |
| deleted_at | | |

Index: `(item_id, deleted_at, incurred_on)`, `(container_id, deleted_at, incurred_on)`, `(container_id, deleted_at, supplier)`.

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

## Sök och filtrering

Byggs mot Laravel Scout med databasdrivern i MVP, se [[ADR-0012 Sök]]. Men det som faktiskt avgör upplevelsen är filtreringen, och den är vanlig SQL:

- efter tagg — flera taggar ska kunna kombineras med OCH
- efter kategori, inklusive underkategorier
- efter uppgiftsstatus och förfallodatum, se [[Scheman och uppgifter]]
- efter utlåningsstatus
- fritext över namn, beskrivning, tillverkare, modell, serienummer

MariaDB FULLTEXT saknar svensk stemming — "batteri" hittar inte "batterier". Accepterat i MVP, löses av Meilisearch när det finns en VPS.
