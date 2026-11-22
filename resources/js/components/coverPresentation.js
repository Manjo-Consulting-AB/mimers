/*
 * Presentationshjälpare för containerns bild, se [[ADR-0047 Containerns bild]]
 * § Beslut, issue 681, issue 683 och [[ADR-0090 Dashboardens hjälte]] § 5.
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

/**
 * Zoomen klamrad till kolumnens intervall: 100–300, och 100 när inget är
 * sparat (NULL betyder 100, se [[ADR-0090 Dashboardens hjälte]] § 5).
 *
 * @param {number|null|undefined} zoom
 * @returns {number}
 */
function coverZoom(zoom) {
    if (! Number.isFinite(zoom)) {
        return 100;
    }

    return Math.min(300, Math.max(100, Number(zoom)));
}

/**
 * Bildens stil: fokuspunkten som `object-position` och zoomen som en
 * förstoring runt SAMMA punkt, eller `null` när inget utsnitt är valt.
 *
 * **Fokuspunktionen är redan en `object-position`** ([[ADR-0047 Containerns
 * bild]], issue 682): punkten `x %` av bilden läggs på `x %` av rutan, och
 * `object-cover` garanterar att ingen tom kant uppstår. Zoomen läggs ovanpå
 * som `transform: scale()`, med `transform-origin` i samma punkt — då står
 * punkten kvar där den stod och bilden förstoras runt den. Det är samma
 * regel som `UserAvatar` ritar den runda formen med, men i CSS i stället för
 * i bildpunkter: containerns bild fyller en ruta vars mått bara anroparen
 * känner, och en `object-position` behöver dem inte.
 *
 * **Utan värden ritas bilden som i dag.** `focus === null` och zoom 100 ger
 * `null`, alltså ingen stil alls — `object-cover` centrerar redan av sig
 * själv, och en stil som säger "mitten" är samma svar två gånger.
 *
 * @param {{focus: {x: number, y: number}|null, zoom: number|null}|null} cover
 * @returns {{objectPosition: string, transform?: string, transformOrigin: string}|null}
 */
export function coverStyle(cover) {
    const focus = cover?.focus ?? null;
    const zoom = coverZoom(cover?.zoom);

    if (focus === null && zoom === 100) {
        return null;
    }

    const x = focus?.x ?? 50;
    const y = focus?.y ?? 50;

    return {
        objectPosition: `${x}% ${y}%`,
        ...(zoom === 100 ? {} : { transform: `scale(${zoom / 100})` }),
        transformOrigin: `${x}% ${y}%`,
    };
}

/**
 * Fokuspunkten efter en dragning i den BREDA formen.
 *
 * **Procenten mäts ur överflödet, inte ur bildens bredd.** En
 * `object-position` på `p %` flyttar bilden `p %` av det överflöd som
 * `object-cover` lämnar — `ritad bredd - rutans bredd` — och zoomen förstoras
 * runt samma punkt. Ett drag på `deltaX` bildpunkter flyttar därför punkten
 * `deltaX / överflödet` av intervallet, och bilden följer fingret: en rörelse
 * åt höger sänker `x`. Det är samma känsla som den runda formen
 * (`imageDragFocus`), men en annan nämnare — den runda formen mäter mot hela
 * den ritade bilden, för där är punkten en förskjutning från mitten.
 *
 * Saknar bilden överflöd i en led (`ritad bredd === rutans bredd`) kan den
 * inte flyttas i den leden, och punkten står kvar — samma klamring som
 * `imageCrop` gör, fast ur `object-cover` i stället för ur bildpunkter.
 *
 * @param {object} options
 * @param {{x: number, y: number}|null} options.focus
 * @param {number|null} options.zoom
 * @param {number} options.imageWidth
 * @param {number} options.imageHeight
 * @param {number} options.boxWidth
 * @param {number} options.boxHeight
 * @param {number} [options.deltaX]
 * @param {number} [options.deltaY]
 * @returns {{x: number, y: number}}
 */
export function coverDragFocus({
    focus = null,
    zoom = null,
    imageWidth,
    imageHeight,
    boxWidth,
    boxHeight,
    deltaX = 0,
    deltaY = 0,
}) {
    const start = {
        x: Math.round(focus?.x ?? 50),
        y: Math.round(focus?.y ?? 50),
    };

    if (! (imageWidth > 0) || ! (imageHeight > 0) || ! (boxWidth > 0) || ! (boxHeight > 0)) {
        return start;
    }

    const cover = Math.max(boxWidth / imageWidth, boxHeight / imageHeight);
    const scale = cover * coverZoom(zoom) / 100;

    // Överflödet i bildpunkter, zoomen inräknad: det är den ritade bilden
    // som `object-position` fördelar, och transformen förstorar den.
    const overflowX = Math.max(0, imageWidth * scale - boxWidth);
    const overflowY = Math.max(0, imageHeight * scale - boxHeight);

    return {
        x: overflowX === 0
            ? start.x
            : clampFocus(start.x - deltaX * 100 / overflowX, 0, 100),
        y: overflowY === 0
            ? start.y
            : clampFocus(start.y - deltaY * 100 / overflowY, 0, 100),
    };
}

/**
 * Fokuspunkten efter ett piltryck i den breda formen. Steget är i procent
 * (2, eller 10 med Shift) och klamras till 0–100, som är hela intervallet
 * `object-position` kan visa.
 *
 * @param {object} options
 * @param {{x: number, y: number}|null} options.focus
 * @param {number} [options.stepX]
 * @param {number} [options.stepY]
 * @returns {{x: number, y: number}}
 */
export function coverNudgeFocus({ focus = null, stepX = 0, stepY = 0 }) {
    return {
        x: clampFocus((focus?.x ?? 50) + stepX, 0, 100),
        y: clampFocus((focus?.y ?? 50) + stepY, 0, 100),
    };
}

/**
 * Avrundar och klamrar en fokuspunkt till ett intervall.
 *
 * @param {number} value
 * @param {number} min
 * @param {number} max
 * @returns {number}
 */
function clampFocus(value, min, max) {
    return Math.max(Math.ceil(min), Math.min(Math.floor(max), Math.round(value)));
}
