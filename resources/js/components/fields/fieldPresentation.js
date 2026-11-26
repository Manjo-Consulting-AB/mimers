import { formatDateOnly } from '../itemPresentation.js';

/*
 * Presentationen av egna fält, se issue 419 och [[ADR-0089 Egna fält på
 * items]] § Riktning 1–4.
 *
 * Egen modul och inte rader i vyerna, av samma skäl som itemPresentation.js:
 * funktionerna går att köra i node och en mall går inte att pröva. Tre regler
 * bor här, och de gäller både formuläret och itemets vy.
 *
 * **Ett fält utan värde ritas inte på itemet.** `customFieldRows` filtrerar
 * bort `null` och tomma strängar — samma regel som `itemFields()` gör för de
 * sex fasta fälten — så vyn aldrig visar en tom etikett eller ett påhittat
 * streck. Formuläret ritar dem, itemets vy inte
 * ([[ADR-0089 Egna fält på items]] § Riktning 2).
 *
 * **Värdet formateras efter typ.** Ett datum är `Y-m-d` och formateras med
 * `formatDateOnly()` — samma hjälpare som de sex fasta datumen, så ett
 * datumfält aldrig flyttar sig för att användaren bor i en annan tidszon. Ett
 * tal är en DECIMAL(20,6)-sträng ur servern ([[ADR-0089 Egna fält på items]]
 * § Riktning 9) och formateras med användarens språk: decimaltecknet ur
 * `Intl`, men siffrorna själva läses aldrig som ett flyttal och
 * heltalsdelen grupperas inte. 14 heltalssiffror och 6 decimaler är 20
 * värdesiffror, och en `Number` räcker bara till 15–16 — ett sådant tal hade
 * tappat sina sista decimaler på vägen till skärmen. Heltalsdelen skrivs ut
 * som den kom från servern, med inledande nollor borttagna, och decimalerna
 * hängs på orörda. Ett talfält är lika ofta ett årtal, ett serienummer eller
 * ett modellnummer som ett belopp, och `1,985` går med punkt som decimaltecken
 * att läsa som knappt två. Systemet lägger därför inte till några tecken som
 * användaren inte skrev, och fältet är `<input type="number">` som inte tar
 * emot avgränsare.
 *
 * **Ett ja/nej-fält räknas aldrig som tomt** ([[ADR-0099 Kryssrutefält]]).
 * Ett fält som inte är ikryssat betyder *nej* och ritas alltid, annars går
 * det inte att kryssa i på itemet. Det är undantaget från regeln ovan; raden
 * bär typen och det råa värdet så att vyn kan rita en kryssruta.
 *
 * **Typen bestämmer inmatningen.** `fieldInputType()` är hela regeln:
 * talfältet är `<input type="number">` och tar därmed bara tal, datumfältet
 * är webbläsarens datumväljare, och textfältet är ett vanligt textfält.
 */

/**
 * Fälten i den form vyerna ritar dem, oavsett vilken resurs de kom ur.
 *
 * `ItemResource` (itemets formulär och vy) bär `id`, `label`, `type`, `scope`
 * och `value`; `ItemFieldResource` (containerns lista på skapandesidan) bär
 * `ulid` och `item` i stället. Skillnaden slätas ut HÄR och inte i två
 * komponenter, så formuläret har en form att förhålla sig till.
 *
 * @param {Array<object>} fields
 * @returns {Array<{id: string, label: string, type: string, scope: string, value: string|null}>}
 */
export function normalizeFields(fields) {
    return (fields ?? []).map((field) => ({
        id: field.id ?? field.ulid,
        label: field.label,
        type: field.type,
        scope: field.scope ?? (field.item == null ? 'container' : 'item'),
        value: field.value ?? null,
    }));
}

/**
 * Är värdet ett ikryssat ja/nej-fält? Servern ger `true`/`false`; en version
 * bär `true`, och en äldre sträng `1` läses på samma sätt.
 *
 * @param {unknown} value
 * @returns {boolean}
 */
export function isChecked(value) {
    return value === true || value === 1 || value === '1' || value === 'true';
}

/**
 * Formulärets startvärde för ett fält: en boolean för ja/nej, annars värdet
 * eller en tom sträng.
 *
 * @param {{type: string, value: unknown}} field
 * @returns {string|boolean}
 */
