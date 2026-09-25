# ADR-0044 Användarens dag

**Status:** Antagen 2026-09-25 · Preciserar [[ADR-0005 Schema och förekomst]] § Konsekvenser om vilken dag `overdue` räknas mot · Bygger vidare på issue 135 ([[M21 Uppgifterna i vardagen]]) · [[ADR-index]]

Tonys beslut 2026-09-25, efter PR:en för issue 135: tiden ska räknas rätt för användarens tidszon, överallt och inte bara i todo-listan.

## Kontext

Servern går i UTC. Mellan midnatt och 02:00 svensk tid (01:00 vintertid) är det fortfarande gårdagen för servern. Issue 135 flyttade todo-urvalet, grupperingen och `overdue` på förekomsterna till användarens kalenderdatum, `User::today()`. Resten av appen räknade vidare med `Carbon::today()`. Under de timmarna gav samma uppgift därför två svar: försenad i todo-listan men inte i itemträdet. Knappen *Tillbaka idag* sparade gårdagens datum, kostnadsraden efter en avbockning föreslog gårdagens datum, och notisen sade *förfaller* om en uppgift som redan var sen.

Frontenden räknade *Idag* och *Om 3 dagar* i webbläsarens tidszon. Den är oftast densamma som användarens, men inte på resa och inte på en delad dator.

## Beslut

**En dag som en användare ser, eller som sparas åt en användare, är användarens dag.** Den räknas med `User::today()`, i tidszonen som `User::preferredTimezone()` ger: användarens egen, annars kontots, annars appens.

1. **Vyer och API räknar mot den inloggade användarens dag.** Det gäller `overdue` på förekomster och lån, förfallet i itemträdet, och varje datum som servern föreslår eller sparar som *idag*.
2. **Bakgrundsjobb räknar mot mottagarens dag.** Notisjobben och kalenderflödet (ICS) bär en användare. Det är hennes dag som avgör om något förfaller eller är försenat.
3. **Nästa förfallodag räknas i tidszonen hos den som bockar av.** En förekomst i en delad container får en ny förfallodag en gång, för alla. Den som trycker avgör vilken dag det är, alltså samma dag som hon ser på todo-listan när hon trycker. Stängs en förekomst utan användare faller regeln tillbaka på containerns ägarkonto.
4. **Frontenden räknar inte sin egen dag.** Servern skickar användarens `today` och `timezone` till varje sida. Relativa datum och klockslag räknas mot dem, inte mot webbläsarens klocka.
5. **Databasen förblir i UTC.** `app.timezone` ändras inte. Tidsstämplar lagras i UTC, och `DATE`-kolumner som `due_at` är kalenderdatum utan tidszon. Jämförelsen görs som datum, med `toDateString()`, aldrig som ögonblick.

**Undantag:** mätningarna ([[ADR-0043 Tre loggar]]), kvotmånaden och kontolivscykelns dedupliceringsnycklar är driftens egna tal och räknas i UTC. De visas inte som en dag för någon användare.

## Motivering

En dag som skiljer sig beroende på vilken skärm man tittar på är värre än en dag som är fel på alla skärmar. Då går felet inte ens att förklara. Användarens tidszon finns redan på `user` och `account` ([[Konton och åtkomst]] § user), och den enda frågan var vilken regel som skulle få gälla.

Att den som bockar av avgör nästa förfallodag gör att det hon ser när hon trycker stämmer med det hon ser efteråt. Ägarkontots tidszon hade gett samma svar oavsett vem som trycker, men kunnat lägga nästa förfallodag på en dag som den som tryckte redan ser som passerad.

Att frontenden får dagen från servern gör att brickan och `overdue`-flaggan aldrig kan säga emot varandra. [[ADR-0042 Designsystemet]] § Konsekvenser lät redan serverns `overdue` vinna. Nu gäller det för dagen också.

## Konsekvenser

- `Carbon::today()` och `now()->startOfDay()` i kod som räknar en användares dag är ett fel. Undantagen ovan är de enda.
- [[Scheman och uppgifter]] beskriver `overdue` och todo-urvalet mot användarens dag, inte mot `CURDATE()`.
- Ett schemalagt jobb som körs en gång per natt kör på en viss UTC-tid. För en användare långt från UTC kan körningen alltså hamna mitt på hennes dag. Det är godtaget: det jobbet avgör *vad* som förfaller, inte *när* notisen skickas. Tysta timmar avgör fortfarande det.
- Två användare i samma container kan se samma uppgift som försenad respektive inte försenad under några timmar. Det är rätt, eftersom de lever på var sin dag.

## Alternativ

**Behålla serverns datum utanför todo-listan.** Det var läget efter issue 135. Valdes bort, eftersom det ger två svar på samma fråga.

**Ställa `app.timezone` till `Europe/Stockholm`.** Hade löst det för svenska användare och gjort det fel för alla andra, och dessutom flyttat tidsstämplarna i databasen. Valdes bort.

**Webbläsarens tidszon i frontenden.** Enklare, men den kan skilja sig från `user.timezone`, och då säger brickan emot flaggan. Valdes bort.
