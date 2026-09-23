# ADR-0043 Händelseloggen

**Status:** Antagen 2026-09-23 · Bygger vidare på issue 40 (revisionsloggen, [[M6 Resten av MVP]]) · Löser ut instrumenteringen som [[ADR-0039 Containerns översikt]], [[ADR-0041 Itemets vy]] och [[ADR-0042 Designsystemet]] lämnade · [[ADR-index]]

Fattat efter retron för M12–M17, när den sista av designerns fyra bilder som inte är byggd visade sig vänta på data och inte på design. Lagringstiden och läsregeln är Tonys svar 2026-09-23.

## Kontext

Tre ytor i bilderna läser samma tabell: dashboardens händelsepanel (`main.jpeg`), containerns historikflik (`container.jpeg`) och itemets historikflik (`struktur - item.jpeg`). Tabellen finns — `audit_log` med resurs, API-kontroller och en enda väg in, `RecordAuditEvent` — men den skrivs från **två** ställen: `AcceptOwnershipTransfer` och `RevokeContainerAccess`. Varje händelse bilderna visar är oregistrerad. [[Att sortera efter mockuparna]] § Händelseinstrumenteringen har hållit frågan sedan 2026-09-18 och sagt att den *"inte ska smygas in i en vy-issue"*.

Genomgången inför den här ADR:en hittade två saker till.

**Loggen blockerar redan gallringen.** `audit_log.container_id`, `user_id` och `account_id` är främmande nycklar med `ON DELETE RESTRICT`. `PurgeContainer` tar hårt bort containern efter trettio dagar i papperskorgen, och den rensar åtkomster, inbjudningar och kalenderflöden men inte loggen, eftersom loggen inte ska rensas. En container som har överlåtits eller fått en åtkomst indragen och sedan raderas faller alltså på ett främmandenyckelfel i den nattliga gallringen, varje natt. I dag är det sällsynt. När varje handling skriver en rad blir det varje container.

**Itemets fråga saknar både kolumn och index.** Tabellen har bara indexet `(container_id, created_at)`. Itemets historik skulle behöva `(subject_type, subject_id)`, men subjektet är inte alltid itemet: när en kostnad registreras är subjektet kostnadsraden, och den hör ändå till itemets historik.

## Beslut

**Loggen sparas för evigt.** Ingen gallring, ingen retentionstid, inget jobb som rensar. Raderna finns för att produkten ska kunna läsa trender ur dem senare, och en trend kan bara läsas ur det som sparats. Att radera en del av loggen är ett eget, framtida beslut med ett eget verktyg, inte ett städjobb. Det stämmer med issue 40 § Beslut 3 och bekräftar det.

**Loggen överlever det den handlar om.** `account_id`, `user_id` och `container_id` förlorar sina främmande nycklar och blir identifierare, samma sak som `subject_id` redan är. En container, ett item eller en användare som raderas lämnar sina rader kvar och tar dem inte med sig. Raderna blockerar inte heller raderingen. Append-only står kvar: ingen rad ändras när det den pekar på försvinner.

**Itemet får en egen kolumn.** `audit_log.item_id`, nullbar och utan främmande nyckel, är satt på varje händelse som hör till ett item, oavsett subjekt. Tre index bär de tre ytorna: `(container_id, created_at)` finns, `(item_id, created_at)` och `(user_id, created_at)` tillkommer.

**Läsregeln: användaren ser sina egna rader, och allt i det användaren äger.** En rad är läsbar för en användare när minst ett av följande gäller:

1. Användaren är medlem i kontot som **äger** radens container. Då syns alla rader i containern och i dess items, vem som än handlade.
2. Raden är användarens **egen** (`user_id` är användarens), och användaren når fortfarande radens container, eller radens item när raden gäller ett item. Omfånget prövas genom `ResolveItemScope` som allt annat.
3. Raden saknar container och gäller ett konto användaren är medlem i.

En gäst med `write` i någon annans container ser alltså sina egna handlingar där och ingenting annat. Ägaren ser gästens. Den som förlorat åtkomsten ser inte längre ens sina egna rader i containern: loggen får inte bli en väg tillbaka in i något som stängts.

**`meta` bär identifierare och fakta, aldrig personuppgifter och aldrig fritext.** ULID:er, belopp och valuta, bilagans `kind`, schemats `recurrence_type`, gamla och nya värden för fält som har en värdelista. Aldrig e-post, aldrig namn, aldrig `description`, anteckningar eller filnamn. Namn och text slås upp **när raden läses**, genom samma omfång som resten av vyn. Då kan loggen sparas för evigt utan att bli ett andra, ogallrat register över det användaren skrivit, och en raderad användare blir en siffra som inte pekar på någon. Den regeln gör att personradering aldrig behöver skriva om loggen.

**Namnrummet är öppet men förteckningen är sluten.** Varje `action` är en konstant på `AuditLog`, som i dag. Loggen får de handlingar bilderna visar och de som redan loggas, och inget annat:

