# ADR-index

Fyrtionio beslut, de femton första fattade i planeringsfasen, augusti 2026. Slå upp när du undrar **varför** något ser ut som det gör — datamodellen beskriver *vad*.

Tillbaka till [[00 Index]].

| # | Beslut | Rör |
|---|---|---|
| [[ADR-0001 Stack]] | PHP 8.5 + Laravel på inleed | Allt |
| [[ADR-0002 Konto äger container]] | Konto som ägarenhet, container som ägd enhet | [[Konton och åtkomst]] |
| [[ADR-0003 Åtkomstmodell]] | Fyra åtkomstformer, varvet äger inte kundens pärm | [[Konton och åtkomst]] |
| [[ADR-0004 Fria taggar och kategorier]] | Blankt papper, systemet vet inget om båtar | [[Items och organisation]] |
| [[ADR-0005 Schema och förekomst]] | Regeln och den enskilda gången är olika saker | [[Scheman och uppgifter]] |
| [[ADR-0006 Innehållsadresserad lagring]] | Dedup via SHA-256, osynlig för användaren | [[Filer och lagring]] |
| [[ADR-0007 Fillagring hos inleed]] | Filer i Sverige och Frankrike, bakom Storage-abstraktion | [[Filer och lagring]] |
| [[ADR-0008 Soft delete och papperskorg]] | Skyddar mot buggen, inte mot diskhaveriet; papperskorgen tar emot saker och inställningar, inte egenskaper som scheman, lån och kostnadsrader; ett ägarbyte tömmer containerns papperskorg | Allt |
| [[ADR-0009 Kvoter och livscykel]] | Bilagor raderas först, items sist och bara i egna containrar | [[Planer och kvoter]] |
| [[ADR-0010 Notisarkitektur]] | Outbox med utbytbara kanaler | [[Notiser]] |
| [[ADR-0011 Autentisering]] | Sanctum, lösenord plus magic link; ett lösenordsbyte bekräftas via mejl, inte med det gamla lösenordet | [[Konton och åtkomst]] |
| [[ADR-0012 Sök]] | Scout med databasdrivern tills vidare | [[Items och organisation]] |
| [[ADR-0013 Språk och i18n]] | *Språkuppsättningen ersatt av ADR-0034.* Svenska och engelska, felkoder aldrig meningar | Allt |
| [[ADR-0014 Prismodell]] | Gratis, Pro 39–49 €/år, tre B2B-spår — konsumentdelen ersatt av ADR-0086 | [[Planer och kvoter]] |
| [[ADR-0015 Backup]] | Tre nivåer, off-site från inleed | Drift |
| [[ADR-0016 Kostnadsregistrering]] | *Pro-gränsen ersatt av ADR-0038.* Kostnadsrader per item, rapport bakom Pro | [[Items och organisation]] |
| [[ADR-0017 Missbruksvektorer]] | Detektera och prissätt, spärra inte i förväg | [[Planer och kvoter]], Drift |
| [[ADR-0018 Utvecklingsprocess och deploy]] | Gren per issue, tagg till produktion, bygg en gång | [[Backlog]], Drift |
| [[ADR-0019 Filleverans]] | Intern omdirigering under webbroten, engångslänk som fallback | [[Filer och lagring]] |
| [[ADR-0020 Plattformsidentitet och frontendgräns]] | Mimers på `mimers.app`, frontend och API på samma origin, API för mobilappar | [[Konton och åtkomst]], Drift |
| [[ADR-0021 Frontendteknik]] | Inertia med Vue i Laravel-appen, sessionsguard för webben | [[Backlog]], [[Pipeline]] |
| [[ADR-0022 Testramverk och statisk analys]] | Pest 5 och Larastan nivå 5, globala hjälpfunktioner i test | [[Backlog]], [[Pipeline]] |
| [[ADR-0023 TOTP-bibliotek]] | `pragmarx/google2fa`, inte Fortify, ingen QR på servern | [[Konton och åtkomst]] |
| [[ADR-0024 Tunna controllers och actions]] | Tunn controller, `Gate::authorize()`, Action när regeln är värd ett test | [[Backlog]] |
| [[ADR-0025 Modellval efter riskaxlar]] | *Ersatt av ADR-0026.* Deepseek V4-Flash för låga axlar, Claude Sonnet för förhöjda | [[Backlog]] |
| [[ADR-0026 Implementering och granskning efter riskaxlar]] | Deepseek implementerar allt, axlarna routar granskningen | [[Backlog]] |
| [[ADR-0027 Agentisolering under utveckling]] | Ingen Docker per agent nu — worktrees + permissions-denylist räcker | Drift |
| [[ADR-0028 Åtkomst på itemnivå]] | Åtkomst per item, nivåerna en ladder read < create < write < delete | [[Konton och åtkomst]], [[Items och organisation]] |
| [[ADR-0029 Agentens läsåtkomst till servern]] | Nyckel låst till ett läsande skript med `command=`, aldrig ett skal | Drift, [[Pipeline]] |
| [[ADR-0030 Miljövariabler ur GitHubs secrets]] | Utrullningen upsertar miljöns secrets i `shared/.env` före `config:cache` | Drift, [[Pipeline]] |
| [[ADR-0031 Köarbetaren körs av schemaläggaren]] | Schemalagd `queue:work --stop-when-empty` sist i `routes/console.php`, ingen daemon | Drift, [[Pipeline]] |
| [[ADR-0032 Produktens ord]] | Container och objekt i gränssnittet, pärmen utgår | Allt |
| [[ADR-0033 Produktens omfång]] | Generell plats för det du äger, använder eller arbetar med — inte ett båtverktyg | Allt |
| [[ADR-0034 Engelska vid lansering]] | Engelska enda levererade språk, maskineriet för fler kvar och testat | Allt |
| [[ADR-0035 Relationen mellan objekt]] | `sibling` heter `related`, tre relationer och inte fyra | [[Items och organisation]] |
| [[ADR-0036 Containerns art]] | `kind` är fritt med autocomplete, CHECK-villkoret utgår | [[Konton och åtkomst]], [[Items och organisation]] |
| [[ADR-0037 Valutans arv]] | Konto → container → rad, ändrat förval rör aldrig gamla poster | [[Items och organisation]] |
| [[ADR-0038 Gränsen för Pro i kostnaderna]] | Fast summering fri, allt frågbart är Pro | [[Planer och kvoter]], [[Items och organisation]] |
| [[ADR-0039 Containerns översikt]] | Containerns förstasida är en översikt, itemlistan en flik; talen räknar det du når | [[Items och organisation]], [[Konton och åtkomst]] |
| [[ADR-0040 Underträdets summor]] | Status och kostnad räknas över itemet och dess ättlingar, donuten grupperar per item | [[Items och organisation]], [[Planer och kvoter]] |
| [[ADR-0041 Itemets vy]] | Itemet bor i containern, strukturen har flera vägar till samma item, anteckningen är itemets egen text | [[Items och organisation]], [[Filer och lagring]] |
| [[ADR-0042 Designsystemet]] | Tokens med roller före komponenter före sidor, designbilderna i `docs/Design/` | Allt |
| [[ADR-0043 Tre loggar]] | Händelselogg, säkerhetslogg och anonym mätning med var sin livslängd, plus en rättslig spärr enligt DSA | [[Konton och åtkomst]], [[Registerförteckning]] |
| [[ADR-0044 Användarens dag]] | En dag som användaren ser eller som sparas åt henne räknas i hennes tidszon; nästa förfall i den avbockandes; frontenden får dagen från servern | [[Scheman och uppgifter]], [[Konton och åtkomst]] |
| [[ADR-0045 Radering av konto och person]] | Främmande bilagor följer containern, författarkolumnerna nollställs, en person raderar sig själv via mejlbekräftelse, delade containrar spärrar inte (2026-11-14) | [[Konton och åtkomst]], [[Planer och kvoter]], [[Registerförteckning]] |
| [[ADR-0046 Containerns karta]] | Kartan är strukturträdet ritat som noder, en öppen gren per nivå, med underträdets status på varje nod | [[Items och organisation]] |
| [[ADR-0047 Containerns bild]] | En bilaga kan tillhöra en container; containerns bild är en pekare som itemets | [[Filer och lagring]], [[Konton och åtkomst]] |
| [[ADR-0048 Mobilen och plusknappen]] | Eget skal under `md:`, plusknappen skapar det vyn visar, sibling förblir struket | Frontend |
| [[ADR-0049 Nyligen besökta]] | De 20 senast öppnade itemen per person, på servern, filtrerade vid läsning | [[Konton och åtkomst]], [[Registerförteckning]] |
| [[ADR-0050 Desktopdesignen]] | Mörk sidopanel, containerns hjälte och sex flikar; kostnadsraderna får ett webbgränssnitt; underhåll, GTD (ersatt av ADR-0052), dokumenttyper och betalningsmetod tas inte in | Frontend, [[Items och organisation]] |
| [[ADR-0051 Senast öppnade filer]] | De 50 senast öppnade filerna per person, skrivna i leveransrutten, filtrerade vid läsning | [[Filer och lagring]], [[Registerförteckning]] |
| [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] | GTD-listan på förekomsten och skild från statusen; Calendar, Done och datumgrupperna härleds; en uppgift får sakna datum, ett återkommande schema får det inte | [[Scheman och uppgifter]] |
| [[ADR-0053 Flytt och kopiering]] | Bilagor flyttas och kopieras, uppgifter flyttas; ägaren står för lagringen och blir målcontainerns ägarkonto om användaren är medlem där, annars användarens eget | [[Filer och lagring]], [[Planer och kvoter]] |
| [[ADR-0054 Inboxen]] | En dold personlig container med ett item tar emot det som saknar hemvist; Inbox är en plats och inte en GTD-lista | [[Konton och åtkomst]], [[Scheman och uppgifter]] |
| [[ADR-0055 Inbjudningskoder och stängd registrering]] | Registreringen är `open` eller `invite_only`; en hashad voucher per testare släpper in och ger Pro i 365 dagar, och en containerinbjudan är en egen väg in till Free | [[Planer och kvoter]], [[Konton och åtkomst]] |
| [[ADR-0056 Flytande navigering]] | Layouten deklareras av sidan och står kvar; Inertias förhämtning med ett cache i minnet som töms vid varje ändring; itemsidan förhämtas aldrig från en annan sida; ingen beständig klientcache | Frontend |
| [[ADR-0057 Ikoner och typsnitt]] | Ikonerna är Lucides, kopierade in under rollnamn och ritade av `UiIcon`; ikonrutans ton följer datumregeln eller domänen; Inter från egen server; `title` medelfet; skuggan och glaset som tokens | Frontend |
| [[ADR-0058 Trädet som tabell]] | Itemflikens träd är en tabell med egna siffror per item och containern som rotrad; filtret gäller trädet och visar träffarna med vägen dit; *Expand all*; ett huvud för de tre lägena | Frontend |
| [[ADR-0059 Kartan med en mitt]] | Kartläget är en fokuskarta med containern eller ett item i mitten och `focus` i adressen; klick centrerar, ikonen öppnar, plusmenyn bara på mitten; vägen till mitten ovanför; filtret väljer mitten och gömmer inga grannar | Frontend, [[Items och organisation]] |
| [[ADR-0060 Itemets ikon]] | Itemets ikon väljs ur hela Lucide och laddas en i taget; ett okänt namn ritar den neutrala; åtta färger i en fast palett utan rött; trädets rotrad fälls inte | Frontend, [[Items och organisation]] |
| [[ADR-0061 Kartans kort och kanter]] | Kartans kort har ikonen till vänster och öppna-ikonen i hörnet; ingen räknare för barn, statusen bara som försenad; böjda linjer med pilspetsar i ett SVG-lager som mäter korten | Frontend |
| [[ADR-0062 Kartans luft, listans ruta och den löpande sökningen]] | Kartan utan pilspetsar och med mer luft, de relaterade på varannan sida, ögat öppnar och plusmenyn i mittkortet; listans ruta visar vald bild, ikon, automatisk bild eller den grå i den ordningen; filterraden söker medan man skriver | Frontend, [[Items och organisation]] |
| [[ADR-0063 Itemets karta i containerkartans form]] | Kartan på itemets sida är `ItemMap` i sin smala form med containerkartans data, plusmenyn bara i mitten, egna tak och en länk till hela kartan; `FocusMap` tas bort | Frontend, [[Items och organisation]] |
| [[ADR-0064 Containerns ikon]] | Containern får itemets ikon och palett med samma väljare; ikonen syns i huvudmenyn, trädets rotrad, kartans mitt och i rutan när bilden saknas | Frontend, [[Konton och åtkomst]] |
| [[ADR-0065 Den samlade papperskorgen]] | `/trash` listar raderade items, bilagor och containrar över allt användaren når, med sökfält, växel med fyra lägen, vem som raderade ur loggen och *Restore*; skalet får posten *Trash* | Frontend, [[Filer och lagring]], [[Konton och åtkomst]] |
| [[ADR-0066 Itemets anteckning]] | Itemets anteckning är Markdown som servern ritar, redigeras direkt på översikten ovanför *Recent activity*, sparas av sig själv, den som sparar sist vinner och loggen får en rad per arbetspass | Frontend, [[Items och organisation]] |
| [[ADR-0067 Itemfliken utan listläge]] | Itemflikens listläge tas bort; växeln är *Tree* och *Map* med trädet som förval, en gammal adress landar i trädet och trädets rad får listans ruta med bild eller ikon | Frontend, [[Items och organisation]] |
| [[ADR-0068 Itemets sida och skapandet]] | Itemets ord kompletterar ADR-0032; sju flikar ur förlagan utan Information och Tags; översikten är sammanfattningar med tak; *Edit* som primärknapp och radering i egen dialog; skapaformuläret i två nivåer med *Save and add another*; bild laddas upp efter itemet; taggar och kategorier skapas i väljaren under befintlig grind; sidopanelerna fälls ihop; ingen räknare på noll; uppföljning 2026-11-14: *Include child items* på *Documents*, *Costs* och *Loans*, en växel per flik | Frontend, [[Items och organisation]] |
| [[ADR-0069 Flytta ett item inom containern]] | Ett item flyttas med sitt underträd till toppnivån eller under ett annat item i samma container, en placering i taget och i en transaktion; reglerna prövas mot grafen efter flytten; `write` i varje ände; att lägga in mellan och att vända är två flyttar | Frontend, [[Items och organisation]], [[Konton och åtkomst]] |
| [[ADR-0070 Subprocesser hos inleed]] | `proc_open` får användas där en ADR beslutat det; köarbetaren får en egen process med ADR-0031:s väg som reserv, och `retro-fakta` visar `disable_functions` | Drift, [[Pipeline]] |
| [[ADR-0071 Miniatyrer med Imagick]] | HEIC, HEIF och TIFF får miniatyrer genom Imagick, skrivna som JPEG; inget PDF och ingen annan delegat | [[Filer och lagring]] |
| [[ADR-0072 Kartan fyller bredden]] | Kartans kort har tre storlekar efter antalet barn per rad; barnen står på högst två jämna rader med upp till sex per rad; uppställningen väljs ur ramens uppmätta bredd | Frontend |
| [[ADR-0073 Översiktens ordning]] | Personen väljer ordning och bredd på containeröversiktens paneler, för alla containrar; en tom panel står kvar; egen dragkod och inget paket | [[Konton och åtkomst]], Frontend |
| [[ADR-0074 Registrering i vyn]] | Ny information registreras i vyn, före listan, med en snabbrad eller ett infällbart formulär som står kvar efter sparning; inboxen är förvald på containerns *Tasks* | Frontend |
| [[ADR-0075 Tasks som listans namn]] | Listan heter Tasks i varje text användaren ser, aldrig To do; identifierarna står kvar | Frontend |
| [[ADR-0076 Profilbilden]] | Profilbilden är en bilaga som tillhör en person, med fokuspunkt och zoom som icke-destruktiv beskärning; den ses av den som delar ett konto eller en container med personen | [[Filer och lagring]], [[Konton och åtkomst]], Frontend |
| [[ADR-0077 Notislistan i appen]] | Notiserna får kanalen `in_app` med en klocka, en panel och en sida, läst-läge per rad, förval per typ och gallring efter 90 dagar; tysta timmar gäller inte kanalen | [[Notiser]], Frontend |
| [[ADR-0078 Valutor ur ISO 4217]] | Bara aktiva ISO 4217-koder på rad, konto och container; sökruta på kod och namn; befintliga ogiltiga koder rapporteras, inte rättas; ägaren kan begränsa containerns valutor (tomt urval = alla), servern svarar 422 `currency.not_allowed` | [[Items och organisation]], Frontend |
| [[ADR-0079 Övervakning]] | Sentry i EU-regionen för fel i PHP och webbläsaren utan personuppgifter (användaren en pseudonym), tillgänglighetskoll och hjärtslag från schemalagda jobb utifrån, prestanda i en egen logg på inleed med veckosammanställning, anonym mätning av sidor och funktioner i appen | Drift, [[Registerförteckning]] |
| [[ADR-0080 Ny förälder från kartan]] | Plusmenyn och kartans mittmeny får *Parent*: ett nytt item skapas med `child`, och kanten till barnet skapas i samma transaktion genom `LinkItems`; barnets placeringar ändras inte, grinden är skapandets plus `update` på barnet | [[Items och organisation]], kartan |
| [[ADR-0081 All lagrad data räknas mot kvoten]] | Kvoten räknar text (UTF-8-byte plus 256 byte per rad, i `metadata_bytes`) tillsammans med filer; den som skapar raden betalar, containerns ägare tar över när betalaren försvinner; nedgraderingen raderar bilagor först och items sist, bara i egna containrar; skrivbegränsning per användare | [[Planer och kvoter]], [[Items och organisation]], [[Scheman och uppgifter]] |
| [[ADR-0082 Kartan är relationernas yta]] | Kartan är relationernas enda yta: relationsfliken utgår, relationer läggs till och knyts upp i den fulla kartans nivå *Focus*, och kartan får zoomnivåerna *Neighbourhood* (två steg, `d3-force`) och *Whole container* (läsning, högst 500 noder) från `md:` i `depth`; ny fråga med ADR-0028:s omfång | Items, kartan |
| [[ADR-0083 Grannskapet är itemets släkt]] | *Neighbourhood* är itemets släkt: varje item som nås genom relationer i omfånget utan stegräkning, samma tak på 500 noder, ritat som ett radialt träd med synliga ringar efter kortaste avstånd, ikon och namn per nod, zoom och panorering; adressen blir `depth=linked` | Items, kartan |
| [[ADR-0084 Hela containern med itemets ikon]] | *Whole container* ritar varje nod som *Neighbourhood*: rund platta med itemets ikon i dess färg, statusen `overdue` som en prick, tydliga namn i fast skärmstorlek, alla namn direkt under 40 noder, raka linjer enligt ADR-0061 § 5, zoomens tak sänkt från 8 till fem knappsteg (`1.25 ** 5`) | Items, kartan |
| [[ADR-0085 Profilen bär personkontot]] | Kontosidan tas bort; profilen bär personkontots namn (eget fält, märkt som synligt för andra) och valuta; språk, tidszon och enhetssystem utan *Följ kontots …*, sparas bara till `user` med kontot som reservvärde; ingen omdirigering från `/settings/accounts`; policyn prövar kontoskrivningen; organisationskonton får egen flik när de finns | Konton, inställningar |
| [[ADR-0086 Pro, Pro+ och grundarpris]] | Gratis 1 GB, Pro 10 GB 39 €, Pro+ 50 GB 69 €; grundarpris 19/39 € första året och via inbjudningskod; prisbyte är en ny planrad; befintliga kunder får aldrig höjt pris; öppen sida om vart pengarna går; § 7 ersatt av ADR-0093 | [[Planer och kvoter]] |
| [[ADR-0087 MCP-server för agenter]] | Fjärrserver med OAuth på `mimers.app/mcp`; grupperade verktyg över hela API:et; MCP kan stängas per container; agenten loggas som agent; endast Pro och Pro+; agenten börjar utan åtkomst och slås på per container och per agent; papperskorgen kan aldrig tömmas av en agent | Konton och åtkomst, API |
| [[ADR-0088 Versionshistorik för items]] | Kontot sätter taket för antalet versioner (standard 3), containern kan sänka eller stänga av det; ägaren betalar; täta skrivningar blir en version; filer versioneras inte; ägarbyte rensar historiken | Items och organisation, Planer och kvoter |
| [[ADR-0089 Egna fält på items]] | Användaren definierar typade fält (text, tal, datum) på containern och på enskilda items; de sex fasta fälten blir vanliga fält; typen låst när fältet har värden; borttaget fält i papperskorgen; 25 per container och 10 per item i config; mallar på kontot bär kategorier och fält och kopieras in; inget arv; agenter använder men sparar inte mallar | Items och organisation, API |
| [[ADR-0090 Dashboardens hjälte]] | Dashboardens övre rad ritas i en hjälte med bild som containerns; personen har en egen hjältebild som följer profilbildens regler; utan egen bild en inbyggd standardbild; profilbildens redigerare med fokuspunkt och zoom delas av profilbilden, hjältebilden och containerns bild, och containerns bild får zoom; låg hjälte på mobilen | Konton och åtkomst, Filer och lagring |
| [[ADR-0091 PDF-pärmen]] | Pärmen är Pro (`pdf_binder` på ägarkontot) och en exportrad med `kind = binder`, utan kvot; försättsblad, förteckning, items i Parent/Child-ordning och sidnummer; användaren väljer sektioner (kostnader av som förval) och bilagor per item, sammanslagna eller som länkar (förval); dompdf och FPDI i ren PHP; pärm för ett item; notisen `binder.ready` i appen | Planer och kvoter, Filer och lagring |
| [[ADR-0092 Tvåfaktor som krav för delade containrar]] | Ägaren med tvåfaktor kräver det av dem hen delar med, för kontot (`account.require_two_factor`) eller en container; en `container_access` ger ingenting utan mottagarens bekräftade tvåfaktor, även för agenter och token; låsta containrar syns med märket; ägarbytet nekas; alla planer | [[Konton och åtkomst]] |
| [[ADR-0093 Ägarbytets bonus till nya kunder]] | Ägarbytets bonus bara till mottagare som saknade konto när ägarbytet skickades, bara från betald Pro (`subscription.source = 'paid'`), högst två per avsändare på tolv månader, beslutet sparas på raden, kvoten prövas mot Pro när bonusen ges, mätt i nattrapporten; ersätter ADR-0086 § 7 och ADR-0017 § 4 | [[Planer och kvoter]], [[Konton och åtkomst]] |
| [[ADR-0094 Hjälpcentret]] | Hjälpen är markdownfiler med front matter, en fil per artikel och fråga; språket är katalogen; adressen bär ett id som aldrig byts; servern ritar markdown med faktarutor, bilder ur repot och YouTube först vid klick; sökningen är i PHP på servern; vyerna länkar via `HelpLink` och `/help/context`; Ja/Nej sparas för alla och fritext bara för inloggade; anonym mätning per dag med sökfrågor bara när de gav noll träffar; ingen adminsida utan en nattlig rapport i loggen; ingen SSR | Frontend, [[Konton och åtkomst]], [[Registerförteckning]] |

## Om att ändra ett beslut

Radera aldrig en ADR. Sätt `Status: Ersatt av ADR-XXXX` och skriv en ny. Poängen med dokumenten är att man ett år senare ska kunna se vad som övervägdes och varför något valdes bort — inte bara vad som gäller nu.
