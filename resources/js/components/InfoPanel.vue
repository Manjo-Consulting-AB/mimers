<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import UiCard from './UiCard.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Informationsytan — tipsen användaren bläddrar igenom och kan kryssa bort,
 * se issue 128 och [[ADR-0039 Containerns översikt]] § Konsekvenser.
 *
 * **Samma komponent på två sidor.** Dashboarden och containerns översikt
 * ritar båda den här, med samma `tips`-propp ur samma App\Support\Tips. Att
 * ytan är en komponent och inte två är hela poängen: en andra kopia hade
 * kunnat visa ett annat första tips, och ett kryss hade bara gömt det i den
 * ena.
 *
 * **Komponenten äger ingenting av tillståndet.** Vilka tips som finns och i
 * vilken ordning de kommer är serverns svar (App\Support\Tips), och det dolda
 * tillståndet bor i `dismissed_tip` och följer personen mellan webbläsare —
 * ingenting här rör `localStorage` (issuens krav 1). Det enda komponenten
 * håller är VILKET av tipsen som står framme; det är en bläddringsposition
 * och inte ett tillstånd någon annan behöver veta om.
 *
 * **Bläddringen går runt de tips användaren INTE dolt.** `tips` är redan
 * filtrerad av servern, så framåt och bakåt rör sig i den listan och aldrig
 * in i något dolt. Ordningen är serverns och komponenten sorterar ingenting.
 *
 * **Krysset postar och lämnar ifrån sig svaret.** `POST /tips/{key}/dismiss`
 * är idempotent (App\Http\Controllers\DismissedTipController), och servern
 * svarar med listan utan den nyckeln. Den nya proppen nollställer positionen,
 * så nästa tips står först — samma svar som `back()` landar i.
 *
 * **Ramen är `UiCard`** (issue 99), som de andra panelerna: rubriken är
 * kortets rubrikrad och krysset står i kortets åtgärdsrad.
 *
 * **Ingen sträng står i filen** (issue 52 · [[ADR-0013 Språk och i18n]]):
 * rubriken, brödtexten och knapparnas ord kommer ur `t()` med en nyckel per
 * tips i `lang/en/ui.php`, och en ny nyckel utan sträng är en synlig lucka
 * där i stället för en tystnad här.
 */
const props = defineProps({
    /* Nycklarna på de tips användaren inte dolt, i serverns ordning. */
    tips: { type: Array, required: true },
});

const { t } = useTranslations();

/* Vilket av tipsen som står framme. Ett index och ingenting mer. */
const index = ref(0);

/*
 * Vänteläget runt skrivningen: en knapp som ser likadan ut medan svaret är på
 * väg är en användare som trycker igen (GenomgangTest). Flaggan stängs i
 * `onFinish` och alltså även när svaret blev ett fel.
 */
const busy = ref(false);

/*
 * Servern svarar med listan UTAN det dolda tipset. Den nya proppen är vad som
 * gör att nästa tips står först, och positionen nollställs därför — annars
 * hade ytan kunnat stå kvar på en plats som inte längre finns.
 */
watch(
    () => props.tips,
    () => {
        index.value = 0;
    },
);

/* Modulolängden skyddar mot ett index som pekar utanför efter en kryssning. */
const current = computed(() =>
    props.tips.length === 0 ? null : props.tips[index.value % props.tips.length],
);

const titleKey = computed(() => `tips.${current.value}.title`);
const bodyKey = computed(() => `tips.${current.value}.body`);

function showPrevious() {
    index.value = (index.value - 1 + props.tips.length) % props.tips.length;
}

function showNext() {
    index.value = (index.value + 1) % props.tips.length;
}

function dismiss() {
    router.post(
        `/tips/${current.value}/dismiss`,
        {},
        {
            preserveScroll: true,
            onStart: () => {
                busy.value = true;
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}
</script>

<template>
    <!--
        Alla tips dolda: ytan ritas inte alls. Ingen tom ram och ingen rubrik
        över ingenting — en panel som står kvar utan innehåll är ett påstående
        om att det finns något att visa. Marginalen sitter därför HÄR och inte
        på anropet: en `<div class="mt-8">` runt panelen hade lämnat kvar sin
        luft när panelen inte ritas, och en klass utifrån kan inte ärvas av en
        rot som är villkorad.
    -->
    <UiCard v-if="props.tips.length" class="mt-8">
        <template #heading>{{ t(titleKey) }}</template>

        <template #action>
            <div class="flex items-center gap-1">
                <button
                    v-if="props.tips.length > 1"
                    type="button"
                    class="inline-flex min-h-11 items-center justify-center px-2 text-accent hover:underline disabled:opacity-60"
                    :disabled="busy"
                    @click="showPrevious"
                >
                    {{ t('tips.previous') }}
                </button>

                <button
                    v-if="props.tips.length > 1"
                    type="button"
                    class="inline-flex min-h-11 items-center justify-center px-2 text-accent hover:underline disabled:opacity-60"
                    :disabled="busy"
                    @click="showNext"
                >
                    {{ t('tips.next') }}
                </button>

                <button
                    type="button"
                    class="inline-flex min-h-11 min-w-11 items-center justify-center text-slate-700 hover:text-ink disabled:opacity-60"
                    :aria-label="t('tips.dismiss')"
                    :disabled="busy"
                    @click="dismiss"
                >
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        stroke-linecap="round"
                        class="h-5 w-5"
                        aria-hidden="true"
                    >
                        <path d="M6 6l12 12"></path>
                        <path d="M18 6L6 18"></path>
                    </svg>
                </button>
            </div>
        </template>

        <p class="text-slate-700">{{ t(bodyKey) }}</p>
    </UiCard>
</template>
