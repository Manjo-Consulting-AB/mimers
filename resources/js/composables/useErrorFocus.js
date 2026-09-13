import { nextTick } from 'vue';

/*
 * Fokus på första felmeddelandet, se issue 53a § Beslut 10.
 *
 * Ett formulär som postas och kommer tillbaka med ett fel ska inte lämna
 * fokus kvar på skicka-knappen: den som använder tangentbord eller
 * skärmläsare får då ingen signal om att svaret ens kom, än mindre var felet
 * sitter. FormField ger felmeddelandet id:t `<fält>-error`, och fältets namn
 * är samma nyckel servern använder i felpåsen — `errors.email` hör till
 * fältet `email` — så uppslaget behöver ingen tabell.
 *
 * `nextTick` innan fokuset: fältet finns inte i DOM:en förrän svaret har
 * renderats. Ett fält som ännu inte finns (felet hör till något annat än
 * ett renderat fält) ger ingen fokusflytt alls, inte en krasch.
 *
 * Fältet måste ha `tabindex="-1"` för att gå att fokusera — se FormField.
 */
export function useErrorFocus() {
    function focusFirstError(errors) {
        const [field] = Object.keys(errors ?? {});

        if (!field) {
            return;
        }

        nextTick(() => {
            document.getElementById(`${field}-error`)?.focus();
        });
    }

    return { focusFirstError };
}
