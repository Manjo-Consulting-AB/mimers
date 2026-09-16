/*
 * Urvalets matematik på lagringsytan, se issue 66b § Beslut 4 och 5.
 *
 * **Talet räknas i klienten och bara i klienten** (Beslut 4): det är en
 * FÖRHANDSVISNING av ett urval — vad kryssen skulle frigöra, och vad som skulle
 * återstå — och den ändrar sig med varje kryss. Förbrukningen EFTER en
 * genomförd rensning kommer ur serverns svar (`removed.storageLabel`) och
 * aldrig ur den här subtraktionen; serverns tal är det som gäller, och en
 * klient som räknade själv hade visat fel siffra så fort en bilaga hann
 * raderas av någon annan.
 *
 * **Taket är 100 per anrop** (Beslut 5), samma `max:100` som
 * App\Http\Requests\Account\RemoveStorageRequest sätter. Konstanten står här
 * för att vyn ska RESPEKTERA taket i stället för att krascha mot det: servern
 * nekar ändå, och en halv rensning finns inte (hela begäran är 422).
 * Glider de två talen isär är det ett fel någon ser — den som höjer taket på
 * servern får höja det här med.
 *
 * Egen modul och inte rader i vyn, av samma skäl som itemPresentation.js och
 * trashPresentation.js ligger här: funktionerna går att köra i node och en
 * mall går inte att pröva.
 */

/* Högsta antal bilagor i ett anrop — RemoveStorageRequest::rules(). */
export const MAX_SELECTION = 100;

/*
 * Bytena de valda raderna står för. `selected` är ULID:er och `rows` är
 * serverns rader, så en rad som inte längre finns i listan kan inte räknas in:
 * urvalet är en delmängd av det som visas, aldrig ett eget register.
 *
 * `byte_size` läses ur raden och ingenting annat — samma tal som servern
 * sorterade listan på. En rad utan ett tal bidrar med noll i stället för att
 * göra summan till `NaN`.
 */
export function selectedBytes(rows, selected) {
    const valda = new Set(selected);

    return rows.reduce(
        (summa, rad) => (valda.has(rad.ulid) ? summa + (Number(rad.byte_size) || 0) : summa),
        0,
    );
}

/*
 * Förbrukningen efter rensningen, som förhandsvisning. Klampad vid noll: en
 * urvalslista kan i teorin frigöra mer än räknaren står på (räknaren är
 * förbrukningens sanning, raderna beskriver urvalet), och ett negativt
 * utrymme är ett påhittat tal.
 */
export function remainingBytes(usedBytes, freedBytes) {
    return Math.max(0, (Number(usedBytes) || 0) - freedBytes);
}

/*
 * Fler valda än taket tillåter. Vyn stänger av knappen och säger det innan
 * något skickas (Beslut 5).
 */
export function exceedsLimit(selected) {
    return selected.length > MAX_SELECTION;
}
