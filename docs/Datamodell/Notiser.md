# Notiser

Hur påminnelser når användaren. Läs [[Datamodell – översikt]] först.

Beslut bakom detta: [[ADR-0010 Notisarkitektur]].

## Grundprincipen

En notis **skapas som en rad**, levereras sedan av kön. Aldrig skickad synkront i ett request. Kanalerna är utbytbara adaptrar; att lägga till en ny ska inte röra något annat.

I MVP finns tre utgångar: **e-post**, **ICS-kalenderfeed** och **webhooks**.

## notification

Outboxen. Vad som hänt, till vem.

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| user_id | FK NULL | Mottagare. NULL för rena webhook-händelser till ett konto. |
| account_id | FK | |
| container_id | FK NULL | |
| type | VARCHAR(60) | `task.due`, `task.overdue`, `loan.due`, `quota.warning`, `invitation.received`, `transfer.requested`, `account.inactive` |
| subject_type, subject_id | | Vad notisen handlar om — oftast en `schedule_occurrence` |
| payload | JSON | Data för mallen. **Ingen färdig text.** Renderas per kanal och språk vid leverans. |
| dedupe_key | VARCHAR(191) UNIQUE NULL | Hindrar dubbletter när minutcronen kör om |
| available_at | TIMESTAMP | Tidigast leverans, efter tysta timmar |
| created_at | | |

Index: `(user_id, created_at)`.

`dedupe_key` är inte valfri. Cronen kör varje minut, och en generator som frågar "vilka förekommer förfaller idag" kommer att svara likadant sextio gånger i timmen.

## notification_delivery

En rad per notis **och kanal**. Det är här idempotensen bor.

| Kolumn | Typ | Not |
|---|---|---|
| id | | |
| notification_id | FK | |
| channel | VARCHAR(20) | `email` \| `webhook` |
| status | VARCHAR(20) | `pending` \| `sent` \| `failed` \| `suppressed` |
| attempts | SMALLINT UNSIGNED | |
| last_error | TEXT NULL | |
| sent_at | TIMESTAMP NULL | |

UNIQUE `(notification_id, channel)`. Index: `(status, channel)`.

ICS har ingen rad här — kalenderfeeden är en läsendpoint som genereras vid hämtning, inte något som skickas.

## notification_preference

Vad användaren vill ha, per typ och kanal.

| Kolumn | Typ | Not |
|---|---|---|
| user_id | FK | |
| type | VARCHAR(60) | |
| channel | VARCHAR(20) | |
| enabled | BOOLEAN | |
| digest | BOOLEAN | Samla i veckosammanfattning istället för direkt |

UNIQUE `(user_id, type, channel)`. Saknad rad = förvalt värde i kod.

**Veckosammanfattning är standard för uppgiftspåminnelser.** Produkten är extremt säsongsbetonad — i april förfaller allting samtidigt, och tjugo separata mejl på en förmiddag ger en avprenumeration istället för en betalande kund.

## Tysta timmar och tidszon

`available_at` sätts när notisen skapas, utifrån mottagarens `quiet_hours_start`, `quiet_hours_end` och `timezone` (se [[Konton och åtkomst]]). Kön plockar aldrig rader vars `available_at` ligger i framtiden.

Seglare befinner sig ofta inte i sin hemtidszon. Tidszonen hör därför på användaren, inte härleds från något annat.

## E-post

Levereras via **Mailgun**. SPF, DKIM och DMARC måste sättas upp på avsändardomänen — transaktionsmejl från delad hosting hamnar annars i skräpposten, och en påminnelseprodukt vars påminnelser inte syns är värdelös. Leverantören var Postmark fram till 2026-09-05, se [[ADR-0010 Notisarkitektur]] § Motivering (uppföljning).

Mallar finns på svenska och engelska, valda utifrån mottagarens `locale`. Se [[ADR-0013 Språk och i18n]].

### email_suppression

Studsar och spamanmälningar tas emot via **Mailguns webhook**. Anropet autentiseras inte med lösenord utan med Mailguns HMAC-SHA256-signatur över `timestamp` + `token`, verifierad mot kontots webhook-signeringsnyckel.

