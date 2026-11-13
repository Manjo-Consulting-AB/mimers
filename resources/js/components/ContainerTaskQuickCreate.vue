<script setup>
import { computed, nextTick, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import QuickCreate from './QuickCreate.vue';
import UiIcon from './UiIcon.vue';
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
 * **Platsvalet är en `<details>`** som *Add cost* på kostnadsfliken och *Add
 * document* på dokumentfliken (issue 360): en enda knapp vars panel ligger
 * absolut över innehållet. *Inbox* står överst, därefter containerns items
 * ur den lata propen `itemTargets`, som hämtas första gången panelen öppnas
 * och filtreras på `containerUlid` som `ItemTargetPicker.filterTargets()`.
 * Raderna är råa `<button>` — ett val byter ingen sida.
 *
 * **Itemets flik (M58 · issue 1210, ADR-0074 tillägg 2026-11-13).** Med propen
 * `item` är platsen alltid det itemet: ingen platsknapp ritas, och raden
 * postar till itemets `schedules` med samma data som när ett item väljs.
 * Utan `item` är beteendet det ovan.
 *
 * Råa `<button>`, `<details>` och `<summary>` i `leading`: GenomgangTest
 * tillåter bara webbläsarens egna element som klickbar yta.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    /* Flikens `?list=`, eller null. */
    list: { type: String, default: null },
    /* ULID:erna i flikens öppna grupper just nu. */
    entryUlids: { type: Array, default: () => [] },
    /* `{ ulid, name }` på itemets flik, annars null. Satt: ingen platsknapp. */
    item: { type: Object, default: null },
});

const emit = defineEmits(['created']);

const { t } = useTranslations();

const page = usePage();

const form = useForm({ title: '' });

/* Platsen: `{ ulid, name }` för ett item, eller null för inboxen. */
const place = ref(null);

const quick = ref(null);
const placeMenu = ref(null);
const hidden = ref(false);

/* Sant medan `itemTargets` hämtas första gången panelen öppnas. */
const loading = ref(false);
let loaded = false;

/* Containerns items, i den ordning `itemTargets` ger dem. */
const options = computed(() => (page.props.itemTargets ?? [])
    .filter((group) => group.container.ulid === props.containerUlid)
    .flatMap((group) => group.items));

function onToggle(event) {
    if (! event.target.open || loaded) {
        return;
    }

    loaded = true;
    loading.value = true;

    router.reload({
        only: ['itemTargets'],
        onFinish: () => {
            loading.value = false;
        },
    });
}

function choose(item) {
    place.value = item === null ? null : { ulid: item.ulid, name: item.name };
    placeMenu.value.open = false;
    quick.value?.focus();
}

function closeMenu(event) {
    if (event.key !== 'Escape' || ! placeMenu.value.open) {
        return;
    }

    placeMenu.value.open = false;
    placeMenu.value.querySelector('summary')?.focus();
    event.preventDefault();
    event.stopPropagation();
}

function submit() {
    const item = props.item ?? place.value;
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
            <template v-if="item === null" #leading>
                <details ref="placeMenu" class="relative" @toggle="onToggle" @keydown="closeMenu">
                    <summary
                        class="inline-flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-control border border-border bg-surface px-3 text-meta font-medium text-ink hover:bg-surface-sunken"
                        :aria-label="`${t('todo.create.place')}: ${place === null ? t('todo.create.inbox') : place.name}`"
                    >
                        <span class="max-w-40 truncate">{{ place === null ? t('todo.create.inbox') : place.name }}</span>

                        <UiIcon name="chevron-down" :size="20" class="size-5 shrink-0" />
                    </summary>

                    <div class="absolute left-0 z-10 mt-2 w-64 rounded-card border border-border bg-surface p-3 shadow-sm">
                        <p class="text-meta text-ink-subtle">{{ t('todo.create.place') }}</p>

                        <ul class="mt-1 flex max-h-80 flex-col overflow-y-auto">
                            <li>
                                <button
                                    type="button"
                                    :aria-current="place === null ? 'true' : undefined"
                                    class="flex min-h-11 w-full items-center justify-between gap-2 rounded-control px-2 text-left text-body text-ink hover:bg-surface-sunken"
                                    :class="{ 'font-semibold': place === null }"
                                    @click="choose(null)"
                                >
                                    {{ t('todo.create.inbox') }}
                                    <UiIcon v-if="place === null" name="check" :size="16" class="size-4 shrink-0 text-accent" />
                                </button>
                            </li>

                            <li v-if="loading" class="px-2 py-2 text-meta text-ink-muted">
                                {{ t('common.pending.default') }}
                            </li>

                            <li v-for="option in options" v-else :key="option.ulid">
                                <button
                                    type="button"
                                    :aria-current="place?.ulid === option.ulid ? 'true' : undefined"
                                    class="flex min-h-11 w-full items-center justify-between gap-2 rounded-control px-2 text-left text-body text-ink hover:bg-surface-sunken"
                                    :class="{ 'font-semibold': place?.ulid === option.ulid }"
                                    @click="choose(option)"
                                >
                                    <span class="truncate">{{ option.name }}</span>
                                    <UiIcon v-if="place?.ulid === option.ulid" name="check" :size="16" class="size-4 shrink-0 text-accent" />
                                </button>
                            </li>
                        </ul>
                    </div>
                </details>
            </template>
        </QuickCreate>

        <p v-if="hidden" role="status" class="mt-1 text-meta text-ink-muted">
            {{ t('container.tasks.quick.hidden') }}
        </p>
    </div>
</template>
