# ADR-0041 Itemets vy

**Status:** Antagen 2026-09-18 · Kompletterar [[ADR-0039 Containerns översikt]] · Rättar tårtbitarnas indelning i [[ADR-0040 Underträdets summor]] · [[ADR-index]]

Fattat vid genomgången av itemmockuparna, den tredje omgången och den innersta vyn. [[ADR-0039 Containerns översikt]] avgjorde containerns förstasida; den här avgör itemets.

## Kontext

Två mockuper visar samma sida på två sätt. Den ena ritar tre paneler samtidigt — strukturen till vänster, itemet i mitten, en karta till höger. Den andra delar upp samma innehåll i tre separata vyer: containerns struktur, itemets sammanhang och itemets fokusgraf.

Det mesta de visar finns redan. **Flera föräldrar är inte en önskan utan byggt kod**, och `App\Actions\Item\LinkItems` säger det i sin egen docblock: *"Flera föräldrar är tillåtet, så sökningen följer ALLA föräldrakanter, inte bara den första — grafen är en DAG, inte ett träd."* Cykelskyddet finns, unikhetsvillkoret hindrar dubbletter, och `App\Actions\Item\ListItemLinks` ger redan de tre grupperna mockupen ritar — föräldrar, barn och relaterade — med omfångsfiltret i samma fråga. Sex av mockupens sju flikar ligger dessutom redan som propar i `Containers/Items/Show`: fälten, relationerna, bilagorna, schemana och utlåningen renderas i dag på en enda lång sida.

Fyra saker i mockuparna har ingen data bakom sig. **Anteckningar** finns som en rad i navigeringen, en knapp i snabbåtkomsten och en sökväg i relationslistan, men det finns varken entitet eller fält — `item.description` är det närmaste, och den är något annat. **Favoriter** finns som en hel sektion och ingen tabell. **"Aktiv"** står som ett märke på itemet, och `item`-migreringens kommentar räknar upp `status` vid namn bland det som med flit saknas. **Historik** är en flik, och `audit_log` skriver två händelsetyper från två anropsställen och har inget index på `(subject_type, subject_id)` — fliken vore tom och en full scan på samma gång.

Två fält i detaljrutan finns inte heller: **leverantör**, som bor på kostnadsraden och inte på itemet, och **artikelnummer**, som inte finns alls.

Och en sak till, som inte syntes förrän itemet fick sin vy: **[[ADR-0040 Underträdets summor]] beskriver donutens tårtbitar på ett sätt som inte överlever en DAG.** Se § Rättelsen nedan.

## Beslut

**Itemet bor i containern.** Ramen runt panelerna är containerns — namnet, arten och flikraden ur [[ADR-0039 Containerns översikt]] — inte en global navigering med egna rader för Struktur, Karta, Uppgifter, Dokument och Kostnader. Den globala navigeringen i mockupen tar bort containern som arbetsrum två dagar efter att ADR-0039 gjorde den till ett. Panelerna inuti ramen är däremot den treställda mockupens: strukturen, itemet och kartan samtidigt slår tre vyer som visar samma sak var för sig.

**Strukturens rötter är de items användaren når som inte har någon nåbar förälder.** Ett projekt är ett item; en referenssamling är ett item; en container är inte ett item utan rummet de ligger i. Det följer av att en container är en gruppering av objekt där varje objekt får vara vad som helst — den har ingen inbyggd form ([[ADR-0033 Produktens omfång]]) — och därför kan en användare bygga ett projekt av items utan att vi ger henne en projekttabell.

Regeln om rötterna är också en åtkomstregel, och den är exakt en mening: **ett item vars samtliga föräldrar ligger utanför omfånget är självt en rot.** Trädet visar aldrig en förfader mottagaren inte når, och gömmer aldrig hennes eget item därför att dess förälder är dold. Den som fått impellern ser impellern som sin rot, inte motorn — och inte heller ett tomrum där motorn skulle stått.

**Ett item kan förekomma på flera ställen, och vilken plats som är den aktuella är en fråga om hur användaren kom dit.** Ingen kolumn pekar ut en huvudplats. Vägen står i querysträngen, som filtret i issue 59a § Beslut 1 och av samma skäl: en delbar länk. En väg som inte längre finns — trädet har byggts om, ett led har raderats — **ignoreras och ersätts av den första i ordningen**. Den svarar aldrig 404: en delad länk som slutar fungera för att någon flyttat ett item är en fälla, inte ett fel.

