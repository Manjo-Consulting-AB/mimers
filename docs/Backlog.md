# Backlog

Issues i beroendeordning. Tillbaka till [[00 Index]].

**Till dig som ska implementera:** läs din issue, läs de dokument som står under **Läs**, och inget mer. Dokumentationen är uppdelad just för att du inte ska behöva gå igenom allt för att ändra en detalj.

**Till den som skapar issues i GitHub:** en rubrik per issue nedan, beskrivningen som brödtext, acceptanskriterierna som checklista. Beroenden anges med issue-nummer.

Alla issues förutsätter konventionerna i [[Datamodell – översikt]] — ULID utåt, soft delete, UTC, utf8mb4, inga ENUM, inga flyttal för pengar.

---

## Var ligger min issue

Öppna **bara** milstolpens fil. Där står din issue med beskrivning, läslista och acceptanskriterier — och ingenting från de andra milstolparna.

| Milstolpe | Issues | Innehåll |
|---|---|---|
| [[M0 Fundament]] | **0–7** | repo, miljöer och deploy-kedja, sätt upp laravel-projektet, gemensamma modellkonventioner, konto och användare, … |
| [[M1 Kärnmodell]] | **8–15** | container, åtkomstmodell och behörighetspolicy, inbjudningar, kategorier, taggar, item, relationer mellan items, … |
| [[M2 Filer]] | **16–20** | uppladdning med innehållshash, referensräkning och fördröjd radering, miniatyrer, säker filleverans, papperskorg |
| [[M3 Uppgifter]] | **21–24** | scheman, förekomster och avslut, beroenden mellan uppgifter, todo-listan |
| [[M4 Planer och kvoter]] | **25–29** | planer och rättigheter, förbrukningsräkning, kontrollpunkter för rättigheter, nedgradering, kontolivscykel |
| [[M5 Notiser]] | **30–37** | notiskärna, preferenser och tysta timmar, e-post via postmark, studshantering, notisgeneratorer, veckosammanfattning, … |
| [[M6 Resten av MVP]] | **38–41** | utlåning, ägarbyte, revisionslogg, export |
| [[M7 Drift]] | **42–44** | backupscript, dead man's switch, återläsningsrunbook |
| [[M8 Kostnadsregistrering]] | **45–47** | kostnadsrader, kostnadsrapport, kostnadskrok vid avbockad uppgift |
| [[M9 Missbruksskydd]] | **48–50** | tak för utestående inbjudningar, ägarbytesbonusen en gång per mottagande konto, nattlig missbruksrapport |
| [[M10 Webbfrontend]] | **51–68** | frontendskal, språk i frontenden, inloggnings- och kontovyer, containervyer, delning och inbjudningar, kategorier och taggar, … |
| [[Efter MVP]] | efter lansering | idéer som inte är beslutade |

Totalt 69 issues under `docs/Backlog/`.
