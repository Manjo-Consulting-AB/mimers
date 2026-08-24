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

**Vid registrering** skapas kontot av den som registrerar sig: `type` blir `personal`, `status` blir `active`, och `name` sätts till användarens e-postadress — hela adressen, inte en gissad namndel. Kontot döps om i kontovyerna. Registreringsformuläret samlar bara in e-post och lösenord, så resten får defaultvärden: `locale` `sv_SE`, `timezone` `Europe/Stockholm`, `unit_system` `metric`. Kontot får samtidigt en `account_user`-rad med `role` `owner` — utan den äger den nya användaren ingenting, se [[ADR-0002 Konto äger container]].

## user

En person. Tillhör ett konto via `account_user` — modellerat som många-till-många eftersom en varvsanställd i förlängningen kan finnas i flera organisationer.

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
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

De fyra åtkomstformerna utöver ägarskap. En rad per beviljad åtkomst.

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| container_id | FK | |
| grantee_type | VARCHAR(20) | `user` \| `account` |
| grantee_id | BIGINT UNSIGNED | Polymorf: en person, eller en hel organisation |
| level | VARCHAR(20) | `read` \| `write` |
| kind | VARCHAR(20) | `member` \| `managed` \| `guest` |
| expires_at | TIMESTAMP NULL | Satt för `guest`. Utgången access nekas i kod, raderas av städjobb. |
| granted_by_user_id | FK | |
| revoked_at | TIMESTAMP NULL | |

Index: `(container_id, revoked_at)`, `(grantee_type, grantee_id, revoked_at)`.

**Skillnaden mellan `member` och `managed`:** en medlem är en person — sambon, delägaren. En managed-rad är en *organisation* med en servicerelation, typiskt ett varv. Poster som varvet skapar tillskrivs organisationen, inte den anställde som råkade vara inloggad, så att relationen överlever personalomsättning. Ägaren ser den alltid listad och kan återkalla den med ett klick. Se [[ADR-0003 Åtkomstmodell]].

## invitation

Delning med någon som inte har konto.

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| container_id | FK | |
| email | VARCHAR(255) | |
| level | VARCHAR(20) | |
| token_hash | CHAR(64) | SHA-256 av token. Klartexten skickas i mejlet och lagras aldrig. |
| status | VARCHAR(20) | `pending` \| `accepted` \| `rejected` \| `expired` |
| expires_at | TIMESTAMP | |
| invited_by_user_id | FK | |

Mottagaren måste skapa konto och verifiera sin e-post för att acceptera. Det är avsiktligt: alla som läser något i systemet ska vara identifierade.

## ownership_transfer

Ägarbyte. Täcker nybyggnadsvarv → kund, mäklare → köpare och privat försäljning med samma mekanism.

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| container_id | FK | |
| from_account_id, to_account_id | FK | |
| to_email | VARCHAR(255) NULL | Om mottagaren saknar konto |
| excluded_item_ids | JSON | Items säljaren behåller — inköpspris, försäkringsbrev |
| retain_access_level | VARCHAR(20) NULL | Varvet behåller ofta `write` efter överlämning |
| status | VARCHAR(20) | `pending` \| `accepted` \| `rejected` \| `expired` |
| accepted_at | TIMESTAMP NULL | |

**Vid accept** ska implementationen, i en transaktion: kontrollera att mottagarens plan rymmer containern (annars nekas överlåtelsen med felkod, inte tyst), flytta `container.account_id`, flytta förbrukat utrymme mellan `usage_counter`-rader, återkalla åtkomster som inte ska följa med, skapa den kvarhållna åtkomsten om sådan begärts, och skriva en `audit_log`-rad. Mottagaren får tolv månader Pro enligt [[ADR-0014 Prismodell]].

## audit_log

Krävs av B2B och av ägarbyten — i en mäklarsituation är det ett värde i sig att kunna visa varifrån pärmen kommer.

| Kolumn | Typ | Not |
|---|---|---|
| id | | |
| account_id, user_id | FK NULL | |
| container_id | FK NULL | |
| action | VARCHAR(60) | `container.transferred`, `access.revoked`, … |
| subject_type, subject_id | | |
| meta | JSON | |
| created_at | | |

Index: `(container_id, created_at)`.

## Behörighetsregler

Sammanfattat, att implementera som en policy och inte utspritt i controllers:

1. Ägarkontots medlemmar har full behörighet till containern.
2. Övriga får behörighet via `container_access` där `revoked_at IS NULL` och `expires_at` inte passerats.
3. `read` får läsa. `write` får skapa och ändra items, filer, scheman — men **aldrig** radera containern, hantera åtkomster eller initiera ägarbyte.
4. Är kontot `read_only` nekas allt skrivande oavsett behörighet.
5. Uppladdningar räknas mot **den uppladdande användarens konto**, inte ägarkontot.
