/*
 * Domänmappningen, se issue 283 och [[ADR-0057 Ikoner och typsnitt]] § 3.
 *
 * **Tonen väljs aldrig för att det ska se omväxlande ut.** En rad utan datum
 * får sin ton ur sin domän: dokument och bild `accent`, kostnad `warning`,
 * uppgift `success`, allt annat `neutral`. Mappningen bor här och inte i varje
 * rad — annars hade varje rad fått sin egen uppfattning om vad en kostnad är.
 *
 * **`danger` ges aldrig av en domän.** Rött betyder att något har passerat,
 * och en kostnad är inte ett fel. Den tonen kommer uteslutande ur datumregeln
 * i `useRelativeDate` (issue 104), aldrig härifrån.
 *
 * En okänd domän ritas som `history` i `neutral`: en domän som läggs till utan
 * att listan uppdateras ska synas som en rad, inte krascha den.
 */
const PRESENTATION = {
    attachment: { role: 'document', tone: 'accent' },
    cost_entry: { role: 'cost', tone: 'warning' },

    schedule: { role: 'task', tone: 'success' },
    schedule_occurrence: { role: 'task', tone: 'success' },
    schedule_dependency: { role: 'task', tone: 'success' },
    occurrence_dependency: { role: 'task', tone: 'success' },

    item: { role: 'item', tone: 'neutral' },
    item_link: { role: 'link', tone: 'neutral' },
    loan: { role: 'loan', tone: 'neutral' },
    tag: { role: 'tag', tone: 'neutral' },
    category: { role: 'category', tone: 'neutral' },
    container: { role: 'container', tone: 'neutral' },

    access: { role: 'share', tone: 'neutral' },
    invitation: { role: 'share', tone: 'neutral' },
    ownership_transfer: { role: 'share', tone: 'neutral' },
};

export function presentationFor(domain) {
    return PRESENTATION[domain] ?? { role: 'history', tone: 'neutral' };
}