| Område | Handlingar |
|---|---|
| Container | `container.created`, `container.trashed`, `container.restored`, `container.transferred` |
| Åtkomst | `access.granted` (inbjudan accepterad), `access.revoked` |
| Item | `item.created`, `item.trashed`, `item.restored` |
| Bilaga | `attachment.added`, `attachment.trashed` |
| Kostnad | `cost.recorded`, `cost.trashed` |
| Uppgift | `schedule.created`, `schedule.changed`, `schedule.trashed`, `occurrence.completed` |
| Utlåning | `loan.started`, `loan.returned` |

Läsningar, inloggningar, sökningar och visningar loggas inte. En ändring av ett items namn eller fält loggas inte i den här omgången: det är den vanligaste handlingen av alla och ingen av bilderna visar den. En ny handling kräver en rad i tabellen ovan, alltså en ändring av den här ADR:en.

**Raden skrivs i handlingens transaktion.** Samma regel som issue 40 § Beslut 9: en rad som överlever ett rollback beskriver något som aldrig hände. Instrumenteringen sker i Actions och controllers som redan äger transaktionen, aldrig i en modellobservatör. En observatör ser `save()` och inte handlingen, och den skriver lika gärna under en gallring som under ett klick.

## Motivering

**Evig lagring kräver att raden är fattig.** En logg som sparas för alltid och innehåller det användaren skrev är ett register som aldrig gallras, och det är precis det [[Registerförteckning]] finns för att förhindra. En logg som bara bär identifierare och fakta kan leva för evigt, eftersom texten den pekar på raderas där den bor. Den kostar en uppslagning vid läsning, och den kostnaden är värd att betala.

**Främmande nycklar är fel verktyg för en logg.** En FK säger att raden inte får finnas utan det den pekar på, och loggens hela poäng är motsatsen. `subject_id` löste det redan 2026-09-07 genom att vara en sträng utan FK. De tre andra kolumnerna fick FK:er av vana, och det är den vanan som nu fäller gallringen.

**Läsregeln följer ägandet och inte åtkomsten.** Regel 1 är densamma som `ContainerPolicy::viewAuditLog()` redan har, och den utvidgas inte till gäster. Loggen berättar vem som gjorde vad, och i en delad container är det ägarens sak. Regel 2 är det nya: i dag får en gäst `403` på hela loggen, men sina egna handlingar är ingen hemlighet för en själv, och dashboardens panel är tom utan dem.

**Kolumnen `item_id` är billigare än ett subjektindex.** `(subject_type, subject_id)` hade hittat itemets egna händelser men inte kostnaderna, bilagorna och uppgifterna på itemet. För att hitta dem hade frågan behövt joina mot fyra tabeller, varav några kan ha gallrats.

## Konsekvenser

- **Den första issuen i [[M18 Händelseloggen]] är en rättelse, inte en funktion.** Nycklarna släpps och indexen läggs innan en enda ny rad skrivs. Annars gör instrumenteringen gallringsfelet universellt.
- **`ContainerPolicy::viewAuditLog()` räcker inte längre.** Regel 2 är ett radfilter och inte en grind: API:ets `GET /containers/{container}/audit-log` ger en gäst gästens egna rader i stället för `403`. Det är en ändring av ett befintligt API-svar och ska stå i PR:en.
- **[[Registerförteckning]] får en rad för `audit_log`** med gallringen *ingen* och `meta`-regeln ovan som skäl. Den rättsliga grunden är Tonys att sätta och står därför inte här.
- **Personraderingen, när den byggs, rör inte loggen.** Användarraden raderas och `user_id` blir en siffra som inte pekar på någon. Visas en sådan rad står det *en tidigare användare*, inte ett namn.
- **Dashboarden kan byggas.** Dess händelsepanel är regel 1 och 2 över användarens konton, sorterad på `created_at`. Den blir en egen milstolpe efter [[M18 Händelseloggen]].
- **Trender är inte byggda, bara möjliga.** Ingen aggregattabell och ingen rapport ingår. Det enda den här ADR:en lovar är att datat finns kvar när frågan ställs.

## Alternativ

**Gallra efter en fast tid, till exempel två år.** Avvisat av Tony: då går det inte att se trender. Med `meta`-regeln finns inget integritetsskäl kvar som kräver gallring.

**Behålla främmande nycklar med `ON DELETE SET NULL`.** Då överlever raden, men den skrivs om av databasen och tappar sin container eller sitt item. Det bryter mot append-only och tömmer historiken just när den behövs, efter en radering.

**Låta gäster läsa hela containerns logg.** Avvisat: loggen visar vem som gjort vad, och i en delad container är den kunskapen ägarens. Tonys svar var *bara sina egna och det som är direkt kopplat till sina egna containers och items*.

**Logga genom modellobservatörer.** Avvisat: en observatör ser en `save()` och inte en handling, den kan inte skilja ett klick från en gallring eller en migrering, och den ligger utanför den transaktion raden ska dela öde med.
