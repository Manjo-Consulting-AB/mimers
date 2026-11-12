/*
 * Flyttlogiken för panelerna på containerns översikt, se issue 1115 ·
 * [[ADR-0073 Översiktens ordning]] § 5–6.
 *
 * En ren funktion: den tar listan, nyckeln som flyttas och nyckeln vars plats
 * den ska ta, och ger en ny lista. Listan den fick ändras aldrig. Pilarna och
 * draget på sidan använder samma funktion, så de kan inte glida isär.
 */

/*
 * Flyttar panelen `key` till platsen där panelen `targetKey` står; panelerna
 * emellan rycker ett steg åt andra hållet. En okänd nyckel, ett okänt mål och
 * ett släpp på sig själv ger en kopia av listan som den var.
 */
export function movePanelTo(panels, key, targetKey) {
    const from = panels.findIndex((panel) => panel.key === key);
    const to = panels.findIndex((panel) => panel.key === targetKey);

    if (from === -1 || to === -1 || from === to) {
        return [...panels];
    }

    const next = [...panels];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);

    return next;
}
