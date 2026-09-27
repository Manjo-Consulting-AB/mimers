# M23 · Mobilen och kartan

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

Tillagd 2026-09-27, medan M22 byggdes. Designern lämnade en femte bild samma dag, `docs/Design/mobil.png`: tio mobilskärmar. Genomgången gav fyra beslut, [[ADR-0046 Containerns karta]], [[ADR-0047 Containerns bild]], [[ADR-0048 Mobilen och plusknappen]] och [[ADR-0049 Nyligen besökta]], och milstolpen bygger dem. **Anmälningsvägen enligt DSA**, som 2026-09-26 skulle bli nästa milstolpe, går efter designen, se [[Att sortera efter mockuparna]] § Ännu inte issues.

**Bilden är förlagan, besluten är regeln.** Där bilden och en ADR säger olika gäller ADR:en. Bilden säger *Sibling* på två ställen, och det ordet förblir struket ([[ADR-0048 Mobilen och plusknappen]] § 4). Bilden har också en flik *Favoriter* i containern, som inte tas in. Mät inte ut värden ur bilden. Tokens och komponenter kommer från [[M17 Designsystemet]], och en komponent som saknas byggs en gång, i den issue som först behöver den.

**Skalet först (151–153).** Mobilskalet, plusknappen som skapar det vyn visar, och formuläret som vet var det öppnades. Allt annat i milstolpen ritas inuti skalet.

**Vyerna (154–157).** Trädet och itemets översikt på mobil, relationsfliken utan syskon, fokuskartan på itemet och containerns karta. Kartan har stått som *"ett eget projekt"* sedan 2026-09-18. [[ADR-0046 Containerns karta]] gör den till en vy genom att bara hålla en gren öppen per nivå.

**Containerns bild (158–159).** En bilaga kan tillhöra en container. **158 är `risk_class: elevated`** och väntar på issue 148 och 149 i [[M22 Redo för testare]], så att bevisen för referensräkningen och filåtkomsten finns innan bilagan får en andra ägare.

**Nyligen besökta (160).** En tabell, en skrivning på itemsidan och en lista i skalet.

---

### 151. Mobilskalet
Under `md:` får `AppLayout` ett eget skal i stället för den hopfällda desktopraden, enligt [[ADR-0048 Mobilen och plusknappen]] § 1. En mörk topprad med sidans titel, en flikrad i botten med *Översikt*, *Sök*, plusknappens plats, *Notiser* och *Meny*, och en sidomeny bakom *Meny*.

**Sidomenyn visar samma innehåll som desktopskalets sidopanel.** Sektionerna ritas ur samma data, så att ingenting finns i den ena och saknas i den andra. Sektionen *Nyligen besökta* kommer i 160, och i den här issuen lämnas bara dess plats i ordningen.

Inne i en container visar toppraden containerns namn och antal items, plus en tillbakaknapp. Bilden kommer i 159. Containerns flikrad (`UiTabs`) går att skrolla i sidled under `md:` i stället för att bryta rader.

Plusknappens plats i flikraden är tom i den här issuen. Knappen kommer i 152. Notisernas taltuta är densamma som klockans i issue 127.

**Över `md:` ändras ingenting.** De befintliga webbläsartesterna och tangentbordsvägarna från 68a och 68b ska gå igenom oförändrade.

**Läs:** [[ADR-0048 Mobilen och plusknappen]] § 1, [[ADR-0042 Designsystemet]] § Beslut, `docs/Design/mobil.png` (bild 1, 2 och 10), `resources/js/layouts/AppLayout.vue` (docblocken), `resources/js/layouts/ContainerLayout.vue`, [[M17 Designsystemet]] § 106
**Klart när:** under `md:` visas flikraden i botten och toppraden, och den gamla menyknappen finns inte; *Meny* öppnar sidomenyn, som går att stänga med knappen, med Escape och genom att trycka utanför; sidomenyn och desktopskalets sidopanel visar samma sektioner i samma ordning ur samma data; fokus hålls i sidomenyn medan den är öppen och återgår till *Meny* när den stängs; varje mål i flikraden är minst 44×44 px och nåbart med tangentbord; containerns flikrad skrollar i sidled under `md:`; över `md:` är skalet oförändrat; hela testsviten är grön.
**Beror på:** -

