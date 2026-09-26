# ADR-0026 Implementering och granskning efter riskaxlar

**Status:** Antagen 2026-09-01 · Ersätter [[ADR-0025 Modellval efter riskaxlar]] · [[ADR-index]]

## Kontext

[[ADR-0025 Modellval efter riskaxlar]] lät axlarna routa **implementeraren**: alla tre låga gav Deepseek V4-Flash, någon förhöjd gav Claude Sonnet. Inför M2 prövades den regeln mot två saker som inte var kända när den skrevs.

**Den dyra banan är huvudbanan.** Av repots 35 issues har 30 ifyllda axlar, och **20 av dem (67 %) är `risk_class: elevated`**. Bland de öppna är andelen 8 av 10. Axeln är sannolikt rätt satt — produkten *är* konton, behörigheter, kvoter, utlåning, filleverans och soft delete i nästan varje issue — men konsekvensen är att en regel som routar implementeraren på axlarna skickar två tredjedelar av all kod till den dyrare modellen. Besparingen ADR-0025 räknade hem gäller då en tredjedel av backloggen.

**Deepseek höll på en `elevated`-issue.** ADR-0025 § Uppföljning innehåller redan mätningen: issue 8 (`risk_class: elevated`) kördes isolerat på Deepseek och blev funktionellt likvärdig med den redan mergade Sonnet-lösningen, till 0,267 USD. Samma uppföljning drog rätt slutsats om var svagheten sitter: Deepseek *"missar subtilare krav om ingen granskar bortom testresultatet"*.

Det är en utsaga om granskningen, inte om implementeraren.

## Beslut

**Deepseek V4-Flash implementerar varje issue, oavsett axlar. Axlarna routar granskningen i stället.**

| Axelprofil | Implementerar | Granskar |
|---|---|---|
| Alla tre låga | Deepseek | ~~Ingen modell~~ → Claude Sonnet 5, se uppföljningen nedan |
| Förhöjd axel, `risk_class: none` | Deepseek | Claude Sonnet 5, läser diffen |
| `risk_class: elevated` | Deepseek | ~~Claude Opus 5, läser issuens läslista~~ → Claude Sonnet 5, se uppföljning 2026-09-03 |

**Uppföljning 2026-09-02, efter M2:** raden om att ingen modell läser en PR med låga axlar är återtagen. Axeln väljer numera granskningens **djup och modell, inte om det finns en läsare** — Sonnet som lägsta nivå, Opus vid `elevated`. Det är en omsvängning av alternativet under § Alternativ (*"Låta Sonnet eller Opus granska varje PR, oavsett axlar"*), och skälet är att dess premiss inte höll i praktiken: M2:s två `none`-issues mergades automatiskt på 13 respektive 5 sekunder, och PR #108 bar då både en fil utanför omfångsrutan och en uttrycklig fråga i sin egen kropp som ingen läste.

**Var ärlig om hur starkt beviset är.** Två av de tre konkreta felen täcks numera också mekaniskt — omfångsrutans grind är lagad, och en icke-tom `## Frågor och antaganden` stoppar automatisk merge. Argumentet i alternativet (*"en PR med alla axlar låga har inget en modell kan säga som inte CI redan sagt"*) är alltså mer sant nu än det var i M2, eftersom den CI-del som skulle säga det var trasig hela tiden. Omsvängningen är därför ett medvetet bälte utöver hängslet, inte ett motbevis. Sonnet på den billiga banan kostar storleksordningen 0,3–0,5 USD på en issue som kostat 0,50; **mät det vid M3-retron** — säger Sonnet ingenting på `none`-banan under en hel milstolpe är raden värd att ta tillbaka igen.

**Uppföljning 2026-09-03: Opus tas bort ur den löpande granskningen. Sonnet granskar varje PR, oavsett `risk_class`.** Anledningen är inte kostnad utan uppgiftens form. Issue-mallen tvingar sedan ADR-0026 fram ett fullständigt kontrakt per issue — numrerade `Beslut`, en `Läs`-lista, `In scope`/`Out of scope` som globbar, `Klart när` som ett test per punkt — och det kontraktet skrivs, för `elevated`-issues, redan med samma eftertanke en arkitektgranskning skulle stått för. Vad som återstår för granskaren är att pröva diffen mot ett redan skrivet facit: matchar de numrerade besluten, håller omfångsrutan, finns testet för varje "Klart när"-punkt. Det är en efterlevnadskontroll, inte ett nytt omdöme, och `bygg_granskningsprompt()` (se `process_next_issue.py`) har redan bett granskaren om precis det, oavsett vilken modell som kört den — Sonnet och Opus har fått identisk prompt och samma verktygsåtkomst till källdokumenten hela tiden. Skillnaden mellan modellerna har aldrig legat i vad de ombads göra.

