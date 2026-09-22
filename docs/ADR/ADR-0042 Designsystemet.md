# ADR-0042 Designsystemet

**Status:** Antagen 2026-09-22 · Bygger vidare på [[ADR-0021 Frontendteknik]] · Löser ut designberoendet i [[ADR-0039 Containerns översikt]] och [[ADR-0041 Itemets vy]] · [[ADR-index]]

Fattat när designern lämnade fyra färdiga bilder. De ligger i `docs/Design/` och är den första designkällan valvet haft — fram till nu har *"väntar på designen"* betytt att ingen kunde peka på något.

## Kontext

Fyra genomgångar har skjutit samma arbete framför sig. [[ADR-0039 Containerns översikt]] lämnade flikraden, [[ADR-0041 Itemets vy]] lämnade flikraden *och* trepanelslayouten, och [[Att sortera efter mockuparna]] bär en rad som säger *"tokens och kärnkomponenter före sidor, annars blir varje vy ett frihandsjobb"*. Frihandsjobbet är redan här: `resources/css/app.css` är tio rader och sätter bara typsnittet, medan trettioåtta komponenter bär sina färger som `text-slate-800` och `text-red-700` direkt i markupen. Ingen av dem är fel; tillsammans är de ingen produkt.

Bilderna är dessutom **nyare än besluten**. Fyra av dem ritar saker som en tidigare genomgång uttryckligen strök, och två ritar en navigering [[ADR-0041 Itemets vy]] avvisade fem dagar innan de kom. Ett designsystem som byggs utan att den skillnaden skrivs ned blir en andra sanning vid sidan av ADR-serien, och den som bygger issue nummer tre får välja själv.

## Beslut

**Tokens först, komponenter sedan, sidor sist.** Ordningen är bindande och är hela skälet till att milstolpen finns. En sida som byggs före sin komponent får sina värden ur bilden med ögonmått, och nästa sida får andra.

**Tokens bor i `@theme` i `resources/css/app.css` och har roller som namn, inte färger.** `--color-surface`, `--color-ink-muted`, `--color-accent` — aldrig `--color-blue-600`. Ett rollnamn kan bindas om; ett färgnamn måste sökas upp och ersättas på trettioåtta ställen, vilket är precis situationen vi är i nu. Värdena, lästa ur bilderna:

| Roll | Värde | Var den syns |
|---|---|---|
| `--color-shell` | `#0b1f35` | Sidopanelen, produktens enda stora mörka yta |
| `--color-shell-active` | `#133d63` | Den aktiva raden i sidopanelen |
| `--color-surface` | `#ffffff` | Kort och paneler |
| `--color-surface-muted` | `#f6fafd` | Sidbakgrunden bakom korten |
| `--color-surface-sunken` | `#eff4f8` | Inmatningsfält, hovrade rader |
| `--color-border` | `#e2e8f0` | Kortens kant, tabellernas linjer |
| `--color-ink` | `#0f172a` | Rubriker |
| `--color-ink-muted` | `#475569` | Brödtext |
| `--color-ink-subtle` | `#64748b` | Metatext, containerns undertitel |
| `--color-accent` | `#2563eb` | Primärknapp, länk, aktiv flik, vald nod |
| `--color-accent-soft` | `#eff6ff` | Chip, markerad rad, informationsrutan |
| `--color-danger` | `#dc2626` | *Om 24 dagar* när den är röd |
| `--color-warning` | `#d97706` | Datum som närmar sig |
| `--color-success` | `#15803d` | OK-märket |

Typskalan är fem steg — `meta` 12px, `body` 14px, `title` 15px halvfet, `heading` 24px, `display` 30px — och radierna tre: `--radius-card` 12px, `--radius-control` 8px, `--radius-pill`. Skuggan är en enda, låg och kall; bilderna har ingen djup skugga någonstans utom under dialoger.

**Tokens skrivs så att ett mörkt läge går att binda om, men inget bygger det nu.** Rollnamnen gör det till ett andra block med samma nycklar. Sol-ikonen i en av bilderna är därmed en möjlighet, inte ett löfte.

**Fokusringen är en token och får aldrig tas bort.** `--color-focus` är accenten, två pixlar med två pixlars förskjutning. Issue 68a och 68b gick igenom hela frontenden med tangentbord; en komponent som sätter `outline: none` utan ersättning river det arbetet, och den regeln ska stå i komponentens egen docblock.

**Kärnkomponenterna är åtta, och de är uttömmande för de fyra bilderna:** knapp, formulärkontroll, kort, bricka, listrad, tom-tillstånd, taltuta och flikrad. Ingen niondel byggs på spekulation. Flikraden byggs **en gång** och används av både containern och itemet — de två har olika rader men samma beteende, och två flikrader glider isär inom en milstolpe.

**Itemet bor i containern, precis som [[ADR-0041 Itemets vy]] säger.** Två av bilderna ritar en global vänstermeny med egna rader för Struktur, Karta, Uppgifter, Dokument och Kostnader. Den navigeringen tas inte in. Panelerna *inuti* containerns ram är däremot bildernas: strukturen till vänster, itemet i mitten, kartans plats till höger.