### 152. Plusknappen skapar det vyn visar
Knappen i flikradens mitt (mobil) och en knapp på samma plats i sidhuvudet (desktop) gör det [[ADR-0048 Mobilen och plusknappen]] § 2 beskriver. I containerlistan och på dashboarden leder den till `containers.create`. I en container leder den till `containers.items.create`, med `?parent` om man står på en nod. I ett item öppnar den en meny.

**Menyn är ett ark som glider upp nerifrån på mobil och en meny under knappen på desktop.** Arket är en ny kärnkomponent, `UiSheet`, med fokusfälla, Escape och en rubrik. Det byggs här en gång, och 159 återanvänder det. Menyns fem rader leder dit samma sak skapas i dag: *Item under* till formuläret med `?parent`, *Relation* till relationsfliken, *Bild eller dokument* till bilagefliken, *Uppgift* till schemaformuläret och *Kostnad* till kostnadsraden.

**Vad knappen gör avgörs av sidan, inte av knappen.** Varje sida som har en plusknapp skickar sitt mål som en prop till skalet. En sida utan prop får ingen knapp. Grindarna är de befintliga policyerna, prövade på servern: en rad man inte får använda skickas inte med, och en tom meny ger ingen knapp.

**Läs:** [[ADR-0048 Mobilen och plusknappen]] § 2, `docs/Design/mobil.png` (bild 9, menyn), `app/Policies/ContainerPolicy.php`, `app/Policies/ItemPolicy.php`, `resources/js/pages/Containers/Items/Show.vue` (flikarnas `href`)
**Klart när:** knappen på dashboarden och i containerlistan leder till formuläret för en ny container; i en container leder den till formuläret för ett nytt item i containern; i ett item öppnar den menyn med de rader användaren får använda; en användare med läsåtkomst ser ingen knapp i en container eller ett item; en rad vars policy nekar finns inte i menyn; knappen visas inte på sidor utan mål; arket har fokusfälla och stängs med Escape; ordet *sibling* förekommer inte; hela testsviten är grön.
**Beror på:** 151

### 153. Ett item skapas där man står
Formuläret i `containers.items.create` visar placeringen som en rad, med containern och föräldern och en *Ändra*-knapp, enligt [[ADR-0048 Mobilen och plusknappen]] § 3. *Ändra* öppnar en väljare med containerns träd och ett sökfält. Trädet är `ItemStructureTree` med samma omfångsfilter, och sökningen filtrerar på namn inom containern.

**Väljaren visar bara items där användaren får skapa ett child.** Ett item utan `ItemPolicy::create` går att se i trädet men inte välja. Valet skickas som `parent` och prövas på servern som i dag.

Resten av formuläret är namnet och rader för beskrivning, taggar, bilder och dokument. Raderna för bilder och dokument leder till itemets bilageflik efter att itemet skapats. Uppladdning i själva formuläret ingår inte.

**Läs:** [[ADR-0048 Mobilen och plusknappen]] § 3, `docs/Design/mobil.png` (bild 7 och 8), `app/Http/Controllers/ItemController.php` (`create` och `store`, docblocken), `resources/js/components/ItemForm.vue`, `resources/js/components/ItemStructureTree.vue`
**Klart när:** formuläret öppnat med `?parent` visar föräldern som placering; *Ändra* öppnar väljaren; sökfältet filtrerar trädet på namn; ett item utanför användarens omfång syns inte i väljaren; ett item utan skapandebehörighet går inte att välja; ett förfalskat `parent` avvisas av servern som i dag; utan förälder skapas itemet som rot; väljaren fungerar med tangentbord; hela testsviten är grön.
**Beror på:** 152

### 154. Trädet och itemets översikt på mobil
Två vyer som redan finns ritas om för mobilen.

**Containerns itemflik får växeln i [[ADR-0046 Containerns karta]]:** *Lista*, som är dagens lista och förblir förval, *Träd* och *Karta*. Läget står i querysträngen. *Träd* är `ItemStructureTree` över hela containern, med expandera och fäll ihop och en rad per item som leder till itemet. *Karta* ritas i 157. Här syns läget, men det visar ingenting förrän 157 är byggd, och valet får inte visas förrän dess.

