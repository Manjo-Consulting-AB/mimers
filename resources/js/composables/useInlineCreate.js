import { onBeforeUnmount } from 'vue';

/*
 * Bryggan mellan skalets plusknapp och sidans formulär, se
 * [[ADR-0074 Registrering i vyn]] § 7 och tillägget 2026-11-18 (issue 1400).
 *
 * **Knappen sitter i skalet, formuläret på sidan.** AppLayout och
 * MobileTabBar äger knappen, men de vet ingenting om uppgifter, items eller
 * filer — därför kan de inte öppna ett formulär. Sidan vet allt om sitt
 * formulär, men ser inte knappen: målet `{ kind: 'inline', form }` går ned
 * som en prop, och knappens klick går upp genom den här modulen.
 *
 * **En räknare på modulnivå.** `request()` ökar räknaren och ropar på varje
 * lyssnare; `onRequest()` hänger på en lyssnare så länge komponenten är
 * monterad. Räknaren är det enda tillståndet, och den finns för att ett klick
 * ska vara en HÄNDELSE och inte ett värde: två klick i följd ska öppna
 * formuläret två gånger, och det kan en boolean inte bära.
 *
 * **Lyssnaren avregistreras vid unmount.** Inertia behåller skalet mellan två
 * sidor, så en sida som glömde ta bort sin lyssnare hade svarat på nästa sidas
 * knapptryck — och det är tyst. Modulen äger därför avregistreringen liksom
 * registreringen.
 */

let requests = 0;

/** @type {Set<(request: number) => void>} */
const listeners = new Set();

/**
 * Knappen har tryckts: räkna och låt sidan öppna sitt formulär.
 */
function request() {
    requests += 1;

    listeners.forEach((listener) => listener(requests));
}

/**
 * Sidan lyssnar på knappen så länge den är monterad.
 *
 * @param {(request: number) => void} callback
 */
function onRequest(callback) {
    listeners.add(callback);

    onBeforeUnmount(() => {
        listeners.delete(callback);
    });
}

export function useInlineCreate() {
    return { request, onRequest };
}
