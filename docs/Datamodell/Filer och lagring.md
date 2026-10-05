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
| storage_path | VARCHAR(255) | `ab/cd/abcdef…` relativt diskens `files`-rot (diskroten är alltså `storage/files/`, se [[ADR-0019 Filleverans]]) — hashen delad i prefix så att ingen katalog får hundratusen poster |
| reference_count | INT UNSIGNED | Antal levande `attachment`-rader |
| scan_status | VARCHAR(20) | `pending` \| `clean` \| `infected` \| `skipped` |
| created_at | | |

**Ingen `deleted_at`.** En `stored_file` lever exakt så länge någon refererar den. När `reference_count` når noll raderas raden och bytena — men först efter en fördröjning, se nedan.

## attachment

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| item_id | FK → item NULL | Itemet bilagan hör till, eller `NULL` för en containerbilaga |
| container_id | FK → container NULL | Containern bilagan hör till, eller `NULL` för en itembilaga |
| stored_file_id | FK | |
| filename | VARCHAR(255) | Användarens namn på filen |
| kind | VARCHAR(20) | `image` \| `document` \| `other` |
| uploaded_by_user_id | FK NULL | `NULL` när personen raderats — filen står kvar, utan avsändare |
| **billed_account_id** | FK → account | **Kontot som betalar för bytena** |
| deleted_at | | |

Index: `(item_id, deleted_at)`, `(container_id, deleted_at)`, `(billed_account_id, deleted_at)`, `(stored_file_id)`.

**Exakt en av `item_id` och `container_id` är satt.** Ett CHECK-villkor upprätthåller det: en bilaga hör till ett item eller till en container, aldrig till båda och aldrig till ingen. Villkoret är det som gör den nullbarheten säker — utan det vore "ingen ägare" ett tillstånd databasen tillät.

**En bilaga kan tillhöra en container** ([[ADR-0047 Containerns bild]]). Det är containerns bild, och den sitter på `container.cover_attachment_id` — se [[Konton och åtkomst]] § container. En containerbilaga är **alltid en bild**: uppladdningen godtar bara det `StoreAttachment` klassar som `kind = image`, och det finns högst en levande per container, för den som byter ersätter den förra. Att ta bort den går inte via papperskorgen — den rensas direkt med `PurgeAttachment` — och ingenting annat än bilden hänger på kolumnen i dag. Allt övrigt är som för en vanlig bilaga: samma dedup, samma `reference_count`, samma miniatyrer och samma leverans.

`billed_account_id` är det uppladdande kontot, inte containerns ägare. Laddar varvet upp 200 MB servicebilder i en gratisanvändares container ska det belasta varvet. Se [[Planer och kvoter]].

Läsarna frågar **vilket item eller vilken container** bilagan hör till genom `Attachment::accessSubject()` och `Attachment::owningContainer()`, inte genom `$attachment->item`. Papperskorgen listar aldrig en containerbilaga: uppslaget joinar mot `item`, och en containerbilaga har `item_id = NULL`.

## image_derivative

Miniatyrer genereras vid uppladdning, inte vid visning. De är små och sparar mycket levererad data.

| Kolumn | Typ |
|---|---|
| stored_file_id | FK |
| variant | VARCHAR(20) — `thumb`, `medium` |
| storage_path | VARCHAR(255) |
| byte_size | BIGINT UNSIGNED |

Derivat räknas **inte** mot användarens kvot. De är systemets kostnad, inte kundens.

## attachment_open

Personens senast öppnade filer — en rad per bilaga hon öppnat, med tiden för öppningen. Se [[ADR-0051 Senast öppnade filer]] och [[M24 Desktopdesignen]] § 177.

| Kolumn | Typ | Not |
|---|---|---|
| id | BIGINT UNSIGNED PK | Ingen `ulid`: raden syns aldrig utåt, och ingen rutt, resurs eller vy identifierar en enskild öppningsrad — listan visar bilagans ULID och namn |
| user_id | FK → user, RESTRICT | **Raden är personens och bara hennes**, som `recent_visit` och `favorite`. `RESTRICT` och inte `CASCADE`: raden städas av `DeleteUser` (issue 144), och en kaskad hade varit en radering ingen action har bett om |
| attachment_id | FK → attachment, RESTRICT | Bilagan öppningen gäller. Kolumnen är bilagans och inte öppnarens: när bilagan gallras försvinner ALLA personers rader på den, genom `PurgeAttachment` |
| opened_at | TIMESTAMP | UTC. Flyttas fram vid varje ny öppning — se upserten nedan |
| created_at, updated_at | | |

