# M17 · Designsystemet

Del av [[Backlog]]. Konventionerna som varje issue förutsätter står i indexet — läs dem en gång, inte per issue.

Tillagd 2026-09-22, när designern lämnade fyra bilder. De ligger i `docs/Design/` och är milstolpens förlaga: `main.jpeg` (dashboarden), `container.jpeg` (containerns översikt), `struktur.jpeg` och `struktur - item.jpeg` (strukturen och itemet). Besluten står i [[ADR-0042 Designsystemet]] — **inklusive de fem ställen där bilden säger emot ett tidigare beslut.** Ingen issue här ska avgöra en sådan motsägelse på nytt; står den i ADR:en är den avgjord.

Ordningen är bindande: **tokens, sedan komponenter, sedan sidor.** En sida som byggs före sin komponent får sina värden ur bilden med ögonmått, och nästa sida får andra.

**Detta ingår inte:** fokuskartan och containerns karta (layoutarbete, eget projekt), mörkt läge (tokens görs ombindbara, ingen yta byggs), historikfliken och dashboardens händelsepanel (väntar på händelseinstrumenteringen), containerns hjältebild och kortens foto (`attachment.item_id` är `NOT NULL` — en datamodellfråga), framdriftsstapeln och vädret (strukna i [[ADR-0042 Designsystemet]]).

**Dashboardsidan byggs inte heller här.** Den är `main.jpeg`, men två av dess paneler är händelseloggen, och resten hör ihop med att `/dashboard` i dag är todo-vyn. Den blir en egen milstolpe när instrumenteringen finns.

---

### 97. Temat
`resources/css/app.css` är tio rader och sätter bara typsnittet. Trettioåtta komponenter bär sina färger som `text-slate-800` direkt i markupen, och därför finns ingen plats att ändra en färg på.

Issuen skriver [[ADR-0042 Designsystemet]] § Beslut som `@theme`-tokens med **roller** som namn — `--color-surface`, `--color-ink-muted`, `--color-accent`, aldrig `--color-blue-600`. Fokusringen är en av dem och får aldrig tas bort; regeln står i docblocken. Två befintliga komponenter, `FormField` och `FlashMessage`, migreras som bevis på att tokens duger till det som redan finns.

**Läs:** [[ADR-0042 Designsystemet]] § Beslut, `docs/Design/container.jpeg`, `resources/css/app.css`, `resources/js/components/FormField.vue`
**Klart när:** varje roll i ADR:ens tabell finns som token; `FormField` och `FlashMessage` bär inga råa färgklasser; fokusringen är en token och syns på tangentbordsfokus; `npm run build` är grön och `public/build/manifest.json` finns; hela testsviten är grön.
**Beror på:** -

### 98. Primitiverna
Knappen finns i dag som femton olika uppsättningar klasser. Issuen bygger `UiButton` med fyra varianter — primär, sekundär, tyst, farlig — och formulärkontrollerna `UiInput`, `UiSelect`, `UiTextarea` och `UiCheckbox` ovanpå den `FormField` som redan äger etiketten, felet och `aria-describedby`.

Kontrollerna ersätter inte `FormField`, de fyller den. Mönstret från issue 51 § Beslut 9 står kvar: valideringen bor på servern, ingen klientvalidering smyger in med en komponent.

**Läs:** [[ADR-0042 Designsystemet]] § Beslut, `docs/Design/struktur - item.jpeg`, `resources/js/components/FormField.vue`, [[M10 Webbfrontend]] § 51
**Klart när:** `UiButton` har fyra varianter och två storlekar; de fyra kontrollerna renderar i `FormField` med etikett, fel och `aria-describedby` oförändrade; ingen kontroll bär egen klientvalidering; minst fem befintliga formulär använder dem; hela testsviten är grön.
**Beror på:** 97

