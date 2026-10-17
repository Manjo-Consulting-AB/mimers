# ADR-0059 Kartan med en mitt

**Status:** Antagen 2026-10-18 · Ersätter kolumnerna, *en öppen gren per nivå* och `?path=` i [[ADR-0046 Containerns karta]]; nodernas status, platsmarkeringen och läget i itemfliken gäller · Ändrar [[ADR-0058 Trädet som tabell]] § 7: kartan filtreras · Bygger vidare på [[ADR-0041 Itemets vy]], [[ADR-0040 Underträdets summor]] och [[ADR-0028 Åtkomst på itemnivå]] · [[ADR-index]]

Tonys beslut 2026-10-18, vid genomgången av `docs/Design/items-map.png`.

## Kontext

Itemflikens läge *Karta* (issue 157) ritar strukturträdet som kolumner, med en öppen gren per nivå. Kartan visar bara föräldrar och barn. De relaterade finns bara i fokuskartan i itemets högerpanel (issue 156), och dit kommer man bara genom att öppna itemet. Kartan filtreras inte ([[ADR-0058 Trädet som tabell]] § 7), eftersom flera träffar på olika grenar inte passar med en öppen gren per nivå.

Mockupen ritar något annat: ett item i mitten med föräldrarna ovanför, barnen under och de relaterade på sidorna, alltså fokuskartan i hela flikens bredd. Mockupen har också sökfältet och filtret ovanför, en teckenförklaring med räknare, en sökväg under föräldrarnas namn och en meny med tre punkter på varje nod. Dessutom har den *Layout*, zoom, helskärm och pilspetsar på linjerna.

## Beslut

**1. Kartläget är en fokuskarta med en mitt.** Mitten står i adressen som `?view=map&focus=<item-ULID>`. Utan `focus` är mitten containern, och under den står rötterna enligt rotregeln i ADR-0041. Med `focus` är mitten itemet, med föräldrarna ovanför, barnen under och de relaterade på sidorna. Kolumnerna, *en öppen gren per nivå* och kartans `?path=` utgår.

**2. Klick på en nod gör den till mitten.** Det är en länk med en ny `focus`, och den läggs alltså i webbläsarens historik. Bakåtknappen går till förra mitten och en omladdning visar samma mitt. Ett `focus` som inte finns, är raderat eller ligger utanför omfånget ger containern som mitt, utan fel och utan en rad om varför. Det är samma regel som för en kapad `?path=` i ADR-0046.

**3. Varje nod har en öppna-ikon** som leder till itemets sida. Ikonen är en egen roll, `open`, kopierad från Lucide (`arrow-up-right`) enligt [[ADR-0057 Ikoner och typsnitt]] § 1. Noden och ikonen är två mål för tangentbordet. Noden centrerar och ikonen öppnar, och ikonen har ett `aria-label` som namnger itemet.

**4. Plusmenyn sitter bara på mittnoden.** Det är samma meny som i fokuskartan (issue 156): *Item under* och *Relation*, med de rader användaren får använda på itemet. När mitten är containern har noden ingen meny. Skalets plusknapp skapar ett item under mitten när den är ett item, och annars i containern, på samma sätt som den gjorde under den markerade noden förut.

**5. Noden visar status, antal barn och antal platser**, som i ADR-0046. Statusen är ADR-0040:s, räknad för de noder som ritas och inga andra. Ett item med flera placeringar i strukturen får ⇄ och antalet. Ikonerna per item i mockupen byggs inte. Kategorin, taggarna och siffrorna i ADR-0058 hör till trädet.

**6. Layouten är fast.** Den följer fokuskartans tre rader och två sidor (issue 156), men i hela flikens bredd. Taken per grupp är fyra föräldrar, tolv barn och sex relaterade, tre per sida. När mitten är containern gäller barnens tak för rötterna. En grupp som är större än taket visar de första i namnordning och sedan *+N till*. Kring ett item leder *+N till* till itemets relationsflik, och kring containern till listläget. Servern skickar bara de noder som ritas, plus antalet i varje grupp.

