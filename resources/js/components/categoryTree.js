/*
 * Trädet byggs i vyn, ur den platta listan — se issue 56a § Beslut 2.
 *
 * `ListCategories` returnerar containerns kategorier platt, sorterade på
 * `position` och sedan `id`, med `parent` satt per rad — exakt det en
 * API-klient får. Hierarkin byggs här, precis som en API-klient skulle göra
 * den, och servern har ingen vy-specifik trädform.
 *
 * Filen är ren — den importerar varken Vue eller Inertia — så reglerna kan
 * testas utan en webbläsare, samma konstruktion som
 * resources/js/components/accessPresentation.js.
 *
 * Ingen virtualisering: djupet är låst till Category::MAX_DEPTH och trädet är
 * litet per definition. En platt lista på några hundra rader är inget en
 * webbläsare märker.
 */

/*
 * Den platta listan som en lista av rotnoder, med `children` på varje nod.
 *
 * Ordningen är listans egen: Map bevarar insättningsordningen, och den platta
 * listan kommer sorterad från servern — så syskonen står i `position`-ordning
 * utan att den här funktionen sorterar om något.
 *
 * En rad vars `parent` inte finns i listan blir en rot. Det kan inte hända för
 * en kategori: `ListCategories` tar alltid med förfäderna till en synlig
 * kategori. Men en rad som tappar sin förälder ska försvinna synligt, inte
 * krascha vyn.
 */
export function buildCategoryTree(categories) {
    const byUlid = new Map();

    for (const category of categories) {
        byUlid.set(category.ulid, { ...category, children: [] });
    }

    const roots = [];

    for (const node of byUlid.values()) {
        const parent = node.parent === null ? undefined : byUlid.get(node.parent);

        if (parent === undefined) {
            roots.push(node);
        } else {
            parent.children.push(node);
        }
    }

    return roots;
}

/*
 * ULID:n för $ulid och alla dess ättlingar, transitivt. Används av
 * föräldraväljaren: en kategori får inte bli sitt eget eller sin ättlings barn.
 *
 * Den här filtreringen är en ARTIGHET och inte skyddet. `MoveCategory` prövar
 * cykeln och djupet på servern och svarar `category.cycle` respektive
 * `category.max_depth_exceeded`, och det svaret är det som gäller — vyn
 * slipper bara visa val som säkert misslyckas (issue 56a § Beslut 3).
 */
export function descendantUlids(categories, ulid) {
    const childrenOf = new Map();

    for (const category of categories) {
        if (category.parent === null) {
            continue;
        }

        const siblings = childrenOf.get(category.parent) ?? [];
        siblings.push(category.ulid);
        childrenOf.set(category.parent, siblings);
    }

    const found = new Set([ulid]);
    const pending = [ulid];

    while (pending.length > 0) {
        for (const child of childrenOf.get(pending.pop()) ?? []) {
            if (!found.has(child)) {
                found.add(child);
                pending.push(child);
            }
        }
    }

    return found;
}

/*
 * Kategorierna $draggedUlid får släppas på (issue 1484): alla utom kategorin
 * själv, dess nuvarande förälder och dess ättlingar.
 *
 * `$draggedUlid === null` betyder att inget drag pågår, och då är ingen rad
 * ett mål. Att släppa på sin nuvarande förälder är en flytt till samma plats
 * och gör ingenting, så raden markeras aldrig som mål under draget.
 *
 * Precis som i descendantUlids() är det här en ARTIGHET och inte skyddet.
 * `MoveCategory` prövar cykeln och djupet på servern, och dess svar är det
 * som gäller — också för ett släpp vyn hade kunnat räkna ut själv.
 */
export function canDropOn(categories, draggedUlid, targetUlid) {
    if (draggedUlid === null || targetUlid === null || targetUlid === draggedUlid) {
        return false;
    }

    const dragged = categories.find((category) => category.ulid === draggedUlid);

    if (dragged === undefined || dragged.parent === targetUlid) {
        return false;
    }

    if (!categories.some((category) => category.ulid === targetUlid)) {
        return false;
    }

    return !descendantUlids(categories, draggedUlid).has(targetUlid);
}

/*
 * Positionen för en kategori som läggs SIST bland $parent:s barn — `null`
 * betyder roten. Högsta befintliga position plus ett, eller 0 när föräldern
 * saknar barn.
 *
 * Ordningen inom en nivå ändras inte av ett drag i övrigt: den sätts
 * fortfarande med fältet `position` på raden (issue 1484).
 */
export function lastPosition(categories, parent) {
    let highest = -1;

    for (const category of categories) {
        if (category.parent === parent) {
            highest = Math.max(highest, category.position);
        }
    }

    return highest + 1;
}

/*
 * Kroppen för flytten när $draggedUlid släpps på $targetUlid, eller `null`
 * när släppet ska göra ingenting. `$targetUlid === null` är ytan *Top level*.
 *
 * `parent` skickas ALLTID med — också som `null` — så servern anropar
 * `MoveCategory` villkorslöst, samma kontrakt som radens väljare (issue 56a
 * § Beslut 5, issue 1484). Sidan skickar den som samma
 * `PATCH /containers/{container}/categories/{category}` raden redan skickar.
 *
 * @returns {{parent: string|null, position: number}|null}
 */
export function movePayload(categories, draggedUlid, targetUlid) {
    if (draggedUlid === null || !categories.some((category) => category.ulid === draggedUlid)) {
        return null;
    }

    if (targetUlid === null) {
        return { parent: null, position: lastPosition(categories, null) };
    }

    if (!canDropOn(categories, draggedUlid, targetUlid)) {
        return null;
    }

    return { parent: targetUlid, position: lastPosition(categories, targetUlid) };
}

/*
 * Kategorierna som val i en `<select>`, i trädordning och indragna efter djup
 * — se issue 57b § Beslut 5.
 *
 * Ett item ligger i HÖGST EN kategori ([[ADR-0004 Fria taggar och
 * kategorier]]), till skillnad från taggarna som är kryssrutor, och indraget
 * är det som gör trädet läsbart i en platt lista. Hårt mellanslag (U+00A0) och
 * inte vanliga: ett `<option>` kollapsar ledande blanksteg i flera webbläsare.
 *
 * Byggd ur samma platta lista som buildCategoryTree(), så ordningen är
 * serverns — syskonen står i `position`-ordning utan att den här funktionen
 * sorterar om något.
 *
 * "Ingen kategori" är INTE med här: den posten bär `null` och inte en ULID,
 * och vyn äger den raden precis som i föräldraväljaren.
 */
export function categoryOptions(categories) {
    const options = [];

    const walk = (nodes, depth) => {
        for (const node of nodes) {
            options.push({ ulid: node.ulid, name: '\u00A0\u00A0\u00A0'.repeat(depth) + node.name });
            walk(node.children, depth + 1);
        }
    };

    walk(buildCategoryTree(categories), 0);

    return options;
}

/*
 * Valen i en föräldraväljare: containerns kategorier utom $currentUlid och dess
 * ättlingar. `$currentUlid` är `null` när inget är valt — vid skapandet finns
 * ingen cykel att undvika, för den nya kategorin har inga ättlingar.
 *
 * Roten är inte med här: den är ett eget val i vyn ("— ingen, lägg i roten —"),
 * och den posten bär `null` och inte en ULID.
 */
export function parentOptions(categories, currentUlid = null) {
    const excluded = currentUlid === null ? new Set() : descendantUlids(categories, currentUlid);

    return categories
        .filter((category) => !excluded.has(category.ulid))
        .map((category) => ({ ulid: category.ulid, name: category.name }));
}
