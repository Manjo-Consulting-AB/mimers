# M46 · Flytta ett item

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet. Läs dem en gång, inte per issue.

Tillagd 2026-10-31, efter feedback från en användare. Det här ingår: ett item flyttas med sitt underträd till toppnivån eller under ett annat item i samma container, i en transaktion, och itemets sida får *Move* i ⋯-menyn. Besluten står i [[ADR-0069 Flytta ett item inom containern]].

**Ordningen:** 345 bygger på 344.

**Byggs inte av kön.** Ingen issue i milstolpen bär etiketten `Build`.

**Releasen:** en release när milstolpen är stängd.

**Detta ingår inte:** att lägga in ett item mellan en förälder och dess barn i ett steg (ADR-0069 § 4), att flytta ett item till en annan container, dra och släpp i trädet och kartan, en varning om att flytten ändrar vem som ser underträdet (ADR-0069 § Konsekvenser), `LinkItems`, `UnlinkItems` och relationsfliken.

---

### 344. Flytten på servern

GitHub #1011. **Actionen.** `App\Actions\Item\MoveItem::handle(Item $item, ?Item $toParent, ?Item $fromParent, User $actor)` byter kanten `from_parent → item` mot `to_parent → item` i en transaktion ([[ADR-0069 Flytta ett item inom containern]] § 1). `to_parent = null` betyder toppnivån. Underträdet följer med utan att något görs för det (§ 2). Har itemet en förälder eller ingen och `fromParent` saknas, härleds den. Har det flera krävs den (§ 3).

**Reglerna** prövas i ordningen i § 5, mot grafen efter flytten. Föräldrakanterna hämtas i en fråga och vandras i PHP, som `LinkItems::loadParentsByChild()` och `wouldCreateCycle()`. Ingen `WITH RECURSIVE`, eftersom testsviten kör sqlite. Felen är `item_move.from_parent_required`, `item_move.not_a_parent`, `item_move.same_parent`, `item_move.cycle`, `item_move.already_child` och `item_move.pair_exists`, alla 422 med `data` enligt § 5. `LinkItems` rörs inte. Behövs dess hjälpare, kopiera dem och skriv i PR:en att de finns på två ställen.

**Loggen.** `AuditLog::ACTION_ITEM_MOVED = 'item.moved'`, skriven i actionens transaktion med itemet som `item_id` och `meta` `{from, to}`, där värdena är förälderns ULID eller `null` (§ 8). Flytten skriver inte `item_link.created` och inte `item_link.deleted`. Historikens ord för `item.moved` läggs bredvid de andra i `lang/en/ui.php`: `:user moved :item`.

**Rutterna** (§ 9). Båda prövar behörigheten enligt § 6 innan actionen anropas: `update` på itemet, `update` på den gamla föräldern när den finns, och `update` på den nya föräldern eller `ContainerPolicy::createItem` för toppnivån. `to_parent` och `from_parent` är ULID:er eller `null`, och ett ULID som inte är ett levande item i rutans container är ett valideringsfel på fältet. `to_parent` är obligatoriskt men får vara `null`.
- `/api`: `POST /api/containers/{container}/items/{item}/move` svarar 204. Felen följer felformatet.
- Webben: `POST /containers/{container}/items/{item}/move`, `containers.items.move`, svarar 302 tillbaka till itemets sida med `status` `item-moved`. Ett `item_move.*`-fel blir ett fel på fältet `to_parent`, översatt med `ApiErrorTranslator` på samma sätt som `ItemLinkController` gör. Översättningarna för de sex koderna läggs i `lang/en/ui.php` bredvid `item_link.*`.

`{item}` binds med `scopeBindings()`, som de andra itemrutterna.

**Läs:** [[ADR-0069 Flytta ett item inom containern]], [[Items och organisation]] § item_link, `app/Actions/Item/LinkItems.php`, `app/Actions/Item/UnlinkItems.php`, `app/Http/Controllers/ItemLinkController.php` (behörigheten och felhanteringen), `app/Http/Controllers/Api/ItemLinkController.php`, `app/Http/Requests/Item/StoreItemLinkRequest.php`, `app/Policies/ItemPolicy.php`, `app/Policies/ContainerPolicy.php` (`createItem()`)

