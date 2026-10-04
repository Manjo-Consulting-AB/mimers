# M24 · Desktopdesignen

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

Tillagd 2026-09-30, efter M23. Gapanalysen gjordes ur koden och visade att desktop aldrig fick sitt skal. Designern lämnade samtidigt fyra nya bilder: `kostnader.png`, `dokument.png`, `uppgifter_1.png` och `Historik.png`. Genomgången gav [[ADR-0050 Desktopdesignen]] och [[ADR-0051 Senast öppnade filer]], och milstolpen bygger dem. **Anmälningsvägen enligt DSA** ligger på is tills testgruppen ska utvidgas, se [[Att sortera efter mockuparna]] § Ännu inte issues.

**Bilden är förlagan, besluten är regeln.** Där bilden och en ADR säger olika gäller ADR:en. Bilderna ritar en flik *Underhåll*, GTD-listor, dokumenttyper, betalningsmetod, en lagringsstapel för kontot och globala rader för Kalender, Kostnader och Dokument. Inget av det tas in som det är ritat, se [[ADR-0050 Desktopdesignen]]. Mät inte ut värden ur bilden. Tokens och komponenter kommer från [[M17 Designsystemet]], och en komponent som saknas byggs en gång, i den issue som först behöver den.

**Luckan först (168).** Kostnadsraderna får ett webbgränssnitt. Det är ingen designfråga, men dashboarden ritar kostnader som ingen webbanvändare kan registrera.

**Skalet (169–170).** Sidopanelen och containerns hjälte. Allt annat i milstolpen ritas inuti dem, och bredden styr varje panel.

**Översikterna (171–173).** Dashboardens rutnät, containerns paneler och *Senaste bilder*.

**Flikarna (174–180).** Uppgifter, kostnader, dokument och historik, i den ordningen. **177 är `risk_class: elevated`**, eftersom skrivningen sitter i filleveransen.

**Itemet (181–182).** Trepanelen i full bredd och sektionerna på tokens.

---

### 168. Kostnadsraderna i webben
Itemet får en flik *Kostnader* där raderna listas, läggs till, ändras och tas bort, enligt [[ADR-0050 Desktopdesignen]] § 8. Reglerna är desamma som i `CostEntryController`: valutan ärvs enligt [[ADR-0037 Valutans arv]], leverantören har autocomplete inom containern, beloppet är ett heltal i minsta valutaenhet och får vara negativt. Webben får egna rutter med Inertia. Logiken delas med API:et och skrivs inte en gång till.

*Kostnad* i plusknappens meny ([[ADR-0048 Mobilen och plusknappen]] § 2) leder hit. Erbjudandet att registrera en kostnad när en uppgift bockas av ([[ADR-0016 Kostnadsregistrering]]) förifyller datumet här.

**Läs:** [[ADR-0016 Kostnadsregistrering]], [[ADR-0037 Valutans arv]], [[Items och organisation]] § cost_entry, `app/Http/Controllers/Api/CostEntryController.php`, `resources/js/pages/Containers/Items/Show.vue` (flikarna)
**Klart när:** itemets flik *Kostnader* listar itemets rader, nyast först; en rad kan läggas till, ändras och tas bort från webben; valutan förväljs enligt arvet och går att välja om; ett negativt belopp sparas; leverantörsfältet föreslår leverantörer från samma container; en användare med läsåtkomst ser raderna men inga knappar, och rutterna nekar henne med 403; *Kostnad* i plusknappens meny leder till fliken; hela testsviten är grön.
**Beror på:** -

### 169. Desktopskalets sidopanel
Över `md:` får `AppLayout` en mörk sidopanel enligt [[ADR-0050 Desktopdesignen]] § 1. Överst ligger raderna ur `ShellSections`, sedan containerlistan grupperad per art enligt [[ADR-0036 Containerns art]], och sist *Nyligen besökta* och *Favoriter*. Toppraden bär plusknappen, sökfältet, klockan och avataren. Innehållsytan släpps från `max-w-3xl`.

