/*
 * Kartans uppställning, se issue 349 · [[ADR-0072 Kartan fyller bredden]]
 * § 1–3.
 *
 * **Tre fasta storlekar.** Måtten här motsvarar Tailwind-klasserna som
 * `ItemMapNode` ritar från `md:`: `card` är kortets bredd (`w-60`, `w-48`,
 * `w-44`), `center` mittens (`w-64`, `w-56`, `w-56`) och `gap` mellanrummet i
 * sidled (`gap-6`, `gap-6`, `gap-4`). Byts en klass där ska måttet bytas här.
 *
 * **Barnen på högst två jämna rader** (§ 2): `n` på en rad när `n ≤ 4`, annars
 * `ceil(n / 2)` med taket sex. Storleken väljs ur antalet per rad — fyra eller
 * färre ger `lg`, fem `md` och sex `sm` — så att den aldrig växer när antalet
 * barn ökar.
 *
 * **Ramen avgör** (§ 3). Antalet per rad prövas nedåt, och varje antal
 * fördelas jämnt på de rader det kräver; för varje antal prövas storlekarna
 * nedåt. Fler kort per rad går alltså före större kort. Utan mätt ram (`null`)
 * gäller uppställningen ur § 2 som den är.
 */
export const MAP_SIZES = {
    lg: { card: 240, center: 256, gap: 24 },
    md: { card: 192, center: 224, gap: 24 },
    sm: { card: 176, center: 224, gap: 16 },
};

const ORDER = ['lg', 'md', 'sm'];

const MAX_PER_ROW = 6;

/* Mittens rad: de relaterades kolumner har `gap-12` och den yttersta noden `mr-8`/`ml-8`. */
const RELATED_GAP = 48;
const RELATED_OFFSET = 32;

export const targetPerRow = (count) => (
    count <= 4 ? Math.max(1, count) : Math.min(MAX_PER_ROW, Math.ceil(count / 2))
);

export const sizeForPerRow = (perRow) => (perRow <= 4 ? 'lg' : perRow === 5 ? 'md' : 'sm');

export const rowWidth = (cards, size) => {
    const { card, gap } = MAP_SIZES[size];

    return cards <= 0 ? 0 : cards * card + (cards - 1) * gap;
};

const centerRowWidth = (size, hasRelated) => {
    const { card, center } = MAP_SIZES[size];

    return hasRelated ? center + 2 * (RELATED_GAP + card + RELATED_OFFSET) : center;
};

/* Antalet per rad när barnen fördelas jämnt på de rader `perRow` kräver. */
const balanced = (count, perRow) => (
    count <= perRow ? Math.max(1, count) : Math.ceil(count / Math.ceil(count / perRow))
);

/**
 * @param {{children: number, parents: number, hasRelated: boolean, width: number|null}} input
 * @returns {{size: 'lg'|'md'|'sm', perRow: number}}
 */
export function mapLayout({ children, parents, hasRelated, width }) {
    const target = targetPerRow(children);

    if (width == null) {
        return { size: sizeForPerRow(target), perRow: target };
    }

    const fits = (perRow, size, withCenter) => Math.max(
        rowWidth(Math.min(perRow, children), size),
        rowWidth(parents, size),
        withCenter ? centerRowWidth(size, hasRelated) : 0,
    ) <= width;

    /*
     * Mittens rad bryts inte av antalet barn per rad. Ryms den inte ens med
     * små kort prövas raderna utan den, med små kort, så att en smal ram inte
     * ger ett kort per rad bara för att de relaterade tar plats.
     */
    for (const withCenter of [true, false]) {
        for (let tried = target; tried >= 1; tried -= 1) {
            const perRow = balanced(children, tried);

            const sizes = withCenter ? ORDER.slice(ORDER.indexOf(sizeForPerRow(perRow))) : ['sm'];

            for (const size of sizes) {
                if (fits(perRow, size, withCenter)) {
                    return { size, perRow };
                }
            }
        }
    }

    return { size: 'sm', perRow: 1 };
}
