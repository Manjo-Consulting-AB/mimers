# Konton och åtkomst

Vem äger vad och vem får se det. Läs [[Datamodell – översikt]] först för gemensamma konventioner.

Beslut bakom detta: [[ADR-0002 Konto äger container]], [[ADR-0003 Åtkomstmodell]], [[ADR-0011 Autentisering]].

## account

Ägarenheten i systemet. Ett privatkonto är bara ett konto med en enda medlem — samma tabell som ett varv med tolv anställda. Planer, kvoter och fakturering hänger här, aldrig på användaren.

| Kolumn | Typ | Not |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| ulid | CHAR(26) UNIQUE | |
| type | VARCHAR(20) | `personal` \| `organisation` |
| name | VARCHAR(255) | |
| locale | VARCHAR(10) | `sv_SE`, `en_GB`. Sätts från dag ett, se [[ADR-0013 Språk och i18n]] |
| timezone | VARCHAR(64) | IANA, t.ex. `Europe/Stockholm` |
| unit_system | VARCHAR(10) | `metric` \| `imperial` |
| status | VARCHAR(20) | `active` \| `read_only` \| `closed` |
| read_only_reason | VARCHAR(40) NULL | `payment_failed`, `over_quota`, `inactivity` |
| created_at, updated_at | | |

**Vid registrering** skapas kontot av den som registrerar sig: `type` blir `personal`, `status` blir `active`, och `name` sätts till **användarens namn**, som formuläret frågar efter tillsammans med e-post och lösenord. Ett personkonto är den personen, och kontonamnet syns för andra i deltagarlistan — där vore en e-postadress en läcka snarare än en identitet. Kontot döps om i kontovyerna. Övriga fält får defaultvärden: `locale` `sv_SE`, `timezone` `Europe/Stockholm`, `unit_system` `metric`. Kontot får samtidigt en `account_user`-rad med `role` `owner` — utan den äger den nya användaren ingenting, se [[ADR-0002 Konto äger container]].

## user

En person. Tillhör ett konto via `account_user` — modellerat som många-till-många eftersom en varvsanställd i förlängningen kan finnas i flera organisationer.

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| name | VARCHAR(255) | Personens namn, som hon skrev det. Obligatoriskt, precis som `email` — en användare utan läsbar identitet är inget systemet har användning för. Det här är vad andra ser: deltagarlistan (§ Behörighetsregler), notiser, revisionsloggen. |
| email | VARCHAR(255) UNIQUE | |
| email_verified_at | TIMESTAMP NULL | Krävs innan användaren kan ta emot delning |
| password_hash | VARCHAR(255) NULL | NULL om användaren bara använder magic link |
| totp_secret | VARBINARY(512) NULL | Krypterad. Bredden är inte godtycklig: Laravels `encrypted`-cast lägger IV, MAC, JSON och base64 runt klartexten, så en hemlighet på 32 tecken blir 256 byte. 255 räckte inte. Se [[ADR-0023 TOTP-bibliotek]] |
| totp_confirmed_at | TIMESTAMP NULL | |
| locale, timezone, unit_system | | Åsidosätter kontots värden för den här personen |
| quiet_hours_start, quiet_hours_end | TIME NULL | Se [[Notiser]] |
| last_active_at | TIMESTAMP | **Uppdateras av API-anrop från vilken klient som helst**, inte bara inloggning. Driver livscykeln i [[Planer och kvoter]] |
| created_at, updated_at | | |

### account_user

Ingen `ulid` — medlemskapet exponeras aldrig som egen resurs i API:et, det nås via kontot eller användaren.

| Kolumn | Typ | Not |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| account_id, user_id | FK | UNIQUE tillsammans |
| role | VARCHAR(20) | `owner` \| `admin` \| `member` |
| created_at, updated_at | | Medlem sedan, och när rollen senast ändrades |

## container

Det ägda objektet. Se [[Översikt]] för vad ordet betyder.

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| account_id | FK → account | Ägaren. Exakt ett konto. |
| name | VARCHAR(255) | |
| kind | VARCHAR(40) | `boat`, `caravan`, `house`, `car`, `other`. Endast för presentation och mallval — systemet beter sig inte olika. |
| template_source_id | FK → container NULL | Om utstämplad från mall, se [[ADR-0002 Konto äger container]] |
| deleted_at | | |

Index: `(account_id, deleted_at)`.

## container_access

