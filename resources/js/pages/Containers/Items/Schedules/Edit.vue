<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../../../../layouts/AppLayout.vue';
import ContainerLayout from '../../../../layouts/ContainerLayout.vue';
import ScheduleActions from '../../../../components/ScheduleActions.vue';
import ScheduleDependencySection from '../../../../components/ScheduleDependencySection.vue';
import ScheduleForm from '../../../../components/ScheduleForm.vue';
import {
    occurrenceDependencyUrl,
    occurrenceRows,
    scheduleDependencyUrl,
    scheduleRows,
} from '../../../../components/scheduleDependencyRows.js';
import { useTranslations } from '../../../../composables/useTranslations.js';

defineOptions({
    layout: (props) => [
        [AppLayout, {}],
        [ContainerLayout, { hero: 'compact', container: props.container }],
    ],
});

/*
 * Redigera ett schema, se issue 63a § Beslut 1, 3, 4 och 5.
 *
 * Sidan ligger i ContainerLayout och bär samma två propar som Create.vue:
 * `container` ur App\Http\Resources\ContainerResource och `item` som
 * `{ulid, name}`. `schedule` är App\Http\Resources\ScheduleResource rakt av —
 * samma format som `/api` ger — och formuläret förvalt sina fält ur den.
 *
 * **Ingen pausknapp här** (Beslut 6). `is_active` är ett fält i raden på
 * detaljvyn och inte i det här formuläret: pausen ska vara ett klick i
 * listan, där man ser vad den gör, och en PATCH som bär bara `is_active` är
 * samma rutt som den här sidan postar till. Ett kryss i formuläret hade gjort
 * pausen till en del av en större sparoperation — och ett pausat schema ska
 * inte kunna smyga med i en titeländring.
 */
const props = defineProps({
    container: { type: Object, required: true },
    item: { type: Object, required: true },
    schedule: { type: Object, required: true },
    /*
     * Den öppna förekomstens förfallodag, eller null när schemat saknar en
     * öppen förekomst (issue 702 § Beslut 3). Skickas rakt vidare till
     * ScheduleForm, som förifyller `anchor_date` med den.
     */
    openDueAt: { type: String, default: null },
    /* Den prövade returadressen och tillbakaadressen (issue 1158). */
    returnTo: { type: String, default: null },
    back: { type: String, required: true },
    /* Samma grindar och inbox-flagga som schemats sida (issue 1159). */
    can: { type: Object, required: true },
    containerIsInbox: { type: Boolean, default: false },
    /* Beroendena och motparterna att välja bland (issue 1160, 63c § Beslut 1-3). */
    scheduleDependencies: { type: Array, required: true },
    occurrenceDependencies: { type: Array, required: true },
    hasOpenOccurrence: { type: Boolean, required: true },
    counterparts: { type: Object, required: true },
    /* Den öppna förekomstens ULID — förekomstberoendets URL bärs av den. */
    openOccurrenceUlid: { type: String, default: null },
});

const { t } = useTranslations();

/* Formulärets osparade tillstånd, för menyns fråga (issue 1159). */
const dirty = ref(false);

const scheduleDependencyRows = computed(() => scheduleRows(props.scheduleDependencies));

const occurrenceDependencyRows = computed(() => occurrenceRows(props.occurrenceDependencies));
</script>

<template>
    <Head :title="t('item.schedule.update.title')" />

    <h1 class="text-2xl font-semibold">{{ t('item.schedule.update.heading') }}</h1>

    <p class="mt-1 text-sm text-slate-600">{{ item.name }}</p>

    <ScheduleActions
        context="edit"
        :container="container"
        :item="item"
        :schedule="schedule"
        :can="can"
        :container-is-inbox="containerIsInbox"
        :return-to="returnTo"
        :back="back"
        :dirty="dirty"
    />

    <ScheduleForm
        class="mt-8"
        :container-ulid="container.ulid"
        :item-ulid="item.ulid"
        :schedule="schedule"
        :open-due-at="openDueAt"
        :return-to="returnTo"
        @dirty="dirty = $event"
    />

    <!-- Beroendena redigeras här (issue 1160). Sidan laddas om efter en
         tillägg eller borttagning; sektionen behåller formulärets osparade
         ändringar med preserveState. -->
    <ScheduleDependencySection
        level="schedule"
        :container-ulid="container.ulid"
        :item-ulid="item.ulid"
        :rows="scheduleDependencyRows"
        :url="scheduleDependencyUrl(container.ulid, item.ulid, schedule.ulid)"
        :counterparts="counterparts.schedule"
        :can="can"
    />

    <ScheduleDependencySection
        level="occurrence"
        :container-ulid="container.ulid"
        :item-ulid="item.ulid"
        :rows="occurrenceDependencyRows"
        :url="occurrenceDependencyUrl(container.ulid, item.ulid, schedule.ulid, openOccurrenceUlid)"
        :counterparts="counterparts.occurrence"
        :active="hasOpenOccurrence"
        :can="can"
    />
</template>
