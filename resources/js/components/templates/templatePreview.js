/*
 * Förhandsvisningen av en mall, se issue 422 och
 * [[ADR-0089 Egna fält på items]] § Riktning 6.
 *
 * **Vad som läggs till och vad som hoppas över räknas HÄR, i klienten.** En
 * malls fält vars rubrik redan finns på containerns items hoppas över av
 * servern (App\Actions\Template\ApplyTemplate), och användaren ska se det
 * INNAN hon tillämpar — inte som en överraskning i flashmeddelandet efteråt.
 * Modulen är ren och testas i node, samma väg som
 * resources/js/components/categoryTree.js.
 *
 * Jämförelsen är skiftlägesoberoende och trimmad, precis som serverns
 * förkontroll. Den är inte auktoritativ: databasens collation kan se två
 * rubriker som samma där JavaScript ser dem som olika. Serverns svar gäller
 * alltid; förhandsvisningen är en upplysning och får vara försiktig.
 *
 * Två fält med SAMMA rubrik i samma mall räknas som en krock: det andra
 * hoppas över, eftersom det första redan har lagts till när servern når det.
 */

/**
 * Antalet kategorinoder i trädet, alla nivåer.
 *
 * @param {Array<{children?: Array<unknown>}>} categories
 * @returns {number}
 */
export function countCategoryNodes(categories = []) {
    return categories.reduce(
        (sum, category) => sum + 1 + countCategoryNodes(category.children ?? []),
        0,
    );
}

/**
 * Vad en mall lägger till på en container.
 *
 * @param {{categories?: Array, fields?: Array<{label: string, type: string}>}|null} content
 * @param {Array<string>} existingLabels Rubrikerna som redan syns på containerns items.
 * @returns {{categoryCount: number, addedFields: Array<{label: string, type: string}>, skippedFields: Array<string>}}
 */
export function templatePreview(content, existingLabels = []) {
    const categories = content?.categories ?? [];
    const fields = content?.fields ?? [];

    const known = new Set(
        existingLabels.map((label) => String(label).trim().toLowerCase()),
    );

    const addedFields = [];
    const skippedFields = [];

    for (const field of fields) {
        const label = String(field.label ?? '').trim();
        const key = label.toLowerCase();

        if (known.has(key)) {
            skippedFields.push(label);

            continue;
        }

        known.add(key);
        addedFields.push({ label, type: field.type });
    }

    return {
        categoryCount: countCategoryNodes(categories),
        addedFields,
        skippedFields,
    };
}

/**
 * Mallens storlek, som kontots mallista visar den: antal kategorier och fält.
 *
 * @param {{categories?: Array, fields?: Array}|null} content
 * @returns {{categories: number, fields: number}}
 */
export function templateCounts(content) {
    return {
        categories: countCategoryNodes(content?.categories ?? []),
        fields: (content?.fields ?? []).length,
    };
}