**Klart när:** ett item med barn och barnbarn flyttas under ett annat item och underträdet följer med; ett item flyttas till toppnivån och tillbaka; ett item med två föräldrar flyttar bara den angivna placeringen och behåller den andra; samma item utan `from_parent` ger `item_move.from_parent_required`; en `from_parent` som inte är förälder ger `item_move.not_a_parent`; flytt till samma förälder och från toppnivån till toppnivån ger `item_move.same_parent`; flytt under sig själv, ett barn och ett barnbarn ger `item_move.cycle`; flytt till itemets andra förälder ger `item_move.already_child`; flytt under ett item som är `related` till itemet ger `item_move.pair_exists`; ett `to_parent` i en annan container eller i papperskorgen är ett valideringsfel; ett avvisat försök lämnar `item_link` orörd; en mottagare utan `write` på itemet, den gamla föräldern eller den nya föräldern får 403 och inget ändras; flytten till toppnivån kräver `ContainerPolicy::createItem`; vändningen P → B → A blir P → A → B med två flyttar; att lägga in X mellan P och C går med två flyttar; flytten skriver en rad `item.moved` med `from` och `to` och ingen `item_link`-rad; ett item som flyttas under ett delat item nås av förälderns mottagare och ett som flyttas därifrån nås inte längre; webben svarar med `item-moved` och med felet på `to_parent`; `/api` svarar 204 och felformatet; hela testsviten är grön.

**Beror på:** —

### 345. *Move* på itemets sida

GitHub #1012. **Menyn.** ⋯-menyn i itemets huvud (`ItemActionMenu`) får *Move* före *Move to trash*, bakom `can.update` ([[ADR-0069 Flytta ett item inom containern]] § 11). Posten öppnar `ParentPicker` i ett flyttläge.

**Väljaren i flyttläget.**
- Itemet självt och dess ättlingar går inte att välja. De räknas fram ur trädet väljaren redan har (`structure`), och ingen fråga skickas till servern.
- Den nuvarande föräldern är markerad som nuvarande plats och går inte att välja. Står itemet på toppnivån gäller det containerraden.
- En nod går att välja när den bär `can_update`. Containerraden går att välja med `can_create_root`, som i dag.
- *Move here* skickar `POST` mot `containers.items.move` med `to_parent` och, när itemet har flera föräldrar, `from_parent`.
- Felet från servern visas i väljaren.

Skapandets läge ändras inte.

**Flera föräldrar.** Har itemet mer än en förälder väljer användaren först vilken placering som flyttas, i ett steg före trädet som listar föräldrarna med namn.

**`can_update` per nod.** `ItemController` ger varje nod i strukturen `can_update` (`ItemPolicy::update`) bredvid `can_create`, räknat på samma sätt och utan fler frågor per nod.

**Efter flytten** står användaren kvar på itemets sida, med flashen `item-moved` och vänsterpanelens träd uppdaterat.

**Orden.** *Move*, *Move here*, rubriken för steget med flera föräldrar, markeringen av den nuvarande platsen och flashen `item-moved` läggs i `lang/en/ui.php`.

**Läs:** [[ADR-0069 Flytta ett item inom containern]] § 3 och 11, [[ADR-0068 Itemets sida och skapandet]] § 3, `resources/js/components/ParentPicker.vue`, `resources/js/components/ItemStructureTree.vue` (pick-läget), `resources/js/components/ItemActionMenu.vue`, `resources/js/pages/Containers/Items/Show.vue` (menyn och `structure`), `app/Http/Controllers/ItemController.php` (strukturens noder och `can_create`)

**Klart när:** ⋯-menyn visar *Move* med `can.update` och inte utan; väljaren öppnas i flyttläget; itemet och dess ättlingar går inte att välja; den nuvarande föräldern är markerad och går inte att välja; en nod utan `can_update` går inte att välja; containerraden går att välja bara med `can_create_root`; *Move here* flyttar itemet och sidan visar `item-moved`; ett item med två föräldrar frågar först vilken placering som flyttas och skickar `from_parent`; ett fel från servern visas i väljaren; strukturens noder bär `can_update`; skapandets väljare fungerar som förut; hela testsviten är grön.

**Beror på:** 344