**Anteckningen är ett eget fält på itemet, skilt från beskrivningen.** De två svarar på olika frågor. `description` säger **vad itemet är** — meningen en annan människa behöver för att veta vad hon tittar på, och den som redan står i FULLTEXT-indexet och i sökningens kolumnlista. Anteckningen är **vad användaren vet om det**: ett fritt informationsfält som växer med tiden, Evernotes textruta och inte en rubrik. `item` får därför en nullbar `notes` vid sidan av `description`.

**Ett fält, inte många daterade rader.** Ingen entitet, ingen tabell, ingen tidsstämpel per stycke. Vill produkten senare ha en ström av daterade anteckningar är det en tabell och ett nytt beslut, inte en tolkning av det här.

**Itemets bild är en vald bilaga, och valet har en regel som gör det frivilligt.** `item` får en nullbar pekare till en bilaga. Upplösningen sker på servern, aldrig i vyn, i den här ordningen: den valda bilagan om den finns kvar, hör till itemet och är en bild; annars itemets **äldsta** bild; annars ingen bild. Regeln gör det användaren bad om — finns bara en bild används den — utan att kräva ett val av den som har två och inte bryr sig, och den låter inte itemets ansikte byta skepnad varje gång någon laddar upp ett foto.

**"Aktiv" är OK.** Två ord för ett tillstånd är precis det [[ADR-0032 Produktens ord]] finns till för att förhindra. Märket är det härledda OK som [[ADR-0040 Underträdets summor]] definierar — noll kvarvarande uppgifter i underträdet — och ingenting annat. Ingen `status`-kolumn, inget livscykeltillstånd vid sidan av.

**Historikfliken ritas inte.** Samma svar som dashboardens händelsepanel fick: ytan väntar på att applikationen instrumenteras, och tills dess är en flik som alltid är tom ett löfte vyn inte kan hålla.

**Leverantör och artikelnummer utgår ur detaljrutan.** Leverantören bor på kostnadsraden, där den redan är indexerad och redan har en autocomplete. Artikelnumret finns inte, och `serial_number` är inte det — ett serienummer identifierar exemplaret, ett artikelnummer modellen.

## Rättelsen av ADR-0040

[[ADR-0040 Underträdets summor]] skriver att containerns donut har *"dess toppnivåitems"* som tårtbitar. Det håller i ett träd och går sönder i en DAG.

Bränslefiltret i mockupen hänger under både *Bränslesystem* och *Underhåll*, där den senare ligger under ett projekt. Bägge vägarna leder upp till var sitt toppnivåitem. Filtrets kostnad hamnar därmed i två tårtbitar, och bitarna summerar till mer än totalen — som står som ett eget tal alldeles bredvid, ur issue 86. Två tal på samma sida som säger emot varandra är det billigaste sättet att göra en kostnadsvy oanvändbar.

**Tårtbitarna är de items som bär kostnadsraderna, inte underträdets toppnivå.** Varje `cost_entry` har exakt ett `item_id`, så varje rad hamnar i exakt en bit och bitarna summerar alltid precis till underträdets total. Frågan *var tog pengarna vägen* besvaras av bitarna; frågan *vad kostar motorn* besvaras av **talet på motorn**, som fortfarande är summan över underträdet. De två är olika frågor och ska inte vara samma tal.

Resten av ADR-0040 står oförändrad. Underträdssumman är vad den var, `ResolveItemDescendants` gör vad den skulle, och ättlingsmängden är en **mängd** — ett item som nås längs två vägar räknas en gång, vilket en vandring med besöksmarkering ger gratis.

## Motivering

**Projektet är gratis om vi låter bli att bygga det.** Ett projekt är en grupp uppgifter knutna till ett eller flera objekt. Är projektet ett item, är de objekt det rör dess barn, och uppgifterna i dess underträd är projektets uppgifter. Då *är* [[ADR-0040 Underträdets summor]] redan projektvyn: OK-märket blir projektets framdrift och underträdssumman blir dess budgetutfall, utan en rad ny kod. En projekttabell hade gett samma sak sämre, och hade dessutom varit domänen inbyggd i schemat.

**Den treställda vyn är billigare än tre vyer.** Strukturen, itemet och relationerna är samma fråga ställd på tre avstånd. Ritas de som skilda sidor måste var och en lösa upp omfånget, sortera och paginera för sig, och de kan glida isär — vilket är precis vad `ListItemLinks` skrevs för att förhindra mellan webben och `/api`.

