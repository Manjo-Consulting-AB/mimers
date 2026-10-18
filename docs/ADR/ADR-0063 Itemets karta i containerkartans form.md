# ADR-0063 Itemets karta i containerkartans form

**Status:** Antagen 2026-10-18 · Ersätter fokuskartans form från issue 156 ([[M23 Mobilen och kartan]] § 156): `FocusMap` och `FocusMapNode` tas bort · Ändrar [[ADR-0061 Kartans kort och kanter]] § Konsekvenser och [[ADR-0062 Kartans luft, listans ruta och den löpande sökningen]] § Konsekvenser, där `FocusMap` skulle stå kvar · [[ADR-index]]

Tonys beslut 2026-10-18, efter [[M36 Kartans luft, listans ruta och sökningen]].

## Kontext

Kartan finns på två ställen, och de ser olika ut.

- **Containerns karta** (`ItemMap`, [[ADR-0059 Kartan med en mitt]], [[ADR-0061 Kartans kort och kanter]] och [[ADR-0062 Kartans luft, listans ruta och den löpande sökningen]]) har kort med ikonen till vänster, slagens färger, plusmenyn i mittkortet, en teckenförklaring med antal och böjda linjer från `md:`.
- **Fokuskartan på itemets sida** (`FocusMap`, issue 156) har små rutor på 80 px med centrerad text, en plusknapp under varje nod, andra prickfärger (*Related* i `warning`) och en teckenförklaring utan antal. Den står i högerpanelen från `md:` och som läget *Focus* i relationsfliken under `md:`.

Tony vill att användaren ska känna igen sig i kartan och lätt få en överblick över de items som är kopplade. Två former av samma karta gör det svårare.

Containerkartans fulla layout går inte att flytta in i högerpanelen. Med en relaterad på varje sida behöver den minst 704 px (mittkortet 224 px, två kort på 192 px och två mellanrum på 48 px). Högerpanelen är 288–384 px (`minmax(18rem,24rem)` i `Show.vue`). Containerkartan har redan en smal form under `md:`: korten står i en kolumn, med föräldrarna, mitten, barnen och sist de relaterade, och utan linjer.

## Beslut

**1. Itemets karta är `ItemMap` i sin smala form.** `ItemMap` får proppen `compact`. Med `compact` ritas den smala formen på alla bredder: inga sidokolumner, inget SVG-lager och de relaterade under barnen. Det är samma form som containerkartan har under `md:`. Kort, färger, mittkort, plusmeny och teckenförklaring är desamma som i containerkartan. Högerpanelen (`ItemMapPanel`) och relationsflikens läge *Focus* ritar båda `ItemMap` med `compact`. `FocusMap.vue` och `FocusMapNode.vue` tas bort.

**2. Datan är `ResolveItemMap` med itemet som mitt.** Itemets sida skickar `ResolveItemMap::handle($user, $container, $item->ulid)` i samma form som containerns kartläge: `center`, `container`, `path`, `parent`, `child`, `related` och `rows`. Med svaret följer statusen för de ritade noderna ur `ItemStatus::forItems()`. `rows` är mittens menyrader ur `CreateTarget::forNode()` med sidans `?path=`, så att *Item under* hamnar i den förekomst man står i, som i dag. Kartan kostar då fyra fasta frågor till på itemets sida: två för upplösningen och två för statusen. De är konstanta oavsett antalet grannar. Det ändrar issue 156:s regel att fokuskartan inte ställer någon egen fråga. En karta med samma noder, samma tak och samma `total` som containerns är värd fyra frågor.

**3. En nod öppnar itemets sida.** I den smala formen på itemets sida leder kortet till nodens sida (`/containers/{c}/items/{ulid}`), som fokuskartans noder gör i dag. På itemets sida är det samma sak som att göra noden till mitten. Kortet har därför inget öga. Mitten är sidan man står på och är ingen länk. Vägen till mitten ritas inte, eftersom sidan redan har sin brödsmula.

**4. Bara mittkortet har plusmenyn.** Plustecknet sitter i mittkortets nedre högra hörn, som i containerkartan (ADR-0062 § 5). Varje nod hade en egen plusmeny i fokuskartan, och den tas bort. Det man vill skapa under en granne skapar man på grannens sida, ett klick bort.

**5. Den smala formen har egna tak.** Panelen ska ge en överblick och inte bli en lång lista. Med `compact` ritas högst 4 föräldrar, 4 barn och 4 relaterade, i serverns ordning. Resten blir *+N till*, räknat ur gruppens `total`. Containerkartans tak (4, 12 och 6) gäller som förut utan `compact`.

**6. Hela kartan är en länk bort.** Under teckenförklaringen står länken *Open in map*. Den leder till containerns kartläge med itemet som mitt (`?view=map&focus=<ulid>`), där de relaterade står på sidorna och linjerna ritas. *+N till* leder dit också och inte längre till relationsfliken: hela kartan visar fler noder och har dessutom sina +N-länkar till relationsfliken.

## Motivering

En form av kartan i stället för två gör att det man lär sig på ett ställe gäller på det andra. Kortet, färgen på slaget och plustecknet i mitten ser likadana ut överallt.

Den smala formen finns redan och är redan testad. Att låta den gälla på alla bredder med en propp är mindre än att bygga en tredje layout för panelen.

Fokuskartans regel att ingen egen fråga ställs gällde så länge den bara ritade relationerna. Containerkartans noder bär mer: `total`, `placements`, `via` och statusen. Fyra fasta frågor ger samma svar på båda ytorna i stället för två olika läsningar av samma grannar.

## Konsekvenser

- Issue 312 och 313 i [[M37 Itemets karta]].
- `ItemController::show()` skickar `itemMap` och `itemMapStatuses`. Den gamla proppen `map`, `map()` och `nodeRows()` tas bort när ingen yta läser dem längre.
- `ItemMap.vue` får `compact`. `ItemMapPanel.vue` och `ItemLinkSection.vue` ritar den. `lang/en/ui.php` får `item.map.open_full`.
- `ItemMapPanel` får propparna `statuses` och `containerUlid`, eftersom `ItemMap` behöver dem. Det är data och ingen layoutfråga, så issue 181 § Beslut 3 gäller som förut.
- *Related* får prickfärgen `ink-subtle` även på itemets sida, som linjernas färg i ADR-0061 § 5.

## Alternativ

**Containerkartans fulla layout i högerpanelen.** Valdes bort: den behöver dubbla panelens bredd.

**Kartan som egen bred flik på itemets sida.** Valdes bort: den ändrar trepanelslayouten ([[ADR-0041 Itemets vy]]) och ger mest arbete. Länken till hela kartan ger samma sak med ett klick.

**Bara en länk till hela kartan, fokuskartan oförändrad.** Valdes bort: de två formerna stod kvar, och det var det Tony ville bort ifrån.

**Behålla plusmenyn på varje nod.** Valdes bort enligt beslut 4.
