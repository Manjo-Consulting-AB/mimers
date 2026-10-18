# ADR-0060 Itemets ikon

**Status:** Antagen 2026-10-18 · Kompletterar [[ADR-0057 Ikoner och typsnitt]]: gränssnittets slutna lista står kvar, itemets ikon är en egen sak · Undantag från [[ADR-0042 Designsystemet]] om rollnamn på färgtokens · Ändrar [[ADR-0058 Trädet som tabell]] § 2: rotraden fälls inte · § 9 ändrad av [[ADR-0062 Kartans luft, listans ruta och den löpande sökningen]]: listan visar bild eller ikon, aldrig båda · [[ADR-index]]

Tonys beslut 2026-10-18, vid genomgången av fynden i v0.23.0 och `docs/Design/findings/Freya Items Tree Dashboard.png`.

## Kontext

Mockuparna ritar en ikon framför varje items namn, i trädet, i kartan och i listan: en droppe, en motor, en skiftnyckel. [[ADR-0057 Ikoner och typsnitt]] § 1 valde bort det. Ikonerna där är gränssnittets ordförråd. Det är en sluten lista under rollnamn (`inbox`, `edit`, `chevron-down`) som granskaren kan hålla emot, och den listan innehåller inga föremål.

ADR-0057 vill förhindra att gränssnittet glider isär när två sidor väljer två olika ikoner för samma sak. Det argumentet gäller systemets egna ytor. Det gäller inte det användaren själv väljer att kalla sitt item, och det är innehåll på samma sätt som namnet och taggens färg.

Fynden visar också att trädets rotrad står fel. Fällknappen på containerns rad tar en kolumn, och toppnivåns items hamnar i linje med containerns namn, så att trädet ser ut att sakna indrag. Att fälla ihop containern döljer hela trädet, och det finns ingen anledning att göra det.

## Beslut

**1. Användaren väljer bland hela Lucide.** Paketet `lucide` (ISC) blir ett beroende, och itemets ikon är ett Lucide-namn i kebab-case, till exempel `droplet` eller `battery-full`. Varje ikon i paketet är en egen ESM-fil, `lucide/dist/esm/icons/<namn>.mjs`, med samma nodform som `resources/js/icons/index.js` redan använder. Ikonen ritas därför med `h()` och utan `v-html`, precis som `UiIcon` gör.

**2. Varje ikon laddas för sig.** Filerna läses med `import.meta.glob(..., { import: 'default' })` i lat form, så att varje ikon blir en egen liten fil i bygget och bara hämtas när den ritas. Väljaren får namnlistan ur samma glob, alltså ur nycklarna, utan att ladda någon ikon den inte visar. Bundlen för en sida utan item-ikoner blir inte större.

**3. Gränssnittet använder fortfarande bara sin slutna lista.** `UiIcon` och `resources/js/icons/index.js` ändras inte, och ingen sida importerar en Lucide-ikon ur paketet för sitt eget gränssnitt. Itemets ikon ritas av en egen komponent, `ItemIcon`. Den följer `UiIcon`:s storlekar och linjebredd och är alltid `aria-hidden`.

**4. Ett namn som inte finns ritar den neutrala ikonen.** Lucide byter ibland namn på en ikon eller tar bort den. Då ritar `ItemIcon` rollen `item` i neutral ton, och itemet går fortfarande att redigera. Servern kontrollerar bara formatet (gemener, siffror och bindestreck, högst 64 tecken) och inte att ikonen finns. Listan finns i klientens bygge och inte på servern. En kontroll mot den hade krävt att listan fanns på två ställen.

**5. Färgen är en av åtta i en fast palett.** `icon_color` är en nyckel och inte en hex-kod: `slate`, `blue`, `sky`, `teal`, `green`, `amber`, `orange` och `violet`. Varje färg är två tokens i `resources/css/app.css`, `--color-tint-<nyckel>` för linjen och `--color-tint-<nyckel>-soft` för rutan bakom. Paletten saknar rött med flit. `danger` betyder att något har passerat ([[ADR-0057 Ikoner och typsnitt]] § 3), och en röd ikon som användaren själv valt skulle läsas som en varning. Saknas färgen ritas `slate`.

