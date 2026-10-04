<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { occurrenceActionUrl, scheduleUrl } from './occurrencePresentation.js';
import { useRelativeDate } from '../composables/useRelativeDate.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * En rad i todo-listan, se issue 64 § Beslut 4 och 5.
 *
 * **Raden leds av uppgiften och inte av itemet** (M24 · issue 719, Beslut 4):
 * första raden är SCHEMats titel — vad som ska göras — och den är en länk till
 * schemats sida (63b). Underraden bär itemet, containern och förfallet, i den
 * ordningen, för "Byt impeller" utan "Motorn" och "Havsörnen" går inte att
 * handla på när man har fyra containers.
 *
 * **`showContainer` styr containerlänken** (Beslut 4). På `/tasks` och i
 * dashboardens panel behövs containernamnet — raden står bland andra
 * containers — men på containerns uppgiftsflik står containern redan i hjälten,
 * och fliken skickar `false`. Förvalet är `true`, så de ytor som inte säger
 * något får dagens rad oförändrad.
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
 * schema, en redan stängd förekomst, ett öppet beroende — formuleras av
 * servern och ritas på raden. På `/tasks` och containerns flik finns
 * blockerade uppgifter aldrig i listan (villkor tre i `scopeTodoFor`), men på
 * itemets flik gör de det med märket nedan, och då är det serverfelet vägen
 * till att förklara varför avbockningen nekas.
 *
 * **Två märken är presentation** (M24 · issue 227, Beslut 4). `paused` sätts på
 * en rad vars schema är pausat (`! is_active`), och `blocked` på en rad vars
 * förekomst har ett öppet beroende — båda kommer färdigräknade ur
 * App\Actions\Schedule\ListItemTasks och läses aldrig ur klockan eller ur en
 * egen fråga. Nycklarna finns bara på itemets flik; på `/tasks` och containerns
 * flik är `entry.paused` och `entry.blocked` odefinierade, alltså falska, och
 * inget märke ritas.
 *
 * **Knappen bär en prick för försenat och framtida** (issue 133). Färgen
 * kommer ur `entry.overdue` och `entry.upcoming`, som servern redan har räknat
 * — raden jämför inga datum själv, av samma skäl som `due` ovan (issue 64 §
 * Beslut 3). En uppgift som förfaller i dag har ingen prick, för de två
 * fälten är varandras komplement och möts inte.
 *
 * **Pricken är dekor; orden bär betydelsen.** Den sitter `absolute`, alltså
 * utanför flödet, så knappen behåller sin storlek och sin 44 px höga träffyta
 * (`min-h-11`). Texten i `sr-only` är det skärmläsaren läser tillsammans med
 * `todo.complete` — *Overdue Check off* — och färgen är därför aldrig den
 * enda bäraren av tillståndet. Ordvalet är grupprubrikernas egna nycklar
 * (`todo.group.*`): samma ord om samma sak, och en kopia i `lang/` hade varit
 * den andra sanningen om vad gruppen heter.
 */
const props = defineProps({
    /* En post ur todo-listan: TodoEntryResource plus `account` och `can`. */
    entry: { type: Object, required: true },
    /*
     * Ritar containernamnet på underraden. `true` är förvalet — `/tasks` och
     * dashboardens panel behöver det — och containerns uppgiftsflik skickar
     * `false`, för containern står redan i hjälten.
     */
    showContainer: { type: Boolean, default: true },
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
            <div class="flex flex-wrap items-center gap-x-2">
                <Link :href="scheduleHref" class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline">
                    {{ entry.schedule.title }}
                </Link>

                <!-- Pausad: schemat öppnar inga nya förekomster (Beslut 4), men
                     den öppna raden ligger kvar och märks i stället för att
                     försvinna. -->
                <span
                    v-if="entry.paused"
                    class="rounded bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-700"
                >
                    {{ t('item.schedule.paused') }}
                </span>

                <!-- Blockerad: förekomsten har ett öppet beroende. Servern
                     nekar avbockningen och formulerar felet på raden. -->
                <span
                    v-if="entry.blocked"
                    class="rounded bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-700"
                >
                    {{ t('todo.blocked') }}
                </span>
            </div>

            <p class="mt-1 flex flex-wrap items-center gap-x-2 text-sm text-slate-600">
                <Link :href="itemUrl" class="inline-flex min-h-11 items-center text-blue-700 hover:underline">
                    {{ entry.item.name }}
                </Link>

                <!-- Containernamnet ritas bara när ytan behöver det (Beslut 4),
                     och det går till ITEMLISTAN (issue 89 · [[ADR-0039
                     Containerns översikt]] § Konsekvenser): uppgiften hör till
                     ett item, och den som följer containern ur todo-vyn letar i
                     listan — inte på en översikt. -->
                <template v-if="showContainer">
                    <span aria-hidden="true">·</span>

                    <Link :href="`/containers/${entry.container.ulid}/items`" class="inline-flex min-h-11 items-center text-blue-700 hover:underline">
                        {{ entry.container.name }}
                    </Link>
                </template>

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
                class="relative inline-flex min-h-11 items-center rounded bg-slate-900 px-3 text-sm font-medium text-white disabled:opacity-50"
            >
                <!-- Pricken (issue 133): `danger` för försenad, `accent` för
                     framtida — designsystemets roller ([[ADR-0042
                     Designsystemet]] § Beslut), aldrig en rå palettfärg som
                     `bg-red-600`. `absolute` så att den varken flyttar
                     knappens text eller ändrar dess storlek. -->
                <span
                    v-if="entry.overdue || entry.upcoming"
                    aria-hidden="true"
                    class="absolute right-1 top-1 h-1.5 w-1.5 rounded-full"
                    :class="entry.overdue ? 'bg-danger' : 'bg-accent'"
                />

                <!-- Betydelsen, för den som inte ser färgen. -->
                <span v-if="entry.overdue" class="sr-only">{{ t('todo.group.overdue') }}</span>
                <span v-else-if="entry.upcoming" class="sr-only">{{ t('todo.group.upcoming') }}</span>

                {{ form.processing ? t('common.pending.complete') : t('todo.complete') }}
            </button>
        </form>
    </li>
</template>