Opus roll krymper till den redan befintliga smala eskaleringen: en obesvarad `## Frågor och antaganden` i PR-kroppen (`los_fraga_och_merga()`), där frågan per definition inte har ett svar i läslistan och kräver ett arkitekturbeslut ingen dokumentläsning kan mekanisera. Det är den uppgift som faktiskt kräver ett nytt omdöme — inte den löpande granskningen.

Det här upphävde inte `risk_class: elevated`s andra effekt: manuell merge hos Tony efter läst diff (se nedan) — ~~stod kvar oförändrad, av samma skäl som förut~~ → borttagen i uppföljningen 2026-09-05. Det som ändrades här var bara vilken modell som läser diffen först, inte vem som hade sista ordet; det senare ändrades senare.

Som bieffekt gör det här den oåtkomliga mellannivån i skalan (`none`/`elevated` i mallen, mappat till `low`/`high` i skriptet — se [[Lärdomar]], rest vid M2-retron) ofarlig snarare än löst: `risk_class` väljer inte längre granskningsmodell, ~~bara mergegrinden~~ → och sedan uppföljningen 2026-09-05 inte heller mergegrinden, se nedan. Mallen är inte ändrad av det här beslutet.

**Uppföljning 2026-09-05: den manuella mergegrinden på `risk_class: high` (skriptets namn för `elevated`) tas bort. En godkänd granskning mergar automatiskt, oavsett axel — tillfälligt.** Skälet är var i sin livscykel produkten är: inga testare och ingen produktionstrafik betyder att felkostnaden för en granskning som missar något på `elevated` i dag är en rättning i en miljö utan användare, inte en incident. Det är just den kostnadsskillnaden — *"när granskningen missar på `elevated` landar kostnaden i produktion"* — som ursprungligen motiverade gaten, och den är för närvarande falsk i sak. Tony vill fortsatt bli notifierad varje gång en PR mergas (pushover redan i `los_fraga_och_merga()`), men vill inte vara den som mergar.

**Detta är en tillfällig ändring, inte en omvärdering av risken i sig.** Gaten återinförs — genom att återställa `if risk_class == "high"`-grenen i `los_fraga_och_merga()` — inför produktionssättning och när testare tas ombord, för då gäller motiveringen från 2026-09-01 igen oförändrad. Mät samma sak som förra uppföljningen bad om (eskaleringsfrekvens per axelprofil) så att beslutet att återinföra gaten fattas med data om hur ofta granskningen faktiskt missar på `elevated`, inte bara på magkänsla.