Fyra händelsetyper prenumereras på, och `reason` faller ut ur dem:

| Mailgun-händelse | Blir |
|---|---|
| `permanent_fail` (`event: failed`, `severity: permanent`) | `hard_bounce` |
| `temporary_fail` (`event: failed`, `severity: temporary`) | ingen rad — en full inkorg är ett skäl att försöka igen imorgon |
| `complained` | `spam_complaint` |
| `unsubscribed` | `unsubscribe` |

Mailgun skickar ingen händelse när en undertryckning **tas bort**. En adress som spärrats av misstag släpps därför manuellt: radera raden här och motsvarande rad i Mailguns egen spärrlista.

| Kolumn | Typ |
|---|---|
| email | VARCHAR(255) UNIQUE |
| reason | VARCHAR(40) — `hard_bounce`, `spam_complaint`, `unsubscribe` |
| created_at | |

Undertryckta adresser ger `status = 'suppressed'` istället för leveransförsök. En adress som studsat hårt i tolv månader hör dessutom ihop med inaktivitetslogiken i [[Planer och kvoter]] — kontot går inte att nå.

Alla icke-transaktionella utskick behöver avregistreringslänk och en inställningssida.

## ICS-kalenderfeed

En hemlig prenumerationslänk per container som Apple Calendar eller Google Calendar hämtar själv. Kostar nästan ingenting att bygga, har ingen leveransproblematik, och hamnar i kalendern användaren redan tittar i varje dag.

| Kolumn | Typ | Not |
|---|---|---|
| id | | |
| container_id | FK | |
| user_id | FK | Feeden visar bara det den här användaren får se |
| token_hash | CHAR(64) | Klartexten finns bara i URL:en |
| revoked_at | TIMESTAMP NULL | Måste gå att återkalla — URL:en är i praktiken ett lösenord |
| last_fetched_at | TIMESTAMP NULL | |

Feeden innehåller öppna förekomster som `VEVENT` med `due_at` som datum och schemats titel som sammanfattning. Textinnehållet följer användarens `locale`.

## Webhooks

För B2B och för alla användare som vill koppla vidare själva. Med webhooks på plats behöver systemet aldrig bygga Telegram, Slack, Discord eller Home Assistant — det löser användaren med n8n eller Zapier. Se [[ADR-0010 Notisarkitektur]].

### webhook_endpoint

| Kolumn | Typ | Not |
|---|---|---|
| id, ulid | | |
| account_id | FK | |
| url | VARCHAR(500) | |
| secret | VARBINARY(255) | Krypterad. Används för HMAC-signatur. |
| event_types | JSON | |
| is_active | BOOLEAN | |
| consecutive_failures | SMALLINT UNSIGNED | Inaktiveras automatiskt efter tillräckligt många |

Varje leverans signeras med HMAC-SHA256 över kroppen, i en header tillsammans med tidsstämpel så att mottagaren kan avvisa återuppspelning. Omförsök med exponentiell backoff.

**Utgående URL:er måste valideras mot SSRF** — privata IP-intervall, `localhost` och molnens metadatatjänster ska avvisas både vid registrering och vid varje leverans, eftersom DNS kan ändras däremellan.

## Kön

Laravels scheduler hängd på inleeds minutcron. Generatorer som körs:

| Jobb | Frekvens | Gör |
|---|---|---|
| Skapa uppgiftsnotiser | var 15:e min | Förekomster som blivit synliga eller förfallit |
| Skapa utlåningsnotiser | dagligen | Öppna lån med `due_at` som närmar sig |
| Skicka väntande leveranser | varje minut | Plockar `pending` där `available_at` passerats |
| Veckosammanfattning | veckovis | Samlar det som markerats `digest` |
| Kvotvarningar | dagligen | 80 % och 100 % av taket |
| Livscykel | dagligen | Inaktivitet och nedgradering, se [[Planer och kvoter]] |

Övervaka att jobben faktiskt kör med en dead man's switch: uteblir pingen efter lyckad körning ska larm gå. Ett tyst havererat cronjobb är det vanligaste verkliga felet i den här typen av system.
