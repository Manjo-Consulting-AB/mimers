# ADR-0074 Registrering i vyn

**Status:** Antagen 2026-11-12 · Ändrar M27 · issue 246 § Beslut 2 för containerns *To do*-flik · Bygger på [[ADR-0054 Inboxen]] § 5 och § 8 · [[ADR-index]]

Tonys beslut 2026-11-12, efter en genomgång av hur ny information registreras i containerns flikar *Items*, *Documents* och *To do*.

## Kontext

De tre flikarna löser samma uppgift, att lägga till något nytt, på tre olika sätt. *Items* har en primärknapp bredvid lägesväxeln. *Documents* har *Add document* med en meny längst till höger, och själva uppladdningen sker på itemets bilageflik. *To do* har *New task*, som leder till en egen sida (`/tasks/create`). Knapparna ligger långt från listan de fyller på, och på *To do* lämnar användaren sidan för något som oftast är en rad text.

## Beslut

**1. Ett gemensamt mönster, två former.** Registreringen sker i vyn, utan modal, utan låda och utan sidbyte. Mönstret har två former med samma formspråk (bakgrund, kantlinje, hörnradie, marginaler):

- **Snabbraden** (`QuickCreate`): ett textfält som alltid syns. Enter sparar. Används där det första fältet räcker för att skapa något, i första hand *To do*.
- **Det infällbara formuläret** (`InlineCreate`): en diskret rad med ett plus, som fälls ut till ett formulär på samma plats. Används där registreringen kräver mer än ett fält, som uppladdningen på *Documents*.

Skalen är presentation och vet ingenting om uppgifter, items eller filer. Varje vy sätter ihop sitt eget formulär och sin egen skrivväg.

**2. Raden står före innehållet.** Ordningen är rubrik, flikrad och filter, sedan registreringsraden och sist listan. Raden är alltså rad noll i listan den fyller på. Det nya ska synas där det hamnar, och en ny rad markeras kort.

**3. Raden står kvar efter sparning.** Att registrera flera saker i följd ska fungera utan extra klick. Efter en lyckad sparning töms fältet och fokus stannar i det. Escape tömmer först fältet och tar sedan bort fokus. Det infällbara formuläret stängs med Escape eller *Cancel*. Har det osparade ändringar frågar det först med bekräftelsedialogen.

**4. Inboxen är förvalt mål på containerns snabbrad.** Det följer issue 246 § Beslut 2 och [[ADR-0054 Inboxen]] § 8: en container är inte ett item.

- Väljer användaren ett item skapas uppgiften där.
- Väljer användaren *Inbox*, eller väljer hon ingenting, skapas uppgiften i den personliga inboxen.
- Fångsten går före att varje ny uppgift syns i containerns lista. Användaren ska inte tvingas välja ett item för att kunna fånga en uppgift.

*Tillägg 2026-11-12.* Platsvalet är **en** knapp vars lista börjar med *Inbox* och fortsätter med containerns items, i samma form som itemväljaren på *Costs* och *Documents*. Det är inte en förvald knapp bredvid en *Change*-knapp. Skäl: två knappar läses som två konkurrerande val.

**5. En uppgift som hamnar i inboxen bekräftas med en väg dit.** Bekräftelsen säger att uppgiften ligger i inboxen och har en länk till `/inbox`. Användaren ska inte behöva undra varför den nya uppgiften inte syns i listan. Hamnar en uppgift på ett item men döljs av fliken eller filtret säger raden det.

**6. *New task* lämnar containerns flik.** Knappen ersätts av snabbraden. Sidan `/tasks/create` finns kvar för `/tasks`, itemets flik och plusknappen. Hela formuläret, med datum och återkommande, nås därifrån tills det kan fällas ut i vyn.

**7. Ordningen är *To do*, *Items*, *Documents*.** *To do* först, eftersom vinsten är störst och skrivvägarna redan finns. *Items* därefter, med namnet i raden, övriga fält bakom *More details* och ett plus på varje trädnod för att skapa ett barn. *Documents* sist, eftersom uppladdningen bär mest tillstånd: kö, förlopp, fel per fil och kopplingen till ett item. Kortkommandot `N` och plusknappens mål bestäms när alla tre är byggda.

## Motivering

En registrering som ligger nära innehållet kräver kortare musvägar och låter användaren se det som redan finns medan hon lägger till något. Två former i stället för en: att tvinga *To do*:s enradiga fångst in i ett formulär som först måste öppnas kostar ett klick där snabbheten betyder mest, och att tvinga uppladdningen in i en enda rad ger inte plats för kön.

Inboxen som förval håller fast vid ADR-0054:s grundtanke att fånga först och sortera sedan. Ett krav på item hade gjort raden till ett sorteringssteg.

## Konsekvenser

- Containerns *To do*-flik bär ingen länk till `/tasks/create`. Proven som kräver knappen på tre ytor gäller två.
- Servern ändras inte för *To do*. Snabbraden postar till `POST /inbox/tasks` eller till itemets `POST …/schedules`, med `return` satt till flikens egen adress.
- Målväljaren kan begränsas till en container, så att containerns rad bara erbjuder containerns egna items.
- Bekräftelsen för `inbox-task-captured` får en länk, och den gäller alla ytor som fångar till inboxen.
- *Items* och *Documents* får egna milstolpar när *To do* är byggd.

## Alternativ

- **Ett item krävs på containerns rad.** Varje ny uppgift hade synts i listan, men fångsten hade krävt ett val först. Avvisat av skälet i § 4.
- **En modal eller en låda.** Ytterligare ett lager, och avståndet mellan det som registreras och listan består. Avvisat.
- **En komponent för alla tre datatyperna.** Hade kopplat ihop affärslogiken för uppgifter, items och filer. Avvisat till förmån för två presentationsskal (§ 1).
