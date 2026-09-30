<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
import HistoryFilterBar from '../../components/HistoryFilterBar.vue';
import HistoryRow from '../../components/HistoryRow.vue';
import { formatLocaleDate } from '../../composables/useRelativeDate.js';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Containerns historikflik — containerns egen sida, se issue 116 ·
 * [[ADR-0043 Tre loggar]] § Händelseloggen.
 *
 * Sidan ligger i ContainerLayout och bär den prop layouten kräver: `container`
 * ur App\Http\Resources\ContainerResource — samma kontrakt som översikten,
 * itemlistan och kostnadsfliken. Fliken är en egen adress
 * (`/containers/{ulid}/history`) och ingen panel på en annan sida: en flik man
 * kan länka till är en flik man kan dela, och historiken är den enda av
 * containerns flikar som hade behövt ett undantag från det (issue 100).
 *
 * **Dagarna kommer färdiga och vyn räknar aldrig om en tidpunkt till en dag**
 * (issue 179 § Beslut 3). Proppen `days` är `[{date, rows}]`, och `date` är
 * dagen i användarens tidszon, räknad av servern ([[ADR-0044 Användarens
 * dag]]). Klientens klocka är inte hennes tidszon: en händelse 23:30 UTC hör
 * till nästa dygn i Stockholm, och en vikning här hade lagt den på fel dag.
 *
 * **Raderna kommer färdiga och vyn ställer ingen fråga.** Varje rad är
 * App\Actions\Audit\PresentAuditEvents svar — högst hundra rader, nyast först,
 * med namnen redan uppslagna. Vilka rader användaren får läsa avgjordes av
 * App\Actions\Audit\ListAuditEvents i kontrollern (issue 108), och den regeln
 * upprepas inte här: en gäst ser sina egna rader och ägaren allas, ur samma
 * svar, och vyn kan inte se skillnad på dem.
 *
 * **Filtren står i querysträngen** (Beslut 4) och ritas av
 * resources/js/components/HistoryFilterBar.vue. Vyn filtrerar ingenting själv:
 * `filter` är filtret så som servern tillämpade det, och `options` är valen
 * hon får bjuda på — användarna och typerna ur läsregeln, itemslistan ur
 * omfånget.
 *
 * **Två tomma tillstånd, och de säger olika saker** (issue 99). Utan filter
 * betyder en tom lista att ingenting har hänt — *ingenting har hänt här ännu*.
 * Med ett filter betyder den att inget matchar. Den första meningen hade varit
 * osann i det andra läget, och skillnaden är varför `audit.history.empty` och
 * `audit.history.empty_filtered` är två nycklar.
 *
 * **Dagens rubrik slås upp, den räknas inte fram.** `date` är redan dagen i
 * användarens tidszon, så vyn jämför den med serverns `today` (den delade
 * proppen, [[ADR-0044 Användarens dag]] § Beslut 4) och skriver *Idag* eller
 * *Igår*; allt äldre blir ett datum ur useRelativeDate.js — samma enda
 * formatering som varje annan yta lånar, och aldrig en egen `Intl`-formatering
 * i en panel (DatumregelTest).
 *
 * Ingen sträng i JavaScript (issue 52 · [[ADR-0013 Språk och i18n]]): rubriken,
 * flikens namn i webbläsaren, dagens rubrik, tomtillstånden och varje rads
 * mening kommer ur `t()` — radens ur resources/js/components/HistoryRow.vue,
 * resten ur `audit.history.*`. Sidans titel är en egen nyckel och inte flikens
 * etikett, som på kategoriernas och taggarnas sidor.
 *
 * **Hjälten kom med issue 170 · [[ADR-0050 Desktopdesignen]] § 2**, och
 * historiken skickar `hero="compact"` som varje flik utom översikten: samma
 * bild, art, namn och beskrivning över `md:`, utan tal. Därför bär sidan
 * också `can` — hjälten ritar *Redigera container* ur `can.update`, och
 * kontrollern räknar flaggan bredvid resursen (issue 54 § Beslut 9).
 */