**Uppföljning 2026-09-09: Opus-eskaleringen villkoras av granskarens egen triagering, i stället för att vara ovillkorlig så fort PR-kroppen bär en icke-tom `## Frågor och antaganden`.** `oppna_fragor()` (`los_fraga_och_merga()`) är kvar som trigger, oförändrad — men en obesvarad fråga eskalerar bara till Opus om granskaren INTE redan satt etiketten `fraga:besvarad` (`ska_eskalera_till_arkitekt()`). Skälet är samma mätning som motiverade Sonnet-granskningen av varje PR (uppföljning 2026-09-03): granskaren har redan issuen, läslistan och diffen framför sig i samma varv, och de fem senaste `## Frågor och antaganden`-avsnitten (#246, #247, #248, #250, #255) var nästan uteslutande antaganden en granskare kan kvittera, inte arkitekturfrågor. `bygg_granskningsprompt()` bjuder nu in granskaren att triagera frågeavsnittet i samma granskningsvarv som resten av diffen, och sätter etiketten bara om och bara om varje punkt antingen har ett svar i issuen eller läslistan, eller är ett bekräftat antagande, och inget av svaren kräver en kodändring. Grinden är fail-closed på samma sätt som `review:approved`: minsta tvekan, en gammal PR utan granskning, eller en granskare som glömmer kommandot ger dagens beteende - obesvarad fråga går till Opus. `run_opus_answer()`s egen prompt är orörd; det som ändras är hur många frågor som når den. Se issue 259.

**Uppföljning 2026-09-26: en begäran om undantag från omfångsrutan dämpas aldrig, och mergespärren försöker åtgärda två sorters rött innan den når Tony.** PR #522 (issue 136) visar varför. Implementeraren bad om undantag för fem testfiler utanför rutan. Granskaren bekräftade begäran i sak och satte `fraga:besvarad`, och därför nådde frågan aldrig arkitekten. Omfångsgrinden godtar bara ett arkitektsvar, så CI förblev röd, mergespärren slog till och kön stod still hos Tony, trots att koden var godkänd och sviten grön. Två ändringar följer av det:

- **Undantagsbegäran går alltid till arkitekten.** `bygg_granskningsprompt()` säger att en begäran om undantag alltid är ett arkitekturbeslut, och att etiketten då inte får sättas. Den mekaniska vägen stänger hålet även om granskaren gör fel: är omfångsgrinden det enda som föll när mergespärren slår till, frågar `hantera_mergesparr()` arkitekten med grindens felrader, utan att `fraga:besvarad` dämpar. Ett beviljat undantag kör om CI, och ett svar som kräver en kodändring går genom åtgärdsloopen. Sedan prövas mergen igen.
- **Ett rött teststeg körs om en gång.** Är teststeget det enda som föll var sviten redan grön lokalt före pushen, så felet pekar på CI:s miljö eller klocka. Mergespärren kör om de fallerade jobben en gång. Faller det igen går PR:en till Tony, och allt annat rött gör det som förut.

Mergen är dessutom bunden till den commit som granskades (`gh pr merge --match-head-commit`). Kön pausar också i stället för att bränna försök när testsviten är röd redan på main (`sviten_ar_rod_pa_basen()`); det är inte issuens fel, och issuen märks inte `needs-human`.

**Granskningen är en efterlevnadskontroll mot ett redan skrivet kontrakt, inte en fri kodgranskning.** Den får issuens `Läs`-lista som indata och prövar betydelsen mot källdokumenten. Den ska uttryckligen inte godta PR-beskrivningens egen redogörelse för vad ändringen gör.

**Granskningen körs i en färsk session per PR**, aldrig i en långlivad orkestrerarsession. En granskare vars kontext bär trettio tidigare diffar är både dyrare per PR och sämre på den trettionde.

**Ingen modell ser en PR som inte är grön i CI och ren i den mekaniska avläsningen.** Grindarna är gratis och deterministiska; granskningen finns för det de inte kan svara på.

**Omtag routas på feltyp, inte på ordning.**

| Vad granskningen fann | Utfall |
|---|---|
| Rött CI, röd omfångsruta, rött `rott-pa-basen.sh` | Deepseek-omtag utan modellgranskning — felet är redan maskinläsbart |
| Regelbrott mot [[AGENTS.md]] (felkodsformat, saknat `deleted_at`-index, `ENUM`, `->command()`) | Deepseek-omtag |
| Saknat test för en "Klart när"-punkt | Deepseek-omtag |
| Missförstånd av domänen | Eskalera — ett omtag reproducerar missförståndet |
| Issuen säger emot sig själv, eller svaret står inte i läslistan | Tillbaka till den som skrev issuen |

Granskaren avger ett strukturerat utfall — `godkänd`, `omtag`, `eskalera`, `issue-fel` — så att routningen blir ett skript och inte ännu en modell som läser allt.

**Hård spärr: högst två Deepseek-varv, ett Sonnet-varv, sedan stannar uppgiften hos Tony.** Ett tredje varv på samma nivå betyder nästan alltid att issuen är fel skriven.

**`risk_class: elevated` mergades tidigare av Tony efter läst diff — se uppföljning 2026-09-05.**

## Motivering

**Testerna skrivs av samma modell som koden.** Missförstår implementeraren en domänregel — säg [[AGENTS.md]] § *"Kvoter räknas på uppladdande konto, inte på containerns ägare"* — så blir koden, testet och PR-beskrivningen fel på samma sätt, konsekvent. Alla tre berättar samma historia, och grindarna kan inte skilja den från den sanna:

- `rott-pa-basen.sh` bevisar att testet reagerar på implementationen, inte att det testar rätt sak. Skriptets eget filhuvud säger det: *"den säger att testet reagerar på implementationen, inte att testet är bra."*
- `omfangsruta.py` bevisar var koden ligger, inte vad den gör.
- Den mekaniska avläsningen matchar "Klart när" mot testnamn — men testnamnet är också skrivet av samma modell.

Fallet är dokumenterat, inte hypotetiskt: issue 13b:s första Deepseek-körning bröt mot ett uttryckligt frågeräkningskrav medan dess eget test för just den punkten var grönt, eftersom testet filtrerade bort de frågor som gjorde det falskt (ADR-0025 § Uppföljning; [[Lärdomar]] § *"Ett test som får välja vad det mäter kan alltså bevisa sin egen premiss"*). Felet fångades av en oberoende kontroll, inte av agenten.

Det enda som bryter slingan är en granskare som läser **källdokumenten** i stället för PR:ens berättelse om sig själv. Det är en granskningsuppgift. En dyrare implementerare köper mindre än en dyrare granskare för samma pengar, eftersom implementeraren sitter inuti slingan och granskaren står utanför den.

**Omtaget är billigt nog att inte vara den bindande faktorn.** Med repots egna mätningar — Deepseek ~0,36 USD per session (M1: sex sessioner, 2,16 USD, se [[Lärdomar]]) mot Sonnets 4,67 USD för issue 13a och 9,06 för 13b — lönar sig ett extra Deepseek-varv om det lyckas i mer än 6–12 % av fallen. Tröskeln är så låg att frågan inte är hur många omtag man har råd med, utan vad som stoppar loopen. Därav spärren ovan och routningen på feltyp: det som gör ett omtag värdelöst är inte kostnaden, utan att samma modell reproducerar samma missförstånd.

## Konsekvenser

- **[[AGENTS.md]] och `agent_task.yml` namnger granskningsnivå, inte implementerare.** Fältet `Modell` i issue-mallen heter numera `Granskning` och har tre lägen i stället för två. Ingen konsument parsar fältetiketten; bytet är rent redaktionellt.
- **`agent_task.yml`s `Granskning`-fält pekar på Sonnet i båda lägena, efter uppföljningen 2026-09-03.** Texten är liksom förut redaktionell — ingen konsument parsar den — men den fick följa med i samma ändring, annars hade mallen motsagt den faktiska routningen i `process_next_issue.py`.
- **`risk_class` betyder något annat än i ADR-0025.** Den köper inte längre en dyrare implementerare utan en djupare granskning. Mallens råd *"vid tvekan: elevated"* står kvar, men motiveringen är billigare än förut — det är därför regeln tål att 67 % hamnar där.
- **Mätningen är en förutsättning, inte en förbättring.** Eskaleringsfrekvens per axelprofil loggas från M2:s första issue. **Stoppregel: eskalerar `elevated` oftare än 30 % flyttar implementationen tillbaka till Sonnet för den gruppen** — då med data, inte med ett antagande. Instrumentet finns i `.claude/hooks/session-usage.py`; se [[ADR-0016 Kostnadsregistrering]].
- **Omfångsrutans grind måste faktiskt köra innan beslutet får full effekt.** `omfangsruta.py` har aldrig kört en enda gång (se [[Lärdomar]] § Observerat). Grinden är den som gör en billig implementerare säker att köra — hela resonemanget ovan lutar sig mot att den fångar den dyraste feltypen. Den ska vara lagad innan M2 startar.
- **`model-routing.md` i `Manjo-Consulting-AB/ai-standards` måste uppdateras manuellt.** Dess rolltabell (`Implementerare, liten` / `Implementerare, stor`) mappar inte längre — rollen `Implementerare` har numera bara en modell, och det är granskarrollen som delas i tre. Samma sak gäller `agents/issue-author.md`, som bär motiveringen *"det som kostar är inte den dyrare modellen, det är felet"*. Separat PR i det repot; ingår inte i det synkade blocket i [[AGENTS.md]].
- **Kostnadstaket per issue är inte längre implementerarens pris.** Det är summan av implementering, granskning och omtag. En `elevated`-issue som går rätt kostar ungefär 0,36 + 0,50 USD; en som går två varv kostar dubbelt. Det är fortfarande en storleksordning under ADR-0025:s upplägg för samma issue.

## Alternativ

**Behålla ADR-0025 oförändrad.** Sonnet implementerar `elevated`, Deepseek resten. Valdes bort: med 67 % `elevated` är det i praktiken "Sonnet implementerar det mesta", alltså nära utgångsläget före ADR-0025, och issue 8:s skuggkörning talar emot att det behövs.

**Låta Sonnet eller Opus granska varje PR, oavsett axlar.** Valdes bort på samma grund som `model-routing.md` redan formulerar: *"Att granska varje PR med den dyraste modellen är samma misstag som att implementera varje issue med den."* En PR med alla axlar låga har inget en modell kan säga som inte CI redan sagt.

**Låta Deepseek granska sin egen PR i en färsk session.** Billigast tänkbart och lockande, eftersom tomt kontext löser mycket. Valdes bort: ett missförstånd av ett källdokument sitter inte i kontextet utan i läsningen, och en granskare måste kunna överpröva implementerarens omdöme, inte dela det.

**Fler omtagsvarv innan eskalering.** Kostnadsmässigt försvarbart — tröskeln ligger på 6–12 % — men valdes bort: varje varv kostar också ett CI-varv, ett granskningsvarv och väggklocka, och den feltyp som överlever första omtaget är just den som ett andra inte löser.

**Låta en modell orkestrera fan-out och merge.** Valdes bort. Att dela ut arbete är inte en omdömesuppgift, och en orkestrerare som också granskar bär varje läst diff genom resten av sessionen. Triggers per issue är ett skript; merge är Tonys, enligt [[ADR-0018 Utvecklingsprocess och deploy]].
