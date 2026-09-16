/*
 * De tre lägena för en notistyp, se issue 65a § Beslut 2.
 *
 * `enabled` och `digest` är två KOLUMNER men ETT val för användaren:
 *
 *   direkt  enabled=true   digest=false   mejl när det händer
 *   samman  enabled=true   digest=true    samlat, en gång i veckan
 *   aldrig  enabled=false  digest=false   inget mejl för den här typen
 *
 * Två fristående kryssrutor gör kombinationen "avstängd men i
 * sammanfattningen" möjlig att klicka fram, och den betyder ingenting. Tre
 * alternativ i en grupp betyder att varje läge går att välja och att inget
 * läge går att missförstå.
 *
 * Filen är ren — den importerar varken Vue eller Inertia — och är den ENDA
 * plats som översätter mellan läget och kolumnparet. Vyn läser `modeOf()` för
 * att rita rätt radio, och skickar `valuesFor()` till servern; skrevs
 * översättningen två gånger kunde de två hållen säga olika saker om samma
 * val.
 *
 * `aldrig` sätter `digest = false`. Kolumnen är inte "i sammanfattningen" när
 * raden är avstängd — en avstängd typ har ingen sammanfattning att ligga i.
 */

/** Lägena, i den ordning de ritas. */
export const MODES = ['direct', 'digest', 'never'];

/**
 * Läget för en preferensrad ur serverns svar.
 *
 * En rad kan bära `enabled=false, digest=true` om någon skrivit den i
 * databasen för hand — `/api` hindrar det inte, för `enabled` och `digest` är
 * två oberoende kolumner där. Vyn har inget läge för den kombinationen, och
 * `aldrig` är det läge som ligger närmast: en avstängd typ skickar inget mejl
 * oavsett vad `digest` står på.
 */
export function modeOf(preference) {
    if (!preference.enabled) {
        return 'never';
    }

    return preference.digest ? 'digest' : 'direct';
}

/**
 * Kolumnparet för ett läge — kroppen `PUT /settings/notifications` tar emot.
 *
 * @returns {{enabled: boolean, digest: boolean}}
 */
export function valuesFor(mode) {
    return {
        enabled: mode !== 'never',
        digest: mode === 'digest',
    };
}
