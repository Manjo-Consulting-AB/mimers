/*
 * Dokumentflikens filter som querysträng — M24, issue 688 · Beslut 7.
 *
 * Filen är ren: den importerar varken Vue eller Inertia och den rör ingen
 * markup. Samma konstruktion som resources/js/components/itemFilter.js —
 * reglerna går att läsa utan en webbläsare, och ingen svensk sträng bor här
 * ([[ADR-0013 Språk och i18n]]).
 *
 * **Formen är serverns `filter`-propp och ingenting annat.** Vyn läser aldrig
 * adressen själv: en länk byggd på adressens råtext hade kunnat bära med sig
 * ett värde servern kastade (`?kind=video`), och då pekar fältet på ett filter
 * listan inte tillämpade. Formen bor därför på ETT ställe, och växelns
 * länkar, sidnumreringen och sorteringen läser alla den här funktionen i
 * stället för att bygga var sin sträng som glider isär.
 *
 * `kind`, `item` och `uploader` är LISTOR (Beslut 4) och skrivs som `kind[]=…`
 * — samma stavning servern läser. `q` och datumen tas med när de inte är null,
 * `sort=newest` är förvalet och lämnas UTANFÖR, och `view=grid` läggs till när
 * läget är rutnätet. En URL utan brus går att läsa och dela.
 */

/*
 * Filtret och läget som en querysträng (utan inledande `?`).
 *
 * `view` är anroparens läge — `grid` när rutnätet ritas, annars ingenting. Det
 * är en parameter och inte en läsning ur adressen: den som bygger en länk för
 * ett ANNAT läge (växelns två länkar) skickar in det, och servern läser det
 * aldrig (issue 178 § Beslut 2).
 */
export function documentFilterQuery(filter, view) {
    const params = new URLSearchParams();

    if (filter.q !== null && filter.q !== undefined && filter.q !== '') {
        params.set('q', filter.q);
    }

    for (const key of ['kind', 'item', 'uploader']) {
        for (const value of filter[key] ?? []) {
            params.append(`${key}[]`, value);
        }
    }

    for (const key of ['from', 'to']) {
        if (filter[key] !== null && filter[key] !== undefined && filter[key] !== '') {
            params.set(key, filter[key]);
        }
    }

    if (filter.sort !== null && filter.sort !== undefined && filter.sort !== 'newest') {
        params.set('sort', filter.sort);
    }

    if (view === 'grid') {
        params.set('view', 'grid');
    }

    return params.toString();
}
