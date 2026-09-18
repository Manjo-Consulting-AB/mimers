# ADR-index

Fyrtio beslut, de femton första fattade i planeringsfasen, augusti 2026. Slå upp när du undrar **varför** något ser ut som det gör — datamodellen beskriver *vad*.

Tillbaka till [[00 Index]].

| # | Beslut | Rör |
|---|---|---|
| [[ADR-0001 Stack]] | PHP 8.4 + Laravel på inleed | Allt |
| [[ADR-0002 Konto äger container]] | Konto som ägarenhet, container som ägd enhet | [[Konton och åtkomst]] |
| [[ADR-0003 Åtkomstmodell]] | Fyra åtkomstformer, varvet äger inte kundens pärm | [[Konton och åtkomst]] |
| [[ADR-0004 Fria taggar och kategorier]] | Blankt papper, systemet vet inget om båtar | [[Items och organisation]] |
| [[ADR-0005 Schema och förekomst]] | Regeln och den enskilda gången är olika saker | [[Scheman och uppgifter]] |
| [[ADR-0006 Innehållsadresserad lagring]] | Dedup via SHA-256, osynlig för användaren | [[Filer och lagring]] |
| [[ADR-0007 Fillagring hos inleed]] | Filer i Sverige och Frankrike, bakom Storage-abstraktion | [[Filer och lagring]] |
| [[ADR-0008 Soft delete och papperskorg]] | Skyddar mot buggen, inte mot diskhaveriet | Allt |
| [[ADR-0009 Kvoter och livscykel]] | Bilagor raderas, items aldrig | [[Planer och kvoter]] |
| [[ADR-0010 Notisarkitektur]] | Outbox med utbytbara kanaler | [[Notiser]] |
| [[ADR-0011 Autentisering]] | Sanctum, lösenord plus magic link | [[Konton och åtkomst]] |
| [[ADR-0012 Sök]] | Scout med databasdrivern tills vidare | [[Items och organisation]] |
| [[ADR-0013 Språk och i18n]] | *Språkuppsättningen ersatt av ADR-0034.* Svenska och engelska, felkoder aldrig meningar | Allt |
| [[ADR-0014 Prismodell]] | Gratis, Pro 39–49 €/år, tre B2B-spår | [[Planer och kvoter]] |
| [[ADR-0015 Backup]] | Tre nivåer, off-site från inleed | Drift |
| [[ADR-0016 Kostnadsregistrering]] | *Pro-gränsen ersatt av ADR-0038.* Kostnadsrader per item, rapport bakom Pro | [[Items och organisation]] |
| [[ADR-0017 Missbruksvektorer]] | Detektera och prissätt, spärra inte i förväg | [[Planer och kvoter]], Drift |
| [[ADR-0018 Utvecklingsprocess och deploy]] | Gren per issue, tagg till produktion, bygg en gång | [[Backlog]], Drift |
| [[ADR-0019 Filleverans]] | Intern omdirigering under webbroten, engångslänk som fallback | [[Filer och lagring]] |
| [[ADR-0020 Plattformsidentitet och frontendgräns]] | Mimers på `mimers.app`, frontend och API på samma origin, API för mobilappar | [[Konton och åtkomst]], Drift |
| [[ADR-0021 Frontendteknik]] | Inertia med Vue i Laravel-appen, sessionsguard för webben | [[Backlog]], [[Pipeline]] |
| [[ADR-0022 Testramverk och statisk analys]] | Pest 5 och Larastan nivå 5, globala hjälpfunktioner i test | [[Backlog]], [[Pipeline]] |
| [[ADR-0023 TOTP-bibliotek]] | `pragmarx/google2fa`, inte Fortify, ingen QR på servern | [[Konton och åtkomst]] |
| [[ADR-0024 Tunna controllers och actions]] | Tunn controller, `Gate::authorize()`, Action när regeln är värd ett test | [[Backlog]] |
| [[ADR-0025 Modellval efter riskaxlar]] | *Ersatt av ADR-0026.* Deepseek V4-Flash för låga axlar, Claude Sonnet för förhöjda | [[Backlog]] |
| [[ADR-0026 Implementering och granskning efter riskaxlar]] | Deepseek implementerar allt, axlarna routar granskningen | [[Backlog]] |
| [[ADR-0027 Agentisolering under utveckling]] | Ingen Docker per agent nu — worktrees + permissions-denylist räcker | Drift |
| [[ADR-0028 Åtkomst på itemnivå]] | Åtkomst per item, nivåerna en ladder read < create < write < delete | [[Konton och åtkomst]], [[Items och organisation]] |
| [[ADR-0029 Agentens läsåtkomst till servern]] | Nyckel låst till ett läsande skript med `command=`, aldrig ett skal | Drift, [[Pipeline]] |
| [[ADR-0030 Miljövariabler ur GitHubs secrets]] | Utrullningen upsertar miljöns secrets i `shared/.env` före `config:cache` | Drift, [[Pipeline]] |
| [[ADR-0031 Köarbetaren körs av schemaläggaren]] | Schemalagd `queue:work --stop-when-empty` sist i `routes/console.php`, ingen daemon | Drift, [[Pipeline]] |
| [[ADR-0032 Produktens ord]] | Container och objekt i gränssnittet, pärmen utgår | Allt |
| [[ADR-0033 Produktens omfång]] | Generell plats för det du äger, använder eller arbetar med — inte ett båtverktyg | Allt |
| [[ADR-0034 Engelska vid lansering]] | Engelska enda levererade språk, maskineriet för fler kvar och testat | Allt |
| [[ADR-0035 Relationen mellan objekt]] | `sibling` heter `related`, tre relationer och inte fyra | [[Items och organisation]] |
| [[ADR-0036 Containerns art]] | `kind` är fritt med autocomplete, CHECK-villkoret utgår | [[Konton och åtkomst]], [[Items och organisation]] |
| [[ADR-0037 Valutans arv]] | Konto → container → rad, ändrat förval rör aldrig gamla poster | [[Items och organisation]] |
| [[ADR-0038 Gränsen för Pro i kostnaderna]] | Fast summering fri, allt frågbart är Pro | [[Planer och kvoter]], [[Items och organisation]] |
| [[ADR-0039 Containerns översikt]] | Containerns förstasida är en översikt, itemlistan en flik; talen räknar det du når | [[Items och organisation]], [[Konton och åtkomst]] |
| [[ADR-0040 Underträdets summor]] | Status och kostnad räknas över itemet och dess ättlingar, donuten grupperar per item | [[Items och organisation]], [[Planer och kvoter]] |

## Om att ändra ett beslut

Radera aldrig en ADR. Sätt `Status: Ersatt av ADR-XXXX` och skriv en ny. Poängen med dokumenten är att man ett år senare ska kunna se vad som övervägdes och varför något valdes bort — inte bara vad som gäller nu.