### 99. Ytorna
Bilderna är byggda av fem saker som upprepas: kortet med rubrik och *Visa alla*, brickan för status och kategori, listraden med ikon, titel, undertitel och meta, tom-tillståndet, och taltutan — de fyra talen i containerns hjälte.

Fem komponenter, inga fler. Kortets rubrikrad är en slot, inte en propp per variant.

**Läs:** [[ADR-0042 Designsystemet]] § Beslut, `docs/Design/container.jpeg`, `docs/Design/main.jpeg`
**Klart när:** de fem komponenterna finns och bär bara tokens; kortet tar rubrik och åtgärd som slots; brickan har fyra tillstånd — OK, varning, fara, neutral; tom-tillståndet skiljer *inget alls* från *inget som matchar*; containerns fyra tal renderas av taltutan; hela testsviten är grön.
**Beror på:** 97

### 100. Flikraden
Containern och itemet har olika rader men samma beteende. Byggs den två gånger glider de isär inom milstolpen, och det är hela skälet att den är en egen issue.

`UiTabs` bär rader med etikett, valfri räknare och aktivt tillstånd, och den är tangentbordsnåbar enligt samma genomgång som issue 68a gjorde: piltangenter flyttar, `Tab` lämnar, `aria-selected` följer med.

**Läs:** [[ADR-0042 Designsystemet]] § Beslut, `docs/Design/container.jpeg`, `docs/Design/struktur - item.jpeg`, [[M10 Webbfrontend]] § 68a
**Klart när:** `UiTabs` renderar rader med etikett och valfri räknare; piltangenter flyttar mellan flikar och `Tab` lämnar raden; `aria-selected` sätts på den aktiva; den aktiva fliken står i URL:en och inte i komponentens eget tillstånd; hela testsviten är grön.
**Beror på:** 97

### 101. Containerns flikrad och inställningssidan
`containerSections.js` har nio rader; bilden har sju flikar. Kategorier, taggar, delning, kalender, export, papperskorg och överlåtelse får inte plats bland flikarna och samlas på containerns inställningssida.

**Ingen rad får försvinna.** En yta ingen hittar är samma sak som en yta som inte finns — issue 62a:s och 67c:s egen motivering. Flikarna är översikten, items, dokument, uppgifter, kostnader och historik; historiken ritas inte ännu och ska därför inte heller ha en flik.

**Läs:** [[ADR-0042 Designsystemet]] § Beslut och § Konsekvenser, [[ADR-0039 Containerns översikt]] § Beslut, `docs/Design/container.jpeg`, `resources/js/layouts/containerSections.js`, [[M10 Webbfrontend]] § 62a, § 67c
**Klart när:** containern har en flikrad byggd av `UiTabs`; varje rad som lämnar flikraden går att nå från inställningssidan; ett test räknar upp alla nio sektionerna och bevisar att var och en är nåbar; uppgifter och underhåll är **en** flik; ingen historikflik finns; hela testsviten är grön.
**Beror på:** 100

### 102. Itemets flikrad
Sex av bildens flikar ligger redan som propar i `Containers/Items/Show` och renderas i dag på en enda lång sida: fälten, relationerna, bilagorna, schemana, utlåningen och taggarna. Issuen är en omfördelning av det som redan hämtas, inte nya ändpunkter.

**Utlåningen har ingen flik i bilden och måste ändå få en plats.** Itemet bor i containern ([[ADR-0041 Itemets vy]]) — flikraden ligger inuti containerns ram, inte i en global navigering.

**Läs:** [[ADR-0042 Designsystemet]] § Beslut, [[ADR-0041 Itemets vy]] § Beslut, `docs/Design/struktur - item.jpeg`, [[M16 Itemets vy]] § 93
**Klart när:** itemets vy har en flikrad byggd av `UiTabs`; alla sex befintliga propar når sin flik och utlåningen har en egen; ingen ny ändpunkt tillkommer; flikraden ligger inuti containerns ram; anteckningen och beskrivningen står på översiktsfliken, inte i en detaljrad; hela testsviten är grön.
**Beror på:** 100