**Sidopanelen och mobilens sidomeny visar samma sektioner ur samma data** ([[ADR-0048 Mobilen och plusknappen]] § 1). En sektion som finns i den ena och saknas i den andra är ett fel.

**Under `md:` ändras ingenting.** Mobilskalet från 151 står kvar som det är.

**Läs:** [[ADR-0050 Desktopdesignen]] § 1, [[ADR-0042 Designsystemet]] § Beslut, `docs/Design/main.jpeg`, `resources/js/layouts/AppLayout.vue`, `resources/js/components/ShellSections.vue`, `resources/js/components/MobileMenu.vue`
**Klart när:** över `md:` visas sidopanelen med raderna, containerlistan, *Nyligen besökta* och *Favoriter* i den ordningen; containrarna grupperas per art när en art har minst två, och resten ligger i högen; den aktiva raden är markerad; sidopanelen och sidomenyn visar samma sektioner i samma ordning; innehållsytan är bredare än `max-w-3xl`; varje rad är nåbar med tangentbord och har fokusring; under `md:` är skalet oförändrat; hela testsviten är grön.
**Beror på:** -

### 170. Containerns hjälte
Varje containersida får hjälten enligt [[ADR-0050 Desktopdesignen]] § 2 och 3: bilden, arten, namnet och hela `description` som undertitel. Översiktens hjälte är hög och bär talen. De andra flikarnas är lägre och bär inga tal. *Redigera container* leder till inställningssidan, och *Inställningar* lämnar flikraden.

Utan bild ritar hjälten den neutrala ytan ur [[ADR-0047 Containerns bild]]. Utan beskrivning utelämnas undertiteln. Pennan på bilden står kvar för den som får ändra containern.

**Läs:** [[ADR-0050 Desktopdesignen]] § 2–3, [[ADR-0047 Containerns bild]], `docs/Design/container.jpeg`, `docs/Design/kostnader.png` (den lägre hjälten), `resources/js/layouts/ContainerLayout.vue`, `resources/js/layouts/containerSections.js`
**Klart när:** varje containersida visar hjälten över `md:`; översiktens hjälte bär talen och de andras gör det inte; undertiteln är hela beskrivningen, och den saknas när beskrivningen är tom; *Redigera container* leder till inställningssidan och ritas bara för den som får ändra; *Inställningar* finns inte i flikraden, och alla sju sektioner går att nå från inställningssidan; under `md:` är toppraden från 151 oförändrad; hela testsviten är grön.
**Beror på:** 169

### 171. Dashboardens rutnät
Dashboarden får bildens rutnät enligt [[ADR-0050 Desktopdesignen]] § 6: brickor och kort i två kolumner, uppgifterna och händelserna i en högerspalt. Panelerna är M19:s och ändras inte. Under `md:` staplas de i dagens ordning.

**Läs:** [[ADR-0050 Desktopdesignen]] § 6, `docs/Design/main.jpeg`, `resources/js/pages/Dashboard.vue`
**Klart när:** över `lg:` står uppgifterna och händelserna i en högerspalt bredvid brickorna, korten och kostnaderna; under `md:` är ordningen oförändrad; ingen panel frågar något nytt av servern; hela testsviten är grön.
**Beror på:** 169

### 172. Containerns översikt får sina paneler
Översikten får panelerna i [[ADR-0050 Desktopdesignen]] § 7: *Kommande uppgifter* (containerns todo-urval, högst fem), kostnadernas fasta summering och donut per item, *Items* (högst sex, ur `ListItems`), *Senaste aktiviteter* (ur containerns historik, högst fem), *Containerdetaljer* och informationsytan. Varje tal räknar det användaren själv når ([[ADR-0039 Containerns översikt]]).

Donuten och summan är fria enligt [[ADR-0038 Gränsen för Pro i kostnaderna]]. Panelen ritas bara när containern har minst en kostnadsrad.

