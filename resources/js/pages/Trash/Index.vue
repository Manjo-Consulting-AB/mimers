<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import ItemIcon from '../../components/ItemIcon.vue';
import UiIcon from '../../components/UiIcon.vue';
import { formatDate } from '../../components/accessPresentation.js';
import { remainingLabel } from '../../components/trashPresentation.js';
import { useTranslations } from '../../composables/useTranslations.js';

defineOptions({ layout: AppLayout });

/*
 * Den samlade papperskorgen, se issue 319 ·
 * [[ADR-0065 Den samlade papperskorgen]] § 4 och 6–9 och
 * [[M39 Den samlade papperskorgen]] § 319.
 *
 * **Sidan ritar raderna och formulerar texten; servern äger urvalet.** Rader,
 * markör och flaggor kommer ur App\Actions\Trash\ListAllTrash via
 * TrashOverviewController. Vyn filtrerar ingenting själv och slår inte upp
 * något: `q` och `type` skickas till samma rutt och svaret ritas.
 *
 * **Sökfältet söker medan man skriver** (ADR-0062 § 7): 300 ms efter den
 * senaste tangenttryckningen, Enter skickar direkt och tar bort den väntande
 * fördröjningen, och en sökning från fältet ersätter den senaste posten i
 * historiken (`replace`). En ny sökning avbryter den förra uttryckligen, så
 * att ett äldre svar aldrig skriver över ett nyare. Fältet är aldrig
 * `disabled`, och `preserveState` behåller fokus och markör.
 *
 * **Växeln skickar direkt** och har fyra lägen: *All*, *Items*,
 * *Attachments* och *Containers*. Både sökordet och växeln står i adressen
 * (`/trash?q=…&type=…`), och servern gör urvalet.
 *
 * **Raden visar det § 4 räknar upp:** ikonen (itemets eller containerns
 * `ItemIcon`, bilagans typikon), namnet, slagets ord, var den hör hemma, när
 * och av vem den raderades, och när den försvinner. `deleted_by` är namnet ur
 * den senaste `*.deleted`-händelsen eller null; null ritas som *a former
 * user*, samma ord som historiken (ADR-0043).
 *
 * **`kind` för bilagor kommer ur kontrollern.** Actionen lämnar `icon` tom
 * för bilagor, och typikonen är `document` eller `image`; `kind` finns på
 * subjektet bredvid raden och läggs på posten i TrashOverviewController.
 *
 * **Restore ritas bara när `canRestore` släpper igenom raden.** Flaggan är
 * presentation: `POST /trash/restore` prövar samma grind som de befintliga
 * rutterna och ger 403 om någon postar förbi vyn. Målet bär det filter
 * servern tillämpade, så att återställningen landar i samma vy.
 *
 * **Raden lämnar listan när den återställs.** `Link` behåller annars
 * komponentens tillstånd efter en POST — `preserveState` är Inertias förval
 * för allt utom GET — och då hade den lokala `rows`-listan stått kvar med en
 * Restore-knapp som nu ger 422 eller 404. `:preserve-state="false"` remountar
 * sidan, så att `rows` sätts ur serverns färska `entries` och raden försvinner.
 *
 * **Load more hämtar nästa sida med markören och lägger den UNDER raderna
 * som redan står där** (§ 7): `preserveScroll` och en lokal `rows`-lista som
 * byggs på i `onSuccess`. Första sidan och varje ny sökning ersätter listan;
 * bara marköranropet lägger till. `canRestore` följer med varje sida och
 * läggs i samma lokala uppslag, annars hade Restore försvunnit på de rader
 * som kom med markören.
 */
const props = defineProps({
    entries: { type: Array, required: true },
    nextCursor: { type: String, default: null },
    filter: { type: Object, required: true },
    canRestore: { type: Object, required: true },
});

const { t } = useTranslations();
const page = usePage();

/* Fördröjningen mellan sista tangenttryckningen och sökningen (ADR-0062 § 7). */
const SEARCH_DELAY_MS = 300;

const modes = [
    { value: null, key: 'all' },
    { value: 'item', key: 'item' },
    { value: 'attachment', key: 'attachment' },
    { value: 'container', key: 'container' },
];

const q = ref(props.filter.q ?? '');
const activeType = ref(props.filter.type ?? null);
const rows = ref([...props.entries]);
const cursor = ref(props.nextCursor);
const restoreFlags = ref({ ...props.canRestore });
const pending = ref(false);
const loadingMore = ref(false);
const searchInFlight = ref(false);

let searchTimer = null;
let cancelToken = null;
let visitSeq = 0;

const hasFilter = computed(() => props.filter.q !== null || props.filter.type !== null);

/*
 * Återställningens mål bär det filter servern tillämpade — inte det
 * användaren råkar ha skrivit men ännu inte skickat. `q` och `type` i
 * querysträngen är filtret; radens `type` ligger i kroppen.
 */
const restoreHref = computed(() => {
    const params = new URLSearchParams();

    if (props.filter.q !== null) {
        params.set('q', props.filter.q);
    }

    if (props.filter.type !== null) {
        params.set('type', props.filter.type);
    }

    const query = params.toString();

    return query === '' ? '/trash/restore' : `/trash/restore?${query}`;
});

