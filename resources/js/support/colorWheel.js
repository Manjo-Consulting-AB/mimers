/*
 * Färgomvandlingen bakom färgväljaren, se issue 165 § Beslut 2 och 4.
 *
 * **Rena funktioner, ingen DOM.** Väljaren ritar hjulet och reglaget, men
 * räknar aldrig själv: nyans, mättnad och ljushet blir `#rrggbb` här, och
 * `#rrggbb` blir tre tal igen här. Modulen importerar varken Vue eller något
 * ur webbläsaren — samma skäl som itemPresentation.js och
 * attachmentPresentation.js, och den vägen går att köra i node, vilket
 * tests/Feature/Frontend/TaggfargvaljareTest.php gör.
 *
 * **Formatet ut är det enda som finns.** `hsvToHex` svarar alltid med sex
 * gemena hexsiffror och ingenting annat — ingen `hsl()`, ingen kortform
 * `#rgb` och ingen versal (Beslut 4). Regeln `/^#[0-9a-fA-F]{6}$/` bor i
 * StoreTagRequest/UpdateTagRequest och är den som avgör vad som är giltigt;
 * `hexToHsv` svarar `null` på allt annat, och då rör hjulet sig inte
 * (Beslut 5).
 *
 * **Skalan är 0–360 för nyans och 0–100 för mättnad och ljushet**, alltså
 * samma skala som CSS och `<input type="range">`. Väljaren ska inte behöva
 * räkna om något mellan reglaget och funktionen.
 *
 * **Talen är INTE avrundade.** `hexToHsv` lämnar nyansen som ett flyttal, och
 * det är det som gör rundturen exakt: en åttabitarskanal har 256 steg, och
 * varje steg är ett exakt heltal i den ena riktningen och tillbaka. Avrundade
 * man nyansen till hela grader först hade markören hoppat upp till två
 * kanalsteg varje gång ett värde lästes tillbaka.
 */

/*
 * En kanal på 0–1 som två gemena hexsiffror. Klippningen är ingen
 * felhantering: hjulet kan inte ge ett värde utanför intervallet, men en
 * flyttalsrest vid 1,0 ska inte bli `100` och tre tecken.
 */
function channelToHex(channel) {
    const clamped = Math.min(1, Math.max(0, channel));

    return Math.round(clamped * 255).toString(16).padStart(2, '0');
}

/*
 * Nyans, mättnad och ljushet som `#rrggbb` med gemener.
 *
 * Negativ nyans eller en nyans över ett varv viras runt i stället för att
 * förkastas: hjulet drar markören runt kanten, och den som håller ned
 * vänsterpilen förbi noll ska fortsätta in i rött och inte stanna.
 */
export function hsvToHex(h, s, v) {
    const hue = ((Number(h) % 360) + 360) % 360;
    const saturation = Number(s) / 100;
    const value = Number(v) / 100;

    const chroma = value * saturation;
    const second = chroma * (1 - Math.abs(((hue / 60) % 2) - 1));
    const floor = value - chroma;

    const [r, g, b] = hue < 60 ? [chroma, second, 0]
        : hue < 120 ? [second, chroma, 0]
        : hue < 180 ? [0, chroma, second]
        : hue < 240 ? [0, second, chroma]
        : hue < 300 ? [second, 0, chroma]
        : [chroma, 0, second];

    return `#${channelToHex(r + floor)}${channelToHex(g + floor)}${channelToHex(b + floor)}`;
}

/*
 * `#rrggbb` som `{h, s, v}`, eller `null` för allt som inte är sex
 * hexsiffror. Versaler läses som gemener — fältet får skrivas hur användaren
 * vill, servern normaliserar, och hjulet ska hamna rätt oavsett.
 *
 * En gråskala har ingen nyans: delta är noll, och svaret är 0 och inte en
 * division med noll. Det är samma sak för `#000000` och `#ffffff`, och det
 * är riktigt — hjulet har ingen kant att peka på när mättnaden är noll.
 */
export function hexToHsv(hex) {
    if (typeof hex !== 'string' || ! /^#[0-9a-fA-F]{6}$/.test(hex)) {
        return null;
    }

    const r = parseInt(hex.slice(1, 3), 16) / 255;
    const g = parseInt(hex.slice(3, 5), 16) / 255;
    const b = parseInt(hex.slice(5, 7), 16) / 255;

    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    const delta = max - min;

    let hue = 0;

    if (delta > 0) {
        if (max === r) {
            hue = 60 * (((g - b) / delta) % 6);
        } else if (max === g) {
            hue = 60 * ((b - r) / delta + 2);
        } else {
            hue = 60 * ((r - g) / delta + 4);
        }
    }

    if (hue < 0) {
        hue += 360;
    }

    return {
        h: hue,
        s: max === 0 ? 0 : (delta / max) * 100,
        v: max * 100,
    };
}
