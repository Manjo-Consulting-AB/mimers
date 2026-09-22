# M16 · Itemets vy

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

Tillagd 2026-09-18, efter genomgången av itemmockuparna. Besluten står i [[ADR-0041 Itemets vy]]; den här milstolpen bygger det av dem som **inte väntar på designen** — samma linje som [[M15 Containerns översikt]] drog för containern.

Det är därför en liten milstolpe med en stor ADR bakom sig. Det mesta mockuparna visar finns redan: flera föräldrar är byggt, relationerna kommer ur `ListItemLinks`, och sex av mockupens sju flikar ligger redan som propar i `Containers/Items/Show`. Det som verkligen saknas är två upplösningar — strukturen nedåt och vägarna uppåt — och ett fält.

**Detta ingår inte, och det är med flit:** flikraden, trepanelslayouten, fokuskartan, containerns hela karta, historikfliken och favoriterna. Flikraden, trepanelslayouten och favoriterna väntade på designsystemet och har sedan 2026-09-22 issues i [[M17 Designsystemet]]; fokuskartan och containerkartan är eget layoutarbete; historiken väntar på att applikationen instrumenteras (se [[Att sortera efter mockuparna]] § Händelseinstrumenteringen). Itemets kostnadsflik kräver ingen ny ändpunkt — issue 91 bygger redan summeringen med itemet som startpunkt, och det som saknas är ytan.

**Issue 91 i [[M15 Containerns översikt]] ändrades samma dag den här milstolpen skrevs.** [[ADR-0041 Itemets vy]] § Rättelsen av ADR-0040 visade att tårtbitarna inte kan vara underträdets toppnivåitems: ett item under två föräldrar hamnar då i två bitar, och bitarna summerar till mer än totalen bredvid. Tårtbitarna är de items som bär kostnadsraderna. Den som bygger 91 läser den ändrade formuleringen, inte den här rutan.

---

### 93. Itemet får en omslagsbild
Itemmockupen visar ett foto bredvid namnet. `attachment` vet redan om en bilaga är en bild — `kind` är `image`, `document` eller `other` — men ingenting säger vilken av dem som är **itemets** bild.

[[ADR-0041 Itemets vy]] svarar med en nullbar pekare på `item` och en upplösning som gör valet frivilligt. Ordningen är: den valda bilagan om den finns kvar, hör till itemet och är en bild; annars itemets **äldsta** bild; annars ingen bild. Regeln ger användaren det hon bad om — finns bara en bild används den — utan att kräva ett val av den som har två och inte bryr sig, och den låter inte itemets ansikte byta skepnad varje gång någon laddar upp ett foto.

**Upplösningen sker på servern, aldrig i vyn.** En vy som själv väljer bland bilagorna är en andra regel som glider ifrån den första, och den går inte att pröva.

**Pekaren nollställs när bilagan försvinner, den blockerar inte.** Det är en avvikelse från husets `onDelete('restrict')` och ska motiveras i migreringens kommentar: ett omslag är en preferens och inte data, och en preferens får aldrig hindra papperskorgens gallring ([[ADR-0008 Soft delete och papperskorg]]).

Den upplösta bilden ligger **bredvid** resursen och inte i den, samma linje som `variants` i issue 61b och kategorinamnet i issue 57a § Beslut 6: `ItemResource` är `/api`:s format och har inte bett om fältet.

**Läs:** [[ADR-0041 Itemets vy]] § Beslut, [[Filer och lagring]] § attachment, [[M2 Filer]] § 16, [[M10 Webbfrontend]] § 57a (Beslut 6) och § 61b (Beslut 1), [[ADR-0008 Soft delete och papperskorg]] § Beslut
**Klart när:** `item` har en nullbar pekare till en bilaga; en bilaga som raderas hårt nollställer pekaren i stället för att blockera raderingen; upplösningen väljer den valda bilagan, annars den äldsta bilden, annars ingen; en vald bilaga som mjukraderats faller tillbaka på regeln i stället för att ge en trasig bild; en bilaga som inte är en bild eller inte hör till itemet kan inte väljas; valet går att sätta och ändra i itemets redigeravy; `ItemResource` har inget nytt fält; hela testsviten är grön.
**Beror på:** -

### 94. Strukturen får en upplösning
Itemmockupens vänsterpanel visar containerns items som ett träd. Kanterna finns — `item_link` med `relation` = `parent` — och **flera föräldrar är redan tillåtet**: `LinkItems` skriver ut det i sin egen docblock, *"grafen är en DAG, inte ett träd"*, och cykelkontrollen följer alla föräldrakanter just därför. Ett item kan alltså förekomma på flera ställen i panelen, och det är avsiktligt.

Issuen lägger till upplösningen, inte panelen: containerns items användaren når, deras föräldrakanter, och ordningen. En fråga för itemen, en för kanterna, slutningen i PHP — `WITH RECURSIVE` finns inte i sqlite på det sätt testsviten skulle behöva, samma skäl som i `ResolveCategoryDescendants` och `ResolveItemDescendants`.

**Rotregeln är issuens enda svåra mening, och den är en åtkomstregel:** *ett item vars samtliga föräldrar ligger utanför omfånget är självt en rot.* Trädet visar därmed aldrig en förfader mottagaren inte når, och gömmer aldrig hennes eget item därför att dess förälder är dold. Den som fått impellern ser impellern som sin rot — inte motorn, och inte ett tomrum där motorn skulle stått.

