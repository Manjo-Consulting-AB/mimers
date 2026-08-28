# Lärdomar

Vad arbetssättet har lärt oss, och vad det ledde till. [Tankar](../Tankar.md) bär frågor om **produkten**; den här filen bär frågor om **hur vi arbetar** — felsatta axlar, läslistor som inte räckte, omfångsrutor som var otydliga, sessioner som svämmade över.

Fylls av retron vid stängd milstolpe, av granskningen när något återkommer, och av processnoteringen i varje PR.

**Två regler gäller.**

**Tvåträffsregeln.** En observation blir en regel först när samma sak setts **två gånger**. En insikt från en enda PR är en anekdot, och en regel skriven på en anekdot kostar alla framtida issues något för att en gång ha stämt. Första träffen står under `Observerat` och väntar.

**Nollsummeregeln.** `AGENTS.md` är kort med flit och har en ordbudget som CI vaktar. Ett förslag som lägger till ska peka ut vad som blev överflödigt — eller motivera varför inget blev det.

Samma hygien som i Tankar: så fort en punkt lett till en ändring flyttar den till `Infört` och stryks härifrån. Står något kvar som redan är avgjort blir filen värdelös.

## Observerat

- **Dokumentationens siffror driver isär.** Antalet issues anges som 71 i [Backlog](../Backlog.md), 69 i [00 Index](../00%20Index.md) och 47 i [ADR-0018](../ADR/ADR-0018%20Utvecklingsprocess%20och%20deploy.md). Antalet ADR:er som 21 i `00 Index` och 24 i `ADR-index`. Ingen enskild ändring är fel — de skrevs vid olika tillfällen och ingen rutin räknar om dem. Kandidat för en mekanisk konsistensvakt snarare än en regel. Restes vid uppsättningen av retro-loopen, 2026-08-28.
- **Backlogfilerna släpar efter GitHub.** Issue 9 är odelad i [M1 Kärnmodell](../Backlog/M1%20K%C3%A4rnmodell.md) men finns som 9a/9b/9c i grenar och i `Tankar.md`. Delningar sker i GitHub och skrivs inte tillbaka. Frågan är vilken av de två som är källan. Restes vid uppsättningen av retro-loopen, 2026-08-28.

## Infört

- **Omfångsrutan och axlarna är fält i en issue-mall, inte prosa i en regelfil.** `AGENTS.md` sa att varje issue bär en omfångsruta och tre axlar, men ingen fil under `docs/Backlog/` innehöll dem och ingen issue-mall fanns — en agent som följde läsprotokollet bokstavligt såg alltså aldrig sin egen omfångsruta. Infört som `.github/ISSUE_TEMPLATE/agent_task.yml`, 2026-08-28. Ersatte ingenting; regeln fanns redan, det som saknades var artefakten den beskrev.
