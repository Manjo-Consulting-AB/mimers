# ADR-0088 Versionshistorik för items

**Status:** Antagen 2026-11-18 · Bygger vidare på [[ADR-0008 Soft delete och papperskorg]], [[ADR-0028 Åtkomst på itemnivå]], [[ADR-0081 All lagrad data räknas mot kvoten]], [[ADR-0066 Itemets anteckning]] och [[ADR-0087 MCP-server för agenter]] · [[ADR-index]]

Tonys beslut 2026-11-18, efter en diskussion samma dag. Tony antog riktningarna ("vi kör helt efter dina förslag"). Det som står som *utformad av Claude* är detaljer som Claude satte i samma diskussion och som Tony godkände uttryckligen ("jag godkänner dina detaljer"). De är beslutade och inga förslag.

## Kontext

- Papperskorgen skyddar mot radering men inte mot överskrivning. Skriver någon över ett items beskrivning eller anteckning finns ingen tidigare version. `UpdateItem` loggar bara fältens namn, aldrig innehållet, så att loggen inte blir ett andra register över vad användaren skrivit ([[ADR-0043 Tre loggar]]).
- [[ADR-0087 MCP-server för agenter]] lämnade frågan öppen (fråga 1b och Riktning 9 § Kvar): en agent kan skriva över fritext, ersätta taggar och ta bort relationer utan att något går att återställa.
- Versioner räddar ett misstag. De räddar inte mot den som med avsikt skriver över tio gånger i följd. Riktning 4 tar udden av det fallet.

## Beslut

### Riktning 1, kontot sätter taket och containern kan sänka det (Tony 2026-11-18)

- *Kontot.* `account.versioning_max_versions`, ett heltal 0–20. 0 betyder av. Standardvärdet är **3**, även för befintliga konton, så att skyddet finns innan misstaget sker (Tony 2026-11-18). Inställningen sätts på `/settings/storage`.
- *Containern.* `container.versioning_max_versions`, NULL eller ett heltal. NULL följer kontot. 0 stänger av versionshistoriken i containern. Ett tal får aldrig överstiga kontots värde. Det valideras när det sparas.
- *Det som gäller räknas fram vid varje skrivning:* 0 om något av värdena är 0, annars det lägsta av kontots och containerns. Inget värde skjuts ut eller kopieras till containrarna. Därför gäller en ändring på kontot från samma ögonblick, även för containrar som skapas senare. Höjs kontots tak följer containrar utan eget värde med, och containrar med ett lägre eget värde behåller det.
- *Ägaren styr.* Det är containerns ägare som har versionshistoriken. Containrar man är inbjuden till följer ägarens konto, och bara medlemmar i ägarkontot ändrar containerns värde, med samma grind som `ContainerPolicy::delete`.
- *Övre gränsen 20* ligger i config (`versioning.max_versions_limit`, utformad av Claude).

### Riktning 2, vad en version är (Tony 2026-11-18, utformad av Claude)

- En version är en ögonblicksbild av itemets egna fält **före** en ändring: `name`, `description`, `notes`, `manufacturer`, `model`, `serial_number`, `position_note`, `purchased_at`, `warranty_until`, `category_id`, `cover_attachment_id`, `icon`, `icon_color` och taggmängden.
- **Filer versioneras inte.** En fil är oföränderlig och deduplicerad ([[Filer och lagring]]). Den kan inte skrivas över, bara tas bort, och det fångar papperskorgen. Versionen pekar på omslagsbilden och på taggarna med id, och bär ingen fil.
- Scheman, kostnader, lån, bilagor och relationer versioneras inte i första steget. Relationer som tas bort (`UnlinkItems`, hård radering) står kvar som en öppen punkt i [[Tankar]].

### Riktning 3, ägaren betalar (Tony 2026-11-18)

- Versionerna räknas i `usage_counter.metadata_bytes` med samma mått som itemraden ([[ADR-0081 All lagrad data räknas mot kvoten]] § 2), och belastar **containerns ägare**: `item_version.billed_account_id` sätts till `container.account_id` när versionen skapas. Det är ett undantag från regeln att raden belastar den som skapade den, på samma sätt som containerraden. Versionshistoriken är ägarens val, så det är ägaren som betalar. Den inbjudnas egen skrivning belastar fortfarande den inbjudna.
- *Full kvot stoppar inte skrivningen (utformad av Claude).* Annars skulle en inbjuden straffas för att ägarens kvot är full. Ryms inte versionen tas itemets äldsta versioner bort tills den ryms. Ryms den ändå inte sparas ingen version, och ägaren får notisen `versioning.skipped` en gång per container och dygn.
- Tony 2026-11-18: att ta betalt extra för versionerna valdes bort.

