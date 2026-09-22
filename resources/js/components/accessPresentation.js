import { formatLocaleDate } from '../composables/useRelativeDate.js';

/*
 * Hur en åtkomstrad beskrivs, se issue 55a § Beslut 5, 6 och 7.
 *
 * Två ytor renderar samma rad: den giltiga raden i ContainerAccessRow.vue —
 * med nivåfältet och återkalla-knappen — och den historiska raden i
 * Sharing.vue, som bara redovisas. Beskrivningen av omfånget, av vad `kind`
 * betyder och av vem mottagaren och beviljaren är får inte formuleras två
 * gånger: då kan de två listorna säga olika saker om samma rad.
 *
 * Filen tar `t` som argument, precis som resources/js/i18n/translate.js.
 * Texten kommer alltså fortfarande ur lang/{locale}/ui.php; den här filen
 * väljer bara vilken nyckel som gäller för en rad.
 *
 * Den enda importen är `formatLocaleDate()` ur datumregeln (issue 104):
 * frontenden har ett anrop till `Intl`, och det bor där. Att skriva ut ett
 * datum är inte att välja hur det skrivs.
 *
 * Ingen härledning av tillstånd: `revoked_at` och `expires_at` delar raderna
 * i giltiga och historiska, och den uppdelningen gör vyn (Beslut 7).
 * Resursen bär med flit ingen `status`.
 */

/**
 * Omfånget: hela containern när `item` är null, annars itemets namn plus hur
 * många items granten faktiskt når.
 *
 * `reach` kommer FÄRDIGT ur App\Http\Resources\ContainerAccessResource och
 * räknas aldrig om här (Beslut 6). Talet är `null` för en containerbred rad —
 * med flit, och därför visas det inte alls där: en containerbred rad är
 * "Hela containern" och ingenting mer.
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
 * containern, `guest` är tillfällig och bär ett utgångsdatum.
 */
export function accessKindLabel(t, access) {
    return t(`sharing.kind.${access.kind}`);
}

/*
 * Mottagarens och beviljarens namn, se arkitektsvaret § 1.
 *
 * `ContainerAccessResource` bär ULID:er — `/api` har inte bett om namn — och
 * `Mottagare 01JKX7Q3F8Z2N6M4B9T0R5V1WQ` är oläsbar för den enda publik
 * åtkomstsektionen har. Kontrollern skickar därför två uppslag,
 * `granteeNames` och `grantedByNames`, med samma form och samma läckageregel
 * som `itemNames`: tomma när `accesses` är `null`.
 *
 * **ULID:en är nyckeln och aldrig texten.** Den ligger kvar i `access` för
 * att den här uppslagningen ska gå att göra — precis som `access.item` ligger
 * kvar för itemets namn — men den renderas inte någonstans.
 *
 * **Ett namn som saknas blir en mening, inte en ULID.** Varken `User` eller
 * `Account` använder `SoftDeletes`, så en mottagare kan faktiskt vara borta;
 * då finns ingen nyckel, och raden får `sharing.accesses.grantee_unknown`.
 * Det är inte den fallback `ParticipantResource` förbjuder — den handlar om
 * en kolumn som aldrig är tom, den här om en rad som inte längre finns.
 *
 * Ingen e-postadress någonsin: [[Konton och åtkomst]] § Behörighetsregler,
 * sista stycket.
 */

/** Mottagarens namn — en `User` eller ett `Account`, samma prop. */
export function granteeLabel(t, granteeNames, access) {
    return granteeNames[access.grantee] ?? t('sharing.accesses.grantee_unknown');
}

/** Beviljarens namn — alltid en `User`. */
export function grantedByLabel(t, grantedByNames, access) {
    return grantedByNames[access.granted_by] ?? t('sharing.accesses.granted_by_unknown');
}

/**
 * Ett datum ur en ISO 8601-sträng, i användarens locale. Formen är
 * klientens — orden runt den ("Går ut :date") kommer ur lang/.
 */
export function formatDate(iso, locale) {
    if (!iso) {
        return '';
    }

    return formatLocaleDate(new Date(iso), locale);
}
