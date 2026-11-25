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
| [[M47 Fynd 2026-11-06]] | **346** | PHP 8.5 i CI, utvecklingsmiljön och dokumentationen, efter att kontot höjts till 8.5.12 |
| [[M48 Subprocesser och miniatyrer]] | **347–348** | köarbetaren i egen process med dagens väg som reserv, och miniatyrer av HEIC, HEIF och TIFF med Imagick — bygger [[ADR-0070 Subprocesser hos inleed]] och [[ADR-0071 Miniatyrer med Imagick]] |
| [[M49 Kartan fyller bredden]] | **349** | kartans kort i tre storlekar och barnen på högst två jämna rader efter ramens bredd — bygger [[ADR-0072 Kartan fyller bredden]] |
| [[M50 Översiktens ordning]] | **350–354** | personen väljer ordning och bredd på containeröversiktens paneler, för alla containrar, i ett redigeringsläge med pilknappar och drag på skrivbordet; en tom panel står kvar — bygger [[ADR-0073 Översiktens ordning]] |
| [[M51 Sårbarhetsskanning]] | **355** | `composer audit`, `npm audit`, Dependabot och Semgrep i CI |
| [[M52 Registrering i vyn]] | **356–360** | snabbraden och det infällbara formuläret, målväljaren för en container, snabbraden på containerns *To do* med inboxen förvald, inboxens bekräftelse med länk och platsknappen på snabbraden — bygger [[ADR-0074 Registrering i vyn]] |
| [[M53 Uppgiftens sida]] | **361–363** | uppgiftens sida får containerns huvud och leder tillbaka dit man kom ifrån, samma meny på visnings- och redigeringssidan med en fråga vid osparade ändringar, och beroendena redigeras på *Edit* men syns som läsbar lista på visningssidan |
| [[M54 Registrering i vyn - Items]] | **364–366** | registrering på containerns *Items*-flik: itemets skrivväg tillbaka till fliken, det infällbara formuläret ovanför trädet med namnet i raden och övriga fält bakom *More details*, och ett plus på varje trädnod som skapar ett barn — bygger [[ADR-0074 Registrering i vyn]] § 7 |
| [[M55 Registrering i vyn - Documents]] | **367–368** | uppladdningskön som egen komponent och ett infällbart formulär med itemväljare och kö på containerns *Documents*-flik — bygger [[ADR-0074 Registrering i vyn]] § 7 |
| [[M56 Registrering i vyn - Costs]] | **369–370** | kostnadsformuläret som egen komponent med skrivväg tillbaka till fliken, och det infällbara formuläret med itemväljare ovanför kostnadstabellen på containerns *Costs*-flik — bygger [[ADR-0074 Registrering i vyn]] § 7 |
| [[M57 Dokumentraden]] | **371–373** | nedladdningsuppsåtet som gör att *Download* laddar ner, filnamnet som öppnar filen i en lightbox på *Documents* och bilagefliken, och raden med *Download* och *More* |
| [[M58 Registrering i vyn - itemets flikar]] | **374–378** | itemets *Documents*, *To do* och *Costs* får samma registrering i vyn som containerns flikar, och plusmenyn och snabbknapparna på itemet landar på fliken med formuläret öppet, och sidorna för nytt och redigerat item får containerns låga hjälte — bygger [[ADR-0074 Registrering i vyn]], tillägget 2026-11-13 |
| [[M59 Profilbilden]] | **379–382** | profilbilden: en bilaga som kan tillhöra en person, skrivvägen och kvoten, ytan på inställningssidan och i toppraden, och beskärningen med fokuspunkt och zoom — bygger [[ADR-0076 Profilbilden]] |
| [[M60 Notislistan i appen]] | **383–386** | notislistan i appen: kanalen `in_app` med läst-läge och gallring efter 90 dagar, rutterna på webben och i API:et, klockan med panelen och sidan `/notifications`, och typernas förval med en kolumn för kanalen i inställningarna — bygger [[ADR-0077 Notislistan i appen]] |
| [[M61 Övervakning]] | **387–391** | övervakning: felrapportering till Sentry från servern och webbläsaren, hjärtslag för schemalagda jobb och vakten för notiskön, prestandaloggen med veckosammanställningen, och sidvisningar och funktioner i den anonyma mätningen — bygger [[ADR-0079 Övervakning]] |
| [[M62 Kartan är relationernas yta]] | **392–394** | relationsfliken bort och redigeringen i den fulla kartan, zoomnivåerna *Neighbourhood* och *Whole container* med `d3-force` — bygger [[ADR-0082 Kartan är relationernas yta]] |
| [[M63 Itemets släkt]] | **395–397** | grannskapet utan stegräkning och ritat som ett radialt träd med ringar efter avstånd, och hela containern med samma nod — bygger [[ADR-0083 Grannskapet är itemets släkt]] och [[ADR-0084 Hela containern med itemets ikon]] |
| [[M64 Profilen bär kontot]] | **398** | kontosidan bort, personkontots namn och valuta på profilsidan och ett lager för språk, tidszon och enhetssystem — bygger [[ADR-0085 Profilen bär personkontot]] |
| [[M65 Pro+ och grundarpris]] | **399** | planraderna för Pro, Pro+ och grundarpriserna, och bonusen vid ägarbyte som aldrig sänker planen — bygger [[ADR-0086 Pro, Pro+ och grundarpris]] |
| [[M66 MCP-server för agenter]] | **400–410, 416** | OAuth-servern och anslutningen, rättighetsmodellen, MCP-servern med verktygen, sidan för agenter och nedgraderingen — bygger [[ADR-0087 MCP-server för agenter]] |
| [[M67 Versionshistorik]] | **411–415** | taket för antalet versioner per item på kontot och containern, versioner som sparas vid varje ändring, visa och återställa, livscykeln vid sänkt tak, papperskorg och ägarbyte, och verktygen för agenter — bygger [[ADR-0088 Versionshistorik för items]] |
| [[M68 Egna fält]] | **417–425** | egna typade fält på containern och på enskilda items, de sex fasta fälten blir vanliga fält, mallar med kategorier och fält, filter och sortering på fält, och verktygen för agenter — bygger [[ADR-0089 Egna fält på items]]; går före M67:s issue 412 |
| [[M69 Fynd 2026-11-21]] | **426–432** | kostnadsverktygen i huvudenhet, agentens svar när en container raderas, agentens handlingar i historiken med filter, små fynd i inställningarna och itemformuläret, väntande inbjudningar som kan ändras, containerns agenter på egen sida och kategorier som dras in under en annan |
| [[M70 Dashboardens hjälte]] | **433–435** | den delade utsnittsredigeraren och zoom på containerns bild, personens hjältebild och dashboardens hjälte med brickorna och donuten i bilden — bygger [[ADR-0090 Dashboardens hjälte]] |
| [[M71 Fynd 2026-11-22]] | **436–439** | containerns namn i historiken som länk, dashboardens hjälte på toppnivåsidorna och inställningssidorna, och nivåvalet som fyra handlingar — bygger [[ADR-0090 Dashboardens hjälte]], tillägget 2026-11-22 |
| [[M72 Kategoridrag, anteckningsfältet och pluralen]] | **440–442** | kategorins drag med hover och dragbild, anteckningsfältet tre rader högt, och agentens raderingsnotis i singular och plural |
| [[M73 PDF-pärmen]] | **443–447** | pärmens motor med försättsblad, förteckning och sidnummer, bilagorna som sidor eller länkar, beställningen på exportsidan, pärmen för ett item och notisen — bygger [[ADR-0091 PDF-pärmen]] |
| [[M74 Tvåfaktor som krav för delade containrar]] | **448–451** | kravet och spärren i åtkomsten, låsta containrar med märket i översikten, ägarens inställningar för kontot och containern, och ägarbytet — bygger [[ADR-0092 Tvåfaktor som krav för delade containrar]] |
| [[M75 Lämna en container]] | **452** | en mottagare lämnar en container själv och ägaren får en notis i klockan — bygger tillägget 2026-11-22 i [[ADR-0003 Åtkomstmodell]] |
| [[M76 Ägarbytets bonus till nya kunder]] | **453–456** | källan till kontots plan, bonusen bara till nya kunder från betald Pro med två per år och kvoten mot Pro, texterna som lovar bonusen, och talet i missbruksrapporten — bygger [[ADR-0093 Ägarbytets bonus till nya kunder]] |
| [[M77 Agentens arbete är aktivitet]] | **457** | en agents verktygsanrop via MCP skriver last_active_at, så att den som arbetar genom sin agent inte räknas som inaktiv — bygger uppföljningen 2026-11-22 i [[ADR-0009 Kvoter och livscykel]] |
| [[M78 Hjälpsidan]] | **458** | vanliga frågor på /help i nio kategorier ur markdownfiler under lang/en/help/, med ett ankare per fråga, och raden Help ovanför Trash i menyn |
| [[M80 Kunskapsbasen]] | **460–465** | hjälpbiblioteket med innehållsmodellen och valideringen, texterna som en fil per fråga, kategorisidorna med de gamla ankarna, renderingen av faktarutor, bilder och video, artikelsidan och startsidan med kategorikort — bygger [[ADR-0094 Hjälpcentret]] |
| [[M81 Sök och hjälp i sammanhanget]] | **466–469** | sökningen på servern, sökrutan med träfflistan, `/help/context` med `HelpLink` och hjälplänkarna i appens vyer — bygger [[ADR-0094 Hjälpcentret]] |
| [[M82 Återkoppling och mätning]] | **470–472** | hjälpens mätning och sökfrågor utan träff, *Was this helpful?* på artiklarna och den nattliga hjälprapporten med `help:feedback` — bygger [[ADR-0094 Hjälpcentret]] |
| [[M83 Gallringen]] | **473** | nattlig gallring av `email_change`, `password_change` och utgångna sessioner, så att [[Registerförteckning]] kan ange en fast frist för dem |
| [[M84 Hjältens kostnader och lagring]] | **474–477** | kostnadssumman i dashboardens och containerns hjältar i stället för brickorna och donuten, lagringskortet i hjälten och kvotmodulen ur toppraden — bygger tillägget 2026-11-24 i [[ADR-0090 Dashboardens hjälte]] |
| [[M85 Om Mimers]] | **478** | berättelsen på `/about` som markdown i `lang/en/pages/about.md`, ritad på servern med `HelpMarkdown`, med kontaktlänken till den ännu inte byggda `/contact` — bygger principen i [[ADR-0094 Hjälpcentret]] § 4 |
| [[M86 Integritetspolicyn och underbiträdena]] | **479–480, 489, 494** | den godkända integritetspolicyn på `/privacy`, användarvillkoren på `/terms` (489, tillagd efter M87), återbetalningspolicyn på `/refund-policy` (494, tillagd efter M89) och registret över underbiträden på `/subprocessors`, med de juridiska länkarna i sidfoten och inloggningssidan och en `description` på varje informationssida — bygger #1600 ovanpå [[M85 Om Mimers]] |
| [[M87 Integritetsbegäranden]] | **481–488** | ärenden för begäranden enligt art. 15–21 som personalen sköter med `privacy:*`-kommandon: intaget, statusarna och verifieringen, fristen, bedömningen, begränsningen, bilagorna och gallringen, med en insamling för art. 15 som förslag — bygger [[ADR-0095 Integritetsbegäranden]] ur #1595 |
| [[M88 Inloggningssidans bilder]] | **490** | ett foto bakom vänsterkolumnen på `/login`, slumpat av tre vid varje sidladdning och inget bildspel; bilderna är beskurna och konverterade till WebP under `public/images/login/` |
| [[M89 Kontaktformuläret]] | **491–493** | `/contact` för gäst och inloggad med tio kategorier, meddelandet som mejl till `support@mimers.app` utan tabell och utan kvitto, honeypot, tidsfälla och begränsare, ett integritetsärende för Data & Privacy och en supportadress som aldrig spärras — bygger [[ADR-0096 Kontaktformuläret]] |
| [[M90 Agenten i inboxen]] | **495–498** | agenten slås på i användarens inbox på `/settings/agents` för uppgifter och bilagor, förvalt av; `inbox_get` ger inboxens item, inboxen syns inte i `containers_list`, agenten bearbetar inboxen till ett item med samma actions som appen, och inget item kan skapas i en inbox — bygger [[ADR-0087 MCP-server för agenter]] § Tillägg 2026-11-25: agenten i inboxen |
| [[Att sortera efter mockuparna]] | — | identifierat arbete som ännu inte fått en plats — ingen milstolpe |
| [[Efter MVP]] | efter lansering | idéer som inte är beslutade |