**Itemets översikt på mobil** visar omslagsbilden överst och sedan *Snabbfakta*: `manufacturer`, `model` och `serial_number`, bara de som har ett värde, och *Visa alla fält*, som öppnar informationsfliken. Därefter uppgifterna. Leverantör och artikelnummer förblir strukna ([[ADR-0042 Designsystemet]] § Bildernas avvikelser).

**Läs:** [[ADR-0046 Containerns karta]] § Beslut (sista två styckena), [[ADR-0041 Itemets vy]], `docs/Design/mobil.png` (bild 2 och 4), `resources/js/pages/Containers/Items/Index.vue`, `resources/js/components/ItemStructureTree.vue`, `resources/js/pages/Containers/Items/Show.vue`
**Klart när:** itemfliken har växeln med *Lista* som förval; *Träd* visar containerns rötter enligt ADR-0041 och ett item med två föräldrar på båda ställena; läget överlever en omladdning; *Karta* visas inte i växeln; itemets översikt visar bara de snabbfakta som har värde, och ingen rubrik när alla saknas; *Visa alla fält* leder till informationsfliken; antalet frågor för trädet är konstant oavsett antal items; hela testsviten är grön.
**Beror på:** 151

### 155. Relationsfliken utan syskon
Itemets relationsflik ritas som bild 5 visar, fast med tre relationer: föräldern ovanför, itemet självt, barnen under och de relaterade i en lista. Det är samma data som `ListItemLinks` redan ger.

**Ordet *sibling* och *syskon* söks bort ur gränssnittet** enligt [[ADR-0048 Mobilen och plusknappen]] § 4 och [[ADR-0035 Relationen mellan objekt]]. Det gäller översättningsfilerna och docblocken om relationer, som den i `Show.vue` som ännu säger *syskon*. Identifierare i databasen rörs inte, eftersom namnbytet där redan gjordes i issue 87. **Kategoriträdets syskon rörs inte.** `categoryTree.js` och `CategoryCreateForm.vue` talar om kategorier med samma förälder, och där är ordet rätt.

**Läs:** [[ADR-0035 Relationen mellan objekt]], [[ADR-0048 Mobilen och plusknappen]] § 4, `docs/Design/mobil.png` (bild 5), `resources/js/components/ItemLinkSection.vue`, `app/Actions/Item/ListItemLinks.php`
**Klart när:** fliken visar förälder, item, barn och relaterade i den ordningen; ett item med två föräldrar visar båda; en relation utanför användarens omfång syns inte; ingen sträng i `lang/` innehåller `sibling` eller `syskon`, vilket ett test prövar; hela testsviten är grön.
**Beror på:** 151

### 156. Fokuskartan
`ItemMapPanel`, som står tom med flit sedan issue 103, får sitt innehåll. Itemet ritas i mitten, föräldrarna ovanför, barnen under och de relaterade på sidorna, med noder och linjer som i bild 6. Datan är `ListItemLinks`. Ingen ny fråga behövs ([[ADR-0042 Designsystemet]] § Beslut).

**Varje nod har ett plus**, som öppnar samma meny som plusknappen i 152 med *Item under* och *Relation* för den noden. En nod leder till sitt item. På mobil är fokuskartan ett läge i itemets relationsflik, *Fokus* eller *Lista*, och på desktop fyller den högerpanelen.

**Layouten är fast, inte beräknad.** Tre rader och två sidor räcker för närmaste relationer. Har en rad fler noder än som får plats visas de första och *+N till*, som leder till relationsfliken.

Teckenförklaringen har tre sorter: *Parent*, *Child* och *Related*, på det språk användaren valt.