const props = defineProps({
    container: { type: Object, required: true },
    /*
     * Händelserna grupperade per dag i användarens tidszon:
     * `[{date, rows}]`, nyast först. Raderna ur
     * App\Actions\Audit\PresentAuditEvents.
     */
    days: { type: Array, required: true },
    /* Filtret så som servern tillämpade det: { type, user, item, from, to }. */
    filter: { type: Object, required: true },
    /* Filterfältets val: { types, users, items }, redan filtrerade av servern. */
    options: { type: Object, required: true },
    /*
     * `{ update }` — samma flagga som inställningssidan ritar sitt formulär
     * ur. Den styr *Redigera container* i hjälten; rutten prövar
     * `ContainerPolicy::update` på nytt.
     */
    can: { type: Object, default: null },
});

const { t } = useTranslations();
const page = usePage();

/* De delade propparna — användarens dag och hennes locale (issue 137). */
const today = computed(() => page.props.today ?? null);
const locale = computed(() => page.props.locale);

const filtered = computed(() => Object.values(props.filter).some((value) => value !== null && value !== ''));

/*
 * En dag som ett `Date` i lokal tid — `Y-m-d` läses som kalenderdatum och
 * aldrig som ett ögonblick (`new Date("2026-10-12")` är UTC-midnatt och visar
 * dagen FÖRE i en negativ offset, samma fälla som `parseDateOnly()` i
 * useRelativeDate.js beskriver).
 */
function parseDay(value) {
    const [year, month, day] = String(value ?? '').split('-').map(Number);

    return year && month && day ? new Date(year, month - 1, day) : null;
}

/* Gårdagen som `Y-m-d`, så att rubriken kan jämföras som strängar. */
const yesterday = computed(() => {
    const parsed = parseDay(today.value);

    if (parsed === null) {
        return null;
    }

    parsed.setDate(parsed.getDate() - 1);

    return [
        parsed.getFullYear(),
        String(parsed.getMonth() + 1).padStart(2, '0'),
        String(parsed.getDate()).padStart(2, '0'),
    ].join('-');
});

/*
 * Dagens rubrik: *Idag*, *Igår*, annars datumet. Jämförelsen är mot serverns
 * dag och inte mot klockan, så rubriken och radernas klockslag aldrig kan
 * peka ut olika dygn.
 */
function dayLabel(date) {
    if (date === today.value) {
        return t('date.today');
    }

    if (yesterday.value !== null && date === yesterday.value) {
        return t('date.yesterday');
    }

    const parsed = parseDay(date);

    return parsed === null ? date : formatLocaleDate(parsed, locale.value);
}
</script>

<template>
    <ContainerLayout hero="compact" :container="container" :can="can">
        <Head :title="t('audit.history.title')" />

        <h1 class="text-heading font-semibold text-ink">{{ t('audit.history.heading') }}</h1>

        <HistoryFilterBar
            :container-ulid="container.ulid"
            :filter="filter"
            :options="options"
        />

        <p v-if="days.length === 0" class="mt-6 text-body text-ink-muted">
            {{ filtered ? t('audit.history.empty_filtered') : t('audit.history.empty') }}
        </p>

        <!--
            En dag är en grupp med sin egen rubrik, och raderna är <li> — samma
            form `UiListRow` kräver, och av samma skäl som i varje annan lista:
            en skärmläsare ska höra hur många rader det finns innan den läser
            den första.
        -->
        <section v-for="day in days" :key="day.date" class="mt-6">
            <h2 class="flex items-baseline gap-2 text-title font-semibold text-ink">
                {{ dayLabel(day.date) }}
                <span class="text-meta font-normal text-ink-subtle">
                    {{ t('audit.history.day_count', { count: day.rows.length }) }}
                </span>
            </h2>

            <ul class="mt-2">
                <HistoryRow v-for="row in day.rows" :key="row.ulid" :row="row" />
            </ul>
        </section>
    </ContainerLayout>
</template>
