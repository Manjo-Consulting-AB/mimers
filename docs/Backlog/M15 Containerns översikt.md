# M15 · Containerns översikt

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

Tillagd 2026-09-18, efter genomgången av containermockupen. Besluten står i [[ADR-0039 Containerns översikt]] och [[ADR-0040 Underträdets summor]]; den här milstolpen bygger det av dem som **inte väntar på designen**.

Det gör den till en annan sorts milstolpe än [[M14 Besluten ur mockupgenomgången]], som bara rättade kod som sade emot ett skrivet beslut. Här byggs nya ytor och ny kod — men bara den del vars form är bestämd av datamodellen och inte av en teckning.

**Detta ingår inte, och det är med flit:** flikradens indelning och omfördelningen av sektionsmenyns nio rader, exportens flytt in under inställningarna, dokumentfliken, bildpanelen, containerns hjältebild och händelsepanelen. De fyra första väntar på designsystemet, hjältebilden på ett beslut om `attachment.item_id`, och händelsepanelen på att applikationen instrumenteras — se [[Att sortera efter mockuparna]] § Händelseinstrumenteringen.

Issue 91 rör samma ändpunkter som issue 86 i [[M14 Besluten ur mockupgenomgången]] och får inte köras förrän den är mergad.

---

### 88. Containern får en beskrivning
`container` bär `name` och `kind` och ingenting mer som användaren skrivit. Containermockupens huvud vill visa något utöver namnet, och [[ADR-0039 Containerns översikt]] svarar med ett enda fritextfält i stället för de strukturerade fält — modell, årtal, tillverkare — som mockupens underrubrik antyder. Strukturerade fält hade varit domänen inbyggd i containern, precis det [[ADR-0033 Produktens omfång]] förbjöd i artens värdelista.

Kolumnen är nullbar text. `ContainerResource` bär den, de två FormRequests validerar längd och ingenting annat, och skapa- och redigeravyn får ett fält. **Fältet är frivilligt** — att kräva en beskrivning vid skapandet är att ställa en fråga användaren ännu inte kan svara på, samma resonemang som gjorde `kind` frivillig i issue 84.

Ingen presentationslogik: fältet visas som det är skrivet, och systemet plockar aldrig isär det i delar.

**Läs:** [[ADR-0039 Containerns översikt]] § Beslut, [[ADR-0033 Produktens omfång]] § Beslut, issue 8 § Beslut 7 i [[M1 Kärnmodell]] (resursformatet)
**Klart när:** `container` har en nullbar `description`; `ContainerResource` bär den och alltid som `null` när den saknas, aldrig utelämnad; en container kan skapas och sparas utan beskrivning; en beskrivning kan sättas och ändras i redigeravyn; ingen kod läser ut delar ur fältet; hela testsviten är grön.
**Beror på:** -

### 89. Itemlistan flyttar och containern får en översikt
`GET /containers/{container}` är i dag itemlistan, beslutat i issue 57a § Beslut 1 med motiveringen *"itemen är containern"*. Det var rätt när containern bestod av items och ingenting annat. [[ADR-0039 Containerns översikt]] flyttar listan till `GET /containers/{container}/items` och sätter en översikt på containerns egen URL.

**Ruttnamnet `containers.show` följer med översikten.** Det är containerns sida, och det var det hela tiden. Itemlistan får ett eget namn.

Översikten bär i den här issuen containerns huvud — namn, art och beskrivning — och en rad räknande brickor: antalet items och antalet öppna uppgifter. **Varje tal räknar det användaren själv når.** Brickan för items är därför exakt lika lång som listan på itemsidan, och det är hela poängen: ett tal som säger *hur många som finns* avslöjar precis det [[ADR-0028 Åtkomst på itemnivå]] § Konsekvenser stänger. Ingen totalsumma, ingen *av N*, ingen upplysning om att något dolts (issue 73 § Beslut 6).

**Uppgifter och underhåll är en bricka.** `schedule` har inget fält som skiljer dem åt och ska inte få ett — skillnaden är domänen. Talet är `ScheduleOccurrence::scopeTodoFor()` avgränsat till containern.

Kostnadsbrickan ingår **inte**: den är issue 86:s ändpunkt, och den här issuen bygger ingen egen väg till samma tal.

