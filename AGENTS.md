# AGENTS.md

Regler för dig som implementerar en issue i det här repot. Läs den här filen helt — den är kort med flit.

**Vad** du ska läsa står i `CLAUDE.md`, kartan över dokumentationen. Den här filen handlar om **hur** du ska arbeta. De överlappar inte.

Motiveringen bakom processen står i [ADR-0018](docs/ADR/ADR-0018%20Utvecklingsprocess%20och%20deploy.md). Läs den bara om du undrar över en avvägning.

## Innan du börjar

1. Slå upp ditt issuenummer i [Backlog](docs/Backlog.md) och öppna **bara** din milstolpes fil under `docs/Backlog/`. Läs din issue där.
2. Läs de dokument som står under **Läs** i issuen. **Inget mer.** Dokumentationen är uppdelad just för att du inte ska behöva gå igenom allt för att ändra en detalj.
3. Hittar du inte svaret i din läslista — **gissa inte, fråga.** Ett felaktigt antagande som blir kod är dyrare än en fråga i PR:en.

Startpunkten för dokumentationen är [00 Index](docs/00%20Index.md).

## Arbetsgång

| Steg | Vem |
|---|---|
| Gren `issue-NN-kort-namn` | du |
| PR mot `main`, CI grön | du |
| Merge | Tony |
| Deploy till staging | automatiskt vid merge |
| Release `vX.Y.Z` och produktionsdeploy | Tony |

Ingen pushar direkt till `main`, inte heller Tony.

## Omfång och modellval

Varje issue bär två saker utöver sin beskrivning: en **omfångsruta** och **tre axlar**. Båda sätts av den som skriver issuen, inte av dig.

**Omfångsrutan** listar `In scope` — filerna och katalogerna du får ändra — och `Out of scope` — det du inte får röra även om det ser trasigt ut. `Out of scope` är bindande. Behöver arbetet en ändring utanför rutan är det inte en lov att ta den: **stanna och fråga i PR:en.** Det gäller också sådant ett `install`-kommando skapar åt dig; ställning som ramverket genererar men issuen inte bett om ska bort.

**De tre axlarna** avgör vilken modell som får uppgiften:

| Axel | Värden |
|---|---|
| `ambiguity` | `low` om svaret står i läslistan, `high` om något måste tolkas |
| `blast_radius` | `contained` om ändringen bor i egna filer, `cross-module` om den rör kod andra issues bygger på |
| `risk_class` | `none` för vanlig funktionalitet, `elevated` för autentisering, behörighet, pengar, kvoter, radering och filleverans |

Är alla tre låga går uppgiften till en billigare modell. Är någon förhöjd går den till en dyrare.

**Upptäcker du att en axel är fel satt — stanna och säg till.** Visar det sig att ändringen måste ut i kod andra issues bygger på, eller att svaret inte står i läslistan, så är det den viktigaste informationen du kan lämna ifrån dig. Att ploga vidare på en uppgift som är större än den utgav sig för att vara är dyrare än att avbryta, för både dig och den som ska granska.

## Vad som krävs för att en PR ska mergas

- **Varje "Klart när"-punkt i issuen motsvaras av ett test.** En PR utan det mergas inte. Det är den enda mekanism som skalar när granskaren inte hinner läsa varje rad.
- CI är grön: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `php artisan test`, `npm run build`.
- PR-mallen är ifylld, inklusive vilka dokument du läst.

## Nya beroenden

**Nya composer- och npm-paket kräver Tonys godkännande.** Ett beroende är ett arkitekturbeslut och hör hemma i en ADR, inte i en implementationsissue. Föreslå det i PR:en och vänta på svar — lägg inte till det och be om ursäkt sedan.

## Databaskonventioner

Gäller **alla** tabeller. Detaljerna och undantagen står i [Datamodell – översikt](docs/Datamodell/Datamodell%20%E2%80%93%20%C3%B6versikt.md).

- **Nycklar.** `BIGINT UNSIGNED AUTO_INCREMENT` som primärnyckel. Varje tabell som syns i API:et har dessutom `ulid CHAR(26)` med unikt index — det är identifieraren utåt. Löpnummer läcker aldrig ut.
- **Tidsstämplar.** `created_at` och `updated_at` på allt. `TIMESTAMP` i UTC, konverteras aldrig i databasen.
- **Soft delete.** `deleted_at TIMESTAMP NULL` på allt användarskapat innehåll. Alla index som används för listning måste inkludera `deleted_at`.
- **Teckenuppsättning.** `utf8mb4` med `utf8mb4_unicode_ci` genomgående.
- **Främmande nycklar.** Alltid deklarerade, `ON DELETE RESTRICT` som standard.
- **Pengar.** Aldrig flyttal. `BIGINT` i minsta valutaenhet plus `currency CHAR(3)`.
- **Byte.** Alltid `BIGINT UNSIGNED`.
- **Uppräkningar.** `VARCHAR` med CHECK-villkor, aldrig MySQL `ENUM`.

