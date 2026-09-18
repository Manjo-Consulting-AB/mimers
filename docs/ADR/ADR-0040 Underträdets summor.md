# ADR-0040 Underträdets summor

**Status:** Antagen 2026-09-18 · Kompletterar [[ADR-0035 Relationen mellan objekt]] och [[ADR-0038 Gränsen för Pro i kostnaderna]] · Tårtbitarnas indelning rättad samma dag av [[ADR-0041 Itemets vy]] · [[ADR-index]]

Fattat vid genomgången av containermockupen. [[ADR-0039 Containerns översikt]] avgjorde vilken sida som visar talen. Den här avgör hur de räknas.

## Kontext

Containermockupen visar två tal som inte finns i datamodellen.

**Varje item bär ordet OK.** `item` har ingen `status`-kolumn, och migreringens kommentar räknar upp den vid namn bland det som med flit saknas: *"ingen extra kolumn, ingen `status`, ingen `quantity`, ingen `location_id`"*.

**Kostnadsdonuten är indelad i Service, Bränsle, Utrustning och Övrigt.** `cost_entry` har `amount`, `currency`, `description`, `supplier` och `item_id` — **ingen kategori**. De fyra tårtbitarna finns ingenstans.

Donuten är inte vilken panel som helst. Den är den ena av de två ytor [[ADR-0038 Gränsen för Pro i kostnaderna]] just gjorde fria, och hela argumentet där var att ett synligt tal säljer Pro medan ett suddat inte gör det. En fri donut utan indelning att rita är alltså ett problem för prismodellen och inte bara för vyn.

Båda talen pekar åt samma håll. Ett item ärver uppåt: motorn har en impellerbyte som sitter på impellern, och kostnaden för bränslepumpen är en kostnad för motorn. Frågan *hur står det till med motorn* och frågan *vad kostar motorn* har samma svarform — allt som hänger under den.

## Beslut

**Ett items status och kostnad räknas över itemet och dess ättlingar.** Ättling betyder transitivt nedåt längs `item_link`-kanter där `relation` är `parent`, precis den riktning [[ADR-0028 Åtkomst på itemnivå]] regel 3 redan går. En `related`-länk bär ingenting, varken behörighet eller summa ([[ADR-0035 Relationen mellan objekt]]).

**OK betyder noll kvarvarande uppgifter i underträdet.** Ingen ny kolumn: statusen härleds ur `schedule_occurrence` för itemet och allt under det. Har något där förfallit är itemet inte OK, och användaren behöver inte öppna sextio items för att hitta det som brinner.

**Kostnadsdonuten grupperar per item.** En komponent, en regel: *summera kostnaderna i det aktuella underträdet, grupperat per item*. På containerns översikt är underträdet hela containern; på ett item är underträdet itemet plus dess ättlingar. **Tårtbitarna är de items som bär kostnadsraderna** — varje `cost_entry` har exakt ett `item_id`, så varje rad hamnar i exakt en bit och bitarna summerar alltid precis till underträdets total. Samma kod, samma svar, olika startpunkt.

**Ingen kategori införs på `cost_entry`.** Fältet hade hetat *kategori* bredvid itemens kategoriträd, vilket är ett ord med två betydelser ([[ADR-0032 Produktens ord]]), och det hade varit ännu en värdelista som påstår vad världen består av ([[ADR-0033 Produktens omfång]]).

**Ättlingsupplösningen bryts ut i en egen Action, `ResolveItemDescendants`.** Förlagan är `ResolveCategoryDescendants`, som löser samma problem för kategoriträdet: slutningen görs i PHP på en fråga, inte som en rekursiv CTE, eftersom sqlite i testsviten saknar `WITH RECURSIVE`. Statusen och donuten delar den. **Åtkomstlagret lämnas utanför** — `ResolveItemScope` behåller sin egen vandring.

## Motivering

**Rullningen uppåt är den fråga produkten finns för.** En platt lista över kostnader per item svarar på *vad kostade impellern*. En summa över underträdet svarar på *vad kostar motorn*, och det är frågan någon faktiskt ställer sig inför ett köp, en försäljning eller en försäkring.

**Regeln är säker utan ett extra omfångsfilter, och det är inte självklart.** Man skulle vänta sig att en summa över ett underträd kan läcka: om mottagaren når föräldern men inte barnet kan hon räkna ut barnets kostnad genom subtraktion. Det kan inte inträffa här. [[ADR-0028 Åtkomst på itemnivå]] regel 3 ger en itemgrant hela underträdet, och reglerna 1 och 2 ger hela containern — **den som ser ett item ser alltid allt under det.** Det finns ingen åtkomstform som ger föräldern utan barnen. Summan över underträdet är därför alltid en summa över rader hon redan når, och läckaget är omöjligt snarare än osannolikt. Skulle en framtida åtkomstform bryta den egenskapen faller den här ADR:n med den.

