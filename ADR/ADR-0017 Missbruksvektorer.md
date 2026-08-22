# ADR-0017 Missbruksvektorer

**Status:** Antagen 2026-08-04 · Punkt 7 om utlåningspåminnelser tillagd 2026-08-22 · [[ADR-index]]

## Kontext

Frågan uppstod ur en enkel observation: vad hindrar en gratisanvändare från att skapa ett nytt konto per container? Svaret är ingenting, och det leder vidare till en bredare fråga. Gratisnivån i [[ADR-0014 Prismodell]] är avsiktligt generös — påminnelser, export och kostnadsregistrering är fria eftersom produktens dragningskraft är ackumulerad data. Generositet innebär att vissa beteenden kan utnyttjas.

Vi vet idag inte vilka av dem som faktiskt förekommer eller vad de kostar. Utan den siffran blir varje framtida diskussion om att strama åt gratisnivån en gissning, och gissningar drar alltid åt spärrhållet.

## Beslut

**Detektera och prissätt. Spärra bara där mätningen visar en verklig kostnad.** Ingen ny registreringsspärr införs i MVP.

Vektorerna nedan är kända och medvetet burna. Varje rad har ett befintligt skydd, ett mätvärde och — där det är billigt — ett litet motmedel.

### 1. Flera gratiskonton för fler containers

Konsumenten som vill ha båt, husvagn och hus utan att betala.

**Befintligt skydd:** friktionen i modellen — separata inloggningar, ingen samlad vy, delning och `managed`-åtkomst per konto, splittrade påminnelser, inget ägarbyte. Dedup gör lagringskostnaden låg och livscykeln i [[ADR-0009 Kvoter och livscykel]] städar de konton som svalnar.

**Motmedel:** positioneringen i [[ADR-0014 Prismodell]]. Ingen spärr.

**Mät:** konton per registrerings-IP, andel konton med exakt en container och noll delningar, lagring per gratiskonto.

### 2. B2B som gratiskonton

Ett varv eller en mäklare som skapar ett gratiskonto per kund istället för att köpa varvsplanen. **Det här är det ekonomiskt betydande läckaget** — konsumentfallet ovan är det inte.

**Befintligt skydd:** uppladdningar räknas mot uppladdande konto, inte ägaren ([[ADR-0003 Åtkomstmodell]]). Varvet äter sin egen kvot först. **Den regeln motiverades ursprungligen med att varvet inte ska fylla kundens gratiskvot, men den bär också anti-missbruksfunktion och får inte optimeras bort utan att den här ADR:n ändras med.**

**Mät:** konton med `managed`-åtkomst till fler än fem containers, containers skapade i kluster från samma IP inom kort tid.

### 3. Lagring som fildelning

Gratiskontot som filhotell. Dedup gör att samma fil hos många konton kostar en gång — vilket också är precis det som gör spridning billig.

**Befintligt skydd:** 1 GB och 10 MB per fil.

**Mät:** `stored_file.reference_count` högt över konton utan inbördes relation; nedladdningar per fil.

### 4. Ägarbytesbonusen

Tolv månader Pro till mottagaren. Kräver att avsändaren är Pro, så varje bonus kostar minst ett betalt Pro-år — men en Pro-användare kan ringa bonusen mellan egna konton.

**Motmedel:** bonusen ges **en gång per mottagande konto**, inte per överlämning. Litet att bygga nu, obehagligt att införa retroaktivt.

### 5. Inbjudningar och magic links som utskicksverktyg

Båda skickar mejl till godtyckliga adresser.

**Befintligt skydd:** rate-limit per adress och per IP ([[ADR-0011 Autentisering]]).

**Motmedel:** tak för antal utestående `pending`-inbjudningar per konto. Den verkliga kostnaden här är inte utrymme utan leveransryktet hos Postmark — och magic links är inloggningskritiska, så ett skadat rykte låser ute betalande kunder.

### 6. Export och skörd av rapportdata

Export är fritt medan kostnadsrapporten är Pro; någon kan exportera och summera i kalkylark. Redan avgjort i [[ADR-0016 Kostnadsregistrering]] — den som gör det är en trolig framtida kund, inte ett läckage att täppa till. Ingen åtgärd.

### 7. Utlåningspåminnelser som utskicksverktyg

Tillagd 2026-08-22. En utlåning bär en fritt inskriven `borrower_email` som ingen har verifierat, och påminnelser mot `due_at` är **återkommande**. Skulle de gå till låntagaren vore utlåning den enda funktionen i systemet som mejlar en okänd adress upprepade gånger — värre än inbjudningar i punkt 5, eftersom en inbjudan skickas en gång och en påminnelse fortsätter tills någon bockar av den.

