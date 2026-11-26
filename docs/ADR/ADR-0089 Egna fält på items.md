# ADR-0089 Egna fält på items

**Status:** Antagen 2026-11-19 · Bygger vidare på [[ADR-0004 Fria taggar och kategorier]], [[ADR-0033 Produktens omfång]], [[ADR-0008 Soft delete och papperskorg]], [[ADR-0081 All lagrad data räknas mot kvoten]] och [[ADR-0087 MCP-server för agenter]] · Ändrar [[ADR-0088 Versionshistorik för items]] § Riktning 2 · Riktning 1 och 2 ändrade av [[ADR-0099 Kryssrutefält]] · Riktning 6 och 8 delvis ersatta av [[ADR-0101 Mallarna]] · Tillägg 2026-11-26: länkar i textfält · [[ADR-index]]

Tonys beslut 2026-11-19, efter en diskussion samma dag. Det som står som *utformad av Claude* är detaljer som Claude satte när ADR:n skrevs och som Tony ännu inte har godkänt uttryckligen. Allt annat är Tonys.

## Kontext

- Ett item har sex fasta fält utöver namn, beskrivning, kategori och taggar: `manufacturer`, `model`, `serial_number`, `purchased_at`, `warranty_until` och `position_note`. De passar en MPPT-regulator men inte en garderob, ett kvitto eller en vinflaska. Sex tomma fält säger användaren att hen fyller i fel sorts sak. Issue 338 fällde ihop dem under *More details*, men de är fortfarande samma sex fält för allt.
- ADR-0004 säger att systemet inte ska veta vad en sak är. De sex fälten är det enda stället där itemet fortfarande antar en domän.
- [[ADR-0088 Versionshistorik för items]] är inte byggd. `item_version` skulle ha fått de sex kolumnerna, och de hade fått migreras bort en gång till. Därför byggs fälten före M67:s issue 412.

## Beslut

### Riktning 1, egna typade fält per container (Tony 2026-11-19)

- Användaren definierar fälten själv på containern: en rubrik och en typ. Fälten gäller alla items i containern. Systemet vet inte vad rubriken betyder.
- Typen är **text**, **tal** eller **datum**. Typen bestämmer vad som får skrivas in och gör att tal och datum går att sortera och filtrera. Rubriken är användarens sak och typen är systemets.
- Namn, beskrivning, anteckning, kategori och taggar är fasta och berörs inte.
- Ett item byter aldrig container ([[Items och organisation]] § cost_entry), så ett värde behöver aldrig flyttas till en container som saknar fältet.

### Riktning 2, fält på ett enskilt item (Tony 2026-11-19)

- Ett item kan få egna fält utöver containerns. De gäller bara det itemet och tas bort med det.
- Ett containerfält kan inte tas bort eller döljas på ett enskilt item. Ett tomt fält visas inte när itemet visas, bara i formuläret. Där kan tomma fält fällas ihop som *More details* gör i dag. Ett uttryckligt *dölj på det här itemet* valdes bort, eftersom ett fält som finns på containern men inte på itemet är svårt att förklara.

### Riktning 3, regler för fälten (Tony 2026-11-19)

- *Typen är låst när fältet har värden.* Ett typbyte på ett fält med minst ett värde avvisas med 422 `field.type_locked`. Utan värden går typen att byta.
- *Ett borttaget fält går till papperskorgen.* Fältdefinitionen räknas som en inställning, som `category` och `tag` ([[ADR-0008 Soft delete och papperskorg]] § Uppföljning 2026-09-26). Värdena följer definitionen: de syns inte medan den ligger i papperskorgen, kommer tillbaka med den och gallras med den. Ett värde som töms är däremot bara borta, som text som suddas ut. Versionshistoriken fångar det.
- *Skrivrätt räcker.* Den som får skriva i containern får definiera, ändra och ta bort containerns fält. Den som får ändra itemet (`ItemPolicy::update`) får definiera itemets egna fält ([[ADR-0028 Åtkomst på itemnivå]]).
- *Kvoten.* Definitioner och värden räknas i `usage_counter.metadata_bytes` som övrig itemdata, enligt [[ADR-0081 All lagrad data räknas mot kvoten]] § 2.

### Riktning 4, gränserna (Tony 2026-11-19, siffrorna föreslagna av Claude och godkända)

Alla gränser ligger i config och prövas i applikationen, inte i schemat. De är desamma för alla planer: det som kostar är lagringen, och den räknas redan mot kvoten.

| Gräns | Värde | Config |
|---|---|---|
| Fält per container | 25 | `fields.max_per_container` |
| Egna fält per item, utöver containerns | 10 | `fields.max_per_item` |
| Ett textvärdes längd | 255 tecken | `fields.text_max_length` |

