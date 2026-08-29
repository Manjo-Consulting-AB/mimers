# Lärdomar

Vad arbetssättet har lärt oss, och vad det ledde till. [Tankar](../Tankar.md) bär frågor om **produkten**; den här filen bär frågor om **hur vi arbetar** — felsatta axlar, läslistor som inte räckte, omfångsrutor som var otydliga, sessioner som svämmade över.

Fylls av retron vid stängd milstolpe, av granskningen när något återkommer, och av processnoteringen i varje PR.

**Två regler gäller.**

**Tvåträffsregeln.** En observation blir en regel först när samma sak setts **två gånger**. En insikt från en enda PR är en anekdot, och en regel skriven på en anekdot kostar alla framtida issues något för att en gång ha stämt. Första träffen står under `Observerat` och väntar.

**Nollsummeregeln.** `AGENTS.md` är kort med flit och har en ordbudget som CI vaktar. Ett förslag som lägger till ska peka ut vad som blev överflödigt — eller motivera varför inget blev det.

Samma hygien som i Tankar: så fort en punkt lett till en ändring flyttar den till `Infört` och stryks härifrån. Står något kvar som redan är avgjort blir filen värdelös.

## Observerat

- **Röda CI-varv duger inte som motmetrik.** Baslinjen mättes över projektets samtliga 50 CI-körningar. Körning 1–7 var röda med flit — `issue-0-repo-fundament` säger det själv: *"Workflows blir röda tills issue 1 finns — de kör composer install och npm ci."* Körning 8 är den enda äkta röda i historien: `resources/js/Pages` mot `pages`, en skiftlägesskillnad mellan Windows och Linux som inte kunde synas lokalt. Därefter 42 gröna i rad. Grinden ligger alltså före pushen, inte i CI — [AGENTS.md](../../AGENTS.md) säger åt implementeraren att köra grindarna före PR, och det efterlevs. En signal som är noll för 42 PR:er kan inte skilja bra från dåligt. Restes vid baslinjemätningen, 2026-08-29.
- **Baslinjen är grenar per issue, och den mäter en ändring vi redan gjort.** Antal separata grenar en issue behövde: issue 0 sju, issue 4 tre, issue 6a tre, issue 2/3/5/6b två vardera, issue 6c/8/9a/9b/3b en var. `process-omfang-och-modellval` införde omfångsrutan och axlarna 2026-08-24; snittet gick från ~3,2 grenar per issue före till ~1,2 efter. Samma commit skrev *"buntade blir de lika stora som issue 4, som var för stor"* — och issue 4 är just den som krävde tre grenar. Metriken pekar ut det utan att någon behöver minnas det. Nästa mätning när M1 stängs. Restes vid baslinjemätningen, 2026-08-29.
- **Mätvarning för framtida jämförelser:** `ci.yml` har `concurrency: cancel-in-progress: true`. En push som snabbt ersätter en tidigare avbryter dess körning, som då inte syns som avslutad. Räkna därför grenar, inte körningar — grennamn försvinner inte.
- **Dokumentationens siffror driver isär.** Antalet issues anges som 71 i [Backlog](../Backlog.md), 69 i [00 Index](../00%20Index.md) och 47 i [ADR-0018](../ADR/ADR-0018%20Utvecklingsprocess%20och%20deploy.md). Antalet ADR:er som 21 i `00 Index` och 24 i `ADR-index`. Ingen enskild ändring är fel — de skrevs vid olika tillfällen och ingen rutin räknar om dem. Kandidat för en mekanisk konsistensvakt snarare än en regel. Restes vid uppsättningen av retro-loopen, 2026-08-28.
- **Backlogfilerna släpar efter GitHub.** Issue 9 är odelad i [M1 Kärnmodell](../Backlog/M1%20K%C3%A4rnmodell.md) men finns som 9a/9b/9c i grenar och i `Tankar.md`. Delningar sker i GitHub och skrivs inte tillbaka. Frågan är vilken av de två som är källan. Restes vid uppsättningen av retro-loopen, 2026-08-28.

## Infört

- **Retrons motmetrik är grenar per issue, inte röda CI-varv.** Bytt i `.claude/commands/retro.md` och i `ai-standards/agents/retro.md`, 2026-08-29. Bar på baslinjemätningens två första observationer ovan. Ersatte raden *kronor per issue | antal röda CI-varv*; röda varv står kvar som en not, eftersom en nolla som slår om är en observation i sig.
- **Omfångsrutan och axlarna är fält i en issue-mall, inte prosa i en regelfil.** `AGENTS.md` sa att varje issue bär en omfångsruta och tre axlar, men ingen fil under `docs/Backlog/` innehöll dem och ingen issue-mall fanns — en agent som följde läsprotokollet bokstavligt såg alltså aldrig sin egen omfångsruta. Infört som `.github/ISSUE_TEMPLATE/agent_task.yml`, 2026-08-28. Ersatte ingenting; regeln fanns redan, det som saknades var artefakten den beskrev.
