# ADR-0025 Modellval efter riskaxlar

**Status:** Antagen 2026-08-30 · [[ADR-index]]

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

## Konsekvenser

- **`AGENTS.md` och `agent_task.yml` namnger leverantör, inte roll.** En framtida modellväxling inom samma leverantör (t.ex. en ny Claude-modell för den förhöjda bucketen) är en enkel textändring i båda filerna, inte en ADR i sig — men ett leverantörsbyte som detta är det.
- **`session-usage.py` måste prissätta varje modell som faktiskt körs.** Ett nytt leverantörsnamn utan en rad i `PRICES` ger ett odiagnostiserat `cost_usd: null`; se `opriced`-fältet i loggen för vilken modell som saknas.
- **`model-routing.md` i `Manjo-Consulting-AB/ai-standards` måste hållas i synk manuellt.** Det är inte samma repo och ingår inte i den synkade AGENTS.md-blocket ([[AGENTS.md]] rad 25–53) — en ändring här uppdaterar inte den filen automatiskt.
- **Ingen mätning bakåt.** Beslutet utvärderas framåt, mot nya issues som faktiskt körs på Deepseek, inte mot en jämförelse med redan stängda issues.

## Alternativ

**Behålla Claude Haiku för den låga bucketen.** Enklast, ingen ny leverantör att hantera i loggning eller proxy. Valdes bort — priset per token är 5–7x högre utan att kvaliteten på den låga bucketen kräver det.

**Byta hela harnessen till Deepseeks eget verktyg (Aider eller motsvarande) i stället för LiteLLM inom Claude Code.** Undersöktes tidigast som ett tekniker-förslag. Valdes bort: det hade krävt en egen agentpipeline vid sidan av Claude Code, med egen hantering av issue-mallens format och CI-grindarna, i stället för att återanvända harnessen som redan finns.

**Låta axlarna själva avgöra leverantör i stället för att namnge den.** Skulle göra dokumenten mer stabila vid framtida modellbyten men mindre läsbara i dag — vem som faktiskt kör en issue skulle kräva ett skiktbyte till proxykonfigurationen för att svara på. Valdes bort explicit: se svaret "Skriv ut Deepseek och Claude explicit" under arbetet med detta beslut.