### Bildernas avvikelser, avgjorda

Fem punkter där bilden säger en sak och ett tidigare beslut en annan. Var och en är avgjord här, en gång, så att ingen issue behöver göra det igen:

- **Uppgifter och underhåll är en flik, inte två.** Bilderna ger dem var sin flik och var sitt tal. `schedule` skiljer dem bara åt via `recurrence_type`, och den skillnaden ska inte bäras av en vy — den är ett filter i listan. Samma svar som dashboardens uppgiftsbricka fick.
- **Vädret är struket och förblir struket.** Ingen datakälla, ingen relation till det produkten är. Det står redan i [[Att sortera efter mockuparna]] § Dashboarden.
- **Kortens framdriftsstapel byggs inte.** *78 %* av vad? Ingen storhet i datamodellen svarar. Det närmaste är OK-talet ur [[ADR-0040 Underträdets summor]], och det är redan ett tal på kortet.
- **Containerns hjältebild och kortens foto väntar på ett eget beslut.** `attachment.item_id` är `NOT NULL`, så en container kan i dag inte äga en fil. Antingen nullbar `container_id` eller ett eget fält — en datamodellfråga, inte en vy-issue, och samma fråga på båda ställena.
- **Leverantör, artikelnummer och placering står kvar strukna.** Bilden återinför dem i itemets detaljruta. Leverantören bor på `cost_entry` där den redan är indexerad, artikelnumret finns inte, och `serial_number` är något annat. Att rita ett fält som inte finns är att lova det.

**Kartan ritas inte här.** Fokuskartan behöver ingen ny fråga — `ListItemLinks` *är* dess noder — men den behöver en layout, och containerns hela karta är en layoutalgoritm över hundratals noder. Trepanelslayouten lämnar kartans plats tom med flit; det är en panel som får innehåll senare, inte en yta som saknas.

## Motivering

**Rollnamn är det som gör bilderna utbytbara.** Designern kommer att lämna en femte bild, och den kommer att ha en annan accent eller en ljusare bakgrund. Med roller är det en rad i `@theme`; med färgnamn är det en genomgång av varje komponent, alltså precis det arbete den här milstolpen finns till för att slippa göra igen.

**Avvikelserna måste avgöras i en ADR och inte i nio issues.** En issue som får se både bilden och beslutet väljer det bindande — det säger `AGENTS.md` — men nio issues som var för sig upptäcker samma motsägelse betalar den nio gånger. `Lärdomar.md` har den posten tre gånger redan under olika namn.

**Bilderna bor i repot.** En agent kan läsa en JPEG. En issue som säger *"härma `docs/Design/container.jpeg`"* är en rad; samma sak i prosa är två stycken som ändå inte räcker. Det är också första gången valvet har en designkälla någon annan än Tony kan öppna.

## Konsekvenser

- **Trettioåtta komponenter bär i dag råa Tailwind-klasser.** De migreras inte i ett svep: varje kärnkomponent tar med sig de befintliga ställen som redan gör dess jobb, och resten står kvar tills en sida ändå ska byggas om. En stor omskrivning är en stor granskning.
- **`containerSections.js` nio rader blir sju flikar plus en inställningssida.** Ingen rad får försvinna — kategorier, taggar, delning, kalender, export, papperskorg och överlåtelse måste alla gå att nå, vilket var issue 62a:s och 67c:s egen motivering.
- **Utlåningen saknar flik i bilderna och måste ändå få en.** Samma krav, samma skäl.
- **Favoriter blir en pivot per användare, inte en flagga på itemet.** Bilderna visar både en stjärna i itemets huvud och en `FAVORITER`-sektion i sidopanelen. En kolumn på `item` hade gjort din favorit till allas i en delad container. Listan filtreras genom `ResolveItemScope` som allt annat.
- **Datumens blandning — *om 24 dagar* bredvid *14 okt 2026* — får en regel.** Den är ren presentation, gäller container och item lika, och avgörs en gång i en komposabel i stället för i varje panel.
- **Historikfliken och dashboardens händelsepanel ritas fortfarande inte.** `audit_log` skrivs från två anropsställen. Instrumenteringen är ett eget arbete med egna beslut.

## Alternativ

**Ta in ett färdigt komponentbibliotek.** Valdes bort — ett nytt npm-paket kräver Tonys godkännande, det hade kommit med sin egen designuppfattning som bilderna sedan skulle brottas mot, och åtta komponenter är mindre arbete än att böja ett bibliotek.

**Bygga sidorna direkt ur bilderna och lyfta ut tokens efteråt.** Valdes bort — det är vad som redan hänt en gång, och resultatet är `text-slate-800` på trettioåtta ställen.

**Följa bilderna också där de säger emot besluten.** Valdes bort per punkt ovan. Bilden är den färskaste artefakten men inte den mest genomtänkta: den ritar ett artikelnummer som inte finns och ett väder som ingen bett om, och den tar bort containern som arbetsrum två veckor efter att två ADR:er gjorde den till ett.
