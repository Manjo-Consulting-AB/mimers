# ADR-0095 Integritetsbegäranden

**Status:** Antagen 2026-11-24 · Bygger vidare på [[ADR-0043 Tre loggar]], [[ADR-0045 Radering av konto och person]] och [[ADR-0094 Hjälpcentret]] § 9 (ingen adminroll) · Spärren i § 12, tillägg 2026-11-25 · [[ADR-index]]

Tonys beslut 2026-11-24 om hur Mimers tar emot, prövar och dokumenterar begäranden från registrerade, efter feature request #1595. Kodarbetet ligger i [[M87 Integritetsbegäranden]].

## Kontext

Självbetjäningen täcker inte alla rättigheter i art. 15–21. Profilen låter personen rätta sina uppgifter, personraderingen och kontoraderingen låter henne radera, och containerexporten ger henne innehållet. Det finns inget i appen som svarar på en begäran om tillgång (art. 15), begränsning (art. 18) eller invändning (art. 21), och en del av det vi behandlar syns aldrig i appen: säkerhetsloggen, registrerings-IP:t och Sentry ([[Registerförteckning]]).

Mimers är personuppgiftsansvarig för kontot och driften men biträde för användarens innehåll ([[Registerförteckning]] § Användarens innehåll). En begäran om det som personen lagt in i en container hör alltså till kunden, inte till oss. Den som tar emot begäran måste kunna avgöra vilket av de två fallen det är.

I dag finns ingen plats att registrera en begäran, ingen frist som någon håller reda på och inget bevis för hur den hanterades. Fristen i art. 12.3 är en månad, och en begäran som ingen ser är en överträdelse som ingen vet om.

Tony svarade på arkitektens frågor 2026-11-24, och svaren är hans beslut:

1. Ärendena sköts med artisan-kommandon. Ingen adminsida och ingen personalroll.
2. Personalen registrerar begäran. `/contact` skapar ärendet när formuläret byggs.
3. Ett avslutat ärende sparas i tre år.
4. Inget eget påminnelseflöde om fristen. Personalen får en notis när kontaktformuläret tar emot en begäran, och listan räcker för resten.

## Beslut

### 1. Ärendet sköts med kommandon

Kommandona heter `privacy:*`, är klasser under `app/Console/Commands/Privacy/` och har logiken i actions under `app/Actions/Privacy/`. Åtkomsten till ärendena är åtkomsten till servern. Ingen rutt, inget API, ingen vy och inget MCP-verktyg, samma avvägning som `legal-hold:*` och `voucher:*` (Tony 2026-11-24). Varje kommando kräver `--by`, handläggarens namn, som sparas på händelsen.

### 2. Intaget

Begäran kommer i dag per mejl, och personalen registrerar den med `privacy:open`. Kontaktformuläret anropar samma action, `OpenPrivacyRequest`, när det byggs (Tony 2026-11-24). Bara namn, e-postadress och beskrivning krävs.

Ärendet kopplas till ett konto och en person först när identiteten prövats. Verifieringen antecknas som metod och resultat, och **ingen kopia av en identitetshandling sparas**. Inga uppgifter lämnas ut eller sammanställs innan identiteten är prövad.

### 3. Ärendet och dess händelser

`privacy_request` och den append-only tabellen `privacy_request_event`. Referensen är `PR-` och åtta slumpade tecken i Crockfords base32. Inget löpnummer, eftersom det hade läckt volymen.

`account_id` och `user_id` är identifierare utan främmande nyckel, som i `legal_hold`, så att ärendet överlever raderingen det beställer. Ärendet skriver inget till `audit_log`, som ägarkontot läser, och inget till `security_log`. Mätningen läser inte tabellerna.

`privacy:show` skriver en händelse `viewed`. `privacy:list` visar aldrig namn, e-postadress eller beskrivning och loggas därför inte.

### 4. Typerna och statusarna

Typerna är `access`, `rectification`, `erasure`, `restriction`, `portability`, `objection`, `consent_withdrawal` och `other`.

De öppna statusarna är `received`, `verification_required`, `under_assessment`, `in_progress` och `awaiting_requester`. De avslutade är `completed`, `partially_completed`, `rejected` och `closed`. `closed` betyder *avslutat utan sakprövning*.

Alla avslutade lägen utom `completed` kräver ett skäl. Ett avslutat ärende kan öppnas igen med skäl.

Samtyckesåterkallelse gäller bara behandling som vilar på samtycke. Kontoradering är ingen allmän återkallelse, eftersom kärnan i tjänsten vilar på avtal.

### 5. Fristen

Fristen är en kalendermånad från mottagandet (art. 12.3), räknad med `addMonthsNoOverflow()`. Den kan förlängas med högst två månader sammanlagt, med skäl och bara före den ursprungliga fristen.

Handläggaren kan räkna om fristen från en ny dag med skäl, till exempel när kompletterande uppgifter för identiteten kommit in. Ingen enda frist är hårdkodad utöver förvalet.

### 6. Ingen påminnelse