**Läs:** [[ADR-0050 Desktopdesignen]] § 7, [[ADR-0039 Containerns översikt]], [[ADR-0038 Gränsen för Pro i kostnaderna]], `docs/Design/container.jpeg`, `resources/js/pages/Containers/Overview.vue`, `app/Http/Controllers/ContainerController.php`
**Klart när:** översikten visar de sex panelerna; uppgiftspanelen visar samma rader som containerns todo-urval i samma ordning; itempanelen visar bara items användaren når; aktivitetspanelen följer händelseloggens läsregel; kostnadspanelen saknas när containern inte har några rader; en gäst med åtkomst till ett item ser bara det itemets tal; hela testsviten är grön.
**Beror på:** 170

### 173. Senaste bilder
Översikten får panelen *Senaste bilder*: de senaste bilagorna med `kind = image` i containern, genom `ResolveItemScope`, högst fem. Varje bild leder till sitt item. Containerns egen bild räknas inte.

**Läs:** [[ADR-0050 Desktopdesignen]] § 7, [[Filer och lagring]] § attachment, `docs/Design/container.jpeg`, `app/Actions/Access/ResolveItemScope.php`
**Klart när:** panelen visar de fem senaste bilderna användaren når, nyast först; en bild på ett item utanför omfånget visas inte; containerns egen bild visas inte; panelen saknas när det inte finns några bilder; bilderna levereras som miniatyrer genom den befintliga leveransvägen; hela testsviten är grön.
**Beror på:** 172

### 174. Containerns uppgiftsflik
Containern får fliken *Uppgifter* enligt [[ADR-0050 Desktopdesignen]] § 4 och 16: en tavla med kolumnerna *Försenade*, *Idag*, *Kommande* och *Klart*, med samma gruppering som `/tasks`, avgränsad till containern. Ett filter visar bara underhåll (`recurrence_type`). *Snabblänkarna* leder till kalenderflödet och exporten. Under `md:` blir tavlan en lista.

**Läs:** [[ADR-0050 Desktopdesignen]] § 4 och 16, [[Scheman och uppgifter]] § Todo-listan, [[ADR-0044 Användarens dag]], `docs/Design/uppgifter_1.png`, `resources/js/pages/Tasks/Index.vue`
**Klart när:** fliken finns i containerns flikrad; en förekomst hamnar i samma grupp som på `/tasks`; *Klart* visar avbockade förekomster i containern, nyast först; underhållsfiltret visar bara återkommande scheman; gruppen *Idag* följer användarens dag; en uppgift på ett item utanför omfånget visas inte; under `md:` är fliken en lista; hela testsviten är grön.
**Beror på:** 170, 172 (`ListTodo::forContainer`)

### 175. Containerns kostnadsflik
Containern får fliken *Kostnader* enligt [[ADR-0050 Desktopdesignen]] § 9. Den fria delen: raderna i en tabell med datum, beskrivning, item, leverantör och belopp, brickorna *Totalt* och *I år* och donuten per item. Tabellen pagineras, och bildens *Senaste kostnader* är dess första rader. *Lägg till kostnad* väljer itemet först och leder till itemets flik från 168.

En gratisanvändare ser en uppgraderingsyta där Pro-delen från 176 kommer att stå.

**Läs:** [[ADR-0050 Desktopdesignen]] § 9, [[ADR-0038 Gränsen för Pro i kostnaderna]], [[ADR-0040 Underträdets summor]], `docs/Design/kostnader.png`, `app/Support/Cost/CostReport.php`
**Klart när:** fliken finns i containerns flikrad; tabellen visar containerns rader användaren når, nyast först; brickorna visar summan per valuta och aldrig en summa över två valutor; donuten grupperar per item enligt ADR-0040; en rad på ett item utanför omfånget räknas inte och visas inte; en gratisanvändare ser uppgraderingsytan och ingen periodväljare; hela testsviten är grön.
**Beror på:** 168, 170

