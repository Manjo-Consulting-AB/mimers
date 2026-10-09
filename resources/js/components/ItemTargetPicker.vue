<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import UiInput from './UiInput.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Målväljaren, se [[M27 Flytten och inboxen]] § 242: dialogen som väljer
 * vilket ITEM något ska flyttas till. Flytten (243), sidan `/inbox` (245) och
 * *New task* (246) använder den, och den väljer bara — den skickar ingenting
 * själv, den lämnar valet vidare ur `choose`.
 *
 * **Listan är en lat propp, och dialogen hämtar den när den öppnas.**
 * `itemTargets` (App\Actions\Item\ListCreatableItems) prövar
 * `ItemPolicy::create` per item i varje container användaren når, och är
 * därför för dyr att bära på varje sidladdning. Den delas som
 * `Inertia::optional()`, och den partiella omladdningen här är hela vägen in:
 * en sida där dialogen aldrig öppnats frågar aldrig efter listan.
 *
 * **Sökningen filtrerar på itemets OCH containerns namn, i minnet.** Listan
 * kommer färdigfiltrerad på omfång ur proppen, så ett item användaren inte
 * får skapa i finns inte här att söka fram, och dialogen frågar aldrig
 * servern medan hon skriver (samma linje som ParentPicker och SearchField,
 * issue 59b § Beslut 5). Containerns namn matchar också: raden står under sin
 * container, och att skriva containernamnet är hur man letar inom den.
 *
 * **Ett item kan uteslutas** (`excludeItem`, källan): att flytta något till
 * sig självt är inget mål. Det är klientens enda regel av det slaget —
 * servern frågar inte vem som flyttar vart, och uteslutningen ritar bara bort
 * en rad ur listan.
 *
 * **Mönstret är ParentPickers.** `Teleport` till `<body>` (dialogen står inte
 * inuti en form), `showModal()` som fokusfällan, fokus tillbaka till
 * `trigger`, Esc som stänger genom `close`, en träffyta på 44 px och tokens i
 * stället för råa färger.
 *
 * Rubriken kommer färdig i `heading` (anroparen väljer ord — *Move to*,
 * *Place*), och de fyra orden ur katalogen ligger i `lang/en/ui.php` under
 * `item.target`. Stängknappen lånar `item.form.parent_cancel`: de två
 * väljarna är samma dialog med olika innehåll.
 */
const props = defineProps({
    /* Sant medan dialogen är öppen. Anroparen äger tillståndet. */
    open: { type: Boolean, default: false },
    /* Elementet som öppnade dialogen: fokus tillbaka dit när den stängs. */
    trigger: { type: Object, default: null },
    /* ULID:n för itemet som inte får väljas — källan. */
    excludeItem: { type: String, default: null },
    /* Rubriken, färdigformulerad av anroparen. */
    heading: { type: String, default: '' },
});

const emit = defineEmits(['choose', 'close']);

const { t } = useTranslations();

const page = usePage();

const dialogElement = ref(null);
const query = ref('');
const loading = ref(false);

/*
 * Det valda itemet — hela objektet och inte ULID:n: bekräftelsen skickar namn
 * och container vidare, och anroparen ritar dem utan att leta i listan.
 */
const chosen = ref(null);

/* Listan ur proppen, eller tom medan den hämtas. */
const targets = computed(() => page.props.itemTargets ?? []);

const filtered = computed(() => filterTargets(targets.value, query.value.trim().toLowerCase()));

/* Sant när svaret kommit och ingenting finns kvar att välja. */
const empty = computed(() => ! loading.value && filtered.value.length === 0);

/*
 * Grupperna som matchar, med det uteslutna itemet borttaget. En grupp vars
 * items alla föll bort ritas inte — en containerrubrik utan rader är en plats
 * som ser ut att finnas.
 */
function filterTargets(groups, needle) {
    const kept = [];

    for (const group of groups) {
        const items = group.items.filter((item) => item.ulid !== props.excludeItem
            && (needle === ''
                || item.name.toLowerCase().includes(needle)
                || group.container.name.toLowerCase().includes(needle)));

        if (items.length > 0) {
            kept.push({ container: group.container, items });
        }
    }

    return kept;
}

/*
 * Dialogen öppnas tom — inget kvarglömt sökord och inget kvarglömt val — och
 * hämtar listan. `showModal()` först: väntetexten ska synas medan svaret är
 * på väg, och dialogen är redan öppen när det kommer.
 */
watch(
    () => props.open,
    async (open) => {
        if (open) {
            query.value = '';
            chosen.value = null;
            loading.value = true;

            await nextTick();

            dialogElement.value?.showModal();

            router.reload({
                only: ['itemTargets'],
                onFinish: () => {
                    loading.value = false;
                },
            });

            return;
        }

        dialogElement.value?.close();
    },
);

