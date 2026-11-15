<script setup>
import { onMounted, ref } from 'vue';
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
 */
defineProps({
    /* Elementets id, så att klockans `aria-controls` pekar på rätt panel. */
    panelId: { type: String, required: true },
});

const emit = defineEmits(['shown']);

const { t } = useTranslations();

const rows = ref([]);
const loading = ref(true);

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

        <p v-else-if="rows.length === 0" class="text-meta text-ink-muted">
            {{ t('inbox.empty') }}
        </p>

        <ul v-else class="flex flex-col divide-y divide-slate-200">
            <NotificationRow v-for="row in rows" :key="row.ulid" :notification="row" />
        </ul>

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