function clearSearchTimer() {
    if (searchTimer !== null) {
        clearTimeout(searchTimer);
        searchTimer = null;
    }
}

/*
 * Skickar filtret som querysträng. En tom `q` och läget *All* lämnas utanför
 * strängen i stället för att skickas tomma, så att adressen går att läsa och
 * dela. Den förra visiten avbryts uttryckligen innan en ny startar, och
 * `visitSeq` gör att en avbruten visits `onFinish` inte nollställer flaggorna
 * för den som tog över.
 */
function apply(overrides = {}, options = {}) {
    clearSearchTimer();

    if (cancelToken !== null) {
        cancelToken.cancel();
        cancelToken = null;
    }

    const seq = ++visitSeq;

    searchInFlight.value = options.fromField === true;

    const params = {};
    const nextQ = overrides.q === undefined ? q.value : overrides.q;

    if (String(nextQ ?? '').trim() !== '') {
        params.q = String(nextQ).trim();
    }

    const nextType = overrides.type === undefined ? activeType.value : overrides.type;

    if (nextType !== null) {
        params.type = nextType;
    }

    router.get('/trash', params, {
        preserveState: true,
        preserveScroll: true,
        replace: options.replace === true,
        only: ['entries', 'nextCursor', 'filter', 'canRestore'],
        onCancelToken: (token) => {
            if (seq === visitSeq) {
                cancelToken = token;
            }
        },
        onStart: () => {
            if (seq === visitSeq) {
                pending.value = true;
            }
        },
        onSuccess: (response) => {
            if (seq !== visitSeq) {
                return;
            }

            rows.value = [...response.props.entries];
            cursor.value = response.props.nextCursor;
            restoreFlags.value = { ...response.props.canRestore };
        },
        onFinish: () => {
            if (seq !== visitSeq) {
                return;
            }

            pending.value = false;
            searchInFlight.value = false;
            cancelToken = null;
        },
    });
}

/*
 * Sökningen från fältet. Ett ord som efter trimning är det servern redan
 * tillämpat skickas inte — tomt räknas som null. En sökning som är på väg med
 * ett annat ord avbryts, annars landar den och filtrerar listan på ett ord
 * fältet inte längre visar.
 */
function search() {
    const next = q.value.trim();
    const current = String(props.filter.q ?? '').trim();

    if (next === current) {
        if (searchInFlight.value) {
            visitSeq++;

            if (cancelToken !== null) {
                cancelToken.cancel();
                cancelToken = null;
            }

            pending.value = false;
            searchInFlight.value = false;
        }

        return;
    }

    apply({ q: next }, { replace: true, fromField: true });
}

function onSearchInput() {
    clearSearchTimer();

    searchTimer = setTimeout(() => {
        searchTimer = null;
        search();
    }, SEARCH_DELAY_MS);
}

/* Enter: skicka nu och ta bort den väntande fördröjningen. */
function submitSearch() {
    clearSearchTimer();
    search();
}

/* Växeln skickar direkt — inget vänteläge mellan klicket och anropet. */
function setType(type) {
    if (type === activeType.value) {
        return;
    }

    activeType.value = type;
    apply({ type }, { replace: false });
}

/*
 * Nästa sida, under de rader som redan står där. Filtret är det servern
 * tillämpade — en halvskriven sökning ska inte ändra vilken sida som hämtas.
 */
function loadMore() {
    if (cursor.value === null || loadingMore.value) {
        return;
    }

    const seq = visitSeq;
    const params = { cursor: cursor.value };

    if (props.filter.q !== null) {
        params.q = props.filter.q;
    }

    if (props.filter.type !== null) {
        params.type = props.filter.type;
    }

    router.get('/trash', params, {
        preserveState: true,
        preserveScroll: true,
        only: ['entries', 'nextCursor', 'canRestore'],
        onStart: () => {
            loadingMore.value = true;
        },
        onSuccess: (response) => {
            if (seq !== visitSeq) {
                return;
            }

            rows.value = [...rows.value, ...response.props.entries];
            cursor.value = response.props.nextCursor;
            // Flaggan följer med nästa sida, annars hade Restore försvunnit
            // på rader som laddats med markören.
            restoreFlags.value = { ...restoreFlags.value, ...response.props.canRestore };
        },
        onFinish: () => {
            loadingMore.value = false;
        },
    });
}

/*
 * Fälten speglas ur `filter`-proppen, inte bara vid montering: bakåtknappen
 * kan byta filter utan att formuläret rörs, och då måste fältet och växeln
 * följa med. Medan användaren skriver, eller en sökning är på väg, får
 * serverns svar inte skriva över det hon skriver.
 */
watch(
    () => [props.filter.q, props.filter.type],
    ([serverQ, serverType]) => {
        if (searchTimer !== null || searchInFlight.value) {
            return;
        }

        q.value = serverQ ?? '';
        activeType.value = serverType ?? null;
        rows.value = [...props.entries];
        cursor.value = props.nextCursor;
        restoreFlags.value = { ...props.canRestore };
    },
);