Inget nattjobb, inget mejl och ingen loggrad om fristen (Tony 2026-11-24). `privacy:list` sorterar de öppna ärendena på frist och visar dagar kvar eller *overdue*. När kontaktformuläret finns får personalen en notis när en begäran kommer in.

### 7. Bedömning, åtgärder och kommunikation

Allt antecknas som händelser med värden ur listor i `config/privacy.php` (system, datakategorier, operationer, skäl att behålla, rättsliga grunder, beslut, kommunikationens slag och kanaler). Fritext står i en egen not.

Rollen, alltså personuppgiftsansvarig, biträde eller båda, sätts av bedömningen. Flera bedömningar per ärende, en per datamängd eller behandling, gör delvis bifall möjligt.

Gäller begäran kundens innehåll där Mimers är biträde antecknas `referred_to_controller`. Den rättsliga spärren kan anges som skäl att behålla uppgifter bara när kontot faktiskt omfattas av en.

### 8. Begränsning är en markering

Begränsningen (art. 18) är en driftinstruktion till personalen, med omfattning och tidpunkter på ärendet. Den gör inte kontot skrivskyddat, stoppar ingen inloggning och läses inte av någon kod.

Den hävs med skäl, och den registrerade ska underrättas innan (art. 18.3). Den rättsliga spärren hindrar gallring men inte åtkomst, och de två blandas inte ihop.

### 9. Självbetjäningen

Profilen, personraderingen, kontoraderingen och containerexporten kan antecknas som `self_service`. Det är inte ett fullgörande. Containerexporten är inte ett fullständigt svar på art. 15 eller 20.

### 10. Bilagorna

En egen disk, `privacy`, under `storage/app/private/privacy` med filer i läge `0600`. Bilagorna hör inte till den innehållsadresserade lagringen ([[ADR-0006 Innehållsadresserad lagring]]), räknas inte mot någon kvot och levereras aldrig av en rutt. Hashen räknas på servern.

Inga onödiga kopior: källfilen raderas av handläggaren, och en bilaga kan tas bort med skäl. Backuperna tar med katalogen.

### 11. Gallringen

Ett ärende med händelser, bilagor och filer raderas 36 kalendermånader efter `closed_at` (Tony 2026-11-24), varje natt. Öppna ärenden gallras aldrig.

Den rättsliga spärren på ärendets konto går före gallringen ([[ADR-0043 Tre loggar]] § Den rättsliga spärren). En gällande begränsning gör det inte.

### 12. Insamlingen

Automatiska hanterare läggs till ett i taget som egna kommandon som skriver en bilaga och en händelse, utan att livscykeln ändras. Den första är `privacy:collect` för art. 15, som sammanställer de uppgifter i databasen som binds till personen (issue 488, **förslag, inte beslutat**). Spärren och dess rader i säkerhetsloggen tas med i svaret på art. 15, utom när spärren är tystnadsbelagd (`legal_hold.confidential`), alltså när en myndighet ålagt tystnad. Bara då finns en grund i lag (art. 23) att hålla inne uppgiften. `reason` lämnas aldrig ut. *Beslut 3, Tony 2026-11-25, issue 501 (#1693) i [[M91 Villkoren och den rättsliga spärren]].*

## Alternativ som valts bort

- **En adminsida med personalroll.** Ingen adminroll finns ([[ADR-0094 Hjälpcentret]] § 9), och en roll är en behörighetsyta som ska skyddas och granskas för ett handfull ärenden om året.
- **En egen ärendetjänst, Zendesk eller liknande.** Den hade blivit ett nytt personuppgiftsbiträde som själv behåller begäran, och förteckningen över underbiträden hade vuxit för ett flöde vi kan sköta själva.
- **Ärendet i `audit_log` eller `security_log`.** Ägarkontot läser händelseloggen, och en begäran från en låntagare eller en medarbetare får inte synas där. Säkerhetsloggen gallras efter tolv månader, och ett ärende ska leva i tre år.
- **Påminnelser om fristen per mejl eller i loggen.** Tony valde bort dem. Listan räcker.
- **Begränsning som skrivskydd.** Det hade gjort en begäran till en funktion i kontot som måste byggas, testas och hävas, och som kan låsa ute kunden från sitt eget innehåll.
- **Att lagra identitetshandlingar.** Verifieringen antecknas som metod och resultat. En kopia av ett pass är mer känslig än allt annat vi behåller.

## Konsekvenser

- Åtta kodissuer i [[M87 Integritetsbegäranden]], 481–488. Den sista är ett förslag.
- Tre nya tabeller, `privacy_request`, `privacy_request_event` och `privacy_request_attachment` ([[Konton och åtkomst]]).
- En ny disk, `privacy`, och `config/privacy.php` som plats för listorna, fristen och gallringstiden.
- Ett nattjobb, `prune-privacy-requests`.
- En ny rad i [[Registerförteckning]]. Raderna raderas inte med personen av `DeleteUser`: ärendet är beviset på hur begäran hanterades.
- Fyra frågor i [[Tankar]]: påminnelsen om fristen, den rättsliga spärren i svaret på art. 15, begränsningen som ingen kod läser, och åtkomstloggen för `viewed`.
