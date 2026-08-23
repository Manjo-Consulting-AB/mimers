# Projektindex

Startpunkt för hela projektet. **Läs inte allt.** Varje issue i [[Backlog]] pekar ut exakt vilka dokument som behövs för just den uppgiften — läs dem, inget mer.

## Vad är det här

API-först system där ägaren av en båt, husvagn, stuga eller bil samlar all dokumentation om objektet: prylar, manualer, kvitton, serienummer, servicehistorik och återkommande underhåll. En blandning av Evernote och OmniFocus, byggd för en nisch.

Systemet heter **Mimers** och bor på `mimers.app`. Namnet syftar på Mimer i nordisk mytologi, som vaktar brunnen där visdomen finns: produkten är den brunn där båtens, husets eller bilens historik, kunskap och underhåll samlas. Produkten är fristående och står på egna ben — se [[ADR-0020 Plattformsidentitet och frontendgräns]].

## Var hittar jag vad

| Jag vill… | Läs |
|---|---|
| förstå produkten på fem minuter | [[Översikt]] |
| veta vad som ingår i MVP | [[Översikt]] § Avgränsning |
| bygga eller ändra en tabell | rätt fil under **Datamodell/** |
| veta *varför* något är som det är | rätt ADR under **ADR/** |
| ta nästa arbetsuppgift | [[Backlog]] |
| implementera en issue | `AGENTS.md` i repo-roten |
| sätta upp eller ändra CI och deploy | [[Pipeline]] |

## Datamodell

Uppdelad per domän så att en ändring bara kräver en fil.

- [[Datamodell – översikt]] — entiteter, hur delarna hänger ihop, gemensamma konventioner
- [[Konton och åtkomst]] — konton, användare, containers, behörigheter, inbjudningar, ägarbyte
- [[Items och organisation]] — items, kategorier, taggar, relationer, utlåning
- [[Scheman och uppgifter]] — återkommande underhåll, förekomster, beroenden
- [[Filer och lagring]] — uppladdningar, innehållshash, dedup, referensräkning
- [[Notiser]] — outbox, kanaler, prenumerationer, leveransstatus
- [[Planer och kvoter]] — planer, rättigheter, förbrukning, nedgradering, livscykel

## Beslut

[[ADR-index]] — tjugoen beslut med kontext och konsekvenser. Slå upp när du undrar varför, inte innan du börjar.

## Arbete

[[Backlog]] — issues i beroendeordning, grupperade i milstolpar. Varje issue har acceptanskriterier och en läslista.

[[Pipeline]] — CI, miljöer och utrullning i konkret form. Motiveringen bor i [[ADR-0018 Utvecklingsprocess och deploy]].

## Konventioner i dokumentationen

- Ett beslut bor på **ett** ställe. Datamodellen beskriver *vad*, ADR:erna beskriver *varför*. Upprepa inte motiveringar i datamodellen.
- Ändrar du ett beslut: uppdatera ADR:en med status `Ersatt av ADR-XXXX`, skriv en ny. Radera aldrig en ADR.
- Nya frågor utan svar hör hemma i [[Tankar]], inte utspridda i dokumenten.
