<script setup>
import { computed, onUnmounted, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Sökrutan i hjälpen — M81 · issue 467, [[ADR-0094 Hjälpcentret]] § 5.
 *
 * Rutan står i två storlekar: `large` på startsidan där den är sidans väg in,
 * och `compact` under brödsmulan på kategorisidan och artikelsidan. Den är
 * samma komponent i båda fallen — bara bredden och textstorleken skiljer.
 *
 * **Förslagen hämtas medan man skriver.** Från två tecken, 250 ms efter den
 * senaste tangenttryckningen, hämtas `/help/search?q=…&limit=8` — samma svar
 * som JSON-ytan (ADR-0094 § 5). Ett svar som hör till en äldre fråga kastas:
 * `isStale()` jämför både anropets nummer och frågan, så ett långsamt svar
 * aldrig skriver över det nyare.
 *
 * **Ingen `v-html`.** Servern lämnar titel och utdrag som segment
 * `{text, match}`, och mallen loopar dem och ritar `<mark>` där `match` är
 * sant. Klienten känner inte till normaliseringen och behöver aldrig rita
 * HTML ur ett svar.
 *
 * **Tangentbordet.** Fältet är en combobox med listbox
 * (`role="combobox"`, `aria-expanded`, `aria-controls`,
 * `aria-activedescendant`): fokus stannar i fältet, och markeringen flyttas
 * med pilarna mellan raderna. Enter på en markerad träff öppnar den; Enter
 * utan markering skickar formuläret till `/help?q=…` som ett vanligt GET —
 * samma form som `SearchField` — så träfflistan går att dela och backa till.
 * Escape stänger listan och tömmer sedan rutan. Träfflänkarna bär
 * `?ref=search`, som 470 räknar på servern.
 *
 * **Lägena läses upp.** Laddning, antal träffar, tomt svar och fel står i en
 * `aria-live="polite"`-region under fältet, så den som inte ser skärmen hör
 * att något hände. Den tomma listan säger vilken fråga som inte gav något och
 * länkar till startsidan.
 *
 * Orden slås upp ur `help.search.*` i lang/{locale}/ui.php
 * ([[ADR-0021 Frontendteknik]] § Beslut).
 */
const props = defineProps({
    /* Frågan rutan startar med — `/help?q=…` fyller den (issue 467). */
    initialQuery: { type: String, default: '' },
    size: { type: String, default: 'large' },
});

const { t } = useTranslations();

/* Fältet söker först från två tecken, efter 250 ms utan tangenttryckning. */
const MIN_QUERY_LENGTH = 2;
const SEARCH_DELAY_MS = 250;

/*
 * Mätningen (470): rutan skickar `log=1` en gång per fråga när användaren
 * varit stilla i 1,5 s med minst tre tecken, eller trycker Enter. Servern
 * räknar `search` och sparar frågan som en lucka när den gav noll träffar
 * (ADR-0094 § 8). Förslagen som hämtas medan man skriver bär aldrig `log=1`.
 */
const LOG_QUERY_LENGTH = 3;
const LOG_DELAY_MS = 1500;

const query = ref(props.initialQuery);
const results = ref([]);
const activeIndex = ref(-1);

/* `idle`, `loading`, `done` eller `failed`. */
const status = ref('idle');
const open = ref(false);

const fieldId = 'help-search-q';
const listId = 'help-search-results';

/*
 * Räknaren gör varje anrop unikt. Ett svar vars nummer inte längre är det
 * senaste hör till en fråga användaren redan lämnat och kastas.
 */
let requestId = 0;
let timer = null;

/* Mätningen: timern för `log=1` och den senast loggade frågan. */
let logTimer = null;
let loggedQuery = null;

/* Listan är öppen först när det finns något att visa. */
const expanded = computed(() => open.value && results.value.length > 0);

/* Sista raden är *See all results* och räknas som ett val i listan. */
const optionCount = computed(() => results.value.length + 1);

const activeDescendant = computed(() => (expanded.value && activeIndex.value >= 0
    ? optionId(activeIndex.value)
    : undefined));

function optionId(index) {
    return `${listId}-option-${index}`;
}

/* Ett svar som hör till en äldre fråga skrivs aldrig över det nyare. */
function isStale(answer, term) {
    return answer !== requestId || term !== query.value.trim();
}

function onInput(event) {
    query.value = event.target.value;
    close();

    if (timer !== null) {
        clearTimeout(timer);
    }

    if (logTimer !== null) {
        clearTimeout(logTimer);
    }

    timer = setTimeout(() => {
        timer = null;
        runSearch();
    }, SEARCH_DELAY_MS);

    /* Stilleståndet i 1,5 s är det som räknar frågan. */
    logTimer = setTimeout(() => {
        logTimer = null;
        logSearch();
    }, LOG_DELAY_MS);
}

async function runSearch() {
    const term = query.value.trim();

    if (term.length < MIN_QUERY_LENGTH) {
        results.value = [];
        status.value = 'idle';
        close();

        return;
    }

    const answer = ++requestId;

    status.value = 'loading';

    try {
        const response = await fetch(`/help/search?q=${encodeURIComponent(term)}&limit=8`, {
            headers: { Accept: 'application/json' },
        });

        const data = await response.json();

        if (isStale(answer, term)) {
            return;
        }

        if (!response.ok) {
            fail();

            return;
        }

        results.value = Array.isArray(data.results) ? data.results : [];
        status.value = 'done';
        activeIndex.value = -1;
        open.value = results.value.length > 0;
    } catch {
        if (isStale(answer, term)) {
            return;
        }

        fail();
    }
}

function fail() {
    results.value = [];
    status.value = 'failed';
    close();
}

/*
 * Skickar den ena mätfrågan. `log=1` används bara här, en gång per fråga
 * (`loggedQuery`) och först från tre tecken. Svaret används inte, och ett
 * fel får aldrig störa sökningen.
 */
function logSearch() {
    const term = query.value.trim();

    if (term.length < LOG_QUERY_LENGTH || term === loggedQuery) {
        return;
    }

    loggedQuery = term;

    fetch(`/help/search?q=${encodeURIComponent(term)}&log=1`, {
        headers: { Accept: 'application/json' },
        keepalive: true,
    }).catch(() => {});
}

function onKeydown(event) {
    if (event.key === 'ArrowDown') {
        if (!expanded.value) {
            return;
        }

        event.preventDefault();
        activeIndex.value = Math.min(optionCount.value - 1, activeIndex.value + 1);

        return;
    }

    if (event.key === 'ArrowUp') {
        if (!expanded.value) {
            return;
        }

        event.preventDefault();
        activeIndex.value = Math.max(0, activeIndex.value - 1);

        return;
    }

    if (event.key === 'Enter') {
        /* Enter är det andra tillfället som räknar frågan. */
        logSearch();

        const result = results.value[activeIndex.value];

        if (result) {
            event.preventDefault();
            openUrl(`${result.url}?ref=search`);

            return;
        }

        if (results.value.length > 0 && activeIndex.value === results.value.length) {
            event.preventDefault();
            openAll();
        }

        /*
         * Utan markering tar formuläret över: webbläsaren skickar frågan som
         * ett vanligt GET till /help?q=…, och adressen går att dela.
         */

        return;
    }

    if (event.key === 'Escape') {
        event.preventDefault();

        if (expanded.value) {
            close();
        } else {
            clear();
        }
    }
}

function openUrl(url) {
    close();
    router.visit(url);
}

function openAll() {
    openUrl(`/help?q=${encodeURIComponent(query.value.trim())}`);
}

function close() {
    open.value = false;
    activeIndex.value = -1;
}

function clear() {
    query.value = '';
    results.value = [];
    status.value = 'idle';
    close();
}

/*
 * Antalet träffar. `t()` pluraliserar inte — samma tvågrenade form som
 * `help.questions_count` — så strängen delas här: första grenen för ett,
 * andra för övriga.
 */
function searchCount(count) {
    const [one, many] = t('help.search.count').split('|');

    return (count === 1 ? one : (many ?? one)).replace(':count', String(count));
}

/* En ny sida ger en ny fråga; då fylls rutan om. */
watch(
    () => props.initialQuery,
    (value) => {
        query.value = value;
        loggedQuery = null;
    },
);

onUnmounted(() => {
    if (timer !== null) {
        clearTimeout(timer);
    }

    if (logTimer !== null) {
        clearTimeout(logTimer);
    }
});
</script>

<template>
    <form method="get" action="/help" :class="size === 'compact' ? 'w-full max-w-md' : 'w-full max-w-2xl'">
        <label :for="fieldId" class="text-body font-medium text-ink">{{ t('help.search.label') }}</label>

        <div class="relative mt-1">
            <input
                :id="fieldId"
                :value="query"
                name="q"
                type="search"
                role="combobox"
                autocomplete="off"
                aria-autocomplete="list"
                :aria-expanded="expanded ? 'true' : 'false'"
                :aria-controls="listId"
                :aria-activedescendant="activeDescendant"
                :placeholder="t('help.search.placeholder')"
                class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-body text-ink outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                @input="onInput"
                @keydown="onKeydown"
            >

            <ul
                v-if="expanded"
                :id="listId"
                role="listbox"
                class="absolute z-10 mt-1 max-h-80 w-full overflow-y-auto rounded-card border border-border bg-surface py-1 shadow-lg"
            >
                <li
                    v-for="(result, index) in results"
                    :id="optionId(index)"
                    :key="result.id"
                    role="option"
                    :aria-selected="index === activeIndex ? 'true' : 'false'"
                    class="px-1"
                >
                    <Link
                        :href="`${result.url}?ref=search`"
                        tabindex="-1"
                        class="flex min-h-11 flex-col justify-center rounded-control px-3 py-2 outline-none"
                        :class="index === activeIndex ? 'bg-accent-soft' : ''"
                        @click="close()"
                    >
                        <span class="text-title font-medium text-ink">
                            <template v-for="(segment, part) in result.title" :key="`title-${part}`">
                                <mark v-if="segment.match" class="bg-accent-soft text-ink">{{ segment.text }}</mark>
                                <template v-else>{{ segment.text }}</template>
                            </template>
                        </span>

                        <span class="mt-1 text-body text-ink-muted">
                            <template v-for="(segment, part) in result.snippet" :key="`snippet-${part}`">
                                <mark v-if="segment.match" class="bg-accent-soft text-ink">{{ segment.text }}</mark>
                                <template v-else>{{ segment.text }}</template>
                            </template>
                        </span>

                        <span class="mt-1 text-meta text-ink-subtle">
                            {{ result.category.title }} · {{ t(`help.types.${result.type}`) }}
                        </span>
                    </Link>
                </li>

                <li
                    :id="optionId(results.length)"
                    role="option"
                    :aria-selected="activeIndex === results.length ? 'true' : 'false'"
                    class="px-1"
                >
                    <Link
                        :href="`/help?q=${encodeURIComponent(query.trim())}`"
                        tabindex="-1"
                        class="flex min-h-11 items-center rounded-control px-3 text-body font-medium text-accent outline-none"
                        :class="activeIndex === results.length ? 'bg-accent-soft' : ''"
                        @click="close()"
                    >
                        {{ t('help.search.all') }}
                    </Link>
                </li>
            </ul>
        </div>

        <div aria-live="polite" class="mt-2 text-body text-ink-muted">
            <p v-if="status === 'loading'">{{ t('help.search.loading') }}</p>

            <p v-else-if="status === 'failed'">{{ t('help.search.error') }}</p>

            <template v-else-if="status === 'done'">
                <p v-if="results.length">{{ searchCount(results.length) }}</p>

                <p v-else>
                    {{ t('help.search.empty', { query: query.trim() }) }}
                    <Link href="/help" class="font-medium text-accent hover:underline">
                        {{ t('help.search.home') }}
                    </Link>
                </p>
            </template>
        </div>
    </form>
</template>
