<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import AppHero from '../../components/AppHero.vue';
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
 *
 * **Väntande inbjudningar står överst** (issue 131 · [[M20 Kontot]] § 131,
 * granskningen av #1315). En inbjudan är ingen notis och finns därför inte i
 * sidproppen; raderna kommer ur den delade optionala proppen
 * `pendingInvitations`, som klockan i skalet hämtar. Under `md:` är klockan
 * bara en länk till den här sidan, så utan raderna här hade en inbjudan inte
 * synts alls på mobilen. Sidan håller en lokal kopia av listan, så att en full
 * omladdning — en POST svarar med en omdirigering — inte tömmer raderna medan
 * proppen är borta. Inbjudningarna markeras aldrig som lästa: de försvinner
 * först när de besvarats eller gått ut.
 *
 * **Tomt läget kräver att båda listorna är tomma**, och det ritas först när
 * inbjudningsproppen har kommit: en tom sida medan listan är på väg hade sagt
 * "inget nytt" om något komponenten ännu inte fått.
 */
const props = defineProps({
    /*
     * `{ data: list<rad>, next_cursor: string|null, unread: int }` — samma
     * form som API:ets svar (§ 2).
     */
    notifications: { type: Object, required: true },
});

const { t } = useTranslations();
const page = usePage();

const rows = ref([...props.notifications.data]);
const cursor = ref(props.notifications.next_cursor);
const loadingMore = ref(false);
const marking = ref(false);

/*
 * Den lokala kopian av inbjudningarna. Proppen är optional: klockan i skalet
 * hämtar den, och en full omladdning tappar den. Kopian behåller raderna
 * däremellan, och `invitationsLoaded` håller tomt läget tillbaka tills första
 * svaret är här.
 */
const pendingInvitations = ref(page.props.pendingInvitations ?? []);
const invitationsLoaded = ref(page.props.pendingInvitations !== undefined);

watch(() => page.props.pendingInvitations, (value) => {
    if (value !== undefined) {
        pendingInvitations.value = value;
        invitationsLoaded.value = true;
    }
});

const hasMore = computed(() => cursor.value !== null);

/* Tomt bara när båda listorna är tomma och inbjudningarna är hämtade. */
const empty = computed(() => rows.value.length === 0
    && pendingInvitations.value.length === 0
    && invitationsLoaded.value);

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
        <AppHero
            :heading="t('notifications.list.heading')"
            :subtitle="t('hero.subtitle.notifications')"
        />

        <!--
            Inbjudningarna först (issue 131): de väntar på ett svar, och
            under `md:` är den här sidan den enda ytan där de syns. Raden
            länkar till `/invitations`, där svaret ges.
        -->
        <ul v-if="pendingInvitations.length > 0" class="flex flex-col divide-y divide-slate-200">
            <li v-for="invitation in pendingInvitations" :key="invitation.ulid">
                <Link
                    :href="invitation.url"
                    class="flex min-h-11 items-center gap-3 text-ink outline-none hover:underline focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                >
                    {{ t('inbox.invitation.received', { inviter: invitation.inviter, container: invitation.container }) }}
                </Link>
            </li>
        </ul>

        <ul v-if="rows.length > 0" class="flex flex-col divide-y divide-slate-200">
            <NotificationRow v-for="row in rows" :key="row.ulid" :notification="row" />
        </ul>

        <p v-if="empty" class="text-sm text-ink-muted">{{ t('notifications.list.empty') }}</p>

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