### 176. Kostnadsflikens Pro-del
Pro-delen enligt [[ADR-0050 Desktopdesignen]] § 9–11: periodväljaren, filtren på item, itemets kategori och leverantör, grafen över tid, nedbrytningen per itemets kategori och jämförelsen mot föregående period. Gränsen prövas på servern: en gratisanvändare som skickar en period i querysträngen får den fasta perioden.

Ingen kategorikolumn på `cost_entry` och ingen betalningsmetod.

**Läs:** [[ADR-0050 Desktopdesignen]] § 9–11, [[ADR-0038 Gränsen för Pro i kostnaderna]], [[Planer och kvoter]] (rättigheterna), `docs/Design/kostnader.png`, `app/Http/Controllers/Api/CostReportController.php`
**Klart när:** en Pro-användare kan välja period och filtrera på item, kategori och leverantör; grafen och kategorinedbrytningen följer filtret; jämförelsen visar ändringen mot en lika lång föregående period; en gratisanvändare får den fasta perioden även med en period i querysträngen; en rad utan kategori hamnar under *Övrigt*; hela testsviten är grön.
**Beror på:** 175

### 177. Senast öppnade filer
Tabellen `attachment_open` enligt [[ADR-0051 Senast öppnade filer]]. `files.download` skriver en rad efter behörighetsgrinden, för både nedladdning och förhandsvisning. Taket är 50 rader per person. Raderna raderas med personen och med bilagan, och Registerförteckningen får sin rad.

**Läs:** [[ADR-0051 Senast öppnade filer]], [[ADR-0049 Nyligen besökta]] (samma mönster), [[ADR-0019 Filleverans]], [[ADR-0045 Radering av konto och person]], `routes/web.php` (`files.download`)
**Klart när:** en nedladdning och en förhandsvisning skriver eller uppdaterar en rad; en nekad förfrågan skriver ingenting; `files.deliver` och miniatyrer skriver ingenting; den femtioförsta raden raderar den äldsta; raderna raderas när personen raderas och när bilagan rensas; exporten tar inte med raderna; frågeräkningsproven för leveransen är uppdaterade; hela testsviten är grön.
**Beror på:** -

### 178. Containerns dokumentflik
Containern får fliken *Dokument* enligt [[ADR-0050 Desktopdesignen]] § 12–15: alla bilagor i containern användaren når, med filter på typ (`kind`), item, uppladdare och datum, sortering och växling mellan lista och rutnät. Överst ligger *Senast öppnade* ur 177. Lagringsstapeln visar det konto en uppladdning i containern debiteras. *Lägg till dokument* väljer itemet först.

**Läs:** [[ADR-0050 Desktopdesignen]] § 12–15, [[ADR-0051 Senast öppnade filer]], [[Filer och lagring]], [[Planer och kvoter]] (förbrukningen), `docs/Design/dokument.png`, `resources/js/components/ItemAttachmentSection.vue`
**Klart när:** fliken finns i containerns flikrad; listan visar bara bilagor på items användaren når; filtren kan kombineras; *Senast öppnade* visar bara användarens egna öppningar; lagringsstapeln visar ägarkontot för dess medlem och gästens eget konto för en gäst; containerns egen bild visas inte i listan; *Lägg till dokument* laddar upp till det valda itemet; hela testsviten är grön.
**Beror på:** 170, 177

### 179. Historikfliken
Historikfliken får bildens form enligt [[ADR-0050 Desktopdesignen]] § 17: händelserna grupperade per dag i användarens tidszon, med filter på typ, användare, item och datum. Läsregeln är händelseloggens ([[ADR-0043 Tre loggar]]).

