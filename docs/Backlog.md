# Backlog

Issues i beroendeordning. Tillbaka till [[00 Index]].

**Till dig som ska implementera:** läs din issue, läs de dokument som står under **Läs**, och inget mer. Dokumentationen är uppdelad just för att du inte ska behöva gå igenom allt för att ändra en detalj.

**Till den som skapar issues i GitHub:** använd mallen `Agentuppgift` (`.github/ISSUE_TEMPLATE/agent_task.yml`). En rubrik per issue nedan, beskrivningen som brödtext, acceptanskriterierna som checklista, beroenden med issue-nummer. Mallens övriga fält — omfångsrutan och de tre axlarna — står inte i backlogfilerna och måste fyllas i här: det är det enda ställe implementeraren ser dem. Kön kör `omfangslint.py` mot rutan innan sessionen startar och kommenterar på issuen om den verkar sakna en fil som bär något issuen beskriver; vill du se det medan du skriver, kör `python3 .github/scripts/omfangslint.py --fil utkast.md`.

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
| [[M15 Containerns översikt]] | **88–92** | containerns förstasida blir en översikt, itemlistan en flik, och underträdets summor får sin upplösning |
| [[M16 Itemets vy]] | **93–96** | itemets omslagsbild, strukturens upplösning, de flera vägarna till samma item och anteckningsfältet |
| [[M17 Designsystemet]] | **97–106** | tokens och kärnkomponenter, flikraden, trepanelslayouten, datumregeln och favoriterna |
| [[M18 Loggarna]] | **107–121** | händelseloggen, säkerhetsloggen och mätningen, den rättsliga spärren, gallringen och historikflikarna |
| [[M19 Dashboarden]] | **122–128** | todo-vyn flyttar, brickorna och containerkorten, kostnaderna, händelsepanelen, notisklockan och informationsytan |
| [[M20 Kontot]] | **129–131, 140** | byta lösenord, byta e-postadress, väntande inbjudningar för inloggade och lösenordsbytet via mejl |
| [[M21 Uppgifterna i vardagen]] | **132–139** | nästa förfall efter det stängda, prickarna på knappen, växeln för framtida uppgifter och användarens dag överallt |
| [[M22 Redo för testare]] | **141–150** | webben räknas som aktivitet, konto- och personraderingen, inbjudningsnotisen som aldrig skickas, kalenderns namn, testplanen för filerna och varningen innan en tagg eller kategori kastas |
| [[M23 Mobilen och kartan]] | **151–160** | mobilskalet, plusknappen som skapar det vyn visar, fokuskartan, containerns karta, containerns bild och nyligen besökta |
| [[M24 Desktopdesignen]] | **168–182, 225–231** | kostnadsraderna i webben, desktopskalets sidopanel, containerns hjälte, översikternas paneler och flikarna för uppgifter, kostnader, dokument och historik |
| [[M25 Itemets vy enligt mockupen]] | — | frågor att avgöra efter testarnas genomgång 2026-10-02: itemets överdel, snabbåtkomsten, flikraden, kartans lägen och skalet |
| [[M26 Uppgifternas listor]] | **232–238** | GTD-listan och statusen på förekomsten, uppgifter utan datum, listans regler, flikarna per lista och API:et — bygger [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] |
| [[M27 Flytten och inboxen]] | **239–247** | flytt och kopiering av bilagor och uppgifter, den personliga inboxen, Back to Inbox och knappen New task — bygger [[ADR-0053 Flytt och kopiering]] och [[ADR-0054 Inboxen]] |
| [[M28 Testarnas fynd 2026-10-07]] | **248–261, 266** | ord och menyer, inboxen som plats och radering i den, /inbox och dashboarden, plusknappen på flikarna, globala dokument, /tasks enligt mockupen, QR-koden vid tvåsteg och att stå kvar i listan efter en flytt |
| [[M29 Privat beta]] | **262–265** | stängd registrering, inbjudningskoder som ger Pro, containerinbjudan som egen väg in och prenumerationer som löper ut — bygger [[ADR-0055 Inbjudningskoder och stängd registrering]] |
| [[M30 Testarnas fynd 2026-10-09]] | **267–272** | kodfältet vid inloggning, inboxens meny och filval, målväljaren, beroendefelet, linjen på Documents, växeln som bara gäller *Active* och den globala historiken på `/history` |
| [[M31 Flytande navigering]] | **273–279** | skalet står kvar mellan sidorna, förhämtning och cache i webbläsaren, avbockningen syns direkt och mjuka övergångar — bygger [[ADR-0056 Flytande navigering]] |
| [[M32 Formspråket]] | **280–290** | typsnittet, skuggan, ikonerna under rollnamn och containerns översikt som första sida med dem — bygger [[ADR-0057 Ikoner och typsnitt]] |
| [[M33 Trädet som tabell]] | **291–295** | ett huvud för itemflikens tre lägen, trädets kolumner, filtret i trädet, tabellen och *Expand all* — bygger [[ADR-0058 Trädet som tabell]] |
| [[M34 Kartan med en mitt]] | **296–299** | kartläget får en mitt med föräldrar, barn och relaterade runt sig, nya noder, vägen till mitten och filtret i kartan — bygger [[ADR-0059 Kartan med en mitt]] |
| [[M35 Itemets ikon och kartans kort]] | **300–307** | itemets ikon ur Lucide med färg, väljaren under *Edit item*, ikonen i listan, trädet och kartan, trädets rotrad, kartans kort och böjda linjer och itemets bild på översikten — bygger [[ADR-0060 Itemets ikon]] och [[ADR-0061 Kartans kort och kanter]] |
| [[M36 Kartans luft, listans ruta och sökningen]] | **308–311** | kartan utan pilspetsar och med mer luft, de relaterade på varannan sida, ögat och plusmenyn i mittkortet, listans ruta med bild eller ikon och filterraden som söker medan man skriver — bygger [[ADR-0062 Kartans luft, listans ruta och den löpande sökningen]] |
| [[M37 Itemets karta]] | **312–313** | kartan på itemets sida ritas med containerkartans kort i dess smala form, med plusmenyn i mitten och en länk till hela kartan — bygger [[ADR-0063 Itemets karta i containerkartans form]] |
| [[M38 Containerns ikon]] | **314–317** | containerns egen ikon och färg med itemets väljare, i huvudmenyn, trädets rotrad, kartans mitt och containerns ruta utan bild — bygger [[ADR-0064 Containerns ikon]] |
| [[M39 Den samlade papperskorgen]] | **318–320** | skalets post *Trash* och sidan `/trash` med raderade items, bilagor och containrar, sökfält, växel, vem som raderade och *Restore* — bygger [[ADR-0065 Den samlade papperskorgen]] |
| [[M40 Fynd 2026-10-18]] | **321** | översiktens bilder direkt under itemen och ikonens ord i historiken |
| [[M41 Fynd 2026-10-19]] | **322–323** | containerns ikon i menyn utan ljus ruta och arket som öppnas uppåt när platsen under inte räcker |
| [[M42 Itemets anteckning]] | **324–325** | anteckningen redigeras direkt på itemets översikt, sparas av sig själv och ritas som Markdown, ovanför *Recent activity* — bygger [[ADR-0066 Itemets anteckning]] |
| [[M43 Trädet som förval]] | **326–327** | itemflikens listläge tas bort, trädet blir förval med listans ruta i raden och kartans länkar leder dit — bygger [[ADR-0067 Itemfliken utan listläge]] |
| [[M44 Fynd efter v0.24.1]] | **328** | uppgiftslistan i itemets anteckning utan punkt före checkboxen |
| [[M45 Itemets sida]] | **329–343** | itemets ord, huvudet med primärknapp och ⋯-meny, bekräftelsedialogen, sju flikar utan nollor, översikten med tak, infällbara paneler, väljaren som skapar taggar och kategorier, skapaformuläret i två nivåer med bild, omslaget bland miniatyrer och trädtabellens rader — bygger [[ADR-0068 Itemets sida och skapandet]] |
| [[M46 Flytta ett item]] | **344–345** | ett item flyttas med sitt underträd till toppnivån eller under ett annat item i samma container, och *Move* i itemets ⋯-meny — bygger [[ADR-0069 Flytta ett item inom containern]] |
| [[Att sortera efter mockuparna]] | — | identifierat arbete som ännu inte fått en plats — ingen milstolpe |
| [[Efter MVP]] | efter lansering | idéer som inte är beslutade |