Totalt 449 issuerubriker under `docs/Backlog/`, numrerade 0–160, 168–182, 225–458 och 460–498. **161–167 finns bara i GitHub** — rättelser efter M22 och M23 som skrevs direkt där, samma familj som nummer 96 nedan. **183–190 likaså** — testarnas fynd 2026-10-02, GitHub #626–#631, #633 och #634; frågorna från samma genomgång står i [[M25 Itemets vy enligt mockupen]]. **140 står i M20 fast M21 är byggd**: den rättar issue 129 och hör hemma bredvid den. Issue 6 är delad i 6a, 6b och 6c. **Nummer 96 fanns som GitHub-issue innan det fanns i valvet** — det skrevs direkt i GitHub under M16 och skrevs in här först 2026-09-22, samma familj som posten om att backlogfilerna släpar efter GitHub i [[Lärdomar]]. **Nummer 38 bar två olika issues fram till 2026-09-07** — *Byt e-postleverantör till Mailgun* i M5 och *Utlåning* i M6. Mailgun var redan byggd som 38a/38b och behöll numret; Utlåning är sedan dess **76**, och `Beror på`-raden i M10 § 67 följde med. Se [[Tankar]] § Avgjort och flyttat. **M11 står sist i numret men grindar sex issues i M10** — 55, 57, 58, 59, 62 och 67 bygger på behörighetsmodellen den ändrar. Beroendena i issuerna är det som gäller, precis som M10:s egen ingress säger.