De fyra åtkomstformerna utöver ägarskap. En rad per beviljad åtkomst — och **flera rader per mottagare** när åtkomsten är avgränsad: en container-bred rad plus en rad per item, med olika nivå. Att samma mottagare inte får två giltiga rader för samma `(container, item)` upprätthålls i applikationslagret; villkoret går inte att uttrycka som UNIQUE när `revoked_at` ska ingå.

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| container_id | FK | |
| item_id | FK → item NULL | `NULL` = hela containern. Satt = **bara det itemet** och dess ättlingar, se [[ADR-0028 Åtkomst på itemnivå]] |
| grantee_type | VARCHAR(20) | `user` \| `account` |
| grantee_id | BIGINT UNSIGNED | Polymorf: en person, eller en hel organisation |
| level | VARCHAR(20) | `read` \| `create` \| `write` \| `delete`. En ladder, inte fria flaggor |
| kind | VARCHAR(20) | `member` \| `managed` \| `guest` |
| expires_at | TIMESTAMP NULL | Satt för `guest`. Utgången access nekas i kod, raderas av städjobb. |
| granted_by_user_id | FK | |
| revoked_at | TIMESTAMP NULL | |

Index: `(container_id, revoked_at)`, `(grantee_type, grantee_id, revoked_at)`, `(item_id, revoked_at)`.

**Skillnaden mellan `member` och `managed`:** en medlem är en person — sambon, delägaren. En managed-rad är en *organisation* med en servicerelation, typiskt ett varv. Poster som varvet skapar tillskrivs organisationen, inte den anställde som råkade vara inloggad, så att relationen överlever personalomsättning. Ägaren ser den alltid listad och kan återkalla den med ett klick. Se [[ADR-0003 Åtkomstmodell]].

## invitation

Delning med någon som inte har konto.

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| container_id | FK | |
| item_id | FK → item NULL | Speglar `container_access.item_id` — `NULL` = hela containern |
| email | VARCHAR(255) | |
| level | VARCHAR(20) | Samma ladder som `container_access.level` |
| token_hash | CHAR(64) | SHA-256 av token. Klartexten skickas i mejlet och lagras aldrig. |
| status | VARCHAR(20) | `pending` \| `accepted` \| `rejected` \| `expired` \| `revoked` |
| expires_at | TIMESTAMP | |
| invited_by_user_id | FK | |

`revoked` är avsändarens ånger — inbjudan drogs tillbaka innan den besvarades, typiskt för att den skickades till fel adress. Raden raderas aldrig; utestående och tillbakadragna inbjudningar är underlaget för [[ADR-0017 Missbruksvektorer]] och M9.

Mottagaren måste skapa konto och verifiera sin e-post för att acceptera. Det är avsiktligt: alla som läser något i systemet ska vara identifierade.

## ownership_transfer

Ägarbyte. Täcker nybyggnadsvarv → kund, mäklare → köpare och privat försäljning med samma mekanism.

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| container_id | FK | |
| from_account_id | FK | |
| to_account_id | FK NULL | `NULL` när mottagaren ännu saknar konto — då bär `to_email` adressen. Exakt en av de två är satt. |
| to_email | VARCHAR(255) NULL | Om mottagaren saknar konto |
| excluded_item_ids | JSON | Items säljaren behåller — inköpspris, försäkringsbrev |
| retain_access_level | VARCHAR(20) NULL | Varvet behåller ofta `write` efter överlämning |
| status | VARCHAR(20) | `pending` \| `accepted` \| `rejected` \| `expired` \| `revoked` |
| accepted_at | TIMESTAMP NULL | |
| initiated_by_user_id | FK | Speglar `invitation.invited_by_user_id`; accepten sätter den som `granted_by_user_id` på en kvarhållen åtkomst |

`revoked` är avsändarens ånger, av samma skäl som på `invitation`: en överlåtelse som skickats till fel adress måste gå att dra tillbaka. Raden raderas aldrig. Utgången härleds ur `created_at` i kod — `status` står kvar på `pending` när tiden passerat, samma princip som `container_access` och `invitation`. **Ingen `token_hash`:** en inbjudan ger läsrätt, ett ägarbyte överlåter hela pärmen, och en bärartoken i ett mejl till en overifierad adress vore en kapabilitet att ta emot någon annans pärm. Mottagaren hittar sitt inkommande ägarbyte på identitet — konto eller verifierad adress — inte på hemlighet.