En skrivning över en gräns avvisas med 422 `field.limit_reached` och `data` som säger vilken gräns och vilket värde. Bara fält som inte ligger i papperskorgen räknas. En återställning ur papperskorgen som skulle gå över gränsen avvisas med samma kod.

### Riktning 5, de sex fälten blir vanliga fält (Tony 2026-11-19)

- `manufacturer`, `model`, `serial_number`, `purchased_at`, `warranty_until` och `position_note` tas bort som kolumner. Det blir ett system i stället för två, och samma regler gäller för *Manufacturer* som för *Liter*.
- *Befintliga containrar* får de sex som containerfält med befintliga värden. Rubrikerna är de engelska som formuläret visar i dag, eftersom engelska är enda språket ([[ADR-0034 Engelska vid lansering]]). Därefter går de att döpa om och ta bort som alla andra fält. Typerna är text, utom `purchased_at` och `warranty_until` som blir datum.
- *Nya containrar* börjar utan fält. Fälten kommer från en mall eller från användaren själv. Det följer [[ADR-0033 Produktens omfång]]: ett förslag är en genväg användaren väljer, aldrig en struktur systemet antar.
- *Expand/contract* (AGENTS.md § Databaskonventioner): värdena kopieras och koden byts i en release, och kolumnerna tas bort i en senare.

### Riktning 6, mallar (Tony 2026-11-19)

- En mall bär **kategorier och fält tillsammans**. Ett mallsystem bara för fält hade fått slås ihop med kategorierna senare.
- *En mall är en kopia, inte en koppling.* Den kopieras in när containern skapas eller senare. Efteråt vet ingenting i containern att den kom från en mall, som för kategoriförslagen i issue 56b. En ändring i mallen slår aldrig igenom i containrar som redan finns. Det skulle krocka med Riktning 3, eftersom typbyten och borttagna fält då hade drabbat containrar som andra skriver i.
- *Namngivna mallar på kontot.* Användaren sparar en containers kategorier och fält som en mall, skapar en ny container från en mall och tillämpar en mall på en befintlig container. Det ersätter förslaget att kopiera fälten direkt från en annan container.
- *Inbyggda förslag i frontenden.* Kategoriuppsättningarna i `resources/js/data/categoryPresets.js` blir inbyggda mallar som också kan bära fält. API:et vet fortfarande inte vad orden betyder (ADR-0004 § Konsekvenser).
- Mallen bär inga items. Den mall med innehåll som [[ADR-0002 Konto äger container]] beskriver med `template_source_id` är en annan sak och står kvar som den är.

### Riktning 7, inget arv (Tony 2026-11-19)

Fält ärvs inte från ett item till dess barn. `parent`/`child` bär redan behörighet ([[ADR-0028 Åtkomst på itemnivå]]). Om relationen också styrde vilka fält som finns skulle det ändra fälten att flytta ett item under en annan förälder, och det är svårt att förutse. Containern är redan den delade nivån.

### Riktning 8, agenterna (Tony 2026-11-19)

- *Värdena följer med de verktyg som finns.* `items_get` returnerar itemets fält med rubrik, typ och värde. `items_create` och `items_update` tar `fields`. `items_search` söker i textvärdena. Riktning 1 i [[ADR-0087 MCP-server för agenter]] varnar för många verktyg, och därför får värdena inga egna.
- *Definitionerna får egna verktyg*, som kategorierna: `fields_list`, `fields_create`, `fields_update` och `fields_delete`. `fields_delete` lägger fältet i papperskorgen och räknas mot raderingstaket (ADR-0087 Riktning 8 och 13).
- *Mallarna.* En agent får lista mallar (`templates_list`) och använda dem, via ett valfritt `template` i `containers_create` och vid tillämpning på en befintlig container. **En agent får inte spara eller ta bort mallar.** Mallarna ligger på kontot, och det ligger nära förbudet mot kontoinställningar i ADR-0087 Riktning 7. Förbudet kan släppas senare utan att något går sönder.
- *Ingen avvecklingstid.* MCP är inte utrullat i produktion (Tony 2026-11-19), så parametrarna `manufacturer` med flera tas bort direkt i stället för att stå kvar i 90 dagar enligt ADR-0087 Riktning 15. Kontraktsprovet uppdateras med avsikt.

### Riktning 9, detaljer (utformad av Claude)

