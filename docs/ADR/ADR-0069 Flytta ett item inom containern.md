# ADR-0069 Flytta ett item inom containern

**Status:** Antagen 2026-10-31 · Bygger vidare på [[ADR-0028 Åtkomst på itemnivå]] och [[ADR-0035 Relationen mellan objekt]] · Kompletterar [[ADR-0053 Flytt och kopiering]], som flyttar bilagor och uppgifter men inte items · [[ADR-index]]

Tonys beslut 2026-10-31, efter feedback från en användare.

## Kontext

En användare kan inte flytta ett item. Strukturen ligger i `item_link`: en rad med `relation = parent` säger att `from` är förälder till `to`. Ett item kan ha flera föräldrar, så strukturen är en graf utan cykler, inte ett träd. Det enda sättet att ändra den är `LinkItems` och `UnlinkItems`, alltså en kant i taget.

Det går därför att flytta ett item genom att först lösa upp länken till den gamla föräldern och sedan skapa en länk till den nya, men det har tre brister:

- **Det är två anrop utan gemensam transaktion.** Mellan dem står itemet på toppnivån och har tappat den åtkomst det ärvde ([[ADR-0028 Åtkomst på itemnivå]] regel 1).
- **Reglerna prövas mot grafen före ändringen.** `LinkItems` vet inte att en kant ska bort, så det som är tillåtet efteråt kan avvisas i mellanläget.
- **Gränssnittet har ingen flytt.** `ParentPicker` används bara när ett item skapas.

Användaren bad också om att kunna lägga in ett item mellan en förälder och dess barn, och om att kunna vända ett par så att barnet blir förälder. Båda går att göra med två flyttar i rad, se § 4.

## Beslut

**1. En flytt byter en placering.** Kanten `from_parent → item` tas bort och kanten `to_parent → item` skapas, i en transaktion. `to_parent = null` betyder toppnivån i containern. Inget annat ändras.

**2. Underträdet följer med.** Itemets barn sitter på itemets egna kanter, så de följer med utan att något görs för dem. Bilagor, uppgifter, kostnader, utlån, taggar, kategori och `related`-länkar stannar på itemet.

**3. Ett item med flera föräldrar flyttar en placering i taget.** `from_parent` anger vilken placering som flyttas. Fältet krävs när itemet har mer än en förälder. Har itemet en förälder eller ingen härleds det. Itemets övriga placeringar ändras inte.

**4. Att lägga in mellan och att vända är två flyttar.** Det finns ingen egen operation för någon av dem.
- **Lägga in X mellan P och C:** skapa X under P (eller flytta dit ett befintligt X), och flytta sedan C under X.
- **Vända P → B → A till P → A → B:** flytta A till P, och flytta sedan B under A. Den andra flytten prövas mot grafen efter den första, så den är ingen cykel.

**5. Reglerna prövas mot grafen efter flytten, i den här ordningen.** Föräldrakanterna hämtas i en fråga och prövas i minnet, på samma sätt som i `LinkItems` och `MoveCategory`.

| # | Regel | Fel (422) |
|---|---|---|
| 1 | `to_parent` finns, är inte mjukraderat och ligger i samma container | valideringsfel på `to_parent` |
| 2 | `from_parent` anges när itemet har flera föräldrar | `item_move.from_parent_required` |
| 3 | En angiven `from_parent` är verkligen förälder till itemet | `item_move.not_a_parent` |
| 4 | Den nya placeringen skiljer sig från den gamla | `item_move.same_parent` |
| 5 | `to_parent` är varken itemet självt eller en av dess ättlingar | `item_move.cycle` |
| 6 | `to_parent` är inte redan en av itemets andra föräldrar | `item_move.already_child` |
| 7 | Itemet och `to_parent` har ingen annan relation, till exempel `related` | `item_move.pair_exists` |

Felen följer `AGENTS.md` § Felformat i API:et. `data` bär de ULID:er klienten behöver för att formulera meddelandet: `item` och `to` i regel 5–7, `item` i regel 2–3 och `item` och `parent` i regel 4.

Regel 6 och 7 avvisar i stället för att göra något åt användaren. I regel 6 skulle flytten bara ta bort en placering, och den borttagningen görs på relationsfliken. I regel 7 skulle flytten tyst ta bort en relation användaren inte bad om att ta bort. Utan regel 6 hade flytten dessutom slagit i unikhetsindexet på `item_link` och gett 500 i stället för 422.

**6. Behörigheten är den som redan gäller för länkar.** En flytt ändrar länkar, och att ändra en länk kräver `write` i båda ändar ([[ADR-0028 Åtkomst på itemnivå]], [[Items och organisation]] § item_link). Därför krävs:
- `ItemPolicy::update` på itemet
- `ItemPolicy::update` på den gamla föräldern, när det finns en
- `ItemPolicy::update` på den nya föräldern, eller `ContainerPolicy::createItem` när itemet flyttas till toppnivån

Saknas något svarar rutten 403. Ingen ny behörighetslogik införs.

