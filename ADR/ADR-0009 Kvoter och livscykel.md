# ADR-0009 Kvoter och livscykel

**Status:** Antagen 2026-08-03 · [[ADR-index]]

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

Tre undantag som måste kontrolleras: radering går **via containern**, och har den aktiva medlemmar erbjuds ägarskapet dem först. Aktiv prenumeration undantar alltid. Aktivitet räknas som API-anrop från vilken frontend som helst, inte bara inloggning.

## Motivering

Metadatan är mikroskopisk — "impellerbyte utfört 2024-06-12" tar några hundra byte. Filerna är det som kostar. Strippar man bara bilagorna behåller användaren hela sin logg, ser posterna stå kvar märkta med att filen saknas, och har en konkret anledning att återaktivera. Raderar man items förstör man exakt det som gör att någon betalar igen. Skillnaden är en kund i vila och en kund som är borta.

Att låta användaren välja vad som ryker är både vänligare och säkrare — hon vet vilka fyrtio semesterbilder som kan gå och vilken besiktningsrapport som inte kan det. Gör hon ingenting tillämpas regeln, men då har hon varnats.

Att radering går via containern skyddar mot att en inaktiv ägares konto tar med sig en pärm som en aktiv medlem använder dagligen.

## Konsekvenser

- Förbrukning måste räknas **transaktionellt** vid varje uppladdning och radering, med ett nattligt avstämningsjobb. Räknare driver alltid isär till slut.
- Rättighetskontrollerna måste sitta i API:et, aldrig i klienten — flera frontends pratar med samma backend. Se [[Planer och kvoter]].
- Varningar krävs vid steg 1, en månad före och en vecka före steg 4, samt flera gånger i livscykeln. Ett enda mejl som fastnar i skräpposten får inte kunna kosta någon flera års dokumentation.
- Rättighetslagret byggs i MVP även om betalflödet inte gör det. Att retroaktivt införa kvoter i ett system som aldrig räknat är obehagligt.

## Alternativ

**Radera äldst först.** Valdes bort — den äldsta dokumentationen är ofta den svåraste att återskapa.

**Radera hela items vid nedgradering.** Valdes bort av skälen ovan.

**Låt gratiskonton behålla allt.** Valdes bort — kostnaden bärs då av betalande kunder i all framtid.
