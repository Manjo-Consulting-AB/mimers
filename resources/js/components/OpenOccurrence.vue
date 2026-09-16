<script setup>
import { computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import { formatDateOnly } from './itemPresentation.js';
import { occurrenceActionUrl, occurrenceWindow } from './occurrencePresentation.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Den öppna förekomsten med sin avbockning, se issue 63b § Beslut 1, 2, 3, 4
 * och 5.
 *
 * **Samma komponent på båda ytorna.** Avbockningen finns på schemats sida och
 * direkt i sektionen på itemet (Beslut 1) — det är produktens vanligaste
 * skrivning och ska kosta minst. Två avskrifter av samma formulär hade glidit
 * isär vid första ändringen, och då hade de två ytorna skickat olika saker
 * till samma Action.
 *
 * **Tre datum i rätt roll** (Beslut 2). `due_at` är förfallodagen,
 * `visible_from` är när uppgiften dök upp, och glappet dem emellan är tiden
 * man har på sig. Alla är DATE-kolumner och formateras utan att räknas om
 * över en tidszon.
 *
 * **`overdue` läses, räknas aldrig** (Beslut 3). Fältet kommer ur
 * App\Http\Resources\ScheduleOccurrenceResource, och den här komponenten
 * jämför aldrig `due_at` mot klientens klocka: en klient med fel datum ska
 * inte kunna färga en uppgift röd. [[ADR-0005 Schema och förekomst]]:s regel
 * — att ett tillstånd klockan ändrar aldrig lagras — gäller lika mycket för
 * en `computed` som för en kolumn.
 *
 * **Kontot är varvet** (Beslut 4). `CompleteOccurrenceRequest` kräver
 * `account`, och förvalet är pärmens ägarkonto när användaren är medlem i
 * det, annars hennes första konto — samma regel som 57b § Beslut 4 och 60a
 * § Beslut 4, ur den delade propen `auth.accounts`. Är hon medlem i exakt ett
 * konto ritas ingen väljare: ett val mellan ett alternativ är ingen fråga,
 * och värdet är ändå förvalt så att formuläret skickar rätt konto.
 *
 * **Anteckningen är valfri och hamnar i historiken** — "bytte även
 * termostaten" är precis den upplysning en logg är till för.
 *
 * **Hoppa över frågar** (Beslut 5). Den stänger förekomsten utan att påstå
 * att jobbet gjordes, och bekräftelsen säger vad som händer med nästa
 * förekomst. Avbockningen frågar inte: den är den handling som ska kosta
 * minst, och en bekräftelseruta på varje oljebyte är en tröghet ingen
 * accepterar.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    itemUlid: { type: String, required: true },
    scheduleUlid: { type: String, required: true },
    /* Den öppna förekomsten ur ScheduleOccurrenceResource. */
    occurrence: { type: Object, required: true },
    /* Pärmens ägarkonto — förvalet när användaren är medlem i det. */
    containerAccount: { type: String, default: '' },
    can: { type: Object, required: true },
});

const { t } = useTranslations();
const page = usePage();

const locale = computed(() => page.props.locale);

/*
 * Kontolistan kommer ur den delade propen `auth.accounts` och inte ur en egen
 * sidprop (Beslut 4): en fråga för samma lista är en fråga för mycket, samma
 * linje som issue 54 § Beslut 5 och 57b § Beslut 4.
 */
const accounts = computed(() => page.props.auth?.accounts ?? []);

const singleAccount = computed(() => (accounts.value.length === 1 ? accounts.value[0] : null));

const defaultAccount = computed(() => {
    const owner = accounts.value.find((candidate) => candidate.ulid === props.containerAccount);

    return owner?.ulid ?? accounts.value[0]?.ulid ?? '';
});

const form = useForm({
    account: defaultAccount.value,
    completion_note: '',
});

/* Id:n per förekomst: sektionen ritar en rad per schema, och två fält med
 * samma id hade fått etiketten att peka på fel inmatning (issue 68b). */
const accountId = computed(() => `occurrence-account-${props.occurrence.ulid}`);
const noteId = computed(() => `occurrence-note-${props.occurrence.ulid}`);
const errorId = computed(() => `occurrence-error-${props.occurrence.ulid}`);

const due = computed(() => formatDateOnly(props.occurrence.due_at, locale.value));
const visibleFrom = computed(() => formatDateOnly(props.occurrence.visible_from, locale.value));
const spare = computed(() => occurrenceWindow(t, props.occurrence));