Fyra länkar pekar i dag på `/containers/{ulid}` och menar listan — `TodoRow.vue`, `Search.vue`, `Containers/Index.vue` och raden `items` i `containerSections.js` — och `ItemController::destroy()` omdirigerar dit efter radering. Alla fem pekar om. Sektionsmenyn står kvar som den är; flikraden är designarbete och ingår inte här.

**Läs:** [[ADR-0039 Containerns översikt]], [[ADR-0028 Åtkomst på itemnivå]] § Konsekvenser, [[M10 Webbfrontend]] § 57a (Beslut 1 och 4), [[M11 Åtkomst på itemnivå]] § 73 (Beslut 6)
**Klart när:** `GET /containers/{container}` svarar med översikten och `GET /containers/{container}/items` med itemlistan; `containers.show` namnger översikten; itemlistans filter i querysträngen fungerar oförändrat på den nya URL:en; översikten visar containerns namn, art och beskrivning; itembrickan visar antalet items användaren når och aldrig ett tal därutöver; en omfångsbegränsad mottagare ser samma tal som antalet rader hon får i listan; uppgiftsbrickan är ett tal och inte två; ingen yta avslöjar hur många rader som filtrerats bort; radering av ett item landar på översikten; hela testsviten är grön.
**Beror på:** 88

### 90. Ättlingarna får en egen upplösning
[[ADR-0040 Underträdets summor]] slår fast att ett items status och kostnad räknas över itemet och dess ättlingar. Vandringen nedåt längs `item_link`-kanter där `relation` är `parent` finns i dag på ett enda ställe: inuti `ResolveItemScope`, byggd för åtkomsten.

**Den går inte att återanvända, och ska inte byggas om.** Den hämtar kanter bara i de containers som faktiskt har en itemgrant och hoppar över steget helt när ingen har det — riktigt för behörigheten, fel för en summering som behöver kanterna varje gång. Att vidga den vore att lägga ett presentationsbehov i behörighetskoden.

Issuen lägger till `App\Actions\Item\ResolveItemDescendants` efter `ResolveCategoryDescendants` förlaga: slutningen sker i PHP på **en** fråga, inte som en rekursiv CTE, eftersom sqlite i testsviten saknar `WITH RECURSIVE`. Frågekostnaden är konstant oavsett trädets djup och bredd.

**Tre saker vandringen måste klara.** En `related`-kant bär ingenting och får aldrig dras med. En cykel avslutar vandringen i stället för att hänga den — `LinkItems` förhindrar cykler vid skrivning med `item_link.cycle`, och `ResolveItemScope` försvarar sig ändå mot en som skrivits in av en migrering eller import; den nya gör detsamma av samma skäl. Ett mjukraderat item räknas inte och bryter kedjan: ett barnbarn under ett raderat barn faller bort med det.

`ResolveItemScope` ändras inte i den här issuen och ligger `Out of scope`.

**Läs:** [[ADR-0040 Underträdets summor]], `app/Actions/Category/ResolveCategoryDescendants.php`, `app/Actions/Access/ResolveItemScope.php` (klassens docblock), [[ADR-0035 Relationen mellan objekt]] § Beslut, [[M11 Åtkomst på itemnivå]] § 70 (Beslut 4)
**Klart när:** `ResolveItemDescendants` returnerar itemet och alla dess ättlingar längs `parent`-kanter, transitivt och utan djuptak; en `related`-kant drar aldrig med sig något, bevisat av ett test; en cykel som skrivits förbi `LinkItems` avslutar vandringen i stället för att hänga den, bevisat av ett test; ett mjukraderat item räknas inte och dess ättlingar faller bort med det; antalet frågor är konstant oavsett trädets djup; `ResolveItemScope` är oförändrad; hela testsviten är grön.
**Beror på:** -

### 91. Kostnaderna bryts ner per item
Issue 86 ger containern och kontot en fast totalsumma. [[ADR-0040 Underträdets summor]] ger den sin indelning: **summera kostnaderna i det aktuella underträdet, grupperat per item.**

