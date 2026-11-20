/*
 * Filtrens etiketter — issue 59a § Beslut 4 och 6.
 *
 * Filen är ren: den importerar varken Vue eller Inertia, och den tar `t` som
 * argument. Samma konstruktion som resources/js/components/categoryTree.js —
 * reglerna går att läsa utan en webbläsare, och ingen svensk sträng bor här.
 *
 * Etiketterna används på TVÅ ställen — chipsen ovanför listan (Beslut 6) och
 * meningen i den tomma träfflistan (Beslut 4). Två uppräkningar av samma filter
 * glider isär, och den ena hade förr eller senare glömt ett filter.
 *
 * **Meningen räknar upp det ANVÄNDAREN själv satt och ingenting annat.** Inget
 * tal om hur många rader omfånget höll borta, ingen antydan om att svaret
 * skulle vara ofullständigt (issue 73 § Beslut 6). En omfångsbegränsad
 * mottagare får därför ordagrant samma mening som ägaren: funktionerna här vet
 * ingenting om omfång, och `filter`, `tags` och `categories` är alla redan
 * filtrerade av servern.
 *
 * **Ingen filtrering sker här.** En tagg-ULID som inte finns i `tags` hoppas
 * över — servern har redan tagit bort den och sagt till i `filter.dropped` —
 * och ingenting sållas ur listan.
 */

/*
 * De aktiva filtren, i den ordning de ritas och räknas upp: sökordet först,
 * sedan taggarna i länkens ordning, sist kategorin.
 *
 * `key` och `value` är det vyn behöver för att kunna ta bort ETT filter:
 * `q` och `category` bär `null` (det finns bara ett av var), medan varje tagg
 * bär sin egen ULID.
 *
 * `name` bär namnet utan etikett, för den grupperade taggformuleringen i
 * filterSummary().
 */
export function activeFilters(filter, tags, categories, fields, t) {
    const entries = [];

    if (filter.q !== null && filter.q !== '') {
        entries.push({
            key: 'q',
            value: null,
            name: filter.q,
            label: t('item.index.filter_label_q', { value: filter.q }),
        });
    }

    for (const ulid of filter.tags) {
        const tag = tags.find((candidate) => candidate.ulid === ulid);

        if (tag !== undefined) {
            entries.push({
                key: 'tag',
                value: ulid,
                name: tag.name,
                label: t('item.index.filter_label_tag', { name: tag.name }),
            });
        }
    }

    if (filter.category !== null) {
        const category = categories.find((candidate) => candidate.ulid === filter.category);

        if (category !== undefined) {
            entries.push({
                key: 'category',
                value: null,
                name: category.name,
                label: t('item.index.filter_label_category', { name: category.name }),
            });
        }
    }

    /*
     * Egna fält (issue 424). Etiketten räknar upp de operatorer användaren
     * faktiskt satte — *Färg: Röd*, *Vikt: 5–10*, *Köpt: today–within:30* —
     * och ett relativt datum visas med samma ord som snabbvalet, aldrig som
     * ett datum komponenten räknat själv.
     */
    for (const [ulid, operations] of Object.entries(filter.fields ?? {})) {
        const field = (fields ?? []).find((candidate) => candidate.ulid === ulid);

        if (field === undefined) {
            continue;
        }

        entries.push({
            key: 'field',
            value: ulid,
            name: field.label,
            label: fieldFilterLabel(field, operations, t),
        });
    }

    return entries;
}

/*
 * Etiketten för ett fältfilter. Text har ett värde; tal och datum har en
 * undre och/eller övre gräns.
 */
function fieldFilterLabel(field, operations, t) {
    if (operations.eq !== undefined) {
        return t('item.index.filter_label_field_eq', { label: field.label, value: operations.eq });
    }

    const min = operations.min === undefined ? null : fieldValueLabel(operations.min, t);
    const max = operations.max === undefined ? null : fieldValueLabel(operations.max, t);

    if (min !== null && max !== null) {
        return t('item.index.filter_label_field_range', { label: field.label, min, max });
    }

    if (min !== null) {
        return t('item.index.filter_label_field_min', { label: field.label, value: min });
    }

    return t('item.index.filter_label_field_max', { label: field.label, value: max });
}

/*
 * Ett relativt datum blir snabbvalets ord — *Today*, *Within 30 days*,
 * *Past 90 days* — och ett absolut datum står som det är.
 */
function fieldValueLabel(value, t) {
    if (value === 'today') {
        return t('item.index.filter_field_today');
    }

    const relative = /^(within|past):(\d+)$/.exec(String(value));

    if (relative === null) {
        return value;
    }

    return t(
        relative[1] === 'within' ? 'item.index.filter_field_within' : 'item.index.filter_field_past',
        { days: relative[2] },
    );
}

/*
 * Uppräkningen i den tomma träfflistans mening, ur samma poster som chipsen.
 *
 * Taggarna blir EN grupp — "taggarna Motor, Impeller" och inte "taggen Motor,
 * taggen Impeller" — medan sökordet och kategorin står för sig. Ordningen är
 * posternas.
 */
export function filterSummary(entries, t) {
    const parts = [];
    let tagNames = [];

    const flushTags = () => {
        if (tagNames.length === 1) {
            parts.push(t('item.index.filter_label_tag', { name: tagNames[0] }));
        } else if (tagNames.length > 1) {
            parts.push(t('item.index.filter_label_tags', { names: tagNames.join(', ') }));
        }

        tagNames = [];
    };

    for (const entry of entries) {
        if (entry.key === 'tag') {
            tagNames.push(entry.name);

            continue;
        }

        flushTags();
        parts.push(entry.label);
    }

    flushTags();

    return parts.join(', ');
}
