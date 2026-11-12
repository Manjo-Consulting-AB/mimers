<script setup>
import { nextTick, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import ItemTargetPicker from './ItemTargetPicker.vue';
import QuickCreate from './QuickCreate.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Snabbraden på containerns *To do*-flik, se [[ADR-0074 Registrering i vyn]]
 * § 2–4 och 6 · M52 · issue 358. Sätter ihop `QuickCreate` (issue 356) med
 * platsvalet och skrivvägen; servern är oförändrad.
 *
 * **Platsen är `null`, och `null` är inboxen** (Beslut 4, ADR-0054 § 8). Den
 * är förvald varje gång sidan laddas, står kvar efter en sparning så länge
 * användaren är kvar, och sparas aldrig i `localStorage`.
 *
 * **Två skrivvägar som redan finns.** Inboxen: `POST /inbox/tasks`. Ett item:
 * `POST /containers/{container}/items/{item}/schedules`. Båda bär `return`
 * med `page.url`, så svaret landar i samma flik med listan och filtret kvar.
 * `gtd_list` skickas bara mot ett item (en uppgift i inboxen är obearbetad,
 * ADR-0054 § 5) och är flikens lista när den är en av de tre lagrade, annars
 * `next`. Itemets rutt kräver dessutom `recurrence_type`; snabbraden skapar en
 * engångsuppgift, alltså `none`.
 *
 * **Döljs den nya raden av fliken eller filtret säger raden det** (Beslut 5):
 * `entryUlids` jämförs före och efter svaret, och kommer ingen ny ULID med
 * visas `container.tasks.quick.hidden`. Nya ULID:er skickas ut i `created`.
 * Inboxens bekräftelse är serverns flash `inbox-task-captured`.
 *
 * Råa `<button>` i `leading`, som i `Tasks/Create.vue`: GenomgangTest
 * tillåter bara webbläsarens egna element som klickbar yta.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    /* Flikens `?list=`, eller null. */
    list: { type: String, default: null },
    /* ULID:erna i flikens öppna grupper just nu. */
    entryUlids: { type: Array, default: () => [] },
});

const emit = defineEmits(['created']);

const { t } = useTranslations();

const page = usePage();

const form = useForm({ title: '' });

/* Platsen: `{ ulid, name }` för ett item, eller null för inboxen. */
const place = ref(null);

const pickerOpen = ref(false);
const pickerTrigger = ref(null);
const quick = ref(null);
const hidden = ref(false);

function openPicker(event) {
    pickerTrigger.value = event.currentTarget;
    pickerOpen.value = true;
}

function onTargetChosen(item) {
    place.value = { ulid: item.ulid, name: item.name };
    pickerOpen.value = false;
}

function submit() {
    const item = place.value;
    const before = new Set(props.entryUlids);

    hidden.value = false;

    const url = item === null
        ? '/inbox/tasks'
        : `/containers/${props.containerUlid}/items/${item.ulid}/schedules`;

    form.transform((data) => {
        if (item === null) {
            return { ...data, return: page.url };
        }

        return {
            ...data,
            recurrence_type: 'none',
            gtd_list: ['next', 'waiting', 'someday'].includes(props.list) ? props.list : 'next',
            return: page.url,
        };
    }).post(url, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: async () => {
            form.reset('title');
            quick.value?.focus();

            if (item === null) {
                return;
            }

            await nextTick();

            const added = props.entryUlids.filter((ulid) => ! before.has(ulid));

            if (added.length === 0) {
                hidden.value = true;

                return;
            }

            emit('created', added);
        },
    });
}
</script>

<template>
    <div>
        <QuickCreate
            ref="quick"
            v-model="form.title"
            :label="t('container.tasks.quick.label')"
            :placeholder="t('container.tasks.quick.placeholder')"
            :submit-label="t('container.tasks.quick.submit')"
            :processing="form.processing"
            :error="form.errors.title ?? null"
            @submit="submit"
        >
            <template #leading>
                <button
                    type="button"
                    :aria-pressed="place === null"
                    class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-control border px-3 text-meta font-medium outline-none focus-visible:ring-2 focus-visible:ring-focus"
                    :class="place === null
                        ? 'border-accent bg-accent text-ink-on-accent'
                        : 'border-border bg-surface text-ink hover:bg-surface-sunken'"
                    @click="place = null"
                >
                    {{ t('todo.create.inbox') }}
                </button>

                <button
                    type="button"
                    class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-control border px-3 text-meta font-medium outline-none focus-visible:ring-2 focus-visible:ring-focus"
                    :class="place === null
                        ? 'border-border bg-surface text-ink hover:bg-surface-sunken'
                        : 'border-accent bg-accent text-ink-on-accent'"
                    @click="openPicker"
                >
                    {{ place === null ? t('todo.create.change') : place.name }}
                </button>
            </template>
        </QuickCreate>

        <p v-if="hidden" role="status" class="mt-1 text-meta text-ink-muted">
            {{ t('container.tasks.quick.hidden') }}
        </p>

        <ItemTargetPicker
            :open="pickerOpen"
            :trigger="pickerTrigger"
            :container="containerUlid"
            :heading="t('todo.create.place')"
            @choose="onTargetChosen"
            @close="pickerOpen = false"
        />
    </div>
</template>
