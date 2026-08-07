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

## Alternativ

**Hård radering med enbart backup som skydd.** Enklare frågor och mindre databas. Valdes bort — återläsning från backup för att rädda ett enskilt item är opraktiskt, och felet upptäcks ofta för sent.