Totalt 297 issuerubriker under `docs/Backlog/`, numrerade 0–160, 168–182 och 225–345. **161–167 finns bara i GitHub** — rättelser efter M22 och M23 som skrevs direkt där, samma familj som nummer 96 nedan. **183–190 likaså** — testarnas fynd 2026-10-02, GitHub #626–#631, #633 och #634; frågorna från samma genomgång står i [[M25 Itemets vy enligt mockupen]]. **140 står i M20 fast M21 är byggd**: den rättar issue 129 och hör hemma bredvid den. Issue 6 är delad i 6a, 6b och 6c. **Nummer 96 fanns som GitHub-issue innan det fanns i valvet** — det skrevs direkt i GitHub under M16 och skrevs in här först 2026-09-22, samma familj som posten om att backlogfilerna släpar efter GitHub i [[Lärdomar]]. **Nummer 38 bar två olika issues fram till 2026-09-07** — *Byt e-postleverantör till Mailgun* i M5 och *Utlåning* i M6. Mailgun var redan byggd som 38a/38b och behöll numret; Utlåning är sedan dess **76**, och `Beror på`-raden i M10 § 67 följde med. Se [[Tankar]] § Avgjort och flyttat. **M11 står sist i numret men grindar sex issues i M10** — 55, 57, 58, 59, 62 och 67 bygger på behörighetsmodellen den ändrar. Beroendena i issuerna är det som gäller, precis som M10:s egen ingress säger.