**Migrationer rullas aldrig tillbaka i produktion.** Expand/contract: additiva steg i en release, destruktiva i en senare, när ingen kod längre använder kolumnen. Fel åtgärdas framåt.

## Driftmiljön saknar proc_open

`exec`, `system`, `passthru`, `shell_exec`, `proc_open`, `proc_close` och `popen` är avstängda hos inleed — i både webb-SAPI och CLI. Det är inte förhandlingsbart och går inte att kringgå.

- **Schemalägg med `->call(...)` eller `->job(...)`, aldrig `->command(...)`.** Det senare körs genom Symfony Process och kraschar på servern även om det fungerar på din maskin.
- **Använd inte `->runInBackground()`.** Samma sak.
- **Köer dras med `queue:work`, aldrig `queue:listen`.**
- Behöver du köra ett externt program — det går inte. Fråga istället.

Se [ADR-0018](docs/ADR/ADR-0018%20Utvecklingsprocess%20och%20deploy.md).

## Felformat i API:et

**API:et returnerar maskinläsbara felkoder, aldrig färdiga meningar.** Klienten översätter. Se [ADR-0013](docs/ADR/ADR-0013%20Spr%C3%A5k%20och%20i18n.md).

Varje felsvar har en stabil kod plus tillräckligt med data för att klienten ska kunna formulera meddelandet — vilken gräns, vilket värde:

```json
{
  "error": {
    "code": "quota.storage_exceeded",
    "data": { "limit_bytes": 5368709120, "used_bytes": 5400000000 }
  }
}
```

**Formatet är fastställt i issue 7 och gäller hela API-ytan.** Det som står nedan är kontraktet — bygg inte något eget bredvid det.

- **`code` är alltid en punktseparerad, stabil sträng.** Domän först, sedan vad som hände: `auth.invalid_credentials`, `quota.storage_exceeded`. Inte `error.login_failed_try_again`.
- **`data` finns alltid**, även tom — och tom `data` serialiseras som `{}`, aldrig `[]`, så klienten slipper hantera två typer.
- **Ingen `message`-nyckel.** Inte ens som bekvämlighet: finns den börjar klienter läsa den, och då är i18n tillbaka i fel lager.
- **Statuskoderna är de vanliga.** 422 validering, 401 oautentiserad, 403 nekad, 404 saknas, 405 fel metod, 429 för många försök. Höljet ändrar kroppen, inte statusen.
- **Översätt inte.** Ingen `__()` i felsvar, ingen `lang/`-fil för dem.

**Valideringsfel bär en kod per fält**, med regelparametrarna i fältets egen `data` — annars kan klienten inte markera rätt fält eller formulera meningen:

```json
{ "error": { "code": "validation.failed", "data": { "fields": {
  "email": [{ "code": "validation.email", "data": {} }],
  "password": [{ "code": "validation.min", "data": { "min": 8 } }]
} } } }
```

**Höljet gäller `/api`, inte webbsidorna.** Webben kör Inertia och behåller Laravels vanliga valideringsfel — Inertia-adapterns formulärhantering bygger på dem. Se [ADR-0020](docs/ADR/ADR-0020%20Plattformsidentitet%20och%20frontendgr%C3%A4ns.md) § Konsekvenser.

Implementationen ligger i `bootstrap/app.php` (`withExceptions`), `App\Support\Api\ApiError` och `App\Support\Api\ValidationErrorMapper`. Ett oväntat undantag ger `server.error` utan undantagstext i produktion, men renderar Laravels vanliga felsida när `app.debug` är på — annars går varje API-bugg inte att felsöka.

Serverrenderat innehåll — mejl, ICS, PDF — väljer språk från mottagarens `locale`, inte från requestens `Accept-Language`.

## Sådant som är lätt att göra fel

- **Kvoter räknas på uppladdande konto**, inte på containerns ägare.
- **Innehållshashen beräknas alltid på servern.** Ta aldrig emot en hash från klienten.
- **En container har exakt en ägare, och ägaren är ett konto** — aldrig en användare.
- **Scheman genererar aldrig serier i förväg.** Endast öppen förekomst plus historik.

## Dokumentationen

- Ett beslut bor på **ett** ställe. Datamodellen beskriver *vad*, ADR:erna beskriver *varför*. Upprepa inte motiveringar i datamodellen.
- Ändrar du ett beslut: sätt status `Ersatt av ADR-XXXX` på den gamla ADR:en och skriv en ny. **Radera aldrig en ADR.**
- Nya frågor utan svar hör hemma i [Tankar](docs/Tankar.md), inte utspridda i dokumenten.
