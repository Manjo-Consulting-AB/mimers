# ADR-0025 Modellval efter riskaxlar

**Status:** Ersatt av [[ADR-0026 Implementering och granskning efter riskaxlar]] 2026-09-01 · Antagen 2026-08-30 · [[ADR-index]]

## Kontext

Issue-mallen (`.github/ISSUE_TEMPLATE/agent_task.yml`) sätter tre axlar per issue — `ambiguity`, `blast_radius`, `risk_class` — som routar arbetet till en modell. Fram till nu gick den låga bucketen (alla tre axlar låga) till Claude Haiku och den förhöjda bucketen till Claude Sonnet, båda inom Claude Code som harness.

Haiku var billigare men samma leverantör som Sonnet, vilket inte testade om priset kunde sänkas ytterligare utan att byta harness. LiteLLM Proxy gör det möjligt att peka Haikus API-anrop mot en annan leverantör medan resten av kedjan — Claude Code, verktygsanropen, sessionens loggning — förblir oförändrad. Det gör leverantörsbytet till en ren routingfråga, inte en ny integration.

Deepseek V4-Flash prissätts på 0,14/0,28 USD per miljon token (in/ut, peak), mot Claude Haiku 4.5 på 1/5 — se `PRICES` i `.claude/hooks/session-usage.py`. Frågan var om kvaliteten på den låga bucketen håller vid det bytet.

## Beslut

**Deepseek V4-Flash (`deepseek-v4-flash`) ersätter Claude Haiku 4.5 för den låga bucketen. Claude Sonnet 5 (`claude-sonnet-5`) behåller den förhöjda bucketen oförändrad.**

Vald modell skrivs ut med sitt leverantörsnamn — "Deepseek" och "Claude Sonnet" — i både `agent_task.yml` och `AGENTS.md`, inte som en rolletikett. Sanningen om vilken modell som kör vilken bucket ska gå att läsa direkt i dokumenten, utan ett indirekt steg via ett tidigare Haiku/Sonnet-namnpar.

Eskaleringsregeln i `AGENTS.md` är oförändrad i sak: säger en session till enligt regeln avbryts den och uppgiften går om till Claude Sonnet med tomt kontext, oavsett vilken modell som körde den låga bucketen.

## Motivering

Beslutet fattades utan en färdig batch av jämförande körningar — det finns ingen historik av stängda issues körda parallellt på båda leverantörerna att peka på. Grunden är dels prisskillnaden ovan, dels att LiteLLM-bytet är billigt att pröva och billigt att backa: det rör routingen i proxyn och två dokumentrader, inte kod eller arkitektur.

Den låga bucketen (alla tre axlar låga) är per definition den del av backloggen med lägst tvetydighet, minst spridningsyta och lägst riskklass — den bucket där en svagare modell gör minst skada om den presterar sämre, och där felet upptäcks billigt via samma testsvit som [[ADR-0022 Testramverk och statisk analys]] redan gör till den bärande kontrollmekanismen.

## Uppföljning (2026-08-31)

Tre shadow-körningar mot verkliga, tidigare oimplementerade issues, med Deepseek och Sonnet på identisk utgångspunkt i en läckagefri isolerad kopia av repot:

| Issue | Sonnet | Deepseek | Resultat |
|---|---|---|---|
| 8 · Container (`risk_class: elevated`) | ingen siffra — mergad innan kostnadsloggningen fanns | $0,267 | Funktionellt likvärdig med den redan mergade Sonnet-lösningen |
| 13a · Item CRUD | $4,671 | $0,188 (~25x billigare) | Likvärdig: 302/302 gröna tester, Pint och PHPStan rena, rätt filomfång |
| 13b · item_tag | $9,063 | $0,354 inkl. en rättningsrunda (~26x billigare) | Se nedan |