Det här är ett **undantag** från ADR-0042:s regel om rollnamn. Färgerna har ingen roll, de är användarens val, och därför heter de som det de är. Undantaget gäller bara `--color-tint-*`.

**6. Utan vald ikon ritas den neutrala.** Ett item utan ikon ritas med rollen `item` i en grå ruta. Raderna står då i linje, och den som aldrig väljer en ikon ser samma ikon överallt i stället för hål.

**7. Två nullbara kolumner på `item`.** `icon VARCHAR(64) NULL` och `icon_color VARCHAR(16) NULL`. Båda står i `ItemResource` och skrivs genom `/api` som de andra fälten, eftersom ikonen är itemets och inte en vy. `icon_color` valideras mot paletten (`Item::ICON_COLORS`), som det enda stället servern känner till färgerna. En ändring loggas med gamla och nya värdet, som `category` och `cover` i `UpdateItem`, eftersom ingen av dem är fritext.

**8. Ikonen väljs under *Edit item*,** i en egen sektion bredvid *Cover image*. Väljaren har ett sökfält som filtrerar på namnet, ett rutnät med ikoner, de åtta färgerna och *Remove icon*. Sökningen är på engelska, eftersom Lucides namn är det.

**9. Ikonen syns där itemet står som rad eller nod:** listan, trädet, kartan, fokuskartan i högerpanelen och itemets överdel. Sökresultaten, todo-listan och dokumentlistorna behåller sina nuvarande ikoner. Där står itemet som sammanhang till en annan rad och inte som raden själv.

**10. Trädets rotrad fälls inte.** Containerns rad har ingen fällknapp. Containerns ikon (`container`) står i den kolumn där knappen stod, och trädets rötter är alltid synliga. Rötternas fällknappar står i samma kolumn, under containerns ikon, och deras ikon och namn står till höger om den. *Expand all* rör inte längre rotraden.

## Motivering

En sluten lista med föremål hade blivit en uppräkning av vad användarna äger. Den hade varit fel för den som har en husvagn när listan ritats för en båt. Hela Lucide räcker till nästan allt och kostar oss ett beroende och en fallback.

Att låta servern strunta i om namnet finns är samma avvägning som för taggens färg. Det kan inte bli farligt, bara fult, och fallbacken gör att det inte ens blir fult.

En fast palett och inte fri färg, eftersom åtta färger går att ge en mjuk ruta som fungerar mot både den ljusa ytan och skalet. En fri hex-kod hade gett vit ikon på vit ruta.

## Konsekvenser

- Issue 300–307 i [[M35 Itemets ikon och kartans kort]].
- `package.json` får `lucide`. `resources/js/icons/LICENSE` gäller de kopierade ikonerna, och paketet bär sin egen licens.
- Bygget får omkring 1 900 små filer till under `public/build/assets`. De följer med i artefakten som allt annat i `public/build` ([[Pipeline]] § Paketering).
- `docs/Datamodell/Items och organisation.md` får de två kolumnerna.
- Exporten (`ContainerExportBuilder`) tar med båda fälten.
- ADR-0058 § 2: *Rotraden är öppen från början* ersätts av beslut 10 här.

## Alternativ

**En kurerad lista med 40–60 föremål, kopierade in som gränssnittets ikoner.** Valdes bort: listan hade varit ett påstående om vilka saker som finns ([[ADR-0033 Produktens omfång]]), och varje ny ikon hade krävt en issue.

**`lucide-vue-next`.** Valdes bort: ett dynamiskt val av namn tar ut tree-shakingen, och då hamnar hela biblioteket i bundlen. `lucide` har en fil per ikon som går att ladda lat.

**`lucide-static`.** Valdes bort: SVG-filerna måste ritas med `v-html`, som ADR-0057 § 2 förbjuder.

**Fri färg som hos taggen.** Valdes bort enligt motiveringen.