- *Tabellerna.* `item_field` bär definitionen med `container_id` och `item_id NULL`, där `item_id` är satt för ett items egna fält. `item_field_value` bär ett typat värde per item och fält, i `value_text`, `value_number` eller `value_date`. Kolumnerna står i [[Items och organisation]].
- *Tal* lagras som `DECIMAL(20,6)`, aldrig som flyttal. Rubriken bär enheten.
- *Rubriken* är högst 100 tecken och unik, utan hänsyn till skiftläge, bland de fält som syns på ett item: containerns fält plus itemets egna. En krock avvisas med 422 `field.label_taken`.
- *Ett tomt värde* lagras inte som en rad. Att tömma ett fält tar bort raden.
- *Ett värde för fel fält*, alltså ett fält från en annan container eller ett annat items egna fält, avvisas med 422 `field.not_applicable`.
- *Ordningen.* Containerns fält har `position`. Itemets egna fält ritas efter containerns, i sin egen ordning.
- *Att tillämpa en mall* på en befintlig container hoppar över fält vars rubrik redan finns. Skulle resultatet gå över gränsen avvisas hela tillämpningen med `field.limit_reached`. Kategorierna läggs till som kategoriförslagen gör i dag.
- *Mallar per konto* är högst 50 (`templates.max_per_account`). Mallen lagras som en rad i `container_template` med innehållet i JSON och räknas i `metadata_bytes`.
- *Versionerna.* `item_version` bär fältvärdena i stället för de sex kolumnerna. En återställning hoppar över ett fält som har raderats sedan versionen sparades, på samma sätt som för kategorier och taggar (ADR-0088 Riktning 5).
- *Exporten* av en container bär fälten och värdena.
- *Ägarbytet* tar med fälten som det tar med kategorierna.

## Konsekvenser

- Nya tabeller `item_field`, `item_field_value` och `container_template`. Alla tre räknas i `MetadataSize` och `CountedRows`.
- De sex kolumnerna på `item` försvinner i två steg. FULLTEXT-indexet på `item` krymper till `(name, description)`, och fritextsökningen söker dessutom i `item_field_value.value_text`.
- ADR-0088 Riktning 2 ändras: en version bär fältvärdena i stället för de sex kolumnerna. M67:s issue 412 beror på [[M68 Egna fält]].
- Byggs i [[M68 Egna fält]], issue 417–425.

## Alternativ som valts bort

- *Bara välja vilka av de sex fälten som visas.* Billigt, men lika låst. Användaren kan inte skriva in *Årgång*. Valdes bort av Tony 2026-11-19.
- *25 platshållarkolumner på `item`* (Tonys eget förslag). Tal och datum hade antingen lagrats som text och sorterats fel, eller krävt 75 kolumner. Fält per item hade gett kolumner som betyder olika saker på olika rader, och taket hade legat i schemat. Två tabeller ger samma kända gräns i config.
- *Mallar som kopplas till containrarna.* Se Riktning 6.
- *Kopiera fälten från en annan container.* Ersatt av mallar, som täcker samma fall utan en mellanlösning.
- *Arv till barnen.* Se Riktning 7.
- *Olika gränser per plan.* Gränserna finns för att formuläret ska gå att överblicka och sökningen ska gå fort, inte för att lagringen kostar.

## Kvar

- *Påminnelser för datumfält,* till exempel innan en garanti går ut. Typen gör det möjligt men ingenting är beslutat.
- *Mallar i kontots export* och *mallar som delas mellan konton.*

## Tillägg 2026-11-26: länkar i textfält

**Kontext.** Ett textfält bär ofta en adress, som tillverkarens sida eller en manual. Den går i dag bara att kopiera. Anteckningen gör redan en adress till en länk som öppnas i en ny flik ([[ADR-0066 Itemets anteckning]] § 2).

**Tonys beslut (2026-11-26):**

1. En URL i ett item ska vara klickbar, i ett fält och i anteckningen.
2. Länken öppnas i en ny flik, inte i det fönster användaren står i.
3. En adress blir en länk var den än står i värdet, inte bara när hela värdet är en URL.

**Utformat av Claude** (issue 518):

- Bara typen `text` får länkar. Tal, datum och ja/nej får det aldrig.
- En adress börjar med `http://`, `https://` eller `www.`. Skiljetecken i slutet hör inte till den, som i GitHub-varianten i anteckningen. `www.` får `https://` i `href`. Inget annat protokoll blir en länk.
- Länken har `target="_blank"` och `rel="noopener noreferrer nofollow"`, samma som anteckningen.
- Klienten delar värdet i segment och ritar dem utan `v-html`. Undantagen i [[ADR-0066 Itemets anteckning]] § 3 blir inte fler.
- Värdet lagras och skickas som text. API:et, MCP och exporterna ändras inte.

**Alternativ som valts bort:**

- *Bara när hela värdet är en URL:* enklare, men "se https://…" blir då ingen länk. Tony valde var som helst (beslut 3).
- *En egen fälttyp `url`:* kräver en typ, en migrering och ett typbyte för fält som redan finns. Ett textfält räcker.
- *Rita fältet som Markdown på servern:* ger fältet hela Markdown och ännu ett `v-html`.

**Konsekvenser.** Issue 518 i [[M96 Länkar i fälten]]. Anteckningen ändras inte, den får bara ett prov.

## Om att ändra ett beslut

Radera aldrig en ADR. Sätt `Status: Ersatt av ADR-XXXX` och skriv en ny.
