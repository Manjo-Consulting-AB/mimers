<script setup>
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { formatDate } from './accessPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * En rad i pärmens kalenderlista, se issue 65b § Beslut 2 och 4.
 *
 * **Adressen står ALDRIG här.** App\Http\Resources\CalendarFeedResource bär
 * varken tokenet eller hashen — klartexten finns bara i svaret på skapandet
 * och visas av resources/js/components/SecretOnce.vue. Raden visar när feeden
 * skapades, när den senast hämtades och en återkallningsknapp. Den som
 * tappat bort länken återkallar och skapar en ny: URL:en ÄR lösenordet.
 *
 * **En återkallad feed ligger KVAR i listan** och märks som återkallad
 * (Beslut 4). Rader raderas aldrig, och den som undrar varför kalendern
 * slutade uppdateras ska kunna se svaret i stället för att mötas av en tom
 * lista. Samma linje som en pausad rad i ScheduleListSection: det reversibla
 * och det avslutade ska synas, inte försvinna.
 *
 * `last_fetched_at` är `null` tills kalenderappen hämtat feeden första
 * gången, och den meningen är sin egen — ett tomt datumfält hade sett ut som
 * ett fel.
 *
 * Datumen formateras med `formatDate()` ur accessPresentation.js, samma
 * hjälpare som delningsvyn använder för ISO-tidsstämplar; orden runt dem
 * kommer ur lang/.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },

    /* En rad ur App\Http\Resources\CalendarFeedResource. */
    feed: { type: Object, required: true },
});

const { t } = useTranslations();
const page = usePage();

const locale = computed(() => page.props.locale);

const revoked = computed(() => props.feed.revoked_at !== null);

const lastFetched = computed(() => (props.feed.last_fetched_at === null
    ? t('calendar.row.never_fetched')
    : t('calendar.row.last_fetched', { date: formatDate(props.feed.last_fetched_at, locale.value) })));

/*
 * Återkallandet. Bekräftelsen är webbläsarens egen dialog med serverns mening
 * ur lang/ — ingen modal komponent och ingen sträng i JavaScript, samma
 * mönster som schemat och bilagorna.
 *
 * `preserveScroll` därför att raden står kvar — den blir återkallad, inte
 * borta — och listan inte ska hoppa för en liten ändring mitt i den.
 */
function revoke() {
    if (! window.confirm(t('calendar.revoke_confirm'))) {
        return;
    }

    router.delete(`/containers/${props.containerUlid}/calendar/${props.feed.ulid}`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <li
        class="flex flex-col gap-2 rounded border border-slate-300 bg-white px-4 py-3"
        :class="revoked ? 'text-slate-500' : null"
    >
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span>{{ t('calendar.row.created', { date: formatDate(props.feed.created_at, locale) }) }}</span>

            <span class="text-sm">{{ lastFetched }}</span>

            <span
                v-if="revoked"
                class="rounded bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-700"
            >
                {{ t('calendar.row.revoked_badge') }}
            </span>
        </div>

        <p v-if="revoked" class="text-sm">
            {{ t('calendar.row.revoked_note', { date: formatDate(props.feed.revoked_at, locale) }) }}
        </p>

        <!-- Ingen återkallningsknapp på en redan återkallad rad: handlingen är
             gjord, och en knapp som inte gör något nytt är en knapp som ljuger
             om vad den kan. -->
        <button
            v-else
            type="button"
            class="self-start text-sm font-medium text-red-700 hover:underline"
            @click="revoke"
        >
            {{ t('calendar.revoke') }}
        </button>
    </li>
</template>