**Vid accept** ska implementationen, i en transaktion: kontrollera att mottagarens plan rymmer containern (annars nekas överlåtelsen med felkod, inte tyst), flytta `container.account_id`, flytta förbrukat utrymme mellan `usage_counter`-rader, återkalla åtkomster som inte ska följa med, skapa den kvarhållna åtkomsten om sådan begärts, och skriva en `audit_log`-rad. Mottagaren får tolv månader Pro enligt [[ADR-0014 Prismodell]].

## audit_log

Krävs av B2B och av ägarbyten — i en mäklarsituation är det ett värde i sig att kunna visa varifrån pärmen kommer.

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | Loggen är läsbar genom API:et, så raden bär ULID som allt annat |
| account_id, user_id | FK NULL | Aktörens konto och användare. `user_id` är `NULL` när ett jobb orsakat händelsen |
| container_id | FK NULL | |
| action | VARCHAR(60) | `container.transferred`, `access.revoked`, … Öppet namnrum, inget CHECK |
| subject_type | VARCHAR(40) NULL | Domännamn (`container_access`), aldrig ett klassnamn |
| subject_id | CHAR(26) NULL | Subjektets ULID |
| meta | JSON | Aldrig e-postadresser — loggen läses av hela ägarkontot |
| created_at | | |

Index: `(container_id, created_at)`.

**Append-only.** Ingen `updated_at`, ingen `deleted_at`, ingen rutt som ändrar eller raderar en rad. Det är den enda avvikelsen från [[Datamodell – översikt]]:s tidsstämpel- och soft delete-krav som är motiverad av vad tabellen är: en logg som går att skriva om är inget bevis.

## Behörighetsregler

Sammanfattat, att implementera som en policy och inte utspritt i controllers:

1. Ägarkontots medlemmar har full behörighet till containern.
2. Övriga får behörighet via `container_access` där `revoked_at IS NULL` och `expires_at` inte passerats.
3. `level` är en **ladder**, inte fria flaggor: `read` < `create` < `write` < `delete`. `read` läser. `create` lägger till — bilagor, kostnader, scheman, nya barn-items — men rör aldrig något som redan finns. `write` ändrar därtill befintligt. `delete` mjukraderar och återställer ur papperskorgen. Ingen nivå får **radera containern, hantera åtkomster eller initiera ägarbyte**, och fysisk gallring är alltid ägarkontots.

    Är `item_id` satt gäller accessen **bara det itemet och dess ättlingar** via `item_link`-relationen `parent`/`child` — transitivt, aldrig uppåt, och `sibling` bär ingen behörighet alls. Itemets `attachment`, `cost_entry`, `schedule` och `loan` följer itemets nivå. När flera grants når samma item vinner **den högsta nivån**. Se [[ADR-0028 Åtkomst på itemnivå]].
4. Är kontot `read_only` nekas allt skrivande oavsett behörighet — **utom två saker: att återkalla en åtkomst, och att rensa lagring**. Båda minskar exponeringen i stället för att öka den, och ett fruset konto ska varken vara utlåst från att klippa en relation det inte längre vill ha eller från att ta sig under sin nya gräns. Rensningen går via `DELETE /api/accounts/{account}/storage` (issue 28a) och prövas mot `AccountPolicy` utan `read_only`-kontroll; det är just den vägen ur en nedgradering som `read_only` finns till för att framtvinga. Att bevilja eller bjuda in är däremot fortfarande spärrat.
5. Uppladdningar räknas mot **den uppladdande användarens konto**, inte ägarkontot.

**Att hantera åtkomster och att se dem är två olika saker.** Regel 3 spärrar det första: bara ägarkontots medlemmar beviljar, bjuder in och återkallar, och de är också de enda som ser åtkomsternas förvaltningsvy — nivåer, utgångsdatum, vem som beviljade, historiken av återkallade rader och de inbjudningar som ännu inte besvarats.

Det andra är **deltagarlistan**: vilka som har åtkomst till containern *just nu*. Den läser varje deltagare, inte bara ägaren. Den som bjudits in med `write` och överväger att lägga in något känsligt ska kunna se att hon inte är ensam — utan att för den skull få veta vem som beviljade vad, vem som haft åtkomst tidigare, eller vilka adresser som har en obesvarad inbjudan ute. Listan visar deltagarna som de är modellerade: ägarkontot som en post, och en post per giltig `container_access`-rad — en organisation räknas som **en** deltagare, aldrig som sina anställda. Identiteten är `user.name` respektive `account.name`, **aldrig en e-postadress**: deltagare ska känna igen varandra, inte kunna kontakta varandra utanför systemet.
