# ADR-0046 Containerns karta

**Status:** Antagen 2026-09-27 · Besvarar *Containerns karta* i [[Att sortera efter mockuparna]] · Bygger vidare på [[ADR-0040 Underträdets summor]] och [[ADR-0041 Itemets vy]] · [[ADR-index]]

Tonys beslut 2026-09-27, vid genomgången av mobilmockupen (`docs/Design/mobil.png`, bild 3) och vid planeringen av [[M23 Mobilen och kartan]].

## Kontext

Containerns karta har stått som *"ett eget projekt"* sedan genomgången 2026-09-18: en graf över hundratals items och deras relationer är en layoutalgoritm och inte en vy, och [[ADR-0042 Designsystemet]] lät därför kartans plats stå tom.

Mobilmockupen ritar något annat och mycket mindre. Den visar containern och dess översta nivå, och man expanderar genom att trycka på en nod. Det är inte en graf över allt. Det är strukturträdet ritat som noder, en nivå i taget.

Trädet finns redan. `ItemStructureTree` ritar containerns rötter enligt [[ADR-0041 Itemets vy]], och ett item som har två föräldrar förekommer på två ställen. Statusen över ett underträd finns också redan, sedan [[ADR-0040 Underträdets summor]] och issue 92.

## Beslut

**Kartan är strukturträdet ritat som noder, med en öppen gren per nivå.** Den läser samma träd som strukturpanelen och ingenting annat: föräldra- och barnrelationerna i `item_link`, rötterna enligt ADR-0041 och omfångsfiltret enligt [[ADR-0028 Åtkomst på itemnivå]]. Relationer av typen `related` ritas inte i containerkartan. De hör hemma i itemets fokuskarta, som är en egen vy med en egen fråga (`ListItemLinks`).

**Bara en gren är öppen per nivå.** När man öppnar en nod stängs den nod som var öppen på samma nivå. Därför ritas aldrig mer än den öppna vägen plus syskonen på varje nivå längs den, hur stor containern än är. Det gör layouten trivial: kolumner på desktop och ett rutnät på mobil. Ingen grafalgoritm och inget nytt npm-paket behövs.

- **Desktop:** en kolumn per nivå från vänster till höger. Den öppna noden i varje kolumn är markerad, och en linje leder till nästa kolumn. Till höger om den sista kolumnen visas en ruta med den markerade nodens namn, antal ättlingar och närmaste uppgift, plus en länk till itemet.
- **Mobil:** noden man står på visas stor överst med sin förälder som en knapp ovanför, och barnen i ett rutnät med två kolumner under. Man trycker på ett barn för att gå ett steg ner. En sökväg ovanför kartan visar var man är, och varje led i den går att trycka på.

**Varje nod visar status för hela sin gren**, med samma tre lägen och samma regel som [[ADR-0040 Underträdets summor]]: OK, närmar sig och förfallen någonstans i grenen. Det är kartans skäl att finnas vid sidan av listan. Listan visar namn, kartan visar var något behöver göras. En nod visar också antalet barn, så att man ser om det finns något att öppna.

**Ett item på flera platser ritas på varje plats, och markeras.** Noden får en markering med antalet platser (⇄ 2). Den ritas inte som en enda nod med två inkommande linjer, eftersom det skulle bryta *en öppen gren per nivå*.

**Den öppna vägen står i querysträngen**, precis som den aktuella platsen i itemvyn (ADR-0041). En karta som öppnats tre nivåer ner kan länkas, laddas om och gås tillbaka i med webbläsarens bakåtknapp.

**Kartan är en vy av containerns itemflik, inte en egen flik.** Fliken får en växel med tre lägen: *Lista*, som är dagens filtrerbara lista och förblir förval, *Träd* och *Karta*. Valet står i querysträngen. Flikraden i [[ADR-0042 Designsystemet]] § Beslut ändras inte.

**Barnen hämtas en nivå i taget.** Att öppna en nod är en partiell omladdning som hämtar nodens barn med deras status. Hela trädet laddas aldrig i förväg. Statusfrågan är ADR-0040:s, avgränsad till de noder som ritas.

## Motivering

Att bara hålla en gren öppen per nivå gör ett layoutproblem utan övre gräns till ett med en känd gräns. En container med 800 items och sju nivåer ritar fortfarande bara ungefär sju kolumner. Den som vill se allt på en gång har listan, och den som vill se helheten har översikten med underträdets summor.

Status på noderna är det som gör kartan till något annat än listan. Utan status vore den samma information i en dyrare form.

## Konsekvenser

- Kartan blir en issue och inte ett projekt: [[M23 Mobilen och kartan]] § 157.
- Det som stod om kartan i [[Att sortera efter mockuparna]] och [[ADR-0042 Designsystemet]] § Beslut (*"Kartan ritas inte här"*) gäller inte längre för containerkartan. Fokuskartan på itemet byggs i samma milstolpe, § 156.
- En graf över *alla* relationer, `related` inbegripet, byggs inte. Behövs den någon gång är det ett nytt beslut med en layoutalgoritm, och det beslutet börjar inte om från noll: den här kartans noder, status och querysträng går att återanvända.

## Alternativ

**En kraftbaserad graf över hela containern.** Det mockuperna från 2026-09-22 antydde. Valdes bort: den kräver ett layoutbibliotek, blir oläslig över ett par hundra noder och är nästan oanvändbar på en telefon. Den svarar dessutom på en fråga ingen har ställt, nämligen hur allt hänger ihop på en gång.

**Flera öppna grenar samtidigt, som i ett vanligt träd.** Då är det trädet, och det finns redan som läget *Träd*.
