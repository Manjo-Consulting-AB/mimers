# ADR-0064 Containerns ikon

**Status:** Antagen 2026-10-18 · Bygger på [[ADR-0060 Itemets ikon]] och använder samma paket, väljare och palett · Kompletterar [[ADR-0047 Containerns bild]] · [[ADR-index]]

Tonys beslut 2026-10-18.

## Kontext

Ett item kan ha en egen ikon i en av åtta färger sedan [[M35 Itemets ikon och kartans kort]]. En container kan inte det. Överallt där en container står som sig själv ritas den generiska rollen `container`, alltså en låda. I huvudmenyns lista ser alla containrar likadana ut, och man hittar dem bara på namnet. En container utan bild ritar dessutom en grå ruta med samma låda på kortet och i överdelen.

## Beslut

**1. Containern får itemets ikonuppsättning.** Ikonen är ett Lucide-namn i kebab-case och färgen är en av de åtta nycklarna i `Item::ICON_COLORS`. Formatkontrollen, att ett okänt namn ritar fallbacken och att färgen saknar rött gäller som i [[ADR-0060 Itemets ikon]] § 1–5. Paletten finns på ett ställe på servern, och containerns validering läser samma konstant.

**2. Två nullbara kolumner på `container`.** Kolumnerna är `icon VARCHAR(64) NULL` och `icon_color VARCHAR(16) NULL`. Båda står i `ContainerResource` och skrivs genom `/api` och formuläret som de andra fälten. `UpdateContainer` loggar dem med gamla och nya värdet. Exporten tar med dem.

**3. Ikonen väljs under containerns inställningar,** i en sektion *Icon* bredvid *Cover image* på `Containers/Edit.vue`. Väljaren är `ItemIconPicker`, med samma sökfält, rutnät, färger och *Remove icon*. Valet skickas med formuläret som `icon` och `icon_color`.

**4. Utan vald ikon ritas lådan.** Fallbacken för en container är rollen `container` och inte `item`. `ItemIcon` får proppen `fallback`, med `item` som förval, så att samma komponent ritar båda. Ett okänt namn ritar också fallbacken.

**5. Ikonen syns där containern står som sig själv:**
- **Huvudmenyns lista** (`ShellContainerList`). Ikonen står i `tile` i storleken 20 före namnet. Paletten fungerar mot skalet ([[ADR-0060 Itemets ikon]] § Motivering).
- **Trädets rotrad** (`ItemTreeTable`), i samma kolumn som i dag.
- **Kartans mitt kring containern** (`ItemMapNode`). `ResolveItemMap` skickar containerns `icon` och `icon_color` på `center` när mitten är containern.
- **Rutan när bilden saknas** (`ContainerCover`) på containerlistan, kortet och överdelen. Ordningen är bild, ikon och sist lådan i den grå rutan. Bilden vinner, eftersom en container har högst en bild och den alltid är vald ([[ADR-0047 Containerns bild]]). Det är samma regel som listans *vald bild före ikon* i [[ADR-0062 Kartans luft, listans ruta och den löpande sökningen]] § 6.

Rollen `container` står kvar där den betyder *containrar* som begrepp och inte en viss container. Det gäller *Containers* i navigeringen (`ShellSections`), rubriken på detaljpanelen (`ContainerDetailsPanel`) och typfiltret i historiken (`HistoryFilterBar`). Sökresultaten, uppgiftslistorna och dokumentlistorna behåller sina ikoner, av samma skäl som i ADR-0060 § 9.

## Motivering

Huvudmenyn är där man byter container oftast, och där hjälper en ikon mest. En båt, ett hus och en bil är lättare att skilja med en droppe, ett hus och en bil framför namnen.

Att använda samma väljare och samma palett gör att den som valt en ikon för ett item redan kan göra det för en container. Det kostar inga nya beroenden och ingen ny komponent.

## Konsekvenser

- Issue 314–317 i [[M38 Containerns ikon]].
- `docs/Datamodell/Konton och åtkomst.md` § container har de två kolumnerna.
- `ListShellContainers`, `ListContainerSummaries` och `ResolveItemMap` läser `icon` och `icon_color` i samma fråga som namnet.
- `ItemIcon` heter fortfarande `ItemIcon`, fast den även ritar containrar. Ett namnbyte hade rört varje yta från M35 och ger ingenting.

## Alternativ

**Ikonen ur containerns `kind`** (`boat`, `house` och så vidare). Valdes bort: `kind` är fritext för presentation och mallval, och Tony vill ha samma väljare som för items.

**Ikonen före bilden på containerns kort.** Valdes bort: containerns bild är alltid ett val, och ett val av bild är ett tydligare uttryck än ikonen.
