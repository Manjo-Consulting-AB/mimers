---
description: Utvärdera en stängd milstolpe och föreslå ändringar i arbetsreglerna
---

Kör retro för milstolpen: **$ARGUMENTS** (t.ex. `M1`). Saknas argument, fråga vilken.

Följ [retro-prompten i ai-standards](https://github.com/Manjo-Consulting-AB/ai-standards/blob/main/agents/retro.md). Sammanfattningen nedan är repospecifik.

## Du har inte tillgång till tidigare sessioner

Deras kontext och resonemang finns inte kvar och går inte att rekonstruera. Skriv aldrig en observation du inte har belägg för i listan nedan — en påhittad observation som blir en regel är det dyraste den här loopen kan göra. Saknas en uppgift: skriv att den saknas, uppskatta den inte.

## Bevismängden

1. **Processnoteringarna** i milstolpens mergeade PR:er. Använd GitHub-verktygen; sök PR:er mot `main` vars gren heter `issue-NN-*` för milstolpens issuenummer enligt [Backlog](docs/Backlog.md).
2. **`docs/Process/Lärdomar.md` § Observerat** — det som redan väntar på en andra träff.
3. **Grenar per issue** — hur många separata grenar issuen behövde innan den var färdig. Räkna `issue-NN-*` i `git log origin/main --merges`. Detta är metriken med bevisad signal; se `docs/Process/Lärdomar.md`.
4. **Per PR:** antal commits och tid från öppnad till merge.
5. **Ändrade filer mot issuens `In scope`-globbar.** Hämta rutan ur GitHub-issuen, jämför med PR:ens filer. Drift ut ur rutan är den enda helt automatiska mätningen av om issue-skrivandet håller.
6. **PR:er där `Frågor och antaganden` inte var "Inga."**
7. **`.claude/usage.jsonl`** om den finns — kostnad per gren. Läs som avvikelse inom axelprofilen, aldrig som absolut tal: en `elevated`- och `cross-module`-issue *ska* kosta mer. Jämför mot medianen för issues med **samma** axelprofil.

## Läs aldrig kostnaden ensam

| Kostnadssignal | Motsignal |
|---|---|
| kronor per issue | **grenar per issue** |
| tid till merge | antal ändrade filer utanför omfångsrutan |
| antal commits | antal ställda frågor och deklarerade antaganden |

En issue som gick billigt och snabbt men behövde tre grenar och rörde filer utanför rutan gick inte bra.

**Röda CI-varv är inte en motmetrik här.** Baslinjen visar 42 gröna körningar i rad; de röda som finns var antingen förväntade (appen fanns inte än) eller en skiftlägesbugg. Grinden ligger före pushen, inte i CI. Räkna dem ändå — den dagen siffran slår om från noll är det i sig en observation värd att skriva ner.

Mäts bara kostnad kommer arbetet att optimeras mot att se billigt ut — mindre läsning, färre frågor, fler gissningar. Det är precis det failure mode reglerna finns till för att förhindra.

## Utdata

1. **Nya punkter i `docs/Process/Lärdomar.md` § Observerat**, var och en med issuenummer och datum.
2. **Andra träffen på samma sak blir ett förslag.** En PR mot `AGENTS.md` — eller mot `agents/agent-core.md` i ai-standards om regeln gäller alla repon, inte bara det här. Varje förslag åtföljs av vad det ersätter och vilka två observationer som bär det.
3. **Är det ett skript snarare än en regel** — kan svaret uttryckas som ett villkor — hör det hemma i `.github/workflows/ci.yml`, inte i en text någon ska minnas.
4. **En kort sammanfattning:** vad gick bra, vad kostade mer än det borde, vad du inte kunde mäta.

Committa aldrig regeländringar direkt. Tony mergar, som allt annat.
