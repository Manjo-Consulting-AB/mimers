import { scheduleUrl } from './occurrencePresentation.js';

/*
 * Beroenderadernas gemensamma form, delad av schemats sida (läsbar lista) och
 * redigeringssidan (issue 1160; normaliseringen kom med 63c § Beslut 1 och 4).
 *
 * Servern svarar med `/api`:s två resurser — de skiljer sig åt på exakt två
 * punkter: en förekomstmotpart har ett förfallodatum och ett `satisfied`, och
 * dess `depends_on.ulid` pekar på en FÖREKOMST och inte på ett schema. Här
 * blir båda samma rad, och sektionen behöver inte veta vilken nivå den ritar.
 *
 * `schedule_ulid` är det enda fältet utöver resursen: länken vidare ska gå
 * till motpartens SCHEMA, och på schemanivån är `depends_on.ulid` redan det.
 */
export function scheduleRows(dependencies) {
    return dependencies.map((row) => ({
        ulid: row.depends_on.ulid,
        title: row.depends_on.title,
        item: row.depends_on.item,
        schedule_ulid: row.depends_on.ulid,
        due: null,
        satisfied: null,
    }));
}

export function occurrenceRows(dependencies) {
    return dependencies.map((row) => ({
        ulid: row.depends_on.ulid,
        title: row.depends_on.title,
        item: row.depends_on.item,
        schedule_ulid: row.schedule_ulid,
        due: row.depends_on.due_at,
        satisfied: row.satisfied,
    }));
}

/* Insamlings-URL:erna; raderingen är samma URL plus motpartens ULID. */
export function scheduleDependencyUrl(containerUlid, itemUlid, scheduleUlid) {
    return `${scheduleUrl(containerUlid, itemUlid, scheduleUlid)}/dependencies`;
}

export function occurrenceDependencyUrl(containerUlid, itemUlid, scheduleUlid, openUlid) {
    return openUlid === null
        ? ''
        : `${scheduleUrl(containerUlid, itemUlid, scheduleUlid)}/occurrences/${openUlid}/dependencies`;
}
