# ADR-0035 Relationen mellan objekt

**Status:** Antagen 2026-09-17 · Kompletterar [[ADR-0032 Produktens ord]] · [[ADR-index]]

Fattat vid genomgången av den första omgången mockuper. [[ADR-0032 Produktens ord]] satte produktens ordförråd men kom aldrig ned på relationsnivå — den slog fast att *relationer* heter så, inte vad de enskilda relationerna heter.

## Kontext

`item_link.relation` tillåter tre värden: `parent`, `child` och `sibling`. Relationen lagras exakt en gång och motsatsen härleds vid läsning — `parent` skrivs alltid, `child` härleds, och `sibling` normaliseras till lägst `id` först. Se issue 14 § Beslut 4.

De två första namnen beskriver vad de gör. Det tredje gör det inte.

**`sibling` påstår en delad förälder.** Det är vad ordet betyder överallt annars — i familjer, i DOM-träd, i filsystem. Men relationen i Mimers har ingenting med föräldrar att göra: den är en avsiktligt skapad, riktningslös länk mellan två objekt som användaren tycker hör ihop. Bränslefiltret och verktygssatsen är inte syskon i någon mening — de har bara med varandra att göra.

Felet blev synligt i mockuparna. Grafens teckenförklaring visar **fyra** sorters kanter: *Parent*, *Child*, *Syskon* och *Relaterad*. De två sista är samma relation ritad två gånger, därför att den som ritade utgick från namnet i koden och sedan behövde ett till namn för det relationen faktiskt gör. Ett ord som tvingar fram en dubblett i första designutkastet är fel ord.

Samma glapp syns i språkfilerna. `lang/en/ui.php` förklarar åtkomstregeln med *"Sharing a parent item also reaches its child items — siblings share nothing."* Meningen är sann men får förklara sig själv, just därför att läsaren väntar sig att syskon ska ärva något.

## Beslut

**Relationen `sibling` heter `related`.** Värdet byter namn i databasen, inte bara etiketten i gränssnittet.

Relationerna blir därmed:

| Värde | Betydelse | Riktning |
|---|---|---|
| `parent` | objektet hör till ett annat objekt | skrivs |
| `child` | motsatsen till `parent` | härleds |
| `related` | två objekt hör ihop utan hierarki | normaliseras till lägst `id` först |

**Grafens teckenförklaring är *Parent · Child · Related*, tre sorter och inte fyra.** Den fjärde sorten mockuparna visar utgår.

**Normaliseringen och härledningen ändras inte.** Det här är ett namnbyte, inget annat. Ingen regel om hur en relation skrivs, läses eller raderas rörs.

**Åtkomstregeln ändras inte.** Delning når nedåt längs `parent`/`child`; en `related`-länk delar ingenting. Se [[ADR-0028 Åtkomst på itemnivå]].

## Motivering

[[ADR-0032 Produktens ord]] slog fast ett ordförråd med motiveringen att avståndet mellan vad användaren ser och vad utvecklaren läser ska vara noll. Att döpa om etiketten till *Related* och låta `sibling` ligga kvar i databasen vore att återinföra precis det avstånd ADR-0032 tog bort — och på det värsta stället, eftersom en bugganmälan om relationer då måste översättas i båda riktningarna.

Namnbytet är dessutom billigt exakt nu och dyrt senare. Det rör en kolumn med ett CHECK-villkor, nio kodfiler, fyra språkfiler och fem testfiler, och det finns ännu ingen extern användare vars data måste migreras. Om ett år är det samma arbete plus en datamigrering under drift.

Att `related` beskriver relationen bättre är inte en smaksak. `sibling` är ett påstående om strukturen som är falskt: två objekt med en `sibling`-länk kan ha olika föräldrar, ingen förälder alls, eller ligga på olika djup i trädet. Ordet lovar något schemat inte håller.

## Konsekvenser

- **CHECK-villkoret på `item_link.relation` byter värde**, och befintliga rader med `sibling` skrivs om. Migreringen är riktad mot en kolumn.
- **`Container`-sidans kod rörs inte, men åtkomstkoden gör det.** `App\Actions\Access\ResolveItemScope` nämner relationen. Ett namnbyte ändrar ingen logik, men filen ligger i åtkomstlagret — issuen som genomför bytet är därför `risk_class: elevated` enligt [[AGENTS.md]] § De tre axlarna, inte för att bytet är farligt utan för att granskningen ska läsa läslistan.
- **Övriga kodställen** är `ItemLink`, `Item`, `LinkItems`, `ItemLinkController`, `ItemController`, `StoreItemLinkRequest`, `ItemLinkSection.vue` och migreringen.
- **Språkfilerna följer med.** `relation_sibling` i `export.php` och de fyra ställena i `ui.php` byter både nyckel och värde. Det arbetet krockar med [[ADR-0034 Engelska vid lansering]] och ska ligga **efter** omskrivningen av `lang/`, inte parallellt — annars skrivs samma rader två gånger.
- **Testerna som påstår något om relationen följer med i samma ändring**: `ItemRelationTest`, `ItemrelationvyTest`, `OmfangsupplosningTest`, `ItemgrindTest` och `ItematkomstTest`.
- **Meningen om delning kan skrivas rakare.** *"Sharing a parent item also reaches its child items — related items share nothing"* behöver ingen bortförklaring.
- **Länkar mellan objekt i olika containrar öppnas inte här.** `item_link` har med flit ingen `container_id`, så schemat tillåter det redan; det som stoppar är åtkomsten, och den frågan hör hemma i en egen ADR mot [[ADR-0028 Åtkomst på itemnivå]].

## Alternativ

**Behålla `sibling` i databasen och visa *Related* i gränssnittet.** Billigast i dag — en radändring i språkfilen. Valdes bort: det återinför avståndet [[ADR-0032 Produktens ord]] tog bort, och priset betalas varje gång någon läser en bugganmälan eller skriver en ny vy.

**Införa `related` som en fjärde relation vid sidan av `sibling`.** Hade följt mockupens teckenförklaring bokstavligt. Valdes bort — de två skulle betyda exakt samma sak, och två namn för en relation är precis det problem ADR-0032 finns till för att lösa.

**Låta det vara.** Valdes bort — ordet har redan kostat ett designutkast en dubblett, och det är det billigaste felet det kommer att orsaka.
