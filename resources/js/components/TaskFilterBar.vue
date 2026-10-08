<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import UiSelect from './UiSelect.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Filterraden på `/tasks` — M28 · issue 782, se `docs/Design/task-dashboard.png`
 * och Tonys beslut 2026-10-07.
 *
 * **Filtret är querysträng** (Beslut 1). Varje val gör en `router.get` mot
 * SAMMA rutt som sidan ligger på, med `preserveState` så att fälten står kvar:
 * ett filtrerat läge är en adress man kan spara, dela och backa ur med
 * webbläsarens bakåtknapp. Ingen POST och ingen egen filtrerad vy. Adressen
 * stavas ut — komponenten känner inte sin egen rutt, samma grepp som
 * `CostFilterBar`.
 *
 * **Fliken följer med, och markören nollställs** (Beslut 5). `list` läggs i
 * varje anrop, så ett filterbyte stannar i samma flik; markören (`after`/
 * `before`) skickas aldrig med, för ett filterbyte är en ANNAN fråga och börjar
 * därför på första sidan.
 *
 * **Komponenten filtrerar ingenting.** Den skickar fyra värden till servern och
 * ritar det svar den får tillbaka; ingen rad sållas här. Servern äger urvalet —
 * `scopeTodoFor()` och ingenting annat — och ett filter som sållade i klienten
 * hade gett en lista som visade något annat än panelens tal.
 *
 * **Filtret som fliken redan bestämmer ritas inte** (Beslut 2): listväljaren på
 * *Inbox*, *Next*, *Waiting*, *Someday* och *Done*, statusväljaren på *In
 * progress* och *Done*. Servern ignorerar samma parametrar på samma flikar, så
 * en adress som ändå bär dem ritar samma lista som fliken valde. Vyns två
 * `computed` speglar `ListTodo::acceptsGtd()`/`acceptsStatus()` — två
 * formuleringar av samma regel, men den ena ritar och den andra tillämpar, och
 * en väljare som inte gör något är värre än ingen väljare.
 *
 * **Containerväljaren ritas bara när det finns något att välja** (samma regel
 * som `CostFilterBar` § Ett fält utan alternativ): en meny med bara *All
 * containers* är brus, och `containers` bär redan bara de containrar användaren
 * når och ser — inboxen är inte ett val (Beslut 1 och 5).
 *
 * **Ingen sträng i JavaScript** (issue 52 · [[ADR-0013 Språk och i18n]]):
 * etiketterna, *All containers*, sorteringens två ord och rensningen kommer ur
 * `t()` under `todo.filters.*`, och listans egna ord är flikradens
 * (`todo.list.*`, `todo.tabs.in_progress`) — samma lista, samma ord.
 */
const props = defineProps({
    /* Fliken ur `?list=`: en av listorna, eller null för *Active*. */
    list: { type: String, default: null },
    /*
     * Filtren så som servern tillämpade dem:
     * `{ container, gtd, status, sort }`. Ett filter fliken bestämmer, ett
     * okänt värde och *Active* står som `null`.
     */
    filters: { type: Object, required: true },
    /* Menyns containrar ur `ListTodo::page()`: `[{ ulid, name }]`, utan inboxen. */
    containers: { type: Array, default: () => [] },
});

const { t } = useTranslations();

/*
 * Flikarna som redan bestämmer listan (Beslut 2): *Inbox*, *Next*, *Waiting*
 * och *Someday*. Jämförelsen är mot flikens nyckel, samma värde som `?list=`
 * bär — ingen egen uppräkning av listor i vyn som kan glida ifrån serverns.
 *
 * *Done* står utanför av ett annat skäl: raden där kommer ur en egen fråga och
 * har ingen `gtd_list` alls, så servern tillämpar inte filtret. Vyn ritar
 * därför inte heller väljaren — ett val som inte gör något är värre än inget
 * val.
 */
const showGtd = computed(() => props.list === null || ['calendar', 'in_progress'].includes(props.list));

/*
 * Flikarna som redan bestämmer statusen (Beslut 2): *In progress* ÄR statusen,
 * och *Done* är dess motsats.
 */
const showStatus = computed(() => !['in_progress', 'done'].includes(props.list));

const container = ref('');
const gtd = ref('');
const status = ref('');
const sort = ref(props.filters.sort);

/*
 * Fälten speglas ur `filters`-proppen med `watch`, inte bara vid montering: en
 * bakåtknapp och en omladdning ger nya proppar, och väljarna ska visa det
 * servern faktiskt tillämpade — inte det användaren råkade klicka på sist.
 * Tom sträng och inte `null`: `<option value="">` är det tomma valet, och en
 * `null` hade ställt väljaren på fel alternativ.
 */
watch(
    () => props.filters,
    (filters) => {
        container.value = filters.container ?? '';
        gtd.value = filters.gtd ?? '';
        status.value = filters.status ?? '';
        sort.value = filters.sort;
    },
    { immediate: true, deep: true },
);

