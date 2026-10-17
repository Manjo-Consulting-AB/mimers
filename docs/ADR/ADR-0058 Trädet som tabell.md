# ADR-0058 Trädet som tabell

**Status:** Antagen 2026-10-18 · Ändrar läget *Träd* ur [[ADR-0046 Containerns karta]] och issue 154: trädet filtreras · Bygger vidare på [[ADR-0041 Itemets vy]] och [[ADR-0028 Åtkomst på itemnivå]] · [[ADR-index]]

Tonys beslut 2026-10-18, vid genomgången av `docs/Design/items-tree.png`.

## Kontext

Itemflikens läge *Träd* (issue 154) är `ItemStructureTree` med fällknappar: ett namn per rad, ingenting mer. Mockupen ritar samma träd som en tabell, med kolumner för typ, dokument, uppgifter och senast ändrad, containern som rotrad, en knapp *Expand all* samt sökfältet och filtret ovanför. Rubriken *Items*, *New item* och växeln List/Tree/Map står på en och samma rad.

Trädet filtrerar ingenting i dag, med flit: issue 154 lät filtret höra till listan. Mockupen har filterraden även i trädläget.

## Beslut

**1. Trädet är en tabell.** Kolumnerna är *Name*, *Category*, *Tags*, *Documents*, *Tasks* och *Last updated*. Mockupens kolumn *Type* blir *Category*, och *Tags* tillkommer. Ikonerna och radens meny (de tre punkterna) byggs inte nu.

- **Category** är itemets kategori, eller tomt.
- **Tags** visar högst två taggar, ritade som i `ItemTagList`, och därefter `+N`.
- **Documents** är antalet levande bilagor på itemet **självt**, och inte på underträdet. Underträdets summor hör hemma på översikten ([[ADR-0040 Underträdets summor]]).
- **Tasks** är antalet **aktiva** förekomster (`open` och `in_progress`, `ScheduleOccurrence::ACTIVE_STATUSES`) på itemets egna scheman.
- **Last updated** är itemets `updated_at`, och det ritas enligt datumregeln som en händelse (`useRelativeDate().eventDate()`, issue 104). Mockupens absoluta tidsstämpel följs inte.

**2. Containern är rotraden.** Raden har containerns namn och ingenting i de andra kolumnerna, och itemträdets rötter (enligt rotregeln i ADR-0041) står under den. Rotraden är öppen från början. Därmed ser trädet ut som i dag tills någon fäller upp en gren.

**3. Ett item på flera platser visar samma siffror på varje plats.** Noderna är förekomster och inte items (`ItemTreeNode`), men siffrorna hör till itemet.

**4. Siffrorna räknar bara det användaren når.** En nod finns bara i trädet om användaren når itemet ([[ADR-0028 Åtkomst på itemnivå]]), och bilagorna och schemana på ett item man når är nåbara. Räkningen görs ändå på exakt de items som står i trädet, och aldrig på containern i stort, så att den inte kan räkna ett item trädet inte visar. Den bor i en egen upplösning bredvid `ResolveItemTree`, så att strukturpanelen och föräldraväljaren inte betalar för kolumner de inte ritar. Frågekostnaden är konstant oavsett trädets storlek.

**5. Filtret gäller trädet.** Sökfältet, taggarna och kategorin är samma filter som i listan, och träffarna är samma som `ListItems` ger. Trädet visar varje träff **och vägen från roten ned till den**. En förfader som inte själv är en träff ritas som väg, och en gren som inte leder till en träff ritas inte. Grenarna som leder till en träff är öppna från början, så att träffarna syns utan klick. Träffen skiljs från vägen med vikten och inte bara med en färg.

Vägen består bara av items i omfånget. Rotregeln gör redan varje item vars förälder är dold till en rot. Trädet visar därför aldrig en förfader användaren inte når, och ett tomt resultat säger inte mer än listans tomma resultat gör (issue 73 § Beslut 6).

**6. *Expand all* öppnar hela trädet**, och en andra tryckning stänger det till utgångsläget. Knappen finns bara i trädläget. Tillståndet bor i vyn och inte i adressen, eftersom det är en tillfällig vy och inte en plats.

**7. Huvudet är gemensamt för de tre lägena.** Rubriken *Items*, *New item* och växeln står på en rad. Under den står filterraden i lägena *Lista* och *Träd*. Kartan filtreras inte, och där ritas raden inte.

**8. Smal skärm.** Under `md:` visas bara namnet, med kategorin som undertext. Kolumnerna kommer fram från `md:`.

## Motivering

Utan kolumner är trädläget listans namn i en annan ordning. Kolumnerna gör det till den vy där man ser strukturen och vad som finns i den samtidigt.

Egna siffror och inte underträdets: en summa som räknar både föräldern och barnen räknar samma dokument på flera rader och blir svår att läsa i ett träd. Mockupen säger emot sig själv på den punkten (Electrical 6, barnen tillsammans 18).

Filtret med vägen kvar är det som skiljer ett filtrerat träd från den filtrerade listan. Utan vägen vore det listan igen.

## Konsekvenser

- Issue 291–295 i [[M33 Trädet som tabell]].
- `ItemStructureTree` står kvar för strukturpanelen (issue 103) och föräldraväljaren (issue 153). Trädläget får en egen tabellkomponent, eftersom en tabellrad och ett led i en panel är olika element.
- `ResolveItemTree` får ett valfritt filter. Panelen och väljaren anropar den utan filter och märker ingen skillnad.
- Raden *Trädläget och kartläget filtrerar ingenting* i `pages/Containers/Items/Index.vue` och `ItemController::index()` gäller inte längre trädet.

## Alternativ

**Underträdets summor i kolumnerna.** Det mockupen antyder på rotraden. Valdes bort: samma dokument räknas på varje nivå ovanför det, och raden blir omöjlig att läsa utan att veta regeln.

**Bara träffarna i trädet, utan vägen.** Det vore listan med indrag. Valdes bort.

**Filtret i kartan också.** Kartans *en öppen gren per nivå* passar dåligt med flera träffar på olika grenar. Det får bli ett eget beslut om det efterfrågas.
