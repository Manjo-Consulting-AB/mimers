# ADR-0013 Språk och i18n

**Status:** Antagen 2026-08-03 · [[ADR-index]]

## Kontext

Taggar och kategorier är ett blankt papper, så API:et behöver aldrig veta vad "akterstuv" betyder. Klienterna är däremot av flera slag: webbfrontenden på samma origin, B2B-integrationer och mobilappar i förlängningen — se [[ADR-0020 Plattformsidentitet och frontendgräns]].

Men allt som **genereras på servern** behöver språk: mejlmallar, ICS-sammanfattningar, den utskrivbara PDF-pärmen och felmeddelanden.

## Beslut

**Svenska och engelska vid lansering.**

`locale`, `timezone` och `unit_system` finns på **konto och användare från dag ett**, oavsett hur många språk som faktiskt är implementerade.

**API:et returnerar maskinläsbara felkoder, aldrig färdiga meningar.** Klienten översätter.

## Motivering

Att lägga till en locale-kolumn senare är trivialt. Att göra befintliga mejlmallar, kalenderfeeder och PDF-generatorer språkmedvetna i efterhand är det inte — språkvalet måste finnas där när mallarna skrivs.

Felkoder i API:et håller i18n på rätt ställe. En mobilapp kan inte ärva backendens språkval — den har sin egen lokalisering, sin egen utgivningstakt och en installerad bas som ligger flera versioner efter servern. Returnerar API:et färdiga meningar blir appen låst vid serverns locale, och den regeln går inte att skruva in i efterhand när endpointsen redan har konsumenter.

Enhetssystemet kostar ingenting att lägga till nu och spelar roll den dag en marknad utanför Norden dyker upp. Meter eller fot är inte en detalj för en båtägare.

## Konsekvenser

- Varje felsvar innehåller en stabil kod, t.ex. `quota.storage_exceeded`, plus tillräcklig data för att klienten ska kunna formulera meddelandet — vilken gräns, vilket värde.
- Serverrenderat innehåll väljer språk från mottagarens `locale`, inte från requestens `Accept-Language`. En notis skickas när användaren inte är där.
- Användarens `locale` åsidosätter kontots. En engelsktalande medlem i ett svenskt varvskonto ska få engelska.
- Tidszon hör på användaren, inte härleds — seglare befinner sig sällan i sin hemtidszon. Se [[Notiser]].
- Frontenden ansvarar för färdiga kategoriuppsättningar vid registrering, eftersom de är språk- och marknadsspecifika. Se [[ADR-0004 Fria taggar och kategorier]].
- Alla tidsstämplar lagras i UTC och konverteras aldrig i databasen.

## Alternativ

**Svenska först, engelska senare.** Snabbare till lansering. Valdes bort — mallarna skrivs ändå en gång, och en marknad utanför Norden är precis det fall enhetssystemet ovan finns till för.

**API:et returnerar översatta meddelanden.** Enklare klienter. Valdes bort — flyttar i18n till fel lager och låser varje klient vid backendens språkval.