`cost_entry` har ingen kategorikolumn, och den får inte heller en. Ordet hade krockat med itemens kategoriträd ([[ADR-0032 Produktens ord]]) och listan hade varit ännu ett påstående om vad världen består av ([[ADR-0033 Produktens omfång]]). Itemet är dessutom den axel användaren redan är tvungen att välja — `cost_entry.item_id` är `NOT NULL` — så indelningen kräver ingen ny disciplin av henne.

**En regel, två startpunkter.** På containern är underträdet hela containern och tårtbitarna dess toppnivåitems. På ett item är underträdet itemet plus dess ättlingar. Samma kod, samma svar, olika ingång.

**Blandade valutor grupperas, de summeras aldrig över.** En nedbrytning kan alltså behöva svara med en uppsättning per valuta, och det är rätt svar ([[ADR-0016 Kostnadsregistrering]], [[ADR-0037 Valutans arv]]).

Ändpunkterna är fortsatt **parameterlösa och fria**. Tar de emot en period är de inte längre fasta och grinden har flyttat sig utan att någon beslutat det ([[ADR-0038 Gränsen för Pro i kostnaderna]]). Jämförelsetal mot en annan period hör till rapportvyn och ingår inte. `CostReportController` rörs inte.

**Läs:** [[ADR-0040 Underträdets summor]], [[ADR-0038 Gränsen för Pro i kostnaderna]] § Beslut, [[ADR-0016 Kostnadsregistrering]] § Konsekvenser, [[M14 Besluten ur mockupgenomgången]] § 86, [[M8 Kostnadsregistrering]] § 46 (Beslut 2)
**Klart när:** containerns fasta summering bär en nedbrytning per toppnivåitem; itemets summering bär itemet plus dess ättlingar; ett barns kostnad räknas in i förälderns tal; en `related`-länk drar aldrig in en kostnad; en nedbrytning över blandade valutor grupperas per valuta och summeras aldrig över dem; ändpunkterna tar fortfarande inga parametrar och ger identiskt utfall med och utan okända sådana; ingen rad från ett item användaren inte når räknas in; `cost_entry` har ingen ny kolumn; `CostReportController` är oförändrad; hela testsviten är grön.
**Beror på:** 86, 90

### 92. Itemets status räknas ur underträdet
Containermockupen sätter ordet **OK** på varje item. `item` har ingen `status`-kolumn, och migreringens kommentar räknar upp den vid namn bland det som med flit saknas: *"ingen extra kolumn, ingen `status`, ingen `quantity`, ingen `location_id`"*. Den kommentaren står kvar.

[[ADR-0040 Underträdets summor]] härleder statusen i stället: **OK betyder noll kvarvarande uppgifter i underträdet.** Har något förfallit på itemet eller under det är itemet inte OK, och användaren behöver inte öppna sextio items för att hitta det som brinner.

En kolumn hade varit ett uppslag, men också en andra sanning som måste hållas synkroniserad med varje förändring i varje schema under itemet. Det som går att räkna fram lagras inte.

**Frågekostnaden är issuens svåraste del.** Statusen räknas för varje rad i itemlistan, och en vandring per rad är precis den N+1 hela åtkomstlösningen byggdes för att undvika. Kanterna och förekomsterna hämtas en gång per lista och slutningen sker i minnet, som i `ResolveItemDescendants` och `ResolveItemScope`.

Flaggan hör till listans svar, inte till `ItemResource`: resursen delas med `/api` och har inte bett om den, samma linje som kategorinamnet i issue 57a § Beslut 1 och 6.

**Läs:** [[ADR-0040 Underträdets summor]], [[M10 Webbfrontend]] § 57a (Beslut 1, 6 och 9), [[Scheman och uppgifter]] § Förekomster, `app/Models/ScheduleOccurrence.php`
**Klart när:** varje rad i itemlistan bär en härledd status; ett item utan förfallna förekomster på sig självt eller under sig är OK; en förfallen förekomst på ett barnbarn gör förälderns förälder icke-OK; en `related`-länk påverkar aldrig statusen; ett mjukraderat barn påverkar den inte heller; `item` har ingen ny kolumn; `ItemResource` har inget nytt fält; antalet frågor är konstant oavsett antalet rader; hela testsviten är grön.
**Beror på:** 90
