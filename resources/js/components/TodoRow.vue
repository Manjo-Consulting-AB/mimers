<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { occurrenceActionUrl, scheduleUrl } from './occurrencePresentation.js';
import { useRelativeDate } from '../composables/useRelativeDate.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * En rad i todo-listan, se issue 64 § Beslut 4 och 5.
 *
 * **Raden säger var uppgiften hör hemma** (Beslut 5): itemets namn, schemats
 * titel och containerns namn, alla tre, för "Byt impeller" utan "Motorn" och
 * "Havsörnen" går inte att handla på när man har fyra containers. Itemets namn är
 * en länk till itemet, schemats titel till schemats sida (63b).
 *
 * **Avbockningen är 63b:s rutt, rakt av** (Beslut 4). Samma `complete`-rutt,
 * samma grind, samma `CompleteOccurrenceRequest` — `back()` landar på
 * todo-vyn, som ritas om ur serverns svar. Ingen ny rutt och ingen egen
 * stängning: den nya förekomstens `due_at` beräknas av
 * App\Actions\Schedule\CloseOccurrence och kan inte gissas här.
 *
 * **Kontot kommer färdigt ur propen** (Beslut 4). Servern har räknat förvalet
 * — containerns ägarkonto när användaren är medlem i det, annars hennes första
 * konto (63b § Beslut 4) — och vyn skickar bara tillbaka ULID:t. Ingen
 * väljare: raden är en arbetsyta och inte ett formulär, och en lista på
 * hundra rader ska inte bära hundra kontoväljare.
 *
 * **Knappen ritas bara för den som får bocka av** (Beslut 4). `can.update`
 * räknas på servern med `ItemPolicy::update()`; flaggan är presentation, och
 * postar en `read`-mottagare ändå svarar rutten 403. Domänfelet — ett pausat
 * schema, en redan stängd förekomst — formuleras av servern och ritas på
 * raden. Blockerade uppgifter finns aldrig i listan (villkor tre i
 * `scopeTodoFor`), så 63b:s blockeringsmening kan inte uppstå här.
 */
const props = defineProps({
    /* En post ur todo-listan: TodoEntryResource plus `account` och `can`. */
    entry: { type: Object, required: true },
});

const { t } = useTranslations();
const { dueDate } = useRelativeDate();

/*
 * Förfallodagen ur datumregeln (issue 104): relativ inom gränsen, absolut
 * bortom den. `entry.overdue` är serverns fält och går in i regeln — raden
 * räknar aldrig försenat själv, och `due.relative` säger att meningen redan
 * bär sin egen preposition ("In 24 days", inte "Due In 24 days").
 */
const due = computed(() => dueDate(props.entry.due_at, props.entry.overdue));

const itemUrl = computed(
    () => `/containers/${props.entry.container.ulid}/items/${props.entry.item.ulid}`,
);

const scheduleHref = computed(
    () => scheduleUrl(props.entry.container.ulid, props.entry.item.ulid, props.entry.schedule.ulid),
);

const form = useForm({ account: props.entry.account });

function complete() {
    form.post(
        occurrenceActionUrl(
            props.entry.container.ulid,
            props.entry.item.ulid,
            props.entry.schedule.ulid,
            props.entry.ulid,
            'complete',
        ),
        { preserveScroll: true },
    );
}
</script>

<template>
    <li class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 py-4">
        <div>
            <Link :href="itemUrl" class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline">
                {{ entry.item.name }}
            </Link>

            <p class="mt-1 flex flex-wrap items-center gap-x-2 text-sm text-slate-600">
                <Link :href="scheduleHref" class="inline-flex min-h-11 items-center text-blue-700 hover:underline">
                    {{ entry.schedule.title }}
                </Link>

                <span aria-hidden="true">·</span>

                <!-- Containernamnet går till ITEMLISTAN (issue 89 · [[ADR-0039
                     Containerns översikt]] § Konsekvenser): uppgiften hör till
                     ett item, och den som följer containern ur todo-vyn letar i
                     listan — inte på en översikt. -->
                <Link :href="`/containers/${entry.container.ulid}/items`" class="inline-flex min-h-11 items-center text-blue-700 hover:underline">
                    {{ entry.container.name }}
                </Link>

                <span aria-hidden="true">·</span>

                <span :class="due.state === 'danger' ? 'text-danger' : ''">
                    {{ due.relative ? due.text : t('todo.due', { date: due.text }) }}
                </span>
            </p>

            <!-- Domänfelet ur avslutsflödet, formulerat av servern och
                 aldrig som en JSON-kropp — samma mönster som OpenOccurrence. -->
            <p
                v-if="form.errors.occurrence"
                role="alert"
                tabindex="-1"
                class="mt-2 whitespace-pre-line rounded bg-red-50 px-3 py-2 text-sm text-red-800 outline-none"
            >
                {{ form.errors.occurrence }}
            </p>
        </div>

        <form v-if="entry.can.update" @submit.prevent="complete">
            <button
                type="submit"
                :disabled="form.processing"
                class="inline-flex min-h-11 items-center rounded bg-slate-900 px-3 text-sm font-medium text-white disabled:opacity-50"
            >
                {{ form.processing ? t('common.pending.complete') : t('todo.complete') }}
            </button>
        </form>
    </li>
</template>
