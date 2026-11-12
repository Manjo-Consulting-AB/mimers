<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../../layouts/AppLayout.vue';
import ContainerLayout from '../../../../layouts/ContainerLayout.vue';
import OpenOccurrence from '../../../../components/OpenOccurrence.vue';
import ScheduleActions from '../../../../components/ScheduleActions.vue';
import ScheduleDependencySection from '../../../../components/ScheduleDependencySection.vue';
import { formatDate } from '../../../../components/accessPresentation.js';
import { formatDateOnly } from '../../../../components/itemPresentation.js';
import { occurrenceStatusLabel } from '../../../../components/occurrencePresentation.js';
import { occurrenceRows, scheduleRows } from '../../../../components/scheduleDependencyRows.js';
import { recurrenceLabel } from '../../../../components/schedulePresentation.js';
import { useTranslations } from '../../../../composables/useTranslations.js';

defineOptions({
    layout: (props) => [
        [AppLayout, {}],
        [ContainerLayout, { hero: 'compact', container: props.container }],
    ],
});

/*
 * Schemats sida — regeln, den öppna förekomsten och historiken, se issue 63b
 * § Beslut 1, 2, 3, 4, 5 och 7.
 *
 * Sidan ligger i ContainerLayout och bär samma två propar som Create.vue och
 * Edit.vue: `container` ur App\Http\Resources\ContainerResource och `item`
 * som `{ulid, name}`. `schedule` är App\Http\Resources\ScheduleResource rakt
 * av, och `occurrences` är hela listan ur
 * App\Http\Resources\ScheduleOccurrenceResource — den ÖPPNA förekomsten
 * inräknad, precis som `Api\ScheduleOccurrenceController::index()` svarar.
 *
 * **Historiken ÄR loggen** ([[Scheman och uppgifter]] § schedule_occurrence):
 * svaret på "när bytte jag impellern senast" är de avklarade förekomsterna
 * själva, inte en historiktabell. Sidan ritar därför ingen egen lista vid
 * sidan av — den ritar listan, och den öppna raden lyfts ut till sin egen
 * ruta ovanför för att den går att göra något med.
 *
 * **Ingen sortering och ingen paginering i vyn** (Beslut 7). Ordningen är
 * serverns — `due_at` fallande med `id` fallande, samma som `/api` (22a
 * § Beslut 1) — och vyn filtrerar bara ut den öppna raden ur den. Den öppna
 * raden är definitionsmässigt den som ska göras nu, och att sortera om
 * listan här hade gett två sanningar om ordningen.
 *
 * **`overdue` läses ur serverns svar** (Beslut 3). Vyn jämför aldrig
 * `due_at` mot sin egen klocka; det är komponenten OpenOccurrence.vue som
 * ritar märkningen, och den läser samma härledda fält.
 *
 * **Efter en avbockning laddas sidan om från servern** (Beslut 8). Den nya
 * förekomstens `due_at` beräknas av App\Actions\Schedule\CloseOccurrence —
 * `fixed` från kalendern, `interval` från `completed_at` — och vyn gissar
 * den aldrig: svaret på en avbockning är en omdirigering tillbaka hit, och
 * listan ritas ur serverns svar.
 */
const props = defineProps({
    container: { type: Object, required: true },
    item: { type: Object, required: true },
    schedule: { type: Object, required: true },
    /* Alla förekomster, den öppna inräknad, i serverns ordning. */
    occurrences: { type: Array, required: true },
    /* Schemats beroenden — regeln (63c § Beslut 2). */
    scheduleDependencies: { type: Array, required: true },
    /* Den öppna förekomstens beroenden — undantaget (63c § Beslut 2). */
    occurrenceDependencies: { type: Array, required: true },
    hasOpenOccurrence: { type: Boolean, required: true },
    /*
     * Sant när schemat ligger på ett inbox-item (M27 · issue 244, ADR-0054
     * § 1). *Back to Inbox* ritas då inte: uppgiften är redan där, och rutten
     * hade svarat 422 `schedule.already_in_inbox`.
     */
    containerIsInbox: { type: Boolean, default: false },
    /* Den prövade returadressen och tillbakaadressen (issue 1158). */
    returnTo: { type: String, default: null },
    back: { type: String, required: true },
    can: { type: Object, required: true },
});

const { t } = useTranslations();
const page = usePage();

const locale = computed(() => page.props.locale);

const recurrence = computed(() => recurrenceLabel(t, props.schedule));

/*
 * Den öppna förekomsten lyfts ur listan och ritas för sig — den är den enda
 * som går att göra något med. Historiken är resten, i den ordning servern
 * gav den.
 */
const open = computed(() => props.occurrences.find((occurrence) => occurrence.status === 'open') ?? null);

const history = computed(() => props.occurrences.filter((occurrence) => occurrence.status !== 'open'));

/*
 * En avklarad rad och en överhoppad rad får inte se likadana ut (Beslut 5):
 * historiken är loggen, och den som läser den ska kunna se vad som faktiskt
 * blev gjort. Orden skiljer dem (occurrenceStatusLabel), och färgen gör det.
 */