/*
 * Båda knapparna postar SAMMA formulär, med samma konto och samma anteckning
 * — det är bara sista ledet i URL:en som skiljer (Beslut 1). Servern svarar
 * med en omdirigering tillbaka, och sidan ritas om ur serverns svar: den nya
 * förekomstens `due_at` beräknas av App\Actions\Schedule\CloseOccurrence och
 * kan inte gissas här (Beslut 8).
 */
function complete() {
    form.post(action('complete'), { preserveScroll: true });
}

function skip() {
    if (! window.confirm(t('item.schedule.occurrence.skip_confirm'))) {
        return;
    }

    form.post(action('skip'), { preserveScroll: true });
}

function action(name) {
    return occurrenceActionUrl(
        props.containerUlid,
        props.itemUlid,
        props.scheduleUlid,
        props.occurrence.ulid,
        name,
    );
}
</script>

<template>
    <div class="rounded border border-slate-300 bg-white px-4 py-3">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span class="text-sm font-medium text-slate-700">
                {{ t('item.schedule.occurrence.heading') }}
            </span>

            <!-- Försenad är serverns härledda `overdue` (Beslut 3) — vyn
                 jämför aldrig förfallodatumet mot sin egen klocka. -->
            <span
                v-if="occurrence.overdue"
                class="rounded bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800"
            >
                {{ t('item.schedule.occurrence.overdue') }}
            </span>
        </div>

        <p class="mt-2 flex flex-wrap gap-x-2 text-sm text-slate-700">
            <span>{{ t('item.schedule.occurrence.due', { date: due }) }}</span>
            <span aria-hidden="true">·</span>
            <span>{{ t('item.schedule.occurrence.visible_from', { date: visibleFrom }) }}</span>
            <template v-if="spare">
                <span aria-hidden="true">·</span>
                <span>{{ spare }}</span>
            </template>
        </p>

        <form v-if="can.update" class="mt-3 flex flex-col gap-3" @submit.prevent="complete">
            <!-- Ett enda konto är en rad text och inte en väljare (Beslut 4):
                 ett val mellan ett alternativ är ingen fråga. Värdet är ändå
                 förvalt, så formuläret skickar rätt konto. -->
            <template v-if="singleAccount">
                <div class="flex flex-col gap-1">
                    <span class="text-sm font-medium text-slate-800">
                        {{ t('item.schedule.occurrence.account') }}
                    </span>
                    <span class="text-sm text-slate-900">{{ singleAccount.name }}</span>
                </div>
            </template>

            <FormField
                v-else
                :label="t('item.schedule.occurrence.account')"
                :id="accountId"
                :error="form.errors.account"
            >
                <select :id="accountId" v-model="form.account" class="rounded border border-slate-300 px-3 py-2 text-sm">
                    <option v-for="candidate in accounts" :key="candidate.ulid" :value="candidate.ulid">
                        {{ candidate.name }}
                    </option>
                </select>
            </FormField>

            <p class="text-xs text-slate-500">{{ t('item.schedule.occurrence.account_hint') }}</p>

            <FormField
                :label="t('item.schedule.occurrence.note')"
                :id="noteId"
                :error="form.errors.completion_note"
            >
                <input
                    :id="noteId"
                    v-model="form.completion_note"
                    type="text"
                    class="rounded border border-slate-300 px-3 py-2 text-sm"
                >
            </FormField>

            <p class="text-xs text-slate-500">{{ t('item.schedule.occurrence.note_hint') }}</p>

            <!-- Domänfelet ur avslutsflödet (Beslut 6). `occurrence.blocked`
                 bär hela blockerarlistan som färdiga rader, en per uppgift
                 med titel och datum — därför `whitespace-pre-line` och inte
                 en enda lång mening. Aldrig en JSON-kropp: kontrollern gör
                 felkoden till ett formulärfel. -->
            <p
                v-if="form.errors.occurrence"
                :id="errorId"
                tabindex="-1"
                class="whitespace-pre-line rounded bg-red-50 px-3 py-2 text-sm text-red-800 outline-none"
            >
                {{ form.errors.occurrence }}
            </p>

            <div class="flex flex-wrap gap-3">
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex min-h-11 items-center rounded bg-slate-900 px-3 text-sm font-medium text-white disabled:opacity-50"
                >
                    {{ form.processing ? t('common.pending.complete') : t('item.schedule.occurrence.complete') }}
                </button>

                <button
                    type="button"
                    :disabled="form.processing"
                    class="inline-flex min-h-11 items-center rounded border border-slate-300 px-3 text-sm font-medium text-slate-800 disabled:opacity-50"
                    @click="skip"
                >
                    {{ t('item.schedule.occurrence.skip') }}
                </button>
            </div>
        </form>
    </div>
</template>
