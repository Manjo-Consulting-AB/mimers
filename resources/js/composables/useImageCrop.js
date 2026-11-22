/*
 * Räkningen bakom det RUNDA utsnittet, se [[ADR-0076 Profilbilden]] § 7,
 * [[M59 Profilbilden]] § 382 och [[ADR-0090 Dashboardens hjälte]] § 4.
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
 * **Därför finns den effektiva punkten.** Klamringen gör att flera olika
 * fokusvärden ritas likadant: en 200×100-bild vid 100 % ser likadan ut för
 * varje x ≤ 25. `imageFocusRange` översätter det klamrade läget tillbaka
 * till den punkt som faktiskt syns, plus det intervall som går att nå vid
 * aktuell zoom. Redigeraren utgår från dem — inte från det sparade talet —
 * så ett drag och ett piltryck rör bilden direkt i stället för att först
 * beta av ett dött område. `imageDragFocus` och `imageNudgeFocus` är
 * samma räkning för de två handgreppen, så ytan och provet delar den.
 *
 * Måtten är bildpunkter. `imageWidth`/`imageHeight` är originalets
 * naturliga mått; saknas de (bilden har inte laddats än) returneras en
 * neutral fyllning så att komponenten kan ritas utan att gissa.
 *
 * **Filnamnet är delat sedan issue 433.** Redigeraren
 * (resources/js/components/ImageCropEditor.vue) använder de här funktionerna
 * för den runda formen och `coverPresentation.js` för den breda. Den breda
 * formen ritar containerns bild, vars fokuspunkt är en `object-position`
 * ([[ADR-0047 Containerns bild]]) och alltså inte klamras mot rutans mitt på
 * samma sätt — se `coverStyle()` där.
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
export function imageCrop({
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

/**
 * Den punkt som faktiskt syns, och intervallet som går att nå.
 *
 * `imageCrop` klamrar förskjutningen till bildens överflöd, så ett
 * fokusvärde nära kanten ritas som ett läge längre in. Här översätts det
 * klamrade läget tillbaka till procent — `50 - offset/width*100` — och
 * intervallet räknas ur samma överflöd. Vid zoom 100 på en kvadratisk bild
 * är överflödet noll och intervallet en enda punkt, 50: bilden kan inte
 * flyttas alls.
 *
 * @param {object} options
 * @param {number} options.imageWidth
 * @param {number} options.imageHeight
 * @param {number} options.box
 * @param {number} [options.focusX]
 * @param {number} [options.focusY]
 * @param {number} [options.zoom]
 * @returns {{
 *   focusX: number,
 *   focusY: number,
 *   minX: number,
 *   maxX: number,
 *   minY: number,
 *   maxY: number,
 * }}
 */
export function imageFocusRange({
    imageWidth,
    imageHeight,
    box,
    focusX = 50,
    focusY = 50,
    zoom = 100,
}) {
    return focusBounds(
        imageCrop({ imageWidth, imageHeight, box, focusX, focusY, zoom }),
        box,
    );
}

/**
 * Den effektiva punkten och det nåbara intervallet ur ett redan räknat
 * utsnitt.
 *
 * @param {{ width: number, height: number, offsetX: number, offsetY: number }} crop
 * @param {number} box
 * @returns {{ focusX: number, focusY: number, minX: number, maxX: number, minY: number, maxY: number }}
 */
function focusBounds(crop, box) {
    // Bilden har inte laddats: samma neutrala svar som `imageCrop` ger.
    if (!(crop.width > 0) || !(crop.height > 0) || !(box > 0)) {
        return { focusX: 50, focusY: 50, minX: 50, maxX: 50, minY: 50, maxY: 50 };
    }

    // Överflödet delat på två, i procent av den ritade sidan.
    const spanX = Math.max(0, (crop.width - box) / crop.width * 50);
    const spanY = Math.max(0, (crop.height - box) / crop.height * 50);

    return {
        focusX: 50 - crop.offsetX / crop.width * 100,
        focusY: 50 - crop.offsetY / crop.height * 100,
        minX: 50 - spanX,
        maxX: 50 + spanX,
        minY: 50 - spanY,
        maxY: 50 + spanY,
    };
}

/**
 * Klamrar en fokuspunkt till ett intervall och håller den heltalig.
 *
 * Servern tar emot heltal (`between:0,100`), och `ceil`/`floor` på
 * intervallet ser till att det avrundade talet fortfarande ligger innanför:
 * intervallet är symmetriskt kring 50 och rymmer därför alltid minst 50.
 *
 * @param {number} value
 * @param {number} min
 * @param {number} max
 * @returns {number}
 */
function clampFocus(value, min, max) {
    return Math.max(Math.ceil(min), Math.min(Math.floor(max), Math.round(value)));
}

/**
 * Fokuspunkten efter en dragning.
 *
 * Utgångsläget är den EFFEKTIVA punkten, inte det sparade talet: annars kan
 * ett drag börja i ett dött område där bilden står stilla medan talet rör
 * sig. `deltaX`/`deltaY` är pekarens rörelse i bildpunkter; bilden följer
 * fingret, så en rörelse åt höger flyttar punkten i rutans mitt åt vänster i
 * bilden. Skalan är bildens RITADE mått, inte rutans, så känsligheten är
 * densamma vid alla zoomar.
 *
 * @param {object} options
 * @param {number} options.imageWidth
 * @param {number} options.imageHeight
 * @param {number} options.box
 * @param {number} [options.focusX]
 * @param {number} [options.focusY]
 * @param {number} [options.zoom]
 * @param {number} [options.deltaX]
 * @param {number} [options.deltaY]
 * @returns {{ x: number, y: number }}
 */
export function imageDragFocus({
    imageWidth,
    imageHeight,
    box,
    focusX = 50,
    focusY = 50,
    zoom = 100,
    deltaX = 0,
    deltaY = 0,
}) {
    const crop = imageCrop({ imageWidth, imageHeight, box, focusX, focusY, zoom });

    if (!(crop.width > 0) || !(crop.height > 0)) {
        return { x: 50, y: 50 };
    }

    const bounds = focusBounds(crop, box);

    return {
        x: clampFocus(
            bounds.focusX - deltaX * 100 / crop.width,
            bounds.minX,
            bounds.maxX,
        ),
        y: clampFocus(
            bounds.focusY - deltaY * 100 / crop.height,
            bounds.minY,
            bounds.maxY,
        ),
    };
}

/**
 * Fokuspunkten efter ett piltryck.
 *
 * Utgår också från den effektiva punkten, så en tangent alltid ger en synlig
 * rörelse — annars hade ett sparat värde utanför intervallet ätit upp de
 * första trycken. Steget är i procent (2, eller 10 med Shift).
 *
 * @param {object} options
 * @param {number} options.imageWidth
 * @param {number} options.imageHeight
 * @param {number} options.box
 * @param {number} [options.focusX]
 * @param {number} [options.focusY]
 * @param {number} [options.zoom]
 * @param {number} [options.stepX]
 * @param {number} [options.stepY]
 * @returns {{ x: number, y: number }}
 */
export function imageNudgeFocus({
    imageWidth,
    imageHeight,
    box,
    focusX = 50,
    focusY = 50,
    zoom = 100,
    stepX = 0,
    stepY = 0,
}) {
    const bounds = imageFocusRange({ imageWidth, imageHeight, box, focusX, focusY, zoom });

    return {
        x: clampFocus(bounds.focusX + stepX, bounds.minX, bounds.maxX),
        y: clampFocus(bounds.focusY + stepY, bounds.minY, bounds.maxY),
    };
}
