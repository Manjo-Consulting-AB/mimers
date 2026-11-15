<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import NotificationRow from '../../components/NotificationRow.vue';
import { useTranslations } from '../../composables/useTranslations.js';

defineOptions({ layout: AppLayout });

/*
 * Notislistan i appen, se M60 · issue 385, [[ADR-0077 Notislistan i appen]]
 * § 2, 3 och 7 samt App\Http\Controllers\NotificationController::index().
 *
 * **Sidan bär första sidan och äger resten själv.** `notifications` är en
 * karta med `data`, `next_cursor` och `unread`: första sidan, markören och
 * märket. Fler sidor hämtas med `router.get('/notifications', { cursor })` och
 * en partiell omladdning av just den proppen, kopian av `/trash`
 * (resources/js/pages/Trash/Index.vue). Raderna läggs under de befintliga —
 * servern svarar med nästa sida och en ny markör.
 *
 * **Sidan har en kopia av raderna, och det är med flit.** Att öppna sidan
 * markerar de visade raderna som lästa (§ 3), men markeringen ska synas först
 * nästa gång listan öppnas — annars hinner personen inte se vilka rader som
 * var nya. `rows` är därför kopian som ritas, och den rörs inte av
 * skrivningens svar; märket i skalets klocka läses ur den delade proppen och
 * uppdateras av omladdningen.
 *
 * **Markera alla** postar till `POST /notifications/read-all`, som markerar
 * alla personens olästa `in_app`-rader (actionen är idempotent). Raderna
 * behåller sin markering av samma skäl som ovan.
 */
const props = defineProps({
    /*
     * `{ data: list<rad>, next_cursor: string|null, unread: int }` — samma
     * form som API:ets svar (§ 2).
     */
    notifications: { type: Object, required: true },
});

const { t } = useTranslations();

const rows = ref([...props.notifications.data]);
const cursor = ref(props.notifications.next_cursor);
const loadingMore = ref(false);
const marking = ref(false);

const hasMore = computed(() => cursor.value !== null);

/*
 * De visade raderna markeras som lästa. Bara de olästa ULID:n skickas:
 * actionen rör ändå bara personens egna rader, och en tom lista är inget
 * anrop.
 */
function markRows(list) {
    const ulids = list
        .filter((row) => row.read_at === null)
        .map((row) => row.ulid);

    if (ulids.length === 0) {
        return;
    }

    router.post('/notifications/read', { ulids }, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => { marking.value = true; },
        onFinish: () => { marking.value = false; },
    });
}

onMounted(() => markRows(rows.value));

/* Nästa sida under de rader som redan står där, med markören från servern. */
function loadMore() {
    if (cursor.value === null || loadingMore.value) {
        return;
    }

    router.get('/notifications', { cursor: cursor.value }, {
        preserveState: true,
        preserveScroll: true,
        only: ['notifications'],
        onStart: () => { loadingMore.value = true; },
        onFinish: () => { loadingMore.value = false; },
        onSuccess: (response) => {
            const list = response.props.notifications;

            rows.value = [...rows.value, ...list.data];
            cursor.value = list.next_cursor;

            markRows(list.data);
        },
    });
}

function markAll() {
    router.post('/notifications/read-all', {}, {
        preserveScroll: true,
        preserveState: true,
        onStart: () => { marking.value = true; },
        onFinish: () => { marking.value = false; },
    });
}
</script>

<template>
    <Head :title="t('notifications.list.title')" />

    <div class="flex w-full flex-col gap-6">
        <h1 class="text-title font-semibold text-ink">{{ t('notifications.list.heading') }}</h1>

        <ul v-if="rows.length > 0" class="flex flex-col divide-y divide-slate-200">
            <NotificationRow v-for="row in rows" :key="row.ulid" :notification="row" />
        </ul>

        <p v-else class="text-sm text-ink-muted">{{ t('notifications.list.empty') }}</p>

        <div class="flex flex-wrap items-center gap-3">
            <button
                v-if="hasMore"
                type="button"
                :disabled="loadingMore"
                class="inline-flex min-h-11 items-center rounded-control border border-border bg-surface px-4 text-sm text-ink disabled:opacity-60"
                @click="loadMore"
            >
                {{ loadingMore ? t('common.pending.default') : t('notifications.list.load_more') }}
            </button>

            <button
                type="button"
                :disabled="marking"
                class="inline-flex min-h-11 items-center rounded-control border border-border bg-surface px-4 text-sm text-ink disabled:opacity-60"
                @click="markAll"
            >
                {{ t('notifications.list.mark_all_read') }}
            </button>
        </div>
    </div>
</template>
