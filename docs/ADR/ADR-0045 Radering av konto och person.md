# ADR-0045 Radering av konto och person

**Status:** Antagen 2026-09-26 · Besvarar tre frågor i [[Tankar]] från granskningen av issue 29b · Bygger vidare på [[ADR-0009 Kvoter och livscykel]] och [[ADR-0043 Tre loggar]] · [[ADR-index]]

Tonys beslut 2026-09-26, vid planeringen av [[M22 Redo för testare]].

## Kontext

Issue 29b byggde kontoraderingen som sista steg i kontolivscykeln och lämnade tre frågor efter sig.

**Främmande bilagor blockerar.** `attachment.billed_account_id` är kontot som betalar för bytena, och det är det uppladdande kontot, inte containerns ägare ([[Filer och lagring]] § attachment). Har ett konto laddat upp i någon annans container hindrar RESTRICT-nyckeln raderingen, och `DeletesDormantAccounts` hoppar därför över kontot (29b § Beslut 5).

**Författarkolumnerna blockerar också, utan att någon kontrollerar dem.** `item.created_by_account_id`, `cost_entry.created_by_account_id` och `schedule_occurrence.completed_by_account_id` pekar på kontot med RESTRICT. Ett konto som skapat ett item eller bockat av en uppgift i en främmande container misslyckas med raderingen, transaktionen rullas tillbaka och `account.deletion_failed` loggas.

**En person kan inte raderas alls.** `DeleteAccount` raderar aldrig `user`-rader, eftersom en person kan vara medlem i flera konton. Det är rätt avgränsning för kontot, men då finns ingen väg för en person att försvinna ur systemet. Rätten till radering i GDPR artikel 17 är ett krav, inte en funktion.

## Beslut

### 1. Främmande bilagor följer containern

När ett konto raderas flyttas betalningsansvaret för dess bilagor i andras containers till **containerns ägarkonto**. `billed_account_id` skrivs om, och bytena flyttar mellan kontonas `usage_counter` i samma transaktion som raderingen.

**Kvoten kontrolleras inte vid flytten.** Ägarkontot har inte valt bilagorna och ska inte förlora dem för att någon annan försvann. Hamnar kontot över sin gräns gäller samma regel som för en vanlig överskriden kvot: nya uppladdningar nekas med `quota.storage_exceeded`. Ingen nedgradering startar, och ingenting raderas.

Förlagan är ägarbytet, där `AcceptOwnershipTransfer` redan flyttar `billed_account_id` mellan konton.

### 2. Författarkolumnerna blir nullbara och nollställs

Kolumnerna som säger **vem** som skapat, laddat upp, bockat av eller bjudit in blir `NULL`-bara, både mot `account` och mot `user`. Vid en radering sätts de till `NULL`. Historiken står kvar, utan avsändare.

| Tabell | Kolumner |
|---|---|
| `item` | `created_by_user_id`, `created_by_account_id` |
| `attachment` | `uploaded_by_user_id` |
| `schedule_occurrence` | `completed_by_user_id`, `completed_by_account_id` (redan nullbara) |
| `cost_entry` | `created_by_user_id`, `created_by_account_id` |
| `container_access` | `granted_by_user_id` |
| `invitation` | `invited_by_user_id` |
| `ownership_transfer` | `initiated_by_user_id` |
| `export` | `requested_by_user_id` |

Nycklarna förblir `ON DELETE RESTRICT`. Nollställningen görs av raderingen, inte av databasen: en kaskad eller `SET NULL` i schemat vore en radering ingen action har bett om.

Gränssnittet och API:et visar en nollställd författare som frånvarande. Det uppfinner inget namn.

### 3. En person kan radera sig själv

**Bara personen själv kan göra det, från inställningarna.** Begäran bekräftas med en länk i ett mejl till `user.email`, på samma sätt som lösenordsbytet ([[ADR-0011 Autentisering]] § Uppföljning 2026-09-26). Har personen tvåfaktor påslagen krävs en giltig kod när begäran görs. Raderingen sker när länken öppnas. Det finns ingen ångerfrist.