**7. En flytt ändrar vem som ser underträdet, och det är avsiktligt.** Åtkomst ärvs nedåt längs `parent` ([[ADR-0028 Åtkomst på itemnivå]] regel 1). Den som flyttar ett item under en delad förälder delar underträdet med förälderns mottagare, och den som flyttar det därifrån tar bort den ärvda åtkomsten. Det är samma effekt som att skapa eller ta bort en länk i dag. Någon varning i gränssnittet ingår inte här, se § Konsekvenser.

**8. Loggen får en rad.** `item.moved` skrivs i containerns logg med itemet som `item_id` och `meta` `{from, to}`, där varje värde är en förälders ULID eller `null` för toppnivån. Flytten skriver inte `item_link.created` och `item_link.deleted`. Det hade blivit två rader för en handling, och den första hade beskrivit ett mellanläge som aldrig syntes.

**9. En action, två rutter.** `App\Actions\Item\MoveItem` äger transaktionen och reglerna. Webben och `/api` anropar den, var och en med sin egen rutt:
- `/api`: `POST /api/containers/{container}/items/{item}/move` med JSON-kroppen `{to_parent, from_parent?}`. Svaret är 204 utan kropp.
- Webben: `POST /containers/{container}/items/{item}/move`, med namnet `containers.items.move`. Svaret är 302 tillbaka till itemets sida med `status` `item-moved`, eller tillbaka med felet på fältet `to_parent`.

**10. Schemat ändras inte.** Ingen kolumn och ingen migration behövs. Trädet sorteras efter namn, så det finns ingen position bland syskonen att flytta.

**11. Gränssnittet är `ParentPicker` i ett flyttläge.** ⋯-menyn i itemets huvud ([[ADR-0068 Itemets sida och skapandet]] § 3) får *Move*, bakom `can.update`. Posten öppnar `ParentPicker`. I flyttläget gäller följande:
- Itemet självt och dess ättlingar går inte att välja.
- Den nuvarande föräldern är markerad och går inte att välja.
- En nod går att välja när användaren får ändra den (`can_update`).
- Containerraden betyder toppnivån och går att välja med `can_create_root`, som i dag.
- Har itemet flera föräldrar väljer användaren först vilken placering som ska flyttas.

Väljaren är en hjälp, och servern prövar ändå varje regel.

## Motivering

En operation som byter en kant i taget räcker för att bygga alla tre önskemålen, och den är enkel att pröva: en regel per rad i § 5, och varje regel mot ett slutläge som går att räkna fram. En operation som även kunde lägga in ett item mellan två andra (ett fält `adopt`) skulle spara ett steg för användaren, men den behöver ytterligare regler och ytterligare behörighetsprövningar och har ett mellanläge som är svårare att förklara. Den kan läggas till senare utan att det här beslutet ändras.

Att flytten avvisar krockarna i § 5 regel 6 och 7 håller den till en sak. En flytt som ibland tar bort en relation eller en placering är svårare att förstå än ett fel som säger vad som står i vägen.

## Konsekvenser

- Issue 344–345 i [[M46 Flytta ett item]].
- `AuditLog` får typen `item.moved`. Historikflikarna och den globala historiken behöver ordet för den.
- Felkoderna `item_move.*` får översättningar i `lang/en/ui.php`, så att webben kan visa dem.
- `ItemController` ger varje nod i strukturen `can_update` utöver `can_create`.
- **Öppen fråga:** ska flytten varna när den ändrar vem som ser underträdet (§ 7)? En sådan varning får inte avslöja det [[ADR-0028 Åtkomst på itemnivå]] säger att vyn inte får avslöja, till exempel hur mycket en mottagare inte når. Frågan står i [[Tankar]].
- Att flytta ett item till en annan container ingår inte. Det hänger ihop med frågan om relationer mellan containrar i [[Tankar]] och med [[ADR-0053 Flytt och kopiering]].

## Alternativ

**En generell operation med `adopt`, som också lägger in ett item mellan förälder och barn.** Valdes bort tills vidare, se § Motivering. Användaren gör samma sak med två flyttar (§ 4).

**Låta klienten anropa `UnlinkItems` och `LinkItems` i följd.** Valdes bort. Det är två anrop utan transaktion, med ett mellanläge där itemet har tappat sin ärvda åtkomst, och reglerna prövas mot fel graf.

**Byta ut `related` mot `parent` när paret redan har en relation.** Valdes bort. Flytten skulle tyst ta bort en relation användaren inte bad om att ta bort.

**Behandla en flytt till en befintlig förälder som en borttagning av den gamla placeringen.** Valdes bort. Det är inte en flytt, och borttagningen har redan en plats på relationsfliken.

**Kräva `create` i stället för `write` på den nya föräldern, som när ett barn skapas.** Valdes bort. En flytt flyttar in ett befintligt underträd och utvidgar därmed förälderns delning, och den som ändrar en länk ska ha `write` i båda ändar.
