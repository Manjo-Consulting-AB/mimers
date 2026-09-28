# ADR-0045 Radering av konto och person

**Status:** Antagen 2026-09-26 · Besvarar tre frågor i [[Tankar]] från granskningen av issue 29b · Bygger vidare på [[ADR-0009 Kvoter och livscykel]] och [[ADR-0043 Tre loggar]] · Uppföljning 2026-09-27 om nyligen besökta och containerns bild · Uppföljning 2026-09-28 om länken som bär sig själv · [[ADR-index]]

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

## Uppföljning 2026-09-27 — två nya tabeller

Två beslut i [[M23 Mobilen och kartan]] lägger till rader som raderingen måste känna till.

- **`recent_visit` är bara personens** ([[ADR-0049 Nyligen besökta]]). Den läggs till i listan i beslut 3 över det som raderas med personen, bredvid favoriterna.
- **En containerbilaga följer containern** ([[ADR-0047 Containerns bild]]). Beslut 1 gäller den på samma sätt som en itembilaga: betalar det raderade kontot för en bild på en annans container, får containerns ägarkonto den som `billed_account_id`.

Båda byggs i sina egna issues, § 158 och § 160, och prövas där mot `DeleteUser` och `DeleteAccount`.

## Uppföljning 2026-09-28 — länken bär sig själv

Tonys beslut 2026-09-28, med anledning av testarens bugg #577. Det ersätter regeln i [[M22 Redo för testare]] § 145 om att länken måste öppnas av samma inloggade användare.

**Felet.** § 145 lade `GET /settings/delete-user/{token}` i `auth`-gruppen och krävde samma inloggade person. En testare som öppnade länken i mejlappens inbyggda webbläsare, eller på en annan enhet, hamnade på `/login`. Loggade hen in med en magic link som öppnades i ännu en webbläsare tappades `url.intended`, och raderingen kördes aldrig. Det gav alla tre symptomen i #577: ingen kvittens, andra sessioner levde vidare, och datan fanns kvar. Lyckovägen i `PersonraderingsytaTest` var grön, men den prövade bara det fall där länken öppnades i samma inloggade session.

### 1. Tokenet räcker

Länken kräver ingen inloggning. Den som når brevlådan får genomföra raderingen, i linje med § Motivering ovan: den som sitter i en kapad session kan begära raderingen, men bara den som når brevlådan kan genomföra den. Är någon annan inloggad i webbläsaren spelar det ingen roll — tokenet avgör vem som raderas, och den inloggades egen session rörs inte.

`GET` och `POST` flyttas därför ut ur `auth`-gruppen men stannar i `web`. Ingen takgräns: tokenet är 64 tecken ur ett 62-teckensalfabet, samma resonemang som för `/settings/profile/email/{token}`. Begäran, `POST /settings/delete-user`, ligger kvar i `auth`-gruppen oförändrad.

### 2. `GET` raderar ingenting

`GET /settings/delete-user/{token}` renderar `Settings/ConfirmUserDeletion` med samma tre listor som säkerhetssidan — `accountsToDelete`, `accountsToLeave` och `blockers`, ur `DeleteUser` — för tokenets person, plus en knapp. Finns en spärr är knappen avstängd och spärren visas, precis som i `UserDeletionForm.vue`.

Mellansteget behövs av två skäl: en mejlskanner som förhandshämtar länkar gör en `GET` och får inte radera någon, och `POST`:en får en CSRF-skyddad sida att utgå ifrån.

### 3. `POST` raderar

`POST /settings/delete-user/{token}` raderar. Spärrarna prövas igen i `DeleteUser::handle()`. Vid en spärr: tillbaka till `GET`-sidan för samma token med `status` = `user-deletion-blocked`. Tokenet är då **inte** förbrukat, eftersom `confirmed_at` rullas tillbaka med transaktionen som i dag.

Vid lyckad radering: tillhör den aktuella sessionen den raderade personen loggas den ut (`logout`, `invalidate`, `regenerateToken`). Sedan redirect till `/` med `status` = `user-deleted`. Kvittensen visas för en gäst också — den som raderat sig har ingen session kvar, och den som var inloggad som någon annan är fortfarande inloggad.

### 4. En ogiltig länk ger `404`

Okänt token, utgånget, redan använt, eller en person som inte längre finns: `404`, både på `GET` och `POST`. `GET` renderar `Settings/UserDeletionLinkInvalid` med en mening om att länken har gått ut eller redan använts, och att en ny begäran görs under Inställningar → Säkerhet. Sidan avslöjar inte vilken av orsakerna det är, och inte vems token det var — samma skäl som gör att `ConfirmUserDeletion` inte skiljer fallen åt: ett "finns inte" mot ett "är redan använt" är en orakelyta mot giltiga token.

### Konsekvenser

- `ConfirmUserDeletion::handle()` tar ett token och ingen `User`, och returnerar den raderade modellen så att kontrollern kan jämföra med `$request->user()`. En ny metod, `ConfirmUserDeletion::pending()`, ger personen bakom ett giltigt token utan att röra raden, och bär `GET`-sidan.
- Mejlet är oförändrat. Det pekar fortfarande på samma adress.
- Listan och spärrarna skrivs en gång: `resources/js/components/UserDeletionSummary.vue`, som både säkerhetssidan och bekräftelsesidan använder.
- Beslut 3 ovan står kvar i övrigt. Det är bara kravet på samma inloggade användare som faller.