**`ResolveItemScope`s vandring går inte att återanvända.** Den hämtar `item_link`-kanter bara i de containers som faktiskt har en itemgrant, och hoppar över steget helt när ingen har det — en optimering som är riktig för åtkomsten och fel för en donut, som behöver kanterna varje gång. Att bygga om den till en allmän resolver vore att lägga ett presentationsbehov i behörighetskoden, och det är så åtkomstbuggar uppstår. Två vandringar med skilda syften är billigare än en som tjänar två herrar.

**Per item är dessutom en bättre axel än per kategori.** En kategoriindelning kräver att användaren kategoriserar varje kostnad hon matar in, vilket är en fråga vid varje registrering. Itemet är hon redan tvungen att välja — `cost_entry.item_id` är `NOT NULL` — så indelningen är gratis och kräver ingen ny disciplin av henne.

## Konsekvenser

- **`ResolveItemDescendants` är ny kod i en känslig grannskap.** Den rör inte behörigheten, men den läser samma tabell och kan förväxlas med den vid granskning. Issuen är `risk_class: elevated` och dess test ska bevisa att en `related`-kant inte drar med sig något.
- **Vandringen måste tåla en cykel.** `LinkItems` förhindrar cykler vid skrivning med `item_link.cycle`, och `ResolveItemScope` försvarar sig ändå mot en som skrivits in av en migrering eller en import. Den nya resolvern gör samma sak, av samma skäl.
- **Frågekostnaden ska vara konstant.** Statusen räknas för varje rad i itemlistan; en vandring per rad är den N+1 hela åtkomstlösningen byggdes för att undvika. Kanterna hämtas en gång per container och slutningen sker i minnet.
- **Valutan gäller.** En summa över ett underträd med flera valutor grupperas per valuta och summeras aldrig över dem — [[ADR-0016 Kostnadsregistrering]] och [[ADR-0037 Valutans arv]]. En donut kan alltså behöva ritas en gång per valuta, och det är rätt svar.
- **Mjukraderade items räknas inte.** Varken deras kostnader eller deras uppgifter, och de bryter inte kedjan: ett barnbarn under ett raderat barn faller bort med det, precis som i åtkomstvandringen.
- **`cost_entry` ändras inte.** Ingen kolumn läggs till och ingen tas bort, vilket också är vad [[M14 Besluten ur mockupgenomgången]] issue 85 kräver av valutaarbetet.
- **Rättelse 2026-09-18.** Beslutet sade när det skrevs att containerns tårtbitar var *dess toppnivåitems*. Det håller i ett träd och går sönder i den DAG `LinkItems` faktiskt tillåter: ett item under två föräldrar hamnar i två bitar, och bitarna summerar till mer än totalen som står bredvid dem. [[ADR-0041 Itemets vy]] § Rättelsen av ADR-0040 skriver om meningen. Underträdssumman själv är oförändrad — ättlingsmängden är en **mängd**, och ett item som nås längs två vägar räknas en gång.
- **Donuten är fortsatt fri.** Den är parameterlös och därmed fast enligt [[ADR-0038 Gränsen för Pro i kostnaderna]]. Vägen vidare in i donuten leder till rapportvyn och är Pro.

## Alternativ

**Lägga en kategorikolumn på `cost_entry`.** Ger mockupens fyra tårtbitar rakt av. Valdes bort — ordet krockar med itemens kategorier, listan blir ett påstående om vilka kostnader som finns, och den kräver att användaren svarar på en fråga till vid varje registrering.

**Gruppera per leverantör.** Kräver ingen ny kolumn och är redan indexerat, `(container_id, deleted_at, supplier)`. Valdes bort — fältet är nullbart och ofta tomt, så den största tårtbiten hade blivit *okänd*, och *vad har jag betalat till vem* är en annan och mindre intressant fråga än *vad kostar motorn*.

**Räkna bara på itemet självt, utan underträd.** Enklast, ingen ny Action. Valdes bort — då är motorns kostnad noll så snart användaren gjort det hon ska och lagt impellern under den, vilket straffar precis det beteende produkten vill ha.

**Lägga ättlingsvandringen i `ResolveItemScope`.** En vandring i stället för två. Valdes bort — den optimering som gör åtkomstupplösningen konstant hade fått rivas, och en ändring i behörighetskoden för en donuts skull är fel sorts risk.

**En `status`-kolumn på `item`.** Hade gjort brickan till ett uppslag. Valdes bort — den måste då hållas synkroniserad med varje förändring i schemat under itemet, vilket är en andra sanning om något som redan går att räkna fram.
