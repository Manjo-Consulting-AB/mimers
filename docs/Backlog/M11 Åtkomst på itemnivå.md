# M11 · Åtkomst på itemnivå

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

Tillagd 2026-09-05. Hela milstolpen genomför [[ADR-0028 Åtkomst på itemnivå]] — **läs den först**, den är den enda källan till varför något ser ut som det gör här.

**Numret är högre än M10 men arbetet kommer före — för sex av dess issues.** 55, 57, 58, 59, 62 och 67 bygger vyer för delning, items, relationer, sök, papperskorg och export. Byggs de mot tvånivåmodellen får de göras om, och de har därför fått beroenden hit. Övriga M10-issues rörs inte; milstolpen är ren backend.

**Detta är i huvudsak rättning av befintlig kod, inte nybygge.** Behörighetsmodellen från issue 9 och grindarna från 13a, 14 och 16 antar alla att den som når containern når allt i den. Räkna med att röra `ContainerPolicy`, `ItemController`, `AttachmentController`, `ItemLinkController`, `ItemSearchController` och `Container::scopeAccessibleBy()`.

### 69. Laddern och migreringen
`container_access.level` går från `read`|`write` till fyra steg: `read` < `create` < `write` < `delete`. Ny kolumn `container_access.item_id` (FK → item, NULL) och samma kolumn på `invitation`, plus index `(item_id, revoked_at)`. Datamigrering: befintliga `read` står kvar, befintliga `write` blir **`delete`** — de kan i dag radera items och bilagor, och ska behålla det. `create` och `write` börjar utan innehavare.

`ContainerPolicy::hasContainerAccess()` tar i dag `list<'read'|'write'>` och matchar med `whereIn('level', $levels)`. Den blir en jämförelse mot ladderns ordning — en `>=` mot ett minimikrav, inte en mängdmatchning. Ingen grind ändrar beteende i den här issuen; `item_id` skrivs ännu aldrig.
**Läs:** [[ADR-0028 Åtkomst på itemnivå]] § Beslut, [[Konton och åtkomst]] § container_access
**Klart när:** hela befintliga testsviten är grön utan ändrad förväntan på behörighet, en migrering fram och tillbaka lämnar `level` intakt, och ett test visar att en migrerad `write`-innehavare fortfarande kan radera ett item.
**Beror på:** 9

### 70. Omfångsupplösning och ItemPolicy
En Action som för en given användare och container returnerar avbildningen item → nivå: container-breda grants gäller alla items, item-grants gäller sitt item och dess ättlingar via `item_link` `parent`/`child`, transitivt och aldrig uppåt. `sibling` bär ingen behörighet. Når flera grants samma item vinner den högsta nivån — en max-operation, inte en ordningsberoende regel. Slutningen är en rekursiv CTE; cykelkontrollen från issue 14 gör den säker, men skriv ändå ett test med en manipulerad cykel i databasen.

Ny `ItemPolicy` med `view`, `create`, `update` och `delete`. Issue 13a § Beslut 2 valde medvetet bort en egen policy och lånade `ContainerPolicy::view()`/`update()`; det valet upphör här. `ContainerPolicy::create()` kan inte återanvändas — den tar en `Account` och gäller att skapa containers.
**Läs:** [[ADR-0028 Åtkomst på itemnivå]] § Beslut, [[Items och organisation]] § item_link, [[ADR-0024 Tunna controllers och actions]]
**Klart när:** `read` på motorn och `write` på impellern ger RW på impellern och R på motorn; ett `sibling`-syskon ärver ingenting; ett nytt barn-item omfattas utan ny grant; och upplösningen kostar ett konstant antal frågor oavsett hur många items containern har.
**Beror på:** 69, 14

### 71. Grindarna för items, bilagor och relationer
`ItemController::store()` går från `update` till `create`, `destroy()` från `update` till `delete`, `update()` blir smalare. Samma tre byten i `AttachmentController` — `store()` på rad 49 och `destroy()` på rad 134 delar i dag grind med `PATCH`. `ItemLinkController` kräver `write` **i båda ändar**: motparten slås redan upp inom containern i `destroy()`, och samma kontroll måste gälla vid skapande, annars kan en mottagare länka in ett item hon inte får se.

`delete` betyder mjukradering och återställning inom eget omfång. Fysisk gallring förblir ägarkontots, se [[ADR-0008 Soft delete och papperskorg]].
**Läs:** [[ADR-0028 Åtkomst på itemnivå]] § Konsekvenser, [[Konton och åtkomst]] § Behörighetsregler
**Klart när:** en `create`-mottagare kan ladda upp en bilaga och skapa ett barn-item men får `auth.forbidden` på `PATCH` av ett befintligt item; en `write`-mottagare nekas `DELETE`; och ett `read_only`-konto nekas allt skrivande oavsett nivå.
**Beror på:** 70

