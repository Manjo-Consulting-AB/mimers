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
 * Valen i en föräldraväljare: pärmens kategorier utom $currentUlid och dess
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
