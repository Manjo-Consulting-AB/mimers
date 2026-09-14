/*
 * Hur en åtkomstrad beskrivs, se issue 55a § Beslut 5, 6 och 7.
 *
 * Två ytor renderar samma rad: den giltiga raden i ContainerAccessRow.vue —
 * med nivåfältet och återkalla-knappen — och den historiska raden i
 * Sharing.vue, som bara redovisas. Beskrivningen av omfånget och av vad
 * `kind` betyder får inte formuleras två gånger: då kan de två listorna säga
 * olika saker om samma rad.
 *
 * Filen är ren — den importerar varken Vue eller Inertia — och tar `t` som
 * argument, precis som resources/js/i18n/translate.js. Texten kommer alltså
 * fortfarande ur lang/{locale}/ui.php; den här filen väljer bara vilken
 * nyckel som gäller för en rad.
 *
 * Ingen härledning av tillstånd: `revoked_at` och `expires_at` delar raderna
 * i giltiga och historiska, och den uppdelningen gör vyn (Beslut 7).
 * Resursen bär med flit ingen `status`.
 */

/**
 * Omfånget: hela pärmen när `item` är null, annars itemets namn plus hur
 * många items granten faktiskt når.
 *
 * `reach` kommer FÄRDIGT ur App\Http\Resources\ContainerAccessResource och
 * räknas aldrig om här (Beslut 6). Talet är `null` för en containerbred rad —
 * med flit, och därför visas det inte alls där: en containerbred rad är
 * "Hela pärmen" och ingenting mer.
 *
 * Namnet slås upp i `itemNames`, som kontrollern skickar som ett eget
 * uppslag (`{ ulid: namn }`) ur EN fråga med `withTrashed()` — en grant på
 * ett mjukraderat item redovisas med sitt namn, inte som en ULID.
 */
export function accessScopeLabel(t, itemNames, access) {
    if (access.item === null) {
        return t('sharing.scope.container');
    }

    return t('sharing.scope.item', {
        item: itemNames[access.item] ?? access.item,
        reach: access.reach ?? 0,
    });
}

/**
 * `kind` med sin konsekvens, inte som ett rått ord (Beslut 5): `member` är
 * en person, `managed` är en organisation med servicerelation som inte äger
 * pärmen, `guest` är tillfällig och bär ett utgångsdatum.
 */
export function accessKindLabel(t, access) {
    return t(`sharing.kind.${access.kind}`);
}

/**
 * Ett datum ur en ISO 8601-sträng, i användarens locale. Formen är
 * klientens — orden runt den ("Går ut :date") kommer ur lang/.
 */
export function formatDate(iso, locale) {
    if (!iso) {
        return '';
    }

    return new Date(iso).toLocaleDateString(locale);
}