function deletedByLabel(entry) {
    const date = formatDate(entry.deleted_at, page.props.locale);

    return entry.deleted_by === null || entry.deleted_by === undefined
        ? t('trash.overview.deleted_by_former', { date })
        : t('trash.overview.deleted_by', { date, user: entry.deleted_by });
}

onBeforeUnmount(() => {
    clearSearchTimer();

    if (cancelToken !== null) {
        cancelToken.cancel();
        cancelToken = null;
    }
});
</script>

<template>
    <Head :title="t('trash.title')" />

    <h1 class="text-2xl font-semibold">{{ t('trash.heading') }}</h1>
    <p class="mt-1 text-sm text-ink-muted">{{ t('trash.overview.description') }}</p>

    <p
        v-if="page.props.errors.ulid"
        role="alert"
        class="mt-4 rounded border border-warning bg-warning-soft px-3 py-2 text-sm text-ink"
    >
        {{ page.props.errors.ulid }}
    </p>

    <p
        v-if="page.props.errors.trash"
        role="alert"
        class="mt-4 rounded border border-warning bg-warning-soft px-3 py-2 text-sm text-ink"
    >
        {{ page.props.errors.trash }}
    </p>

    <form class="mt-4 flex flex-col gap-1" @submit.prevent="submitSearch">
        <label for="trash-search" class="text-sm font-medium text-ink">
            {{ t('trash.overview.search.label') }}
        </label>
        <input
            id="trash-search"
            v-model="q"
            type="search"
            :placeholder="t('trash.overview.search.placeholder')"
            class="min-h-11 rounded-control border border-border bg-surface px-3 text-ink"
            @input="onSearchInput"
        />
    </form>

    <div class="mt-3 flex flex-wrap gap-2" role="group" :aria-label="t('trash.overview.filter.label')">
        <button
            v-for="mode in modes"
            :key="mode.key"
            type="button"
            :aria-pressed="activeType === mode.value"
            :disabled="pending"
            class="inline-flex min-h-11 items-center rounded-control border px-3 text-sm disabled:opacity-60"
            :class="activeType === mode.value
                ? 'border-accent bg-accent-soft text-accent'
                : 'border-border bg-surface text-ink'"
            @click="setType(mode.value)"
        >
            {{ t(`trash.overview.filter.${mode.key}`) }}
        </button>
    </div>

    <ul v-if="rows.length > 0" class="mt-6 flex flex-col gap-3">
        <li
            v-for="entry in rows"
            :key="entry.ulid"
            class="flex min-h-11 items-start gap-3 rounded border border-border bg-surface px-4 py-2 text-sm"
        >
            <span class="flex shrink-0 items-center">
                <ItemIcon
                    v-if="entry.type !== 'attachment'"
                    :name="entry.icon"
                    :color="entry.icon_color"
                    :tile="true"
                    :fallback="entry.type === 'container' ? 'container' : 'item'"
                />
                <UiIcon
                    v-else
                    :name="entry.kind === 'image' ? 'image' : 'document'"
                    tile="neutral"
                />
            </span>

            <span class="flex min-w-0 flex-col gap-1">
                <span class="font-medium text-ink">
                    {{ entry.label }}
                    <span class="font-normal text-ink-muted">{{ t(`trash.type.${entry.type}`) }}</span>
                </span>

                <span v-if="entry.type === 'item' && entry.container" class="text-ink-muted">
                    {{ t('trash.overview.in_container', { container: entry.container.name }) }}
                </span>

                <span v-else-if="entry.type === 'attachment' && entry.item" class="text-ink-muted">
                    {{
                        t('trash.overview.in_item', {
                            item: entry.item.name,
                            container: entry.container === null ? '' : entry.container.name,
                        })
                    }}
                </span>

                <span class="text-xs text-ink-subtle">{{ deletedByLabel(entry) }}</span>
                <span class="text-xs text-ink-subtle">{{ remainingLabel(t, entry.expires_at) }}</span>

                <Link
                    v-if="restoreFlags[entry.ulid] === true"
                    :href="restoreHref"
                    method="post"
                    :data="{ type: entry.type, ulid: entry.ulid }"
                    as="button"
                    :preserve-state="false"
                    preserve-scroll
                    class="inline-flex min-h-11 items-center self-start text-sm text-accent underline"
                >
                    {{ t('trash.restore') }}
                </Link>
            </span>
        </li>
    </ul>

    <p v-else-if="hasFilter" class="mt-6 text-sm text-ink-muted">
        {{ t('trash.overview.empty_filtered') }}
    </p>

    <p v-else class="mt-6 text-sm text-ink-muted">{{ t('trash.empty') }}</p>

    <p v-if="cursor !== null" class="mt-6">
        <button
            type="button"
            :disabled="loadingMore"
            class="inline-flex min-h-11 items-center rounded-control border border-border bg-surface px-4 text-sm text-ink disabled:opacity-60"
            @click="loadMore"
        >
            {{ loadingMore ? t('common.pending.default') : t('trash.overview.load_more') }}
        </button>
    </p>
</template>