**Läs:** [[ADR-0050 Desktopdesignen]] § 17, [[ADR-0043 Tre loggar]], [[ADR-0044 Användarens dag]], `docs/Design/Historik.png`, `resources/js/pages/Containers/History.vue`
**Klart när:** händelserna grupperas per dag, och dagsgränsen följer användarens tidszon; filtren kan kombineras och står i querysträngen; en gäst ser bara de händelser läsregeln ger henne; ett filter på en användare avslöjar ingenting utöver läsregeln; hela testsviten är grön.
**Beror på:** 170, 172 (`ListAuditEvents::forContainer`)

### 180. Historikens diagram
Historikfliken får diagrammen *Aktiviteter över tid*, *Aktivitetstyper* och *Senaste aktiva items*, enligt [[ADR-0050 Desktopdesignen]] § 17. Talen räknas på servern under samma läsregel och samma filter som listan i 179.

**Läs:** [[ADR-0050 Desktopdesignen]] § 17, [[ADR-0043 Tre loggar]], `docs/Design/Historik.png`
**Klart när:** diagrammen räknar samma händelser som listan visar med samma filter; en gäst räknar bara de händelser hon får se; ett item utanför omfånget förekommer inte i *Senaste aktiva items*; hela testsviten är grön.
**Beror på:** 179

### 181. Itemvyns trepanel i full bredd
Trepanelen från 103 får skalets bredd: strukturen till vänster, itemet i mitten och kartan till höger, som i `docs/Design/struktur - item.jpeg`. Under `lg:` faller kartan under itemet, och under `md:` gäller mobilen från 154.

**Läs:** [[ADR-0041 Itemets vy]], [[ADR-0042 Designsystemet]], `docs/Design/struktur - item.jpeg`, `resources/js/pages/Containers/Items/Show.vue`, `resources/js/components/ItemStructurePanel.vue`, `resources/js/components/ItemMapPanel.vue`
**Klart när:** över `lg:` står tre paneler bredvid varandra och ingen är smalare än strukturträdets längsta rad kräver; under `lg:` står kartan under itemet; under `md:` är vyn oförändrad; hela testsviten är grön.
**Beror på:** 169

### 182. Itemets sektioner på tokens
`ItemAttachmentSection`, `ItemLoanSection`, `ItemLinkSection` och itemsidan själv byter sina råa Tailwind-färger mot tokens och kärnkomponenter, enligt [[ADR-0042 Designsystemet]] § Konsekvenser. De har flest råa färger av alla filer i frontenden. Beteendet ändras inte.

**Läs:** [[ADR-0042 Designsystemet]] § Beslut och § Konsekvenser, `resources/css/app.css` (`@theme`), de fyra filerna
**Klart när:** de fyra filerna har inga råa färgklasser som `slate-`, `blue-` eller `red-` kvar; fokusringen finns kvar på varje interaktiv del; tangentbordsvägarna från 68a och 68b fungerar som förut; hela testsviten är grön.
**Beror på:** 181

### 225. Containerns Tasks-flik blir en lista med This week

GitHub #719. Containerns uppgiftsflik går från tavla till lista: *Overdue → Today → This week → Upcoming → Done*, och visar alltid allt oavsett `show_upcoming_tasks`. Gruppen *This week* (i morgon till söndag, ISO 8601) införs i `ListTodo::group()` och gäller på alla ytor. Raden leds av uppgiftens titel.

**Läs:** `docs/Design/tasks-container.png`, `app/Actions/Schedule/ListTodo.php`, `resources/js/pages/Containers/Tasks.vue`, `resources/js/components/TodoRow.vue`

**Klart när:** grupperna står i ordningen ovan och tomma grupper ritas inte; *This week* är tom på en söndag och följer inte locale; fliken visar kommande uppgifter fast växeln är av, medan `/tasks` fortfarande följer den; hela testsviten är grön.

**Beror på:** —

### 226. Pausa och radera ett schema från schemats egen sida

GitHub #720. Schemats sida får *Pause/Resume* och *Delete*, så att itemets flik kan sluta vara en regellista (227). En paus landar på schemats sida.