export function initialFieldValue(field) {
    if (field.type === 'boolean') {
        return isChecked(field.value);
    }

    return field.value ?? '';
}

/**
 * Har fältet ett sparat värde som ska stå direkt i formuläret? Ett ja/nej-fält
 * har alltid det: det har inget tomt läge att fälla ihop.
 *
 * @param {{type: string, value: unknown}} field
 * @returns {boolean}
 */
export function hasStoredValue(field) {
    return field.type === 'boolean'
        || (field.value !== null && field.value !== undefined && field.value !== '');
}

/**
 * Itemets egna fältrader: de som har ett värde, och alla ja/nej-fält.
 *
 * `key` är fältets ULID och inte rubriken: två fält får heta nästan samma sak,
 * och rubriken är användarens text. `label` är rubriken som den står. En rad
 * för ett ja/nej-fält bär dessutom `type: 'boolean'`, `fieldId` (ULID:en som
 * PATCH:en pekar på) och `checked` (det råa värdet).
 *
 * @param {object} item itemet ur `ItemResource`
 * @param {string} locale användarens språk
 * @param {(key: string) => string} [t] översättaren, för *Yes* och *No*
 * @returns {Array<{key: string, label: string, value: string}>}
 */
export function customFieldRows(item, locale, t = (key) => key) {
    return (item.fields ?? [])
        .filter((field) => field.type === 'boolean'
            || (field.value !== null && field.value !== undefined && field.value !== ''))
        .map((field) => {
            const row = {
                key: `field-${field.id}`,
                label: field.label,
                value: formatFieldValue(field, locale, t),
            };

            if (field.type === 'boolean') {
                row.type = 'boolean';
                row.fieldId = field.id;
                row.checked = isChecked(field.value);
            }

            return row;
        });
}

/**
 * Värdet som användaren ska läsa, formaterat efter fältets typ. Ett ja/nej-fält
 * ger alltid *Yes* eller *No* — aldrig `null` och aldrig `true`.
 *
 * @param {{type: string, value: string|boolean|null}} field
 * @param {string} locale
 * @param {(key: string) => string} [t] översättaren; `field.value.yes`/`no`
 * @returns {string|null}
 */
export function formatFieldValue(field, locale, t = (key) => key) {
    if (field.type === 'boolean') {
        return t(isChecked(field.value) ? 'field.value.yes' : 'field.value.no');
    }

    if (field.value === null || field.value === undefined || field.value === '') {
        return null;
    }

    if (field.type === 'date') {
        return formatDateOnly(field.value, locale);
    }

    if (field.type === 'number') {
        return formatDecimal(field.value, locale);
    }

    return field.value;
}

/**
 * `input`-elementets typ för ett fält, se [[ADR-0089 Egna fält på items]]
 * § Riktning 1. `text` är standardtypen; de två andra känner webbläsaren.
 *
 * @param {string} type
 * @returns {string}
 */
export function fieldInputType(type) {
    if (type === 'number') {
        return 'number';
    }

    return type === 'date' ? 'date' : 'text';
}

/**
 * Decimalsträngen med språkets decimaltecken, utan att tappa värdesiffror.
 *
 * Heltalsdelen grupperas inte: fältet kan lika gärna vara ett årtal som ett
 * belopp, och användaren kan inte skriva någon avgränsare i ett
 * `<input type="number">`. Siffrorna skrivs ut som de kom, med inledande
 * nollor borttagna.
 *
 * @param {string} value
 * @param {string} locale
 * @returns {string}
 */
function formatDecimal(value, locale) {
    const text = String(value).trim();
    const match = /^([+-]?)(\d+)(?:\.(\d+))?$/.exec(text);

    // Servern har redan prövat formatet (App\Actions\Field\ItemFieldInput);
    // ett värde som inte matchar skrivs ut som det kom.
    if (match === null) {
        return text;
    }

    const [, sign, integer, fraction = ''] = match;
    const decimals = fraction.replace(/0+$/, '');
    const whole = integer.replace(/^0+(?=\d)/, '');

    if (decimals === '') {
        return `${sign}${whole}`;
    }

    const separator = new Intl.NumberFormat(locale)
        .formatToParts(1.5)
        .find((part) => part.type === 'decimal')?.value ?? '.';

    return `${sign}${whole}${separator}${decimals}`;
}
