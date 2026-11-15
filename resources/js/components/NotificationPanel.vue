<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import NotificationRow from './NotificationRow.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Panelen som klockan öppnar över `md:`, se M60 · issue 385 och
 * [[ADR-0077 Notislistan i appen]] § 2 och 3.
 *
 * **Listan hämtas först när panelen öppnas.** `GET /notifications/panel`
 * svarar JSON med de tio senaste och antalet olästa
 * (App\Http\Controllers\NotificationController::panel()). Den är ingen
 * Inertia-propp, så en vanlig sidladdning bär den aldrig — samma kostnad som
 * 383:s delade `notifications` med flit undviker. Panelen monteras bara
 * medan den är öppen, så ett stängt läge är ett anrop mindre.
 *
 * **Rader som visats markeras som lästa av klockan, inte här.** Panelen
 * skickar sina olästa ULID:n till NotificationBell när svaret kommit, och
 * klockan postar dem och hämtar om märket. Panelen behåller sitt eget svar:
 * markeringen syns först nästa gång listan öppnas, så att personen hinner se
 * vilka som var nya (§ 3).
 *
 * **Panelen äger ingen stängning.** Escape, klicket utanför och klicket på
 * klockan hanteras av NotificationBell, som också lämnar tillbaka fokus till
 * klockan — den känner knappen, och panelen gör det inte.
 *
 * **Inbjudningarna står överst och kommer ur en annan tabell** (issue 131 ·
 * [[M20 Kontot]] § 131, granskningen av #1315). En inbjudan är ingen notis —
 * den skrivs aldrig till `notification` och finns därför inte i
 * `GET /notifications/panel`. Raderna kommer ur den delade proppen
 * `pendingInvitations`, som klockan skickar in, en rad per väntande inbjudan
 * med katalogens mening och länken till `/invitations`. Ordningen är inte
 * kosmetisk: en inbjudan väntar på ett svar, en notis är ett kvitto.
 * Inbjudningsraderna går aldrig till `POST /notifications/read` — de
 * försvinner först när de besvarats eller gått ut.
 */
const props = defineProps({
    /* Elementets id, så att klockans `aria-controls` pekar på rätt panel. */
    panelId: { type: String, required: true },
    /*
     * Väntande inbjudningar, ur den delade optionala proppen. Tom listan är
     * samma sak som inga inbjudningar.
     */
    pendingInvitations: { type: Array, default: () => [] },
});

const emit = defineEmits(['shown']);

const { t } = useTranslations();

const rows = ref([]);
const loading = ref(true);

/*
 * Tomt läget kräver att BÅDA listorna är tomma: en panel med bara en
 * väntande inbjudan har något att visa, och "inget nytt" hade varit osant.
 */
const empty = computed(() => ! loading.value
    && rows.value.length === 0
    && props.pendingInvitations.length === 0);

/*
 * Hämtningen är ett vanligt GET-anrop och inte en Inertia-visit: svaret är
 * JSON, sidan ska stå kvar och klockan ska inte navigera. Ett fel ger en tom
 * lista — en panel som inte kunde svara visar ingenting hellre än en trasig
 * rad.
 */
onMounted(async () => {
    try {
        const response = await fetch('/notifications/panel', {
            headers: { Accept: 'application/json' },
        });

        if (response.ok) {
            const data = await response.json();

            rows.value = data.data ?? [];
        }
    } catch {
        rows.value = [];
    } finally {
        loading.value = false;
    }

    emit('shown', rows.value
        .filter((row) => row.read_at === null)
        .map((row) => row.ulid));
});
</script>

<template>
    <section
        :id="panelId"
        :aria-label="t('notifications.list.heading')"
        class="absolute right-0 z-10 mt-1 w-80 max-w-[calc(100vw-2rem)] rounded-card border border-border bg-surface p-4 shadow-lg"
    >
        <p v-if="loading" class="text-meta text-ink-muted">
            {{ t('common.pending.default') }}
        </p>

        <p v-else-if="empty" class="text-meta text-ink-muted">
            {{ t('inbox.empty') }}
        </p>

        <template v-else>
            <!--
                Inbjudningarna först (issue 131): de väntar på ett svar,
                notiserna är kvitton på något som redan hänt. Raden bär ingen
                tid — det som betyder något för en inbjudan är att den väntar.
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
        </template>

        <div class="mt-2 flex justify-end">
            <Link
                href="/notifications"
                class="inline-flex min-h-11 items-center text-meta text-accent outline-none hover:underline focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
            >
                {{ t('notifications.list.see_all') }}
            </Link>
        </div>
    </section>
</template>