**Motmedel:** systemet mejlar aldrig låntagaren. Påminnelsen går till den som lånat ut, med adressen synlig i vyn så att hen själv tar kontakt. Se [[Items och organisation]] § loan.

Det kostar en aning bekvämlighet och tar bort hela vektorn — ingen avanmälningslänk, inga studsar från adresser vi inte äger relationen till, ingen påverkan på leveransryktet hos Postmark. Ska automatiska påminnelser till låntagaren någon gång byggas kräver de verifiering av adressen först, och då är det ett eget beslut.

### Mätningen

En **nattlig rapport, inte realtidsspärrar.** Fyra tal räcker som utgångsläge: nya gratiskonton per vecka, andel som aldrig laddar upp något, lagring per gratiskonto, utskickade mejl per konto. Ingenting av det kräver ny data — allt finns i tabeller som redan skrivs.

## Motivering

**Att mäta före att spärra** följer av var kostnaden för ett misstag hamnar. En spärr vid registrering betalas av äkta användare i exakt det ögonblick där konverteringen är skörast, och den falska positiven syns aldrig i statistiken — den personen kommer bara aldrig tillbaka. Ett obemärkt missbruk kostar däremot lagring, och lagring är mätbar och billig.

**Flera av vektorerna är avsiktliga produktval, inte hål.** Fri export, fria påminnelser och fri kostnadsregistrering är alla lönsamma på ackumulering enligt [[ADR-0014 Prismodell]]. Att i efterhand döma dem som läckage vore att riva prismodellen. Poängen med att skriva ner dem är att veta vad vi valt att bära, så att någon om ett år inte "fixar" ett problem som var ett beslut.

**Den som orkar med tre konton, tre inloggningar och tre inbjudningar för att slippa 39 € var förmodligen aldrig en betalande kund.** Vi förlorar inte intäkt på det beteendet, vi bär lagringskostnad — och med dedup och 1 GB tak är den låg. B2B-fallet är annorlunda: där finns någon som *skulle* ha betalat, och en prislapp i storleksordningen 490–1990 € per år.

**Konsumentidentitet går inte att kontrollera.** Systemet känner e-postadresser, inte personer ([[ADR-0011 Autentisering]]). Varje regel formulerad per person är i praktiken en regel per e-postadress, och e-postadresser är gratis.

## Konsekvenser

- Reglen att uppladdningar räknas mot uppladdande konto är nu dubbelt motiverad. Den står i [[ADR-0003 Åtkomstmodell]] och får inte ändras utan att den här ADR:n uppdateras.
- Den nattliga rapporten bör byggas **efter** kvotberäkningen i [[Planer och kvoter]] — den läser samma räknare, och att bygga den först betyder att bygga räknaren två gånger.
- **Trösklarna ovan är platshållare.** Fem containers per `managed`-konto och alla andra siffror är gissningar tills tre månaders data finns. De ska revideras, inte kodas in som konstanter.
- `ownership_transfer` behöver en kontroll av att mottagande konto inte redan konsumerat bonusen. Issue 49 i [[Backlog]].
- Tak för `pending`-inbjudningar per konto läggs till som en liten uppgift i MVP. Issue 48 i [[Backlog]].
- Rapporten är issue 50 i [[Backlog]] och ligger utanför MVP.
- **Utlåningspåminnelser går aldrig till låntagaren.** Regeln bor i [[Items och organisation]] § loan och verifieras av issue 38. Den är det enda motmedlet i den här ADR:n som stänger en vektor helt i stället för att mäta den — möjligt eftersom kostnaden är en aning bekvämlighet, inte en funktion.
- Mätvärdena är personuppgiftsnära — registrerings-IP och e-postdomän. De ska ha en gallringsfrist och stå i registerförteckningen; det räcker inte att de är "bara statistik".

## Alternativ

**Verifiering vid registrering — telefonnummer eller kort.** Effektivt mot allt ovan. Valdes bort: kostar fler äkta registreringar än den stoppar, och hela prismodellen vilar på att tröskeln in är låg nog att data hinner ackumuleras.

**En container per person istället för per konto.** Valdes bort — det finns ingen personidentitet i systemet att hänga regeln på.

**Hårdare gratisnivå istället för mätning.** Valdes bort av skälen i [[ADR-0014 Prismodell]]: användaren möter väggen innan hon förstått vad produkten är bra för, och upplever det som att produkten inte fungerar snarare än att hon borde uppgradera.

**Inte mäta alls.** Valdes bort — utan siffror blir varje framtida diskussion om gratisnivån en gissning, och en oro utan mätvärde vinner alltid över en generositet utan mätvärde.
