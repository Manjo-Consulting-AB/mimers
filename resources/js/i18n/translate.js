/*
 * Uppslagningen av en sträng, se issue 52 § Beslut 4.
 *
 * Reglerna, och inga andra:
 *
 *   - Punktnycklar slås upp i den delade arrayen (`nav.dashboard`).
 *   - `:param` byts mot värdet i `params` — samma syntax som lang/-filerna
 *     använder på serversidan, så en sträng kan flyttas mellan dem utan att
 *     skrivas om.
 *   - En nyckel som inte finns returnerar NYCKELN SJÄLV, aldrig en tom
 *     sträng. En saknad översättning ska synas i vyn som `nav.dashboard`,
 *     precis som `auth.totp_invalid` gjorde innan dess nycklar fanns — en
 *     tyst tom sträng är ett fel ingen upptäcker.
 *
 * Ingen pluralisering: Laravels `trans_choice` har ingen motsvarighet här,
 * och ingen sträng i M10 behöver den ännu (Beslut 4).
 *
 * Filen är ren — den importerar varken Vue eller Inertia — så reglerna kan
 * testas utan en webbläsare. Kopplingen till sidans props ligger i
 * resources/js/composables/useTranslations.js.
 */
export function translate(translations, key, params = {}) {
    const value = key
        .split('.')
        .reduce((node, part) => (node == null ? undefined : node[part]), translations);

    if (typeof value !== 'string') {
        return key;
    }

    return value.replace(/:([A-Za-z0-9_]+)/g, (placeholder, name) =>
        Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : placeholder,
    );
}
