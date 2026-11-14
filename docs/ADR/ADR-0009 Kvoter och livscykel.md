# ADR-0009 Kvoter och livscykel

**Status:** Antagen 2026-08-03 · Steg 4 justerat 2026-11-14 · [[ADR-index]]

## Kontext

En användare som lagrat mycket data och sedan går ner till gratisnivån skulle annars kunna ligga kvar för alltid på en kostnad som betalas av någon annan. Samtidigt får nedgraderingen inte förstöra kundrelationen — den som slutat betala är ofta bara en kund i vila.

Samma logik gäller konton som slutar användas helt.

## Beslut

### Nedgradering: radera bilagor, aldrig items

1. Betalning uteblir → kontot sätts `read_only`. Ingenting raderas.
2. Användaren får sina bilagor listade sorterade på storlek och väljer själv vad som ska bort.
3. Tre månaders frist att betala eller exportera.
4. Sker inget raderas **bilagor automatiskt, nyast först**, tills kontot ligger under gränsen. Items står kvar.
5. Kontot återgår till `active` på gratisnivån.

### Kontolivscykel

12 månader utan aktivitet → påminnelse. 15 månader → kontot stängs. 18 månader → kontot raderas.

Tre undantag som måste kontrolleras: radering går **via containern**, och har den aktiva medlemmar erbjuds ägarskapet dem först. Aktiv prenumeration undantar alltid. Aktivitet räknas som API-anrop från vilken klient som helst, inte bara inloggning.

## Motivering

Metadatan är mikroskopisk — "impellerbyte utfört 2024-06-12" tar några hundra byte. Filerna är det som kostar. Strippar man bara bilagorna behåller användaren hela sin logg, ser posterna stå kvar märkta med att filen saknas, och har en konkret anledning att återaktivera. Raderar man items förstör man exakt det som gör att någon betalar igen. Skillnaden är en kund i vila och en kund som är borta.

Att låta användaren välja vad som ryker är både vänligare och säkrare — hon vet vilka fyrtio semesterbilder som kan gå och vilken besiktningsrapport som inte kan det. Gör hon ingenting tillämpas regeln, men då har hon varnats.

Att radering går via containern skyddar mot att en inaktiv ägares konto tar med sig en pärm som en aktiv medlem använder dagligen.

## Konsekvenser

- Förbrukning måste räknas **transaktionellt** vid varje uppladdning och radering, med ett nattligt avstämningsjobb. Räknare driver alltid isär till slut.
- Rättighetskontrollerna måste sitta i API:et, aldrig i klienten — webbfrontenden, B2B-integrationer och kommande mobilappar delar samma backend. Se [[Planer och kvoter]].
- Varningar krävs vid steg 1, en månad före och en vecka före steg 4, samt flera gånger i livscykeln. Ett enda mejl som fastnar i skräpposten får inte kunna kosta någon flera års dokumentation.
- Rättighetslagret byggs i MVP även om betalflödet inte gör det. Att retroaktivt införa kvoter i ett system som aldrig räknat är obehagligt.

## Alternativ

**Radera äldst först.** Valdes bort — den äldsta dokumentationen är ofta den svåraste att återskapa.

**Radera hela items vid nedgradering.** Valdes bort av skälen ovan.

**Låt gratiskonton behålla allt.** Valdes bort — kostnaden bärs då av betalande kunder i all framtid.

## Uppföljning 2026-09-26 — webben räknas som aktivitet

**Aktivitet är aktivitet i webben eller API:et, inte bara API-anrop.** Middlewaren `UpdateLastActiveAt` satt bara i `api`-gruppen, så en person som enbart använde webben räknades som inaktiv sedan kontot skapades, hur ofta hen än loggade in. Den ligger nu i båda grupperna. Den gällande formuleringen står i [[Planer och kvoter]] § Kontolivscykel.

**Skrivningen stryps till en per dygn.** Livscykeln räknar i månader, och en UPDATE per sidvisning ger den ingenting — kolumnen skrivs bara när den är NULL eller äldre än ett dygn.

**Säkerhetsloggen övervägdes som källa och valdes bort:** den gallras efter tolv månader, och livscykeln behöver se arton månader bakåt.

## Uppföljning 2026-11-11 — papperskorgen räknas i kvoten

**Regeln:** `usage_counter.storage_bytes` är summan av `byte_size` för **alla** kontots bilagor, levande och slängda. En bilaga lämnar räknaren när raden raderas fysiskt, inte när den slängs. Att slänga eller återställa en bilaga rör alltså inte räknaren, och en återställning kvotprövas inte.

**Varför:** bilagan räknades bort i samma ögonblick som den slängdes, men bytena låg kvar på disken i upp till 60 dagar. Den som laddade upp, slängde och laddade upp igen kunde fylla disken utan att kvoten slog i.

**Steg 2 och steg 4 följer av det.** I steg 2 frigör användaren utrymme genom att radera för gott i lagringsvyn; att bara släng räcker inte. Steg 4 tömmer först det som redan är slängt (äldst slängd först) och slänger sedan levande bilagor, nyast först, tills de levande ligger på eller under gränsen. De nyslängda bilagorna ligger i papperskorgen i 30 dagar och räknas under tiden, så kontot kan vara `active` på gratisnivån och ändå ligga över kvoten; uppladdningar är spärrade tills papperskorgen tömts. Ett konto under rättslig spärr rörs inte av steg 4. Se [[Planer och kvoter]] § Nedgradering och [[Filer och lagring]] § Kvot kontra faktisk lagring.

**Steg 4 är justerat i § Uppföljning 2026-11-14 — steg 4 raderar för gott nedan:** bilagorna raderas för gott i stället för att slängas, så meningen om 30 dagar i papperskorgen och om ett konto över kvoten gäller inte längre.

## Uppföljning 2026-11-14 — steg 4 raderar för gott

**Beslutet:** bilagor som nedgraderingen tar bort automatiskt i steg 4 **raderas för gott** och går inte till papperskorgen. Steg 4 tömmer först det som redan ligger i papperskorgen, äldst slängd först, och raderar sedan levande bilagor för gott, nyast först, tills kontots förbrukning ligger på eller under gränsen. Det är samma väg som lagringsvyn använder (`PurgeContent::attachment()`).

**Det ersätter** meningen i § Uppföljning 2026-11-11 om att de nyslängda bilagorna ligger i papperskorgen i 30 dagar, och att kontot därför kan vara `active` men ändå ligga över kvoten. Efter steg 4 ligger kontot inom kvoten.

**Varför:** kontot är fruset, och användaren har haft tre månader och flera varningar på sig att välja själv. En papperskorg som räknas i kvoten frigör inget och kräver en extra tömning. Samma skäl gällde redan när lagringsvyn fick radera för gott: användaren ska inte behöva gå in en gång till och tömma papperskorgen. Regeln missades för den automatiska rensningen. Ett konto under rättslig spärr rörs fortfarande inte av steg 4. Se [[Planer och kvoter]] § Nedgradering.