### 72. Beviljande och inbjudan av itemåtkomst
API för att bevilja, ändra och återkalla en åtkomst med `item_id` satt, och för att bjuda in till ett item. Bara ägarkontots medlemmar får göra det — regel 3 är oförändrad. Förvaltningsvyn listar itemåtkomster tillsammans med de container-breda och visar hur många items en grant faktiskt når, så att ägaren ser att "motorn" betyder fyra items. `audit_log` får raderna som förut.

Deltagarlistan räknar en omfångsbegränsad mottagare som deltagare men avslöjar inte vem som har vilket omfång — samma princip som redan gäller för nivåer och utgångsdatum.
**Läs:** [[Konton och åtkomst]] § container_access, § invitation, § Behörighetsregler
**Klart när:** en inbjudan med `item_id` ger efter accept en `container_access`-rad med samma omfång och nivå; två giltiga rader för samma `(container, item, mottagare)` avvisas med felkod; och en `write`-mottagare nekas att bevilja åtkomst.
**Beror på:** 70, 10

### 73. Filtrering av listning, sök, taggar och kategorier
Läckageytan, del ett. Itemlistningen filtreras på omfång. Fritextsöket är den svåra biten: FULLTEXT-indexet är containerbrett, så träffar måste efterfiltreras och **paginering räknas efter filtreringen, inte före** — annars läcker totalsumman antalet dolda items. `Container::scopeAccessibleBy()` bär toppnivårutten `GET /items` (issue 15b § Beslut 4) och måste bli omfångsmedveten. Taggmoln och kategoriträd räknas per omfång; en tagg med noll synliga träffar visas inte alls, eftersom den annars avslöjar att något finns där. En `item_link` till ett item utanför omfånget döljs helt, inte som ett namnlöst spöke.
**Läs:** [[ADR-0028 Åtkomst på itemnivå]] § Konsekvenser, [[Items och organisation]] § Sök och filtrering
**Klart när:** en mottagare med `read` på ett enda item ser exakt ett item i listningen, får noll träffar på ett sökord som bara finns i ett dolt item, ser bara det itemets taggar, och inte kan sluta sig till antalet dolda items ur någon svarsheader eller paginering.
**Beror på:** 70

### 74. Filtrering av papperskorg, kostnadsrapport, todo och export
Läckageytan, del två — alla aggregat som i dag räknar per container. Papperskorgen (issue 20a) är en containervy och blir annars ett fönster in i allt som någon gång raderats i pärmen; den filtreras på omfång, och `delete`-mottagaren får återställa det hon själv når. Kostnadsrapporten (issue 46) summerar bara synliga items. Todo-listan (issue 24) listar bara förekomster på synliga items. Exporten (issue 41) tar med exakt det mottagaren når.
**Läs:** [[ADR-0028 Åtkomst på itemnivå]] § Konsekvenser, [[ADR-0008 Soft delete och papperskorg]]
**Klart när:** en omfångsbegränsad mottagares kostnadsrapport summerar samma belopp som hennes egen itemlistning, hennes papperskorg innehåller inget hon aldrig kunnat se, och hennes export går att öppna utan spår av containerns övriga innehåll.
**Beror på:** 70, 73

### 75. Notisgeneratorerna respekterar omfång
Generatorerna från M5 skickar i dag till alla med åtkomst till containern — och till ingen utanför ägarkontot alls, eftersom 34b:s kontogrind stänger ute varje delegerad mottagare. Uppgiftsnotisen byter grind: den går till den som kan bocka av uppgiften, alltså den som når itemet på minst `write`, ättlingarna inräknade. En mottagare med bara `read` får ingen uppgiftsnotis — uppgiften syns i hennes todo-lista när hon loggar in. Kvotvarningar rör kontot och inte pärmen och går aldrig till en delegerad mottagare, oavsett omfång; utlåningsnotiserna likaså. Kontonivåns notiser (fakturering, nedgradering) är oförändrade.
**Läs:** [[Notiser]] § Vem får en uppgiftsnotis, [[ADR-0028 Åtkomst på itemnivå]] § Konsekvenser (uppföljning 2026-09-13)
**Klart när:** en mottagare med `write` på ett item får notiser om det itemet och dess ättlingar men aldrig en som nämner ett annat items namn, en mottagare med bara `read` får ingen uppgiftsnotis alls, och veckosammanfattningen skickas inte alls till en mottagare vars omfång saknar händelser.
**Beror på:** 70, 34
