# Backlog

Issues i beroendeordning. Tillbaka till [[00 Index]].

**Till dig som ska implementera:** läs din issue, läs de dokument som står under **Läs**, och inget mer. Dokumentationen är uppdelad just för att du inte ska behöva gå igenom allt för att ändra en detalj.

**Till den som skapar issues i GitHub:** använd mallen `Agentuppgift` (`.github/ISSUE_TEMPLATE/agent_task.yml`). En rubrik per issue nedan, beskrivningen som brödtext, acceptanskriterierna som checklista, beroenden med issue-nummer. Mallens övriga fält — omfångsrutan och de tre axlarna — står inte i backlogfilerna och måste fyllas i här: det är det enda ställe implementeraren ser dem.

**Vilken av de två som är källan.** Valvet äger *vilket arbete som finns* och varför; GitHub-issuen äger *hur det utförs* — omfångsrutan, axlarna, besluten. Delar du en issue i a/b/c när du skriver den ändrar du det första, inte det andra: **skriv tillbaka delningen som en `Byggd som:`-rad** under rubriken här. Under M1 gjordes fyra delningar (9, 10, 13, 15) och ingen skrevs tillbaka, så backlogfilen beskrev en milstolpe som aldrig byggdes så. Numret i valvet förblir odelat — det är fortfarande ett stycke arbete; raden säger bara i hur många omgångar det togs.

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
| [[M5 Notiser]] | **30–38** | notiskärna, preferenser och tysta timmar, e-post via mailgun, studshantering, notisgeneratorer, veckosammanfattning, … |
| [[M6 Resten av MVP]] | **39–41, 76** | utlåning, ägarbyte, revisionslogg, export |
| [[M7 Drift]] | **42–44** | backupscript, dead man's switch, återläsningsrunbook |
| [[M8 Kostnadsregistrering]] | **45–47** | kostnadsrader, kostnadsrapport, kostnadskrok vid avbockad uppgift |
| [[M9 Missbruksskydd]] | **48–50** | tak för utestående inbjudningar, ägarbytesbonusen en gång per mottagande konto, nattlig missbruksrapport |
| [[M11 Åtkomst på itemnivå]] | **69–75** | ladder och migrering, omfångsupplösning, grindar, beviljande, filtrering av listning/sök/rapporter, notiser · **byggs före M10** |
| [[M10 Webbfrontend]] | **51–68** | frontendskal, språk i frontenden, inloggnings- och kontovyer, containervyer, delning och inbjudningar, kategorier och taggar, … |
| [[M12 Ordet och de första fynden]] | **77–80** | container och objekt i stället för pärm, sökfältet, vägen till inställningarna, magic link och tvåfaktorn |
| [[M13 Omskrivningen]] | **81–82** | valvets prosa följer ADR-0033 och ADR-0034, sedan strängarna användaren möter |
| [[M14 Besluten ur mockupgenomgången]] | **83–87** | fyra ställen där koden säger emot ett skrivet beslut, plus en knapp som aldrig borde ha funnits |
| [[Att sortera efter mockuparna]] | — | identifierat arbete som ännu inte fått en plats — ingen milstolpe |
| [[Efter MVP]] | efter lansering | idéer som inte är beslutade |

Totalt 88 issuerubriker under `docs/Backlog/`, numrerade 0–87. Issue 6 är delad i 6a, 6b och 6c. **Nummer 38 bar två olika issues fram till 2026-09-07** — *Byt e-postleverantör till Mailgun* i M5 och *Utlåning* i M6. Mailgun var redan byggd som 38a/38b och behöll numret; Utlåning är sedan dess **76**, och `Beror på`-raden i M10 § 67 följde med. Se [[Tankar]] § Avgjort och flyttat. **M11 står sist i numret men grindar sex issues i M10** — 55, 57, 58, 59, 62 och 67 bygger på behörighetsmodellen den ändrar. Beroendena i issuerna är det som gäller, precis som M10:s egen ingress säger.