**Ingen räknare över det som fallit bort.** Trädet får inte berätta att något dolts, och en omfångsbegränsad mottagares träd ska vara ordagrant det hon hade sett om resten inte fanns (issue 73 § Beslut 6).

Ordningen är namnet, stigande, på varje nivå — servern sorterar och vyn sorterar aldrig om (issue 57a § Beslut 8). Ett mjukraderat item är borta och bryter kedjan. En cykel som skrivits förbi `LinkItems` avslutar vandringen i stället för att hänga den. `ResolveItemScope` och `ResolveItemDescendants` rörs inte.

**Läs:** [[ADR-0041 Itemets vy]] § Beslut och § Konsekvenser, [[ADR-0028 Åtkomst på itemnivå]] § Beslut (reglerna 1–3), [[M11 Åtkomst på itemnivå]] § 73 (Beslut 6 och 7), [[M10 Webbfrontend]] § 57a (Beslut 8), `app/Actions/Item/LinkItems.php` (klassens docblock), `app/Actions/Category/ResolveCategoryDescendants.php`
**Klart när:** upplösningen ger containerns items i omfånget med sina föräldrakanter; rötterna är de items vars samtliga föräldrar ligger utanför omfånget eller saknas; ett item med två föräldrar förekommer på båda ställena; en `related`-kant bygger ingen gren; ett mjukraderat item är borta och dess barn blir rötter; en cykel avslutar vandringen i stället för att hänga den, bevisat av ett test; ordningen är namnet stigande på varje nivå; antalet frågor är konstant oavsett trädets djup och bredd; ingenting i svaret avslöjar hur många items som filtrerats bort; `ResolveItemScope` och `ResolveItemDescendants` är oförändrade; hela testsviten är grön.
**Beror på:** -

### 95. Förekomsterna och den aktuella platsen
Ett item som hänger under två föräldrar har två vägar upp, och mockupen visar båda: en brödsmula överst och en lista *Förekomster i struktur* med den aktuella platsen utmärkt.

**Ingen kolumn pekar ut en huvudplats.** [[ADR-0041 Itemets vy]] avvisar den: den måste väljas vid varje ny länk, den blir fel så snart trädet byggs om, och den påstår att en av två lika giltiga placeringar är den riktiga. Vägen står i stället i **querysträngen**, som itemlistans filter i issue 59a § Beslut 1 och av samma skäl — det gör vyn till en delbar länk.

**En väg som inte längre finns ignoreras och ersätts av den första i ordningen.** Aldrig 404. En delad länk som slutar fungera för att någon flyttat ett item är en fälla, inte ett fel, och det är hela skälet till att regeln står skriven.

Upplösningen uppåt är vandringen i issue 94 vänd: alla vägar från en rot till itemet, längs `parent`-kanter, med **samma rotregel** — en väg börjar vid det första ledet mottagaren når, och en väg som skulle passera ett item utanför omfånget finns inte för henne. De två issuerna delar den meningen ordagrant, och en av dem får aldrig råka bli generösare än den andra.

Ordningen är namnen längs vägen, så förekomstlistan och brödsmulan alltid står i samma ordning för samma användare. Vägarna ligger **bredvid** resursen, inte i den.

**Läs:** [[ADR-0041 Itemets vy]] § Beslut, [[M16 Itemets vy]] § 94 (rotregeln), [[M10 Webbfrontend]] § 59a (Beslut 1), [[M11 Åtkomst på itemnivå]] § 73 (Beslut 6), [[ADR-0028 Åtkomst på itemnivå]] § Beslut (regel 3)
**Klart när:** itemets vy bär alla vägar från en rot till itemet; ett item med två föräldrar får två förekomster; en väg som passerar ett item utanför omfånget finns inte i svaret; rotregeln är ordagrant issue 94:s; querysträngen väljer vilken förekomst som är den aktuella; en väg som inte längre finns ignoreras och den första i ordningen används i stället, aldrig ett fel; utan querysträng används den första i ordningen; ordningen är stabil för samma användare; en cykel avslutar vandringen; antalet frågor är konstant oavsett antalet vägar; `ItemResource` har inget nytt fält; hela testsviten är grön.
**Beror på:** 94

### 96. Itemet får ett anteckningsfält
Itemmockupen har en knapp *Ny anteckning* i snabbåtkomsten och en rad *Anteckningar* i navigeringen. Det finns varken entitet eller fält bakom dem.

[[ADR-0041 Itemets vy]] svarade att `item.description` *är* anteckningen. Issuen skiljer dem åt igen, och skillnaden är hela issuen: **`description` säger vad itemet är** — meningen en annan människa behöver för att veta vad hon tittar på — medan **anteckningen säger vad användaren vet om det**, ett fritt fält som växer med tiden. Ett fält som bär två syften får förr eller senare två format. Kolumnen är nullbar text vid sidan av `description`.

**Skriven direkt i GitHub som #406 och infogad här i efterhand, 2026-09-22.** Den ändrar ADR-0041 § Beslut på en punkt; ADR:en står oförändrad som historik enligt regeln i [[ADR-0032 Produktens ord]].

**Läs:** [[ADR-0041 Itemets vy]] § Beslut och § Konsekvenser, [[Items och organisation]] § item, [[ADR-0012 Sök]]
**Klart när:** `item` har en nullbar anteckningskolumn vid sidan av `description`; fältet går att sätta och ändra i itemets redigeravy och visas i itemets vy; de två fälten är åtskilda i formuläret; hela testsviten är grön.
**Beror på:** -
