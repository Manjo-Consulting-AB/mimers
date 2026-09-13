import { nextTick } from 'vue';

/*
 * Fokus på första felmeddelandet, se issue 53a § Beslut 10.
 *
 * Filen ligger hos sina enda tre användare (pages/Auth) i stället för i
 * resources/js/composables/: den vyn är den enda som importerar den, och
 * issue 53a:s omfångsruta räknar inte upp composables-katalogen. En ny fil
 * utanför rutan fäller omfångsgrinden (se .github/scripts/omfangsruta.py).
 * Inertia löser upp sidor på `./pages/**\/*.vue`, så en .js-fil här bredvid
 * vyerna krockar inte med sidnamnen.
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
