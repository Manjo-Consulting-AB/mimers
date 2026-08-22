# ADR-index

Tjugo beslut, de femton första fattade i planeringsfasen, augusti 2026. Slå upp när du undrar **varför** något ser ut som det gör — datamodellen beskriver *vad*.

Tillbaka till [[00 Index]].

| # | Beslut | Rör |
|---|---|---|
| [[ADR-0001 Stack]] | PHP 8.3 + Laravel på inleed | Allt |
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
| [[ADR-0013 Språk och i18n]] | Svenska och engelska, felkoder aldrig meningar | Allt |
| [[ADR-0014 Prismodell]] | Gratis, Pro 39–49 €/år, tre B2B-spår | [[Planer och kvoter]] |
| [[ADR-0015 Backup]] | Tre nivåer, off-site från inleed | Drift |
| [[ADR-0016 Kostnadsregistrering]] | Kostnadsrader per item, rapport bakom Pro | [[Items och organisation]] |
| [[ADR-0017 Missbruksvektorer]] | Detektera och prissätt, spärra inte i förväg | [[Planer och kvoter]], Drift |
| [[ADR-0018 Utvecklingsprocess och deploy]] | Gren per issue, tagg till produktion, bygg en gång | [[Backlog]], Drift |
| [[ADR-0019 Filleverans]] | Intern omdirigering under webbroten, engångslänk som fallback | [[Filer och lagring]] |
| [[ADR-0020 Plattformsidentitet och frontendgräns]] | Mimers på `mimers.app`, frontend och API på samma origin, API för mobilappar | [[Konton och åtkomst]], Drift |

## Om att ändra ett beslut

Radera aldrig en ADR. Sätt `Status: Ersatt av ADR-XXXX` och skriv en ny. Poängen med dokumenten är att man ett år senare ska kunna se vad som övervägdes och varför något valdes bort — inte bara vad som gäller nu.
