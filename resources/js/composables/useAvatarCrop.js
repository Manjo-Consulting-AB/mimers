/*
 * Räkningen bakom profilbildens utsnitt, se [[ADR-0076 Profilbilden]] § 7 och
 * [[M59 Profilbilden]] § 382.
 *
 * **En ren funktion, delad av ytan och provet.** Avataren ritas i en ruta av
 * en given storlek, och utsnittet är en FOKUSPUNKT (procent av originalet)
 * plus en ZOOM (procent). Funktionen översätter de tre talen till bildens
 * faktiska mått och placering i rutan, så att komponenten bara behöver sätta
 * dem som CSS. Provet kör SAMMA funktion i node med kända fall — en andra
 * upplaga av formeln i provet hade kunnat vara grön medan ytan ritade fel.
 *
 * **Utan tom kant.** Utgångsläget är `object-fit: cover`: bilden skalas så
 * att den täcker rutan, och zoomen förstoras runt fokuspunkten. Förskjutningen
 * mäts från den CENTRERADE bilden — `0` vid mitten och 100 % — och klamras
 * till bildens överflöd, så en punkt i kanten stannar vid bildens kant i
 * stället för att dra in en tom remsa.
 *
 * **Fokus står i rutans mitt så långt bilden räcker.** Vid en punkt nära
 * kanten kan bilden inte flyttas längre; då står punkten så nära mitten den
 * kan utan att en tom kant uppstår. Det är klamringen ovan.
 *
 * Måtten är bildpunkter. `imageWidth`/`imageHeight` är originalets
 * naturliga mått; saknas de (bilden har inte laddats än) returneras en
 * neutral fyllning så att komponenten kan ritas utan att gissa.
 */

/**
 * Klamrar ett tal till ett intervall.
 *
 * @param {number} value
 * @param {number} min
 * @param {number} max
 * @returns {number}
 */
function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
}

/**
 * Utsnittets mått och placering i en kvadratisk ruta.
 *
 * @param {object} options
 * @param {number} options.imageWidth  originalets bredd i bildpunkter
 * @param {number} options.imageHeight originalets höjd i bildpunkter
 * @param {number} options.box         rutans sida i bildpunkter
 * @param {number} [options.focusX]    fokuspunktens x i procent (0–100)
 * @param {number} [options.focusY]    fokuspunktens y i procent (0–100)
 * @param {number} [options.zoom]      zoom i procent (100–300)
 * @returns {{
 *   width: number,
 *   height: number,
 *   left: number,
 *   top: number,
 *   offsetX: number,
 *   offsetY: number,
 *   visibleX: number,
 *   visibleY: number,
 * }}
 */
export function avatarCrop({
    imageWidth,
    imageHeight,
    box,
    focusX = 50,
    focusY = 50,
    zoom = 100,
}) {
    // Bilden har inte laddats: rita en neutral fyllning i stället för att
    // dividera med noll och hoppa till NaN.
    if (!(imageWidth > 0) || !(imageHeight > 0) || !(box > 0)) {
        return {
            width: box > 0 ? box : 0,
            height: box > 0 ? box : 0,
            left: 0,
            top: 0,
            offsetX: 0,
            offsetY: 0,
            visibleX: 1,
            visibleY: 1,
        };
    }

    // `cover`: den mest krävande sidan avgör skalan, så ingen tom kant
    // uppstår. Zoomen läggs ovanpå.
    const cover = Math.max(box / imageWidth, box / imageHeight);
    const scale = cover * (zoom / 100);

    const width = imageWidth * scale;
    const height = imageHeight * scale;

    // Hur mycket bilden kan flyttas innan en kant syns.
    const overflowX = Math.max(0, width - box);
    const overflowY = Math.max(0, height - box);

    // Förskjutningen som sätter fokuspunkten i rutans mitt, mätt från den
    // centrerade bilden; klamrad så att bilden alltid täcker rutan.
    const offsetX = clamp(
        width * (0.5 - focusX / 100),
        -overflowX / 2,
        overflowX / 2,
    );
    const offsetY = clamp(
        height * (0.5 - focusY / 100),
        -overflowY / 2,
        overflowY / 2,
    );

    return {
        width,
        height,
        left: (box - width) / 2 + offsetX,
        top: (box - height) / 2 + offsetY,
        offsetX,
        offsetY,
        // Hur stor del av bilden som syns; 200 % ger en halv bild.
        visibleX: box / width,
        visibleY: box / height,
    };
}
