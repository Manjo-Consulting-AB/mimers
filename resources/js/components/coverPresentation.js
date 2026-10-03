/*
 * Presentationshjälpare för containerns bild, se [[ADR-0047 Containerns bild]]
 * § Beslut, issue 681 och issue 683.
 *
 * **Den begärda varianten ritas om den finns, annars originalet.** `cover` är
 * `{ ulid, variants, focus }` eller null, och `variants` bär de varianter som
 * FINNS (samma form som `variants`-proppen på itemets detaljvy, issue 61b
 * § Beslut 1). Finns den begärda varianten i listan ritas `?variant=<den>`,
 * annars originalet. Ingen kedja `medium` → `thumb`:
 * App\Jobs\GenerateImageDerivatives skriver aldrig en variant som vore en
 * förstoring, så en bild utan `medium` är antingen mindre än 1024 px
 * (originalet duger) eller ännu inte bearbetad (originalet är det enda som
 * finns). Ett nyuppladdat foto har inga derivat förrän kön kört, och en
 * `<img>` mot en variant som saknas är en trasig bild (issue 19a § Beslut 5) —
 * originalet är alltid en giltig URL, så fallbacken är ett faktum och ingen
 * gissning.
 *
 * **Egen modul och inte rader i vyn** (issue 683 § Beslut 1). Regeln bodde i
 * `ContainerCover.vue` och behövdes igen av väljaren i `ContainerCoverSheet.vue`;
 * två formuleringar av samma regel hade glidit isär. Funktionen går att köra i
 * node, som grannmodulerna.
 *
 * **Adressen går alltid till appdomänen.** `/files/{ulid}` svarar 302 till en
 * signerad länk på filoriginet, och signaturen präglas där behörigheten prövas
 * (issue 61a § Beslut 1 och 4). Att bygga filoriginets URL i klienten hade
 * varit en andra och osignerad väg till samma byten.
 *
 * `null` utan `cover`: anroparen ritar då den neutrala ytan, aldrig en tom ram
 * (ADR-0047 § Beslut).
 */
export function coverUrl(cover, variant) {
    if (! cover) {
        return null;
    }

    const available = cover.variants?.includes(variant) ? variant : null;

    return available === null
        ? `/files/${cover.ulid}`
        : `/files/${cover.ulid}?variant=${available}`;
}