const badgeClass = (occurrence) => (occurrence.status === 'completed'
    ? 'bg-emerald-100 text-emerald-900'
    : 'bg-amber-100 text-amber-900');

const date = (value) => formatDateOnly(value, locale.value);

/* Beroenderadena i den läsbara listan (issue 1160): normaliseringen delas med Edit.vue. */
const scheduleDependencyRows = computed(() => scheduleRows(props.scheduleDependencies));

const occurrenceDependencyRows = computed(() => occurrenceRows(props.occurrenceDependencies));

</script>

<template>
    <Head :title="schedule.title" />

    <h1 class="text-2xl font-semibold">{{ schedule.title }}</h1>

    <div class="mt-2 flex flex-wrap items-center gap-3">
        <p class="text-sm text-slate-600">{{ recurrence }}</p>

        <!-- Pausen är synlig och reversibel (issue 720 § Beslut 3): samma
             märke och samma mening som listan använder, så att ett pausat
             schema går att känna igen på båda ytorna. -->
        <span
            v-if="! schedule.is_active"
            class="rounded bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-700"
        >
            {{ t('item.schedule.paused') }}
        </span>
    </div>

    <p v-if="! schedule.is_active" class="mt-2 text-sm text-slate-600">
        {{ t('item.schedule.paused_note') }}
    </p>

    <p v-if="schedule.notes" class="mt-2 whitespace-pre-line text-slate-900">{{ schedule.notes }}</p>

    <ScheduleActions
        context="show"
        :container="container"
        :item="item"
        :schedule="schedule"
        :can="can"
        :container-is-inbox="containerIsInbox"
        :return-to="returnTo"
        :back="back"
    />

    <section class="mt-8">
        <!-- Den öppna förekomsten med sin avbockning (Beslut 1). En
             engångsuppgift som redan är klar har ingen öppen rad — då
             sägs det i stället, och historiken nedanför bär raden. -->
        <OpenOccurrence
            v-if="open"
            :container-ulid="container.ulid"
            :item-ulid="item.ulid"
            :schedule-ulid="schedule.ulid"
            :occurrence="open"
            :container-account="container.account"
            :can="can"
        />

        <p v-else class="text-sm text-slate-600">
            {{ schedule.recurrence_type === 'none'
                ? t('item.schedule.occurrence.done')
                : t('item.schedule.occurrence.none') }}
        </p>
    </section>

    <section class="mt-10">
        <h2 class="text-lg font-semibold">{{ t('item.schedule.occurrence.history') }}</h2>

        <p v-if="history.length === 0" class="mt-2 text-sm text-slate-600">
            {{ t('item.schedule.occurrence.history_empty') }}
        </p>

        <ul v-else class="mt-2 flex flex-col gap-2">
            <li
                v-for="occurrence in history"
                :key="occurrence.ulid"
                class="rounded border border-slate-300 bg-white px-4 py-3"
            >
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span class="rounded px-2 py-0.5 text-xs font-medium" :class="badgeClass(occurrence)">
                        {{ occurrenceStatusLabel(t, occurrence) }}
                    </span>

                    <span class="text-sm text-slate-700">
                        {{ t('item.schedule.occurrence.due', { date: date(occurrence.due_at) }) }}
                    </span>

                    <span v-if="occurrence.completed_at" class="text-sm text-slate-600">
                        {{ t('item.schedule.occurrence.completed_at', { date: formatDate(occurrence.completed_at, locale) }) }}
                    </span>

                    <!-- Kontot är varvet och inte den anställde (Beslut 4):
                         det är det som står i loggen, och det är därför
                         resursen bär `completed_by_account` och aldrig
                         `completed_by_user`. -->
                    <span v-if="occurrence.completed_by_account" class="text-sm text-slate-600">
                        {{ t('item.schedule.occurrence.completed_by', { name: occurrence.completed_by_account.name }) }}
                    </span>
                </div>

                <p v-if="occurrence.completion_note" class="mt-2 whitespace-pre-line text-sm text-slate-800">
                    {{ occurrence.completion_note }}
                </p>
            </li>
        </ul>
    </section>

    <!--
        Beroendena som en läsbar lista (issue 1160): de redigeras på
        redigeringssidan, så sektionerna ritas utan formulär och utan
        borttagning (`update: false`). En sektion utan rader ritas inte alls —
        står det inget i någon av dem syns ingen beroenderubrik.

        Schemanivån är REGELN som ärvs av varje ny förekomst; förekomstnivån
        är UNDANTAGET som bara gäller den här gången (63c § Beslut 2).
    -->
    <ScheduleDependencySection
        v-if="scheduleDependencyRows.length > 0"
        level="schedule"
        :container-ulid="container.ulid"
        :item-ulid="item.ulid"
        :rows="scheduleDependencyRows"
        url=""
        :counterparts="[]"
        :can="{ update: false }"
    />

    <ScheduleDependencySection
        v-if="occurrenceDependencyRows.length > 0"
        level="occurrence"
        :container-ulid="container.ulid"
        :item-ulid="item.ulid"
        :rows="occurrenceDependencyRows"
        url=""
        :counterparts="[]"
        :active="hasOpenOccurrence"
        :can="{ update: false }"
    />
</template>