**Läs:** [[ADR-0048 Mobilen och plusknappen]] § 4, [[ADR-0042 Designsystemet]] § Beslut (om kartan), `docs/Design/mobil.png` (bild 6 och 9), `resources/js/components/ItemMapPanel.vue` (docblocken), `app/Actions/Item/ListItemLinks.php`
**Klart när:** högerpanelen visar itemet med föräldrar, barn och relaterade; en nod utanför omfånget ritas inte; *+N till* visas när en rad är full; varje nods plus öppnar menyn med de rader användaren får använda på den noden; på mobil växlar relationsfliken mellan *Fokus* och *Lista*; noderna går att nå med tangentbord och har en läsbar etikett för skärmläsare; teckenförklaringen har tre sorter; hela testsviten är grön.
**Beror på:** 152, 155

### 157. Containerns karta
Läget *Karta* i itemfliken, enligt [[ADR-0046 Containerns karta]]. Det är strukturträdet ritat som noder, med en öppen gren per nivå: kolumner på desktop och ett rutnät med sökväg på mobil. Varje nod visar underträdets status enligt [[ADR-0040 Underträdets summor]] och antalet barn. Ett item på flera platser får markeringen ⇄ med antalet platser.

**Den öppna vägen står i querysträngen**, och att öppna en nod är en partiell omladdning som bara hämtar den nodens barn. Statusfrågan är densamma som i översikten, avgränsad till de noder som ritas.

Plusknappen i en öppen karta skapar ett item under den markerade noden (152).

**Läs:** [[ADR-0046 Containerns karta]], [[ADR-0040 Underträdets summor]], [[ADR-0041 Itemets vy]], `docs/Design/mobil.png` (bild 3), `resources/js/components/ItemStructureTree.vue`, [[M15 Containerns översikt]] § 92 (statusfrågan)
**Klart när:** *Karta* visas i växeln; bara en gren är öppen per nivå, och att öppna en syskonnod stänger den förra; varje nod visar grenens status med samma regel som översikten och antalet barn; ett item med två föräldrar markeras med antalet platser; den öppna vägen överlever en omladdning och bakåtknappen; ett item utanför omfånget syns inte och räknas inte i någon status; antalet frågor per öppnad nod är konstant oavsett antal barn; plusknappen skapar ett item under den markerade noden; hela testsviten är grön.
**Beror på:** 152, 154

### 158. En bilaga kan tillhöra en container
Datamodellen och grinden i [[ADR-0047 Containerns bild]]. En migrering gör `attachment.item_id` nullbar och lägger till `attachment.container_id`, nullbar med `ON DELETE RESTRICT`, plus ett CHECK-villkor om att exakt en är satt och indexet `(container_id, deleted_at)`. Samma migrering lägger till `container.cover_attachment_id`, nullbar. Förlagan är `item.cover_attachment_id` från issue 93. Migreringen är additiv och ändrar ingen befintlig rad.

**En action sätter bilden:** `SetContainerCover` laddar upp genom `StoreAttachment` med `container_id`, godtar bara `kind = image`, sätter pekaren och rensar en tidigare bild med `PurgeAttachment`. Den kräver `ContainerPolicy::update`. `RemoveContainerCover` rensar och nollställer pekaren.

**Grinden avgörs på ett ställe.** Leveransen i [[ADR-0019 Filleverans]] frågar i dag `view` på `$attachment->item`. Den frågar i stället en metod på bilagan som ger containern eller itemet, och alla fem anropsställen går genom den.

**Varje läsare av `attachment` ska klara `item_id = NULL`.** Det gäller resurserna, exporten, flytten av `billed_account_id` i `DeleteAccount`, `ReportsAbuseSignals`, `PurgeContent` och papperskorgens listning, som aldrig visar en containerbilaga. När en container rensas rensas dess bilagor med.

