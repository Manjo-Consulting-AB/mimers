# ADR-0008 Soft delete och papperskorg

**Status:** Antagen 2026-08-03 · [[ADR-index]]

## Kontext

Hela produktlöftet är att kunden inte förlorar sina papper. Backupstrategin i [[ADR-0015 Backup]] är medvetet enkel: daglig backup hos inleed, veckovis mot egen server, månadsvis mot arkivlagring.

Men den vanligaste orsaken till dataförlust i ett system som det här är **inte** diskhaveri. Det är en dålig migration eller en bugg som raderar rader — och mot det skyddar backup dåligt, eftersom felet upptäcks långt efter att det skedde.

## Beslut

**Soft delete på allt användarskapat innehåll**: container, item, attachment, category, tag, schedule. Kolumnen `deleted_at TIMESTAMP NULL`, och alla listningsindex inkluderar den.

En **papperskorg** med retention exponeras i API:et. Fysisk radering av filbytes sker tidigast 30 dagar efter att referensräknaren nått noll.

## Motivering

Nästan varje "jag har tappat data"-ärende löses från papperskorgen utan att någon backup rörs. Det är därför en enkel backupstrategi är försvarbar — soft delete gör det tunga jobbet.

Fördröjningen innan bytes raderas fysiskt kostar nästan ingenting eftersom filerna ändå är oföränderliga och deduplicerade.

## Konsekvenser

- Varje fråga mot användardata måste filtrera på `deleted_at IS NULL`. Laravels `SoftDeletes` gör det automatiskt — avvik inte från det.
- Index utan `deleted_at` blir oanvändbara så fort mängden raderat innehåll växer.
- Referensräknaren i `stored_file` minskas när en attachment lämnar papperskorgen, inte vid soft delete. Se [[Filer och lagring]].
- `rsync --delete` får aldrig användas mot filbackupen — det propagerar en felaktig radering till backupen inom ett dygn.
- Papperskorgens retention måste framgå av integritetspolicyn, eftersom raderad data lever kvar en tid.

## Retentionstiden i MVP (2026-08-31)

Beslutet ovan säger "papperskorg med retention" utan att sätta ett tal. Talet är **30 dagar**, räknat från `deleted_at`, och gäller allt användarskapat innehåll: container, item, attachment, category, tag, schedule.

Samma tal som fördröjningen innan filbytes raderas fysiskt, och det är hela motiveringen: två tal att hålla isär blir ett tal som är fel. En användare som återställer på dag 29 får tillbaka både raden och filen, eftersom bytena tidigast kan gallras 30 dagar efter att referensräknaren nått noll — och räknaren minskas först när attachmenten lämnar papperskorgen.

Konsekvenser:

- **Papperskorgen exponerar återstående tid**, inte bara raderingsdatumet. Webbvyn (issue 62) visar den, och den räknas ut ur `deleted_at` plus retentionen — den lagras inte i en egen kolumn, som skulle kunna säga emot `deleted_at`.
- **Gallringen är schemalagd, inte lat.** Ett innehåll som passerat retentionen får aldrig dyka upp i papperskorgen igen bara för att jobbet inte hunnit köra.
- **Talet hör hemma i integritetspolicyn**, enligt konsekvenslistan ovan.
- **Retentionen är inte en plangräns.** Free och Pro har samma 30 dagar. Skulle den någon gång skilja sig åt per plan är det en gräns i `plan.limits` och ett eget beslut.

## Alternativ

**Hård radering med enbart backup som skydd.** Enklare frågor och mindre databas. Valdes bort — återläsning från backup för att rädda ett enskilt item är opraktiskt, och felet upptäcks ofta för sent.