**Mockuparna är inte lika aktuella.** Den treställda använder *Relaterad*; den andra säger fortfarande *Syskon*, ordet [[ADR-0035 Relationen mellan objekt]] avskaffade för en vecka sedan, och märker noderna med det. Ett utkast som håller sig till det nyaste beslutet är det utkast som ska bära vidare.

## Konsekvenser

- **Två upplösningar till ska byggas, och båda gränsar till åtkomsten.** En för strukturen nedåt — containerns items och deras föräldrakanter, i en fråga var, slutningen i PHP — och en för vägarna uppåt, som räknar upp ett items alla förekomster. `ResolveItemScope` rörs inte av någondera, av skälet [[ADR-0040 Underträdets summor]] redan skrev ut.
- **Rotregeln är samma mening på båda ställena.** Trädets rötter och vägarnas startpunkter är samma sak sedd från två håll, och en av dem får aldrig råka bli generösare än den andra. De två issuerna binds därför till samma formulering.
- **Ingen räknare över det som fallit bort.** Varken trädet eller förekomstlistan får berätta att något dolts — issue 73 § Beslut 6, och en omfångsbegränsad mottagares träd ska vara ordagrant det hon hade sett om resten inte fanns.
- **Bilagans radering får aldrig blockeras av omslagspekaren.** Pekaren är en preferens, inte data, så den nollställs när bilagan försvinner i stället för att hindra gallringen — en avvikelse från husets `onDelete('restrict')`, och den ska motiveras i migreringen.
- **Anteckningsfältet går med i sökningen.** `Item::toSearchableArray()` räknar fem kolumner och är dokumenterad som en spegel av FULLTEXT-indexet från issue 13a § Beslut 4. En anteckning är precis vad någon söker efter — *bytte impeller 2024* står ingen annanstans — så `notes` läggs till i **båda** listorna, i samma migrering. Att lägga till kolumnen nu och indexet senare är dyrare på en full tabell, vilket `item`-migreringen själv anför som skäl till att indexet skapades i förväg.
- **Flikraden och trepanelslayouten byggs inte här.** De väntar på designsystemet, precis som containerns flikrad i [[ADR-0039 Containerns översikt]]. Det som byggs nu är det vars form datamodellen bestämmer.
- **Fokuskartan väntar med dem.** Den ritar `ListItemLinks` som noder och behöver ingen ny fråga, men den behöver en layout, och teckenförklaringen ska vara tre sorter — *Parent · Child · Related* — enligt [[ADR-0035 Relationen mellan objekt]].
- **Containerns hela karta är ett eget projekt.** En graf över hundratals noder är en layoutalgoritm och inte en vy.
- **Favoriter har ingen tabell och ingen issue.** Raden i mockupen är en önskan, inte ett beslut.
- **Itemets kostnadsflik kräver ingen ny ändpunkt.** Issue 91 bygger redan summeringen med itemet som startpunkt; det som saknas är ytan, och den väntar på designen.

## Alternativ

**Bygga den globala navigeringen ur den treställda mockupen.** Hem, Items, Struktur, Karta, Uppgifter, Dokument, Kostnader som egna rader. Valdes bort — containern upphör då att vara ett rum, varje vy måste själv säga vilken container den handlar om, och [[ADR-0039 Containerns översikt]] hade behövt rivas upp två dagar efter att den antogs.

**Låta `description` vara anteckningsfältet.** Ingen ny kolumn, och fältet finns redan. Valdes bort — beskrivningen säger vad itemet är och anteckningen vad användaren vet om det, och slås de ihop blir beskrivningen antingen en uppsats eller anteckningen en rubrik. Ett fält som bär två syften får förr eller senare två format.

**Låta en anteckning vara ett eget item.** Hade följt mockupens sökväg *"Serviceintervall — Anteckningar"* bokstavligt. Valdes bort — en anteckning hör till ett objekt, den är inte ett objekt, och varje anteckning som item hade fått egna uppgifter, egna kostnader och en plats i trädet den inte förtjänar.

**Lagra en huvudplats på itemet.** Hade gjort brödsmulan till ett uppslag. Valdes bort — den måste väljas vid varje ny länk, den blir fel så snart trädet byggs om, och den påstår att en av två lika giltiga placeringar är den riktiga.

**Rita historikfliken tom, som en platshållare.** Valdes bort här, till skillnad från dashboardens händelsepanel, av en enkel anledning: panelen på dashboarden har en plats i rutnätet som annars gapar, medan en flik är en väg användaren klickar på och blir besviken av.