**Läs:** [[ADR-0047 Containerns bild]], [[Filer och lagring]] § stored_file, § attachment och § Radering, [[ADR-0019 Filleverans]], [[ADR-0045 Radering av konto och person]] § Beslut 1 och § Uppföljning 2026-09-27, [[Testplan filer]], `database/migrations/2026_09_21_010000_add_cover_attachment_id_to_item_table.php`, `app/Actions/Attachment/StoreAttachment.php`, `app/Actions/Attachment/PurgeAttachment.php`
**Klart när:** migreringen går på MariaDB och CHECK-villkoret avvisar en rad med båda eller ingen satt; en containerbild kan sättas, bytas och tas bort av den som får ändra containern och av ingen annan; ett dokument avvisas; att byta bild minskar den gamla filens `reference_count` exakt en gång; samma bild på två containers delar en `stored_file`; bilden levereras till den som når containern, även med åtkomst till ett enda item, och nekas med 404 till övriga; papperskorgen visar ingen containerbilaga; en container som rensas tar sin bild med sig; kontoradering flyttar `billed_account_id` för en främmande containerbild; exporten tar med bilden; proven från issue 148 och 149 är gröna utan ändring; [[Filer och lagring]] och [[Konton och åtkomst]] § container beskriver kolumnerna; hela testsviten är grön.
**Beror på:** 148, 149

### 159. Containerns bild i gränssnittet
Ytan i [[ADR-0047 Containerns bild]] § Beslut (sista stycket). En penna på bilden överst i containern och ett avsnitt under containerns inställningar öppnar samma val i `UiSheet` från 152: *Ta ett foto*, *Välj från enheten* och *Ta bort bilden*. *Ta ett foto* är en filväljare med `capture` och ingen egen kamera. Pennan visas bara för den som får ändra containern.

**Bilden visas på tre ställen:** containerlistan, som ritas som bild 1 med en rad per container, dashboardens containerkort och containerns topprad från 151. Utan bild visas en neutral yta med artens ikon. Miniatyren är den som [[Filer och lagring]] § image_derivative redan skapar.

**Läs:** [[ADR-0047 Containerns bild]], `docs/Design/mobil.png` (bild 1), `docs/Design/main.jpeg` och `docs/Design/container.jpeg` (kortens och hjältens bild), `resources/js/components/ContainerCard.vue`, `resources/js/pages/Containers/Index.vue`, `resources/js/pages/Containers/Edit.vue`
**Klart när:** pennan och inställningarna öppnar samma ark; en uppladdad bild syns i containerlistan, på dashboardkortet och i toppraden; att ta bort bilden visar ytan med artens ikon; pennan visas inte för den som bara läser; ett fel från servern, som kvoten eller en fil som inte är en bild, visas med sin felkod översatt; listan och dashboarden gör ett konstant antal frågor oavsett antal containers; hela testsviten är grön.
**Beror på:** 151, 158

### 160. Nyligen besökta
Tabellen, skrivningen och listan i [[ADR-0049 Nyligen besökta]]. En migrering skapar `recent_visit`. `ItemController::show` gör en upsert efter grinden och raderar personens rader utöver de 20 senaste i samma förfrågan.

**Listan är en valfri Inertia-prop** som hämtas med en partiell omladdning när sidomenyn öppnas, och när sidopanelen ritas på desktop. Den går genom `ResolveItemScope`, som favoritlistan i issue 106, och visar högst tio items med namn, containerns namn och hur länge sedan besöket var.

**Raderna raderas med personen** (`DeleteUser`, issue 144) **och med itemet och containern** när de rensas ur papperskorgen. Exporten tar inte med dem.

**Läs:** [[ADR-0049 Nyligen besökta]], [[ADR-0045 Radering av konto och person]] § Beslut 3 och § Uppföljning 2026-09-27, [[Registerförteckning]], [[M17 Designsystemet]] § 106 (förlagan för listan och filtret), `app/Http/Controllers/ItemController.php` (`show`), `app/Actions/User/DeleteUser.php`, `app/Actions/Trash/PurgeContent.php`
**Klart när:** att öppna ett item skriver en rad, och att öppna det igen uppdaterar `visited_at` utan en ny rad; en 21:a rad raderar den äldsta; en nekad visning skriver ingenting; listan visar de senaste i ordning; ett item utanför omfånget eller i papperskorgen syns inte, och syns igen när åtkomsten eller itemet kommer tillbaka; ingenting avslöjar hur många som filtrerats bort; propen frågas inte på sidor där menyn inte öppnats; personradering, rensning av item och rensning av container tar bort raderna; [[Konton och åtkomst]] har avsnittet `recent_visit` och [[Registerförteckning]] raden; hela testsviten är grön.
**Beror på:** 144, 151