**Kontona avgörs först, i samma transaktion:**

- **Ett konto där personen är enda medlem** raderas med `DeleteAccount`, med samma spärrar som i livscykeln.
- **Ett konto med andra medlemmar** lämnas: personens `account_user`-rad tas bort. **Är personen kontots enda `owner` spärras raderingen** tills en annan medlem har rollen.
- **En delad container med aktiva medlemmar**, i ett konto som annars skulle raderas, spärrar på samma sätt som i livscykeln (29b § Beslut 4). Vägen ut är ägarbytet.
- **En rättslig spärr** ([[ADR-0043 Tre loggar]]) som täcker något av personens konton spärrar hela raderingen.

En spärrad radering gör ingenting. Personen ser vad som spärrar, och varför.

**En aktiv prenumeration spärrar inte.** Rätten att bli glömd går före. Prenumerationen på ett konto som raderas avslutas utan återbetalning. Det skiljer sig med flit från livscykeln, där en aktiv prenumeration alltid undantar ([[Planer och kvoter]] § Kontolivscykel): där är det systemet som raderar, här är det personen som ber om det.

**Det som bara är personens raderas:** medlemskap, åtkomster som mottagare, kalenderlänkar, notiser och notispreferenser, favoriter, avfärdade tips, inloggningslänkar, återställningskoder, väntande e-post- och lösenordsbyten, sessioner och API-token. Väntande inbjudningar och ägarbyten som personen startat dras tillbaka. Besvarade behåller sin rad, med författaren nollställd enligt beslut 2.

**Loggarna står kvar.** `audit_log.user_id` och `security_log.user_id` är redan identifierare utan främmande nyckel (issue 107, [[ADR-0043 Tre loggar]]). Raderna lever sin frist ut och gallras som vanligt. Den rättsliga grunden är berättigat intresse och står i [[Registerförteckning]]. Personraderingen skriver en egen rad i säkerhetsloggen, `user.deleted`, utan e-postadress.

**`user`-raden raderas på riktigt.** Tabellen har ingen `deleted_at`, och en mjukraderad person vore en person som inte är raderad. E-postadressen blir ledig och kan registreras igen som ett nytt konto.

## Motivering

Beslut 1 och 2 gör att innehåll i någon annans container aldrig hindrar en radering. Innehållet tillhör containern, och ägaren ska inte behöva märka att en gäst försvann. Att flytta ansvaret är vad ägarbytet redan gör, så det är ingen ny mekanism.

Beslut 3 följer mönstret från lösenordsbytet: den som sitter i en kapad session kan begära raderingen, men bara den som når brevlådan kan genomföra den. Spärrarna är desamma som i livscykeln, så att en radering som personen själv begär aldrig tar med sig mer än en radering som systemet gör.

## Konsekvenser

- `DeletesDormantAccounts` tappar spärren för främmande bilagor (29b § Beslut 5). Spärren för delade containers finns kvar.
- Varje vy och resurs som visar en författare måste klara `NULL`.
- Integritetspolicyn ska säga att en radering är omedelbar i appen men lever kvar i backuperna under deras retention ([[ADR-0015 Backup]]), och att loggraderna lever sin frist ut.
- Villkoren ska säga att en prenumeration avslutas utan återbetalning när personen raderar sig själv och kontot därmed raderas.
- Personradering via API:et byggs inte nu. Mobilapparna får den när de byggs.

## Alternativ

**Bilagan raderas med kontot.** Enklast, men containerns ägare förlorar innehåll utan att ha gjort något.

**En mjukraderad person, anonymiserad.** Behåller nycklarna intakta, men lämnar kvar en rad som GDPR ändå kräver bort, och e-postadressen går inte att använda igen.

**Ångerfrist på trettio dagar.** Skyddar mot ett förhastat beslut, men mejlbekräftelsen är redan ett andra steg. En frist betyder också att data som personen bett om att få raderad ligger kvar.