Uniknyckeln `(user_id, attachment_id)` är det som gör öppningen till ett PAR: skrivningen är en upsert på den, så att öppna samma fil igen flyttar `opened_at` i stället för att lägga en andra rad. Indexet `(user_id, opened_at)` bär både läsningen och taket — de femtio senaste för en person.

**Taket är femtio rader per person, och det upprätthålls vid skrivningen.** `App\Actions\Attachment\RecordAttachmentOpen` raderar det som ligger utanför de femtio senaste i samma förfrågan som upserten, så tabellen är begränsad per person utan ett gallringsjobb. Listan visar de fem översta (`App\Actions\Attachment\ListRecentOpens`); resten finns kvar som buffert, så att en lista som filtrerats av omfånget fortfarande har något att fylla på ur. Taket är högre än de tjugo i `recent_visit`, eftersom en fil öppnas oftare än ett item besöks.

**Skrivningen sker i `files.download`, efter grinden.** Två slag av hämtning räknas: nedladdningen och PDF-förhandsvisningen, där `variant` saknas, och bildvisarens `medium`. **Miniatyren (`?variant=thumb`) räknas inte** — den ritas i en lista utan att någon öppnat något — och inte heller **containerns egen bild** ([[ADR-0047 Containerns bild]]), som hör till containern och inte till något item. En nekad förfrågan och en 404 skriver ingenting, och `files.deliver` på filoriginet skriver aldrig: den bär en signerad URL och vet inte vem som frågar ([[ADR-0019 Filleverans]]).

**Läsningen filtreras, och den filtreras när den läses.** Listan går genom `ResolveItemScope` som favoritlistan (issue 106) och besökslistan (issue 160): en bilaga man förlorat åtkomsten till, eller som ligger i papperskorgen, försvinner ur listan utan att en räknare avslöjar det. Raden står kvar och blir synlig igen om åtkomsten eller bilagan kommer tillbaka.

**Raderna raderas med bilagan, itemet och containern.** `PurgeAttachment` tar bilagans rader före `forceDelete()`, och den vägen bär också `PurgeContent::item()`, `PurgeContainer` och därmed `DeleteAccount` — de gallrar bilagor genom samma action och behöver därför inte känna tabellen. Exporten (`App\Support\Export\ContainerExportBuilder`) tar inte med raderna: den exporterar containerns innehåll, inte vad en viss person tittat på.

Ingen `deleted_at`: raden är inget innehåll att återställa, papperskorgen listar fyra typer (issue 76 § Beslut 3), och en mjukraderad rad hade legat kvar i det unika indexet och blockerat en ny öppning av samma fil.

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

## API:et

`/api` bär samma skrivningar som webben, genom samma actions (M27 · issue 247).

| Skrivning | Rutt och kropp |
|---|---|
| Flytta en bilaga | `POST /api/containers/{container}/items/{item}/attachments/{attachment}/move` — `{"target": "<item-ulid>"}`, 200 med `AttachmentResource`. Inom samma container rörs ingen kvot; ett ägarbyte prövar den nya ägarens kvot och nekar med 403 `quota.storage_exceeded` |
| Kopiera en bilaga | `POST .../attachments/{attachment}/copy` — `{"target": "<item-ulid>"}`, 201 med `AttachmentResource`. Kopian är en ny `attachment` mot samma `stored_file` och belastar kvoten alltid ([[ADR-0053 Flytt och kopiering]] § 4) |
| Skicka en bilaga tillbaka till inboxen | `POST .../attachments/{attachment}/inbox` — ingen kropp, 200 med `AttachmentResource`. Ägaren blir personkontot och dess kvot prövas ([[ADR-0054 Inboxen]] § 6) |

Målet för `move` och `copy` slås upp globalt på ULID och kan ligga i en annan container än ruttens `{container}` — en ULID som inte finns eller är mjukraderad ger 404, inte ett valideringsfel ([[ADR-0053 Flytt och kopiering]] § 8).

## Virusskanning

Filer sprids mellan användare via delade containers, så en infekterad fil kan nå någon annan. ClamAV är osannolikt tillgängligt på delad hosting. I MVP: `scan_status = 'skipped'`, kolumnen finns, och de tre leveranskraven ovan är den faktiska skyddsmekanismen. Lös skanningen på riktigt när det finns en VPS.