/* Ett nytt sökord är ett nytt urval: det gamla valet kan ha filtrerats bort. */
watch(query, () => {
    chosen.value = null;
});

function choose() {
    if (chosen.value === null) {
        return;
    }

    emit('choose', chosen.value);

    dialogElement.value?.close();
}

function close() {
    dialogElement.value?.close();
}

/*
 * Stängningen, oavsett väg in: Esc fyrar `close` precis som knapparna gör.
 * Fokus tillbaka till öppnaren — utan den står fokus kvar i en dialog som
 * inte finns.
 */
function onClosed() {
    emit('close');

    props.trigger?.focus();
}
</script>

<template>
    <!--
        Dialogen flyttas till `<body>` och står inte kvar där den ritas.
        Ett `<dialog>` bryter ingen formägarhet, och en modal hör högst upp i
        dokumentet och inte inuti den form som råkade innehålla öppnaren —
        samma skäl som ParentPicker.
    -->
    <Teleport to="body">
        <dialog
            ref="dialogElement"
            :aria-label="heading"
            class="m-0 h-full max-h-none w-full max-w-none bg-transparent p-0 backdrop:bg-ink/50"
            @close="onClosed"
        >
            <div class="flex h-full w-full flex-col justify-end md:items-center md:justify-center">
                <!--
                    Ytan utanför panelen. Dialogen täcker hela skärmen, så
                    trycket utanför landar här och inte i en bakgrund utan mål.
                -->
                <button
                    type="button"
                    class="absolute inset-0 min-h-11"
                    :aria-label="t('item.form.parent_cancel')"
                    @click="close"
                ></button>

                <div
                    class="relative flex max-h-full w-full flex-col gap-3 overflow-y-auto rounded-t-card bg-surface p-4 md:max-w-md md:rounded-card md:border md:border-border"
                >
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-title font-semibold text-ink">
                            {{ heading }}
                        </h2>

                        <button
                            type="button"
                            class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control border border-border text-ink-muted"
                            :aria-label="t('item.form.parent_cancel')"
                            @click="close"
                        >
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.5"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="h-5 w-5"
                                aria-hidden="true"
                            >
                                <path d="m6 6 12 12"></path>
                                <path d="m18 6-12 12"></path>
                            </svg>
                        </button>
                    </div>

                    <!--
                        `autofocus` styr var fokus landar när `showModal()`
                        öppnar dialogen. Utan den tar webbläsaren första
                        tabbbara element, och det är tryckytan utanför panelen.
                    -->
                    <FormField
                        v-slot="{ describedBy }"
                        :label="t('item.target.search')"
                        id="item-target-search"
                    >
                        <UiInput
                            id="item-target-search"
                            v-model="query"
                            type="search"
                            :described-by="describedBy"
                            autofocus
                        />
                    </FormField>

                    <div class="flex max-h-80 flex-col gap-1 overflow-y-auto">
                        <!--
                            Raderna grupperas under containerns namn: ett item
                            utan sin plats är ett item utan sammanhang, och
                            samma namn kan finnas i två containrar.
                        -->
                        <template v-for="group in filtered" :key="group.container.ulid">
                            <p class="px-2 pt-2 text-body font-bold text-ink">
                                {{ group.container.name }}
                            </p>

                            <button
                                v-for="item in group.items"
                                :key="item.ulid"
                                type="button"
                                :aria-current="chosen?.ulid === item.ulid ? 'true' : null"
                                class="flex min-h-11 items-center gap-2 rounded-control px-2 text-left text-body outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                                :class="chosen?.ulid === item.ulid
                                    ? 'bg-accent-soft font-semibold text-accent'
                                    : 'text-ink hover:bg-surface-sunken'"
                                @click="chosen = item"
                            >
                                {{ item.name }}
                            </button>
                        </template>

                        <p v-if="loading" class="px-2 text-body text-ink-muted">
                            {{ t('item.target.loading') }}
                        </p>

                        <p v-else-if="empty" class="px-2 text-body text-ink-muted">
                            {{ t('item.target.empty') }}
                        </p>
                    </div>

                    <!--
                        En rå `<button>` och inte en UiButton: knappen bär en
                        `@click` och en `:disabled`, och GenomgangTest tillåter
                        bara webbläsarens egna element som klickbar yta.
                    -->
                    <button
                        type="button"
                        :disabled="chosen === null"
                        class="inline-flex min-h-11 items-center justify-center self-end rounded-control bg-accent px-4 font-medium text-ink-on-accent outline-none hover:bg-accent/90 focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 disabled:opacity-50"
                        @click="choose"
                    >
                        {{ t('item.target.choose') }}
                    </button>
                </div>
            </div>
        </dialog>
    </Teleport>
</template>