/*
 * Vänteläget för hela raden (issue 68a § Beslut 4): varje val gör samma sorts
 * anrop — en GET — så en enda flagga räcker. Medan svaret är på väg är
 * kontrollerna stängda, så raden inte ser död ut.
 */
const pending = ref(false);

/*
 * Skickar filtren som querysträng. Ett val som inte betyder något — *All
 * containers*, *Active*, förvalet `due_asc` — lämnas UTANFÖR adressen i stället
 * för att skickas tomt: `?gtd=` är samma sak som inget filter, och en URL utan
 * brus går att läsa och dela.
 *
 * `preserveScroll` för att listan inte ska hoppa till toppen varje gång en
 * väljare ändras, och `preserveState` för att fälten ska stå kvar.
 */
function apply() {
    const params = {};

    if (props.list !== null) {
        params.list = props.list;
    }

    if (container.value !== '') {
        params.container = container.value;
    }

    if (showGtd.value && gtd.value !== '') {
        params.gtd = gtd.value;
    }

    if (showStatus.value && status.value !== '') {
        params.status = status.value;
    }

    if (sort.value !== 'due_asc') {
        params.sort = sort.value;
    }

    router.get('/tasks', params, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => { pending.value = true; },
        onFinish: () => { pending.value = false; },
    });
}

/*
 * En egen hanterare per väljare, och inte ett `v-model`: fältet ska uppdateras
 * OCH frågan ställas, och `@update:model-value` bär det nya värdet som argument.
 */
function chooseContainer(value) {
    container.value = value;
    apply();
}

function chooseGtd(value) {
    gtd.value = value;
    apply();
}

function chooseStatus(value) {
    status.value = value;
    apply();
}

function chooseSort(value) {
    sort.value = value;
    apply();
}

/*
 * Är något filter satt? Bara då ritas rensningen (Beslut 5): en tom handling är
 * en död knapp, och en lista utan filter är redan sitt eget vilotillstånd.
 */
const hasFilters = computed(
    () => props.filters.container !== null
        || props.filters.gtd !== null
        || props.filters.status !== null
        || props.filters.sort !== 'due_asc',
);

/*
 * Rensningens adress behåller fliken och tappar allt annat: filtren ÄR
 * querysträngen, så en adress utan dem är den oavgränsade listan i samma flik.
 */
const clearUrl = computed(() => (props.list === null ? '/tasks' : `/tasks?list=${props.list}`));
</script>

<template>
    <div class="flex flex-wrap items-end gap-3" role="group" :aria-label="t('todo.filters.label')">
        <FormField
            v-if="containers.length > 0"
            :label="t('todo.filters.container')"
            id="task-filter-container"
            label-hidden
        >
            <UiSelect
                id="task-filter-container"
                :model-value="container"
                :disabled="pending"
                @update:model-value="chooseContainer"
            >
                <option value="">{{ t('todo.filters.all_containers') }}</option>
                <option v-for="option in containers" :key="option.ulid" :value="option.ulid">
                    {{ option.name }}
                </option>
            </UiSelect>
        </FormField>

        <FormField
            v-if="showGtd"
            :label="t('todo.filters.list')"
            id="task-filter-gtd"
            label-hidden
        >
            <UiSelect
                id="task-filter-gtd"
                :model-value="gtd"
                :disabled="pending"
                @update:model-value="chooseGtd"
            >
                <option value="">{{ t('todo.filters.all_lists') }}</option>
                <option value="next">{{ t('todo.list.next') }}</option>
                <option value="waiting">{{ t('todo.list.waiting') }}</option>
                <option value="someday">{{ t('todo.list.someday') }}</option>
            </UiSelect>
        </FormField>

        <FormField
            v-if="showStatus"
            :label="t('todo.filters.status')"
            id="task-filter-status"
            label-hidden
        >
            <UiSelect
                id="task-filter-status"
                :model-value="status"
                :disabled="pending"
                @update:model-value="chooseStatus"
            >
                <option value="">{{ t('todo.filters.all_statuses') }}</option>
                <option value="open">{{ t('todo.filters.status_open') }}</option>
                <option value="in_progress">{{ t('todo.tabs.in_progress') }}</option>
            </UiSelect>
        </FormField>

        <FormField
            :label="t('todo.filters.sort')"
            id="task-filter-sort"
            label-hidden
        >
            <UiSelect
                id="task-filter-sort"
                :model-value="sort"
                :disabled="pending"
                @update:model-value="chooseSort"
            >
                <option value="due_asc">{{ t('todo.filters.sort_due_asc') }}</option>
                <option value="due_desc">{{ t('todo.filters.sort_due_desc') }}</option>
            </UiSelect>
        </FormField>

        <Link
            v-if="hasFilters"
            :href="clearUrl"
            class="inline-flex min-h-11 items-center text-body text-accent underline"
        >
            {{ t('todo.filters.clear') }}
        </Link>
    </div>
</template>
