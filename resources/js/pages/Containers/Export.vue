<script setup>
import { computed, onMounted, onUnmounted } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
import ExportRow from '../../components/ExportRow.vue';
import { isOpenExport } from '../../components/exportPresentation.js';
import { useTranslations } from '../../composables/useTranslations.js';
import { useErrorFocus } from '../Auth/useErrorFocus.js';

/*
 * Pärmens export, se issue 67c § Beslut 1–8.
 *
 * Sidan svarar på EN fråga — "kan jag få med mig allt jag lagt in här?" — och
 * har tre delar i den ordningen en användare möter dem: vad påsen innehåller,
 * knappen som beställer den, och listan över de beställningar som finns.
 *
 * **Sidan ligger i pärmen och exporten är fri** (Beslut 1 och 2). Raden i
 * navigationen kommer ur resources/js/layouts/containerSections.js, och
 * grinden är `view` på pärmen: den som får läsa pärmen får ta ut den. Ingen
 * `can`-flagga ritas, för det finns inget svar att rita olika för två
 * användare — en `read`-deltagare ser samma sida som ägaren.
 *
 * **Väntan visas ärligt, och sidan laddar om sig själv med måtta** (Beslut 3).
 * Beställningen svarar med en omdirigering, raden finns i listan som
 * `pending`, och jobbet packar i bakgrunden. Så länge någon rad är `pending`
 * eller `running` laddas BARA `exports`-propen om med `router.reload({ only:
 * [...] })` — en timer som armar om sig efter varje svar, inte en som tickar
 * vidare på egen hand: den slutar när raden är klar eller misslyckad. Ingen
 * pollrutt, ingen websocket och inget paket, och `onUnmounted` river timern
 * så inget lever vidare efter att sidan lämnats.
 *
 * **Knappen är stängd medan en rad packas** (Beslut 4). Det är samma villkor
 * som håller pollningen igång: en pågående export blockerar en ny, och servern
 * svarar `export.already_running` för den som postar förbi vyn. Felet ritas
 * som en ruta ovanför formuläret och inte som en rå felkod —
 * App\Support\Frontend\ApiErrorTranslator formulerar meningen ur `lang/`, och
 * nyckeln är `export` eftersom felet gäller pärmens tillstånd och beställningen
 * inte har något fält.
 *
 * **En färdig eller misslyckad rad hindrar ingenting** (Beslut 3 och 4):
 * knappen är öppen igen så fort ingen rad packas, och en misslyckad export
 * beställs om med samma knapp.
 */
const props = defineProps({
    /* Pärmen ur App\Http\Resources\ContainerResource. */
    container: { type: Object, required: true },

    /* Pärmens exporter, nyast först, ur App\Http\Resources\ExportResource. */
    exports: { type: Array, required: true },
});

const { t } = useTranslations();
const page = usePage();
const { focusFirstError } = useErrorFocus();

/*
 * Hur ofta statusen hämtas medan en rad packas. Några sekunder: en export
 * byggs på en kö och tar sällan under ett par sekunder, så tätare anrop hade
 * bara varit last utan att svaret ändrats.
 */
const POLL_INTERVAL_MS = 3000;

const form = useForm({});

function request() {
    form.post(`/containers/${props.container.ulid}/export`, {
        preserveScroll: true,
        onError: focusFirstError,
        // Timern armar sig när svaret landat: efter en beställning finns den
        // nya raden i propen, och efter ett fel är det den pågående raden som
        // redan håller pollningen igång.
        onFinish: schedulePoll,
    });
}

/* Är någon rad fortfarande oklar? Delas av knappen och pollningen. */
const hasOpenRow = computed(() => props.exports.some(isOpenExport));

let timer = null;
let stopped = false;

function clearPoll() {
    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }
}

function schedulePoll() {
    clearPoll();

    if (stopped || ! hasOpenRow.value) {
        return;
    }

    timer = setTimeout(reloadExports, POLL_INTERVAL_MS);
}

function reloadExports() {
    timer = null;

    // Bara `exports`: pärmens namn och navigationen ändras inte av att en påse
    // blir klar, och en full omladdning hade räknat om allt på sidan.
    router.reload({ only: ['exports'], onFinish: schedulePoll });
}

onMounted(schedulePoll);

onUnmounted(() => {
    stopped = true;
    clearPoll();
});
</script>

<template>
    <ContainerLayout :container="container">
        <Head :title="t('export.title')" />

        <h1 class="text-2xl font-semibold">{{ t('export.heading') }}</h1>

        <!--
            Vad påsen innehåller, INNAN den beställs (Beslut 7): den som tar
            en export ska veta vad hon får, och den som hoppas på något annat
            slipper vänta i onödan.
        -->
        <p class="mt-2 text-sm text-slate-700">{{ t('export.intro') }}</p>

        <!--
            Domänfelet ur en beställning — pärmen har redan en export som
            packas — blir en ruta och inte en rå felkod. Nyckeln är `export`:
            felet gäller pärmens tillstånd och inte ett fält.
        -->
        <p
            v-if="page.props.errors.export"
            id="export-error"
            role="alert"
            tabindex="-1"
            class="mt-6 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 outline-none"
        >
            {{ page.props.errors.export }}
        </p>

        <form class="mt-6" @submit.prevent="request">
            <button
                type="submit"
                :disabled="form.processing || hasOpenRow"
                class="inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
            >
                {{ form.processing ? t('common.pending.export') : t('export.create') }}
            </button>

            <!--
                Varför knappen är stängd, i ord: en avstängd knapp utan
                förklaring är en återvändsgränd. Samma mening som felet ovan
                säger, för det är samma tillstånd.
            -->
            <p v-if="hasOpenRow" class="mt-2 text-sm text-slate-600">{{ t('export.running_notice') }}</p>
        </form>

        <section class="mt-10">
            <h2 class="text-lg font-semibold">{{ t('export.list_heading') }}</h2>

            <p v-if="exports.length === 0" class="mt-2 text-sm text-slate-600">{{ t('export.empty') }}</p>

            <ul v-else class="mt-4 flex flex-col gap-2">
                <ExportRow v-for="row in exports" :key="row.ulid" :row="row" />
            </ul>
        </section>
    </ContainerLayout>
</template>