**7. Linjerna är raka och utan pilspetsar.** Föräldrar ovanför och barn under säger redan åt vilket håll strukturen går. Linjerna till föräldrar och barn är heldragna och linjerna till de relaterade streckade. Kartan har ingen *Layout*, ingen zoom och ingen helskärm. När allt som ritas är en mitt och en ring finns det inget att zooma i.

**8. Teckenförklaringen har fyra rader:** *Parent*, *Child*, *Related* och *Same item*. De tre första visar antalet i gruppen och inte antalet ritade noder. *Same item* förklarar ⇄ och har inget tal. Slagen skiljs åt med ord och plats, inte bara med färg.

**9. Vägen till mitten står ovanför kartan.** Den börjar med containern och fortsätter med mittens förfäder ned till mitten, och varje led i den gör ledet till mitten. Ett item med flera platser visar den första vägen i namnordning, följd av ⇄ och antalet platser. Under en förälders namn står dess egen väg från roten, som i mockupen. Vägarna räknas i minnet ur samma kanter som kartan redan läser, och de kostar inga nya frågor.

**10. Filtret gäller kartan.** Filterraden från listan och trädet ritas också i kartläget. Med ett aktivt filter visar en träfflista bredvid kartan de träffar som `ListItems` ger, högst tjugo. Därefter kommer en länk till listläget med samma filter. Ett klick på en träff gör den till mitten, och filtret står kvar i adressen. **Filtret väljer mitten men gömmer aldrig något runt den.** Mittens grannar ritas oavsett om de är träffar, eftersom en karta som gömde en förälder för att den inte matchade hade visat en struktur som inte finns.

**11. Kartan stannar i containern.** En relation kan inte gå mellan två containrar (`LinkItems`), så varje nod går att centrera i samma flik. Om det ska ändras är en öppen fråga i [[Tankar]].

## Motivering

En mitt och en ring har samma övre gräns som *en öppen gren per nivå*: det som ritas beror på taket och inte på containerns storlek. Därför behövs varken grafbibliotek eller layoutalgoritm, och ADR-0046:s argument mot en kraftbaserad graf gäller fortfarande.

Mitten löser det kolumnerna inte kunde. Filtret får en uppgift, nämligen att välja mitten, och de relaterade får en plats i containerns vy utan att man först öppnar itemet.

Vägen ovanför kartan ersätter kolumnerna som svar på frågan *var är jag*. Bakåtknappen är redan spåret av var man har varit, och därför bärs inget eget spår i adressen.

## Konsekvenser

- Issue 296–299 i [[M34 Kartan med en mitt]].
- `ResolveItemMap` byter svar: en mitt och tre grupper i stället för nivåer. Den läser `related`-kanterna i samma fråga som föräldrakanterna, men de påverkar aldrig rotregeln eller platsräkningen.
- `ContainerMap.vue` och `ContainerMapNode.vue` utgår. Strängarna under `item.board` som bara de använder följer med.
- Fokuskartan i itemets högerpanel (`FocusMap`, issue 156) ändras inte. Den har sin egen form för en smal panel, med plus på varje nod.
- Raden *Kartan filtreras inte* i ADR-0058 § 7 och M33:s *Detta ingår inte* gäller inte längre.

## Alternativ

**En fri graf med zoom och layoutval**, som mockupen antyder. Valdes bort av samma skäl som i ADR-0046.

**Ett spår över besökta mittpunkter i adressen.** Valdes bort: webbläsarens bakåtknapp gör redan det, och ett andra spår hade kunnat säga emot den.

**Filtret gömmer grannar som inte är träffar.** Valdes bort: kartan hade då visat en struktur som inte finns.

**Plusmeny på varje nod**, som i högerpanelen. Valdes bort för kartläget: korten blir lugnare, och man skapar oftast utifrån det man står på.
