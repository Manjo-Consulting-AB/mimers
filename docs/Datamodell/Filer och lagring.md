# Filer och lagring

Uppladdningar, dedup och hur bytena serveras. Läs [[Datamodell – översikt]] först.

Beslut bakom detta: [[ADR-0006 Innehållsadresserad lagring]], [[ADR-0007 Fillagring hos inleed]].

## Grundprincipen

En fil identifieras av sitt **innehåll**, inte av sitt namn. Två användare som laddar upp samma Victron-manual delar en enda lagrad fil; var och en har sin egen `attachment`-rad med sitt eget filnamn. Ingen av dem märker något.

Två tabeller: `stored_file` är bytena, `attachment` är kopplingen till ett item.

## stored_file

En rad per unikt innehåll i hela systemet.

| Kolumn | Typ | Not |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| content_hash | CHAR(64) UNIQUE | SHA-256, hex. **Beräknas alltid på servern.** |
| byte_size | BIGINT UNSIGNED | |
| mime_type | VARCHAR(127) | Bestämd av servern genom innehållssniffning, inte av klientens `Content-Type` |
| storage_path | VARCHAR(255) | `files/ab/cd/abcdef…` — hashen delad i prefix så att ingen katalog får hundratusen poster |
| reference_count | INT UNSIGNED | Antal levande `attachment`-rader |
| scan_status | VARCHAR(20) | `pending` \| `clean` \| `infected` \| `skipped` |
| created_at | | |

**Ingen `deleted_at`.** En `stored_file` lever exakt så länge någon refererar den. När `reference_count` når noll raderas raden och bytena — men först efter en fördröjning, se nedan.

## attachment

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| item_id | FK | |
| stored_file_id | FK | |
| filename | VARCHAR(255) | Användarens namn på filen |
| kind | VARCHAR(20) | `image` \| `document` \| `other` |
| uploaded_by_user_id | FK | |
| **billed_account_id** | FK → account | **Kontot som betalar för bytena** |
| deleted_at | | |

Index: `(item_id, deleted_at)`, `(billed_account_id, deleted_at)`, `(stored_file_id)`.

`billed_account_id` är det uppladdande kontot, inte containerns ägare. Laddar varvet upp 200 MB servicebilder i en gratisanvändares container ska det belasta varvet. Se [[Planer och kvoter]].

## image_derivative

Miniatyrer genereras vid uppladdning, inte vid visning. De är små och sparar mycket levererad data.

| Kolumn | Typ |
|---|---|
| stored_file_id | FK |
| variant | VARCHAR(20) — `thumb`, `medium` |
| storage_path | VARCHAR(255) |
| byte_size | BIGINT UNSIGNED |

Derivat räknas **inte** mot användarens kvot. De är systemets kostnad, inte kundens.

## Uppladdningsflödet

1. Ta emot filen till en temporär plats.
2. Kontrollera storleksgräns mot planen, se [[Planer och kvoter]]. Nekas med felkod om över.
3. Beräkna SHA-256 **på det mottagna innehållet**.
4. Bestäm MIME-typ genom att titta i filen.
5. Slå upp hashen.
   - Finns den: öka `reference_count`, skapa `attachment`, kasta den temporära filen. Inga bytes skrivs.
   - Finns den inte: flytta till `storage_path`, skapa `stored_file`, skapa `attachment`.
6. Öka `usage_counter` för `billed_account_id` med `byte_size`.
7. Köa miniatyrgenerering och virusskanning.

Steg 3 måste ske på servern. Tar du emot en klientberäknad hash kan någon skapa en `attachment` mot en annan användares fil genom att gissa hashen och därmed läsa innehåll hen inte har rätt till. Se [[ADR-0006 Innehållsadresserad lagring]].

## Radering

När en `attachment` soft-raderas ska `reference_count` minskas först när den lämnar papperskorgen — inte vid soft delete. Annars försvinner bytena medan användaren fortfarande tror att filen går att återställa.

Når räknaren noll: markera för fysisk radering, men **vänta minst 30 dagar**. Det skyddar mot buggen som råkar radera fel rader, och kostar nästan ingenting eftersom filerna ändå är oföränderliga. Se [[ADR-0008 Soft delete och papperskorg]].

## Kvot kontra faktisk lagring

Kvoten mäter vad användaren **upplever** sig lagra: summan av `byte_size` för hens levande attachments. Den faktiska diskförbrukningen är lägre, ibland mycket lägre, eftersom en populär manual bara finns i ett exemplar.

Båda talen behövs. Fakturera på det första, kapacitetsplanera på det andra. Blandar du ihop dem blir prognosen fel med en faktor tio.

## Säkerhet vid leverans

Tre krav som inte är förhandlingsbara:

**Användarfiler serveras från en annan origin än applikationen.** En uppladdad SVG eller HTML-fil kör annars skript i appens domän och kommer åt sessionen. Egen subdomän, `files.mimers.app`.

**`Content-Disposition: attachment` som standard**, undantag endast för bilder som ska visas inline.

**Åtkomstkontroll före leverans.** Inleed har ingen S3 och därmed finns inga presignerade URL:er — varje nedladdning måste passera appen. Men låt inte PHP skyffla bytena: appen kontrollerar behörigheten, webbservern levererar filen.

Hur det görs konkret avgörs av [[ADR-0019 Filleverans]]. Notera att `storage_path` ovan är en sökväg i Storage-abstraktionen, inte nödvändigtvis en URI som webbservern kan nå — LiteSpeed kräver det senare.

**Sökvägen är inte en hemlighet.** `storage_path` byggs av innehållshashen, och den kan beräknas av var och en som råkar ha samma fil. Leveransen får därför aldrig vila på att sökvägen är svår att gissa.

## Virusskanning

Filer sprids mellan användare via delade containers, så en infekterad fil kan nå någon annan. ClamAV är osannolikt tillgängligt på delad hosting. I MVP: `scan_status = 'skipped'`, kolumnen finns, och de tre leveranskraven ovan är den faktiska skyddsmekanismen. Lös skanningen på riktigt när det finns en VPS.