Issue 13b:s första Deepseek-körning bröt mot ett uttryckligt "Klart när"-krav: taggsynken skulle göra ett konstant antal databasfrågor oavsett antal taggar (Beslut 6 i issuen), men bokstavlig `sync()` plus per-element `Rule::exists()` skalar med antal taggar (13 frågor vid två taggar, 20 vid fem). Deepseeks eget test för just den punkten gick ändå grönt — det mätte bara en delmängd av frågorna. Felet fångades genom en oberoende empirisk kontroll (fullständig frågeräkning, inte agentens egen), inte av agenten själv. En andra körning, given exakt det fyndet, fixade båda källorna korrekt (verifierat: 11 frågor oavsett två eller fem taggar, samma som Sonnet).

**Slutsats hittills:** Deepseek håller kvalitetsmässigt när kraven är explicit uppräknade och testbara — vilket den här repots issue-stil är byggd för — till en genomsnittlig kostnad på ungefär 4 % av Sonnets. Men den missar subtilare krav (en prestandaregression bortom "mitt eget test är grönt") om ingen granskar bortom testresultatet. Sonnet eller en människa i granskarledet är alltså fortsatt nödvändigt för den låga bucketen, inte valfritt — precis den roll `AGENTS.md`:s eskaleringsregel redan ger den.

Sidofynd under arbetet, inte specifikt för Deepseek: en genuin, leverantörsoberoende testflakighet upptäcktes (`DB::listen`-baserade frågeräkningstest racear mot `UpdateLastActiveAt`-middlewarens sekundprecisa skrivning), rotorsaksbestämd och dokumenterad som [issue #80](https://github.com/Manjo-Consulting-AB/mimers/issues/80).

## Konsekvenser

- **`AGENTS.md` och `agent_task.yml` namnger leverantör, inte roll.** En framtida modellväxling inom samma leverantör (t.ex. en ny Claude-modell för den förhöjda bucketen) är en enkel textändring i båda filerna, inte en ADR i sig — men ett leverantörsbyte som detta är det.
- **`session-usage.py` måste prissätta varje modell som faktiskt körs.** Ett nytt leverantörsnamn utan en rad i `PRICES` ger ett odiagnostiserat `cost_usd: null`; se `opriced`-fältet i loggen för vilken modell som saknas.
- **`model-routing.md` i `Manjo-Consulting-AB/ai-standards` måste hållas i synk manuellt.** Det är inte samma repo och ingår inte i den synkade AGENTS.md-blocket ([[AGENTS.md]] rad 25–53) — en ändring här uppdaterar inte den filen automatiskt.
- **Mätning finns nu framåt, se § Uppföljning.** Issue 8 hade redan en mergad Sonnet-lösning (utan loggad kostnad, se tabellen) och kördes om isolerat som ett rent kvalitetsprov; 13a och 13b var båda oimplementerade och kördes fräscht på båda modellerna parallellt, med jämförbar kostnad för första gången.

## Alternativ

**Behålla Claude Haiku för den låga bucketen.** Enklast, ingen ny leverantör att hantera i loggning eller proxy. Valdes bort — priset per token är 5–7x högre utan att kvaliteten på den låga bucketen kräver det.

**Byta hela harnessen till Deepseeks eget verktyg (Aider eller motsvarande) i stället för LiteLLM inom Claude Code.** Undersöktes tidigast som ett tekniker-förslag. Valdes bort: det hade krävt en egen agentpipeline vid sidan av Claude Code, med egen hantering av issue-mallens format och CI-grindarna, i stället för att återanvända harnessen som redan finns.

**Låta axlarna själva avgöra leverantör i stället för att namnge den.** Skulle göra dokumenten mer stabila vid framtida modellbyten men mindre läsbara i dag — vem som faktiskt kör en issue skulle kräva ett skiktbyte till proxykonfigurationen för att svara på. Valdes bort explicit: se svaret "Skriv ut Deepseek och Claude explicit" under arbetet med detta beslut.