### 103. Trepanelslayouten
Strukturen till vänster, itemet i mitten, kartans plats till höger — allt inuti containerns ram. Strukturen är issue 94:s upplösning och förekomsterna issue 95:s; båda finns.

**Kartans panel lämnas tom med flit.** Den är en panel som får innehåll senare, inte en yta som saknas. Under en smal skärm staplas panelerna; strukturen blir en utfällbar yta och inte en andra sida.

**Läs:** [[ADR-0042 Designsystemet]] § Beslut och § Konsekvenser, `docs/Design/struktur.jpeg`, `docs/Design/struktur - item.jpeg`, [[M16 Itemets vy]] § 94 och § 95
**Klart när:** de tre panelerna renderas inuti containerns ram; strukturpanelen visar issue 94:s träd och markerar den aktuella förekomsten ur querysträngen; kartans panel är tom och annonserar sig inte som trasig; panelerna staplas på smal skärm utan att strukturen blir en egen sida; hela testsviten är grön.
**Beror på:** 99, 102

### 104. Datumregeln
Bilderna blandar två format i samma lista: *Om 24 dagar* bredvid *14 okt 2026*. Vilket som visas när behöver en regel, och den är ren presentation utan schemapåverkan.

Regeln bor i en komposabel och inte i varje panel, och strängarna i `lang/` som allt annat användaren läser.

**Läs:** [[ADR-0042 Designsystemet]] § Konsekvenser, `docs/Design/main.jpeg`, `docs/Design/container.jpeg`, [[M10 Webbfrontend]] § 52
**Klart när:** en komposabel avgör relativt eller absolut datum efter en skriven gräns; förfallna datum är alltid relativa och markerade som fara; formatet följer användarens `locale` och inte `Accept-Language`; ingen panel formaterar datum själv; hela testsviten är grön.
**Beror på:** -

### 105. Favoritmarkeringen
Bilderna har en stjärna i itemets huvud och en `FAVORITER`-sektion i sidopanelen. Det finns ingen tabell.

En favorit är **per användare**, alltså en pivot och inte en kolumn på `item`: en flagga på itemet hade gjort din favorit till allas i en delad container. Du kan bara favoritmarkera ett item du når, och markeringen ger ingen åtkomst — den speglar den.

**Läs:** [[ADR-0042 Designsystemet]] § Konsekvenser, [[ADR-0028 Åtkomst på itemnivå]] § Beslut, [[Items och organisation]] § item, [[M11 Åtkomst på itemnivå]] § 73
**Klart när:** en pivot binder användare till item med tidsstämpel och unikt par; ett item du inte når går inte att favoritmarkera och svaret är 403; markeringen går att sätta och ta bort från itemets huvud; en borttagen favorit lämnar itemet orört; `ItemResource` har inget nytt fält; hela testsviten är grön.
**Beror på:** 98

### 106. Favoritlistan i skalet
Sidopanelens `FAVORITER`-sektion, med samma listrad som resten av skalet.

Listan filtreras genom `ResolveItemScope` som allt annat: ett item du förlorat åtkomsten till försvinner ur listan i stället för att ge en trasig länk, och ingen räknare berättar att något fallit bort.

**Läs:** [[ADR-0042 Designsystemet]] § Konsekvenser, `docs/Design/struktur - item.jpeg`, [[M11 Åtkomst på itemnivå]] § 73 (Beslut 6), [[M17 Designsystemet]] § 105
**Klart när:** sidopanelen visar användarens favoriter i namnordning; ett item utanför omfånget finns inte i listan; ingenting avslöjar hur många som filtrerats bort; en användare utan favoriter ser ingen tom sektion; antalet frågor är konstant oavsett antal favoriter; hela testsviten är grön.
**Beror på:** 99, 105
