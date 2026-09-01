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
| Alla tre låga | Deepseek | Ingen modell — CI och mekanisk avläsning räcker |
| Förhöjd axel, `risk_class: none` | Deepseek | Claude Sonnet 5, läser diffen |
| `risk_class: elevated` | Deepseek | Claude Opus 5, läser issuens läslista |

**`elevated`-granskningen är en annan uppgift än en kodgranskning.** Den får issuens `Läs`-lista som indata och prövar betydelsen mot källdokumenten. Den ska uttryckligen inte godta PR-beskrivningens egen redogörelse för vad ändringen gör.

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

**`risk_class: elevated` mergas av Tony efter läst diff.** Oförändrat mot [[ADR-0018 Utvecklingsprocess och deploy]], och inte förhandlingsbart av kostnadsskäl: när granskningen missar på `elevated` landar kostnaden i produktion, och den syns inte i `.claude/usage.jsonl`.

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