### Riktning 4, skrivningar som kommer tätt blir en version (Tony 2026-11-18)

- Skriver samma aktör samma item flera gånger med högst **10 minuter** mellan skrivningarna sparas bara läget före den första. Aktören är användaren, eller användaren och agenten när en agent skriver ([[ADR-0087 MCP-server för agenter]] Riktning 3). Fönstret glider: varje skrivning i skurven skjuter det framåt. Det är samma arbetspass som anteckningens autosparning har ([[ADR-0066 Itemets anteckning]] § 10).
- En agent i en loop, eller en sabotör som skriver över tio gånger på en minut, förbrukar alltså en plats, och originalet finns kvar. Den som väntar ut fönstret mellan varje överskrivning täcks inte.
- En skrivning från en annan aktör bryter skurven, och en återställning (Riktning 5) bryter alltid.

### Riktning 5, visa och återställa (Tony 2026-11-18)

- *Vem.* Den som får se itemet (`ItemPolicy::view`) ser dess versioner. Den som får ändra itemet (`ItemPolicy::update`, nivå `write`) får återställa en version, enligt [[ADR-0028 Åtkomst på itemnivå]].
- *Var.* Itemets flik *History* får en lista över versionerna, nyast först, med vem som ersatte versionen och när. En version kan öppnas och läsas.
- *En återställning går att ångra.* Den skriver genom `UpdateItem`, så att den loggas och bokförs som en vanlig ändring, och sparar först det nuvarande läget som en ny version.
- *Det som inte längre finns hoppas över (utformad av Claude).* En kategori, tagg eller omslagsbild som har raderats sedan versionen sparades lämnar det nuvarande värdet orört, och svaret säger vilka fält som hoppades över.
- API:et får samma läsning och återställning, och MCP-servern får verktygen `items_versions_list` och `items_versions_restore`.

### Riktning 6, livscykeln (utformad av Claude)

- *Taket hålls vid varje skrivning.* Har itemet fler versioner än det som gäller tas de äldsta bort.
- *Sänks taket eller stängs historiken av* tas överskottet bort direkt av ett jobb, efter en bekräftelsedialog som säger det. Kvoten frigörs därmed.
- *Papperskorgen.* Ett slängt item behåller sina versioner, som räknas tills itemet raderas för gott, och följer med vid återställning. När itemet raderas för gott raderas versionerna med det.
- *Ägarbyte rensar historiken (Tony 2026-11-18).* Versionerna raderas i ägarbytets transaktion, som papperskorgen ([[ADR-0008 Soft delete och papperskorg]] § Uppföljning 2026-11-11). Annars ärver köparen det säljaren har rättat bort.
- *Rättslig spärr.* Under en spärr på ägarkontot raderas inga versioner, varken av taket, av ett sänkt värde eller av en full kvot. Listan visar bara så många som taket tillåter. Allt annat fungerar som vanligt, så att spärren inte syns ([[ADR-0043 Tre loggar]] § Rättslig spärr).
- *Export* bär inte versionerna i första steget.

## Konsekvenser

- Ny tabell `item_version`, två nya kolumner (`account.versioning_max_versions`, `container.versioning_max_versions`) och en ny räknad tabell i `MetadataSize` och `CountedRows`, med `billed_account_id` som betalare.
- ADR-0087 fråga 1b är besvarad för fritext och taggar. Relationer som tas bort står kvar som öppen punkt i [[Tankar]].
- Byggs i [[M67 Versionshistorik]]: 411 inställningarna och tabellen, 412 att spara versioner, 413 att visa och återställa, 414 livscykeln och 415 agenterna.

## Alternativ som valts bort

- *En agent får bara lägga till text, aldrig skriva över* (förslaget i `Tankar.md`). Skyddar inte mot användarens egna misstag och gör agenten mycket mindre användbar.
- *Filer i varje version, debiterade per version.* En manual på 100 MB hade kostat 600 MB för fem rättade beskrivningar. Valdes bort av Tony 2026-11-18.
- *Inställningen kopieras ut till varje container.* Kräver ett jobb vid varje ändring och missar containrar som skapas senare. Ersatt av ett värde som räknas fram vid skrivningen (Riktning 1).
- *Opt-in.* Skyddar bara den som slog på det före misstaget. Valdes bort av Tony 2026-11-18 till förmån för 3 som standard.

## Om att ändra ett beslut

Radera aldrig en ADR. Sätt `Status: Ersatt av ADR-XXXX` och skriv en ny.