**Läs:** `resources/js/pages/Containers/Items/Schedules/Show.vue`, `app/Http/Controllers/ScheduleController.php` (`update()`, `destroy()`), `app/Http/Controllers/ScheduleOccurrenceController.php` (`show()`)

**Klart när:** knapparna ritas efter `can.update` och `can.delete`; en write-mottagare får 403 på raderingen; en paus redirectar till schemats sida och en formulärsparning till itemet; hela testsviten är grön.

**Beror på:** —

### 227. Itemets Tasks-flik blir en lista av förekomster med Include child items

GitHub #721. Itemets uppgiftsflik visar förekomster i samma grupper och med samma rad som containern, med *Done* sist. Reglaget *Include child items* (på som förval) styr om ättlingarna räknas med, också i *Done*. Pausade och blockerade uppgifter står kvar med märken, som i #696.

**Läs:** `docs/Design/tasks-item.png`, `app/Actions/Schedule/ListTodo.php`, `app/Http/Controllers/ItemController.php`, `resources/js/components/ScheduleListSection.vue`

**Klart när:** ett barn med två parents ger en rad; en förekomst på ett item mottagaren inte når syns varken öppen eller i *Done*; `children=0` begränsar både öppna och klara till itemet; fliken har inga knappar för att pausa, redigera eller radera; hela testsviten är grön.

**Beror på:** 225, 226

### 228. Itemets sida slutar skicka openOccurrences

GitHub #726. Propen `openOccurrences` läses inte längre sedan 227 och tas bort, med sin metod i `ItemController`. Proven som läste den läser `itemTasks` i stället.

**Läs:** `app/Http/Controllers/ItemController.php` (`show()`, `openOccurrences()`), `resources/js/pages/Containers/Items/Show.vue`

**Klart när:** varken kontrollern eller sidan nämner `openOccurrences`; proven i `ForekomstvyTest` och `SchemavyTest` är gröna mot `itemTasks`; hela testsviten är grön.

**Beror på:** 227

### 229. Uppgiftsraden bär itemets omslag

GitHub #727. Raderna i uppgiftslistorna får `cover` bredvid resursen, med samma regel som itemlistan (`ResolveItemCover`) och ett konstant antal frågor. `/api/todo` får inget nytt fält.

**Läs:** `app/Actions/Item/ResolveItemCover.php`, `app/Actions/Schedule/ListTodo.php`, `app/Actions/Schedule/ListItemTasks.php`

**Klart när:** raden bär bilagans ULID och `hasThumb`, eller null; samma `cover` på `/tasks`, containerns flik, itemets flik och i *Done*; frågeantalet är konstant; hela testsviten är grön.

**Beror på:** —

### 230. Uppgiftsraden enligt mockupen

GitHub #728. `TodoRow` får rund bock, datum med ikon och miniatyr till höger, och röd ton på försenade rader. *Done* ritas med samma komponent. Pricken och textknappen *Check off* försvinner.

**Läs:** `docs/Design/tasks-container.png`, `resources/js/components/TodoRow.vue`, [[ADR-0042 Designsystemet]]

**Klart när:** bockens kant följer serverns fält; försenat och kommande står i knappens `aria-label`; inga råa palettfärger i raden; *Done* ritas inte längre med `UiListRow`; hela testsviten är grön.

**Beror på:** 229

### 231. Grupprubrikerna med antal och ihopfällning

GitHub #729. Ny komponent `TaskGroup`: en rubrik med antal som fäller ihop gruppen. Används på containerns flik, itemets flik och `/tasks`. Ihopfällningen sparas inte.

**Läs:** `docs/Design/tasks-container.png`, `resources/js/pages/Containers/Tasks.vue`, `resources/js/components/ScheduleListSection.vue`, `resources/js/pages/Tasks/Index.vue`

**Klart när:** rubriken är en knapp med `aria-expanded`; antalet har nycklar för ental och flertal; de tre ytorna ritar `TaskGroup`; hela testsviten är grön.

**Beror på:** 230
