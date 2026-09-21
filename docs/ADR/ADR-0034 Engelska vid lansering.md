# ADR-0034 Engelska vid lansering

**Status:** Antagen 2026-09-17 · Ersätter språkuppsättningen i [[ADR-0013 Språk och i18n]] · [[ADR-index]]

Ersätter **ett** av ADR-0013:s beslut: vilka språk som levereras. Allt det beslutet sade om i18n-*maskineriet* — `locale` på konto och användare, serverrenderat innehåll som väljer språk från mottagaren, felkoder i stället för meningar — gäller oförändrat och upprepas inte här.

## Kontext

[[ADR-0013 Språk och i18n]] beslutade svenska och engelska vid lansering och valde uttryckligen bort *svenska först, engelska senare* med motiveringen att **mallarna ändå skrivs en gång**.

Den premissen håller inte längre. [[ADR-0033 Produktens omfång]] kräver att varje användarvänd sträng skrivs om. Mallarna skrivs alltså en andra gång oavsett, och att bära två språk genom den omskrivningen är dubbelt arbete på text ingen användare läst.

Samtidigt visar M10 vad två språk faktiskt kostat hittills:

- **Språkfilerna är inte i synk.** Inloggningsmejlet blandar svenska och engelska i samma utskick.
- **Ingen har avgjort vilket språk användaren möter först**, och det finns ingen väg i gränssnittet att byta.
- ADR-0032 fann tre ordförråd för två begrepp — en direkt följd av att två språkfiler underhållits av olika issues vid olika tillfällen.

Ingen av bristerna är en trasig funktion. De är priset för att hålla två uppsättningar text i takt utan att någon äger uppgiften.

## Beslut

**Engelska är enda levererade språk vid lansering. Maskineriet för fler språk står kvar och bevakas av ett test.**

- **`en` är enda locale som levereras.** `lang/sv/` utgår ur produkten i samma omskrivning som ADR-0033 kräver.
- **Ingen användarvänd sträng får stå utanför `lang/`** — inte i en Vue-komponent, inte i en mejlmall, inte i en Blade-vy. **Ett test faller om den gör det.** Det är den regeln, och bara den, som gör löftet om fler språk sant.
- **Att lägga till ett språk ska vara en katalog.** En ny katalog under `lang/` med samma nycklar, plus locale i listan över valbara. Ingen kodändring, ingen ny mall, ingen ändrad vy.
- **Standardspråket är `en` för alla**, oavsett `Accept-Language`. Det finns inget att välja emellan.
- **Ingen språkväljare byggs nu.** `locale` finns kvar på konto och användare enligt ADR-0013; ytan för att välja byggs i den issue som lägger till det andra språket. Att den saknas är ett beslut, inte en lucka.
- **Koden och dokumentationen är oberörda.** [[AGENTS.md]] § Språk i koden är en kodkonvention, inte ett i18n-beslut: kommentarer, docblock, commit-meddelanden, testnamn, hjälpfunktioner och allt under `docs/` förblir svenska. Skriv inte om `tests/` till engelska i det här beslutets namn.

## Motivering

Argumentet som bar två språk i ADR-0013 var att omskrivningen bara sker en gång. Det är inte längre sant, och när premissen faller bör beslutet prövas om.

**Två språk som inte hålls i synk är sämre än ett.** Inloggningsmejlet är beviset. En användare som möter ett halvöversatt system drar slutsatsen att produkten är slarvig, och den slutsatsen är dyrare än att sakna hennes modersmål.

**Engelska framför svenska som det enda språket.** ADR-0013 lade in `unit_system` från dag ett just för att en marknad utanför Norden var väntad. Engelska är den lägre tröskeln för de första användarna där, och asymmetrin är tydlig: en svensk användare läser engelska, det omvända gäller inte.

**Löftet om fler språk är värdelöst som avsikt.** Det som gör det sant är att en hårdkodad sträng inte kommer förbi CI. Med testet är beredskapen strukturell och kostar ingenting att upprätthålla; utan det är den borta inom tre issues, precis som synkroniseringen mellan `sv` och `en` var.

## Konsekvenser

- **`lang/sv/` tas bort efter att `lang/en/` är komplett, inte före.** Den svenska filen är i praktiken nyckelinventariet — diffen ska visa att ingen nyckel försvunnit på vägen.
- **Blandspråket i inloggningsmejlet försvinner som bieffekt**, inte som en egen rättning. Samma gäller övriga mallar, ICS-sammanfattningar och exportvyer.
- **Testet mot hårdkodade strängar blir en grind i CI**, vid sidan av `pint`, `phpstan` och sviten. Det är den bestående delen av det här beslutet.
- **[[Översikt]] § Avgränsning säger i dag "Svenska och engelska" under *Ingår i MVP*.** Raden ändras till engelska.
- **Ett andra språk är en egen milstolpe efter lansering**, inte en issue som smyger in. Den bär språkväljaren, valet av standardspråk per användare och översättningen — i den ordningen.
- **Frågan om svenska någonsin kommer tillbaka lämnas öppen.** Den avgörs av marknaden, inte här. Se [[Tankar]].

## Alternativ

**Behålla båda språken och rätta synken.** Valdes bort. Det dubblar omskrivningen ADR-0033 kräver, och det åtgärdar inte orsaken — ingen äger uppgiften att hålla filerna i takt, och ingen grind fångar när de glider isär.

**Svenska som enda språk.** Kortaste vägen för de första användarna, som är svenska. Valdes bort: det stänger precis den marknad `unit_system` lades in för, och de första användarna utanför Norden skulle kräva samma omskrivning en tredje gång.

**Behålla `lang/sv/` halvöversatt tills någon fyller i.** Valdes bort — det är exakt dagens läge, och det är det som producerade det blandade mejlet. Ett ofullständigt språk levereras inte; det tas bort tills det är komplett.
