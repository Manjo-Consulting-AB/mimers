<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { categoryOptions } from './categoryTree.js';
import { activeFilters } from './itemFilter.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Filterraden i pärmens itemlista — issue 59a § Beslut 1, 2, 5, 6 och 8.
 *
 * **Filtret är querysträng, och formuläret submittar med GET** (Beslut 1).
 * `router.get` mot SAMMA rutt som sidan ligger på, med `preserveState` så att
 * fälten står kvar. Ingen POST, ingen egen söksida: ett filtrerat läge är en
 * länk som går att spara, dela och backa ur med webbläsarens bakåtknapp.
 *
 * **Komponenten filtrerar ingenting** (Beslut 2). Den skickar tre värden till
 * servern och ritar det svar den får tillbaka; ingen rad sållas här, och ingen
 * klientmatchning sker på `name`. Servern äger urvalet.
 *
 * **Den ritar bara det pärmen och omfånget har** (Beslut 5). `tags` och
 * `categories` kommer ur `ListTags` och `ListCategories` — anropade i
 * kontrollern, aldrig omskrivna här. Är den ena listan tom ritas dess fält
 * inte alls: ett filter utan alternativ är brus. En mottagare ser därmed bara
 * de taggar som sitter på items hon når och de kategorier som bär sådana
 * items, och kan inte filtrera på något hon inte ser.
 *
 * **Aktiva filter syns, och går att ta bort ett i taget** (Beslut 6). Chipsen
 * byggs av resources/js/components/itemFilter.js — samma etiketter som den
 * tomma träfflistans mening, så de två inte kan glida isär. Ett filter
 * användaren inte kan se att det är på är ett filter hon tror är en bugg.
 *
 * **Ett bortfallet filter sägs rakt ut** (Beslut 3). `filter.dropped` sätts av
 * kontrollern när en ULID i länken inte längre finns i hennes omfång — taggen
 * är raderad, kategorin flyttad — och raden är hela svaret på det. Ingen 422,
 * ingen redirect tillbaka till samma querysträng.
 *
 * **Ingen sträng i JavaScript** (Beslut 8). Varje text kommer ur `t()` med en
 * nyckel under `item.index.filter*`.
 *
 * Fälten speglas ur `filter`-propen med `watch`, inte bara vid montering:
 * chipsen kan ta bort ett filter utan att formuläret rörs, och då måste
 * kryssrutan och väljaren följa med.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    /* Pärmens taggar inom omfånget, ur ListTags. */
    tags: { type: Array, required: true },
    /* Pärmens kategoriträd inom omfånget, ur ListCategories. */
    categories: { type: Array, required: true },
    /* Filtret så som servern tillämpade det: { q, tags, category, dropped }. */
    filter: { type: Object, required: true },
});

const { t } = useTranslations();

const options = computed(() => categoryOptions(props.categories));
const entries = computed(() => activeFilters(props.filter, props.tags, props.categories, t));

const q = ref('');
const selectedTags = ref([]);
const category = ref(null);

/*
 * Vänteläget för hela filterraden (issue 68a § Beslut 4 och 5): submit,
 * chipkryssen och "rensa" gör alla samma sorts anrop — en GET — så en enda
 * flagga räcker. Medan svaret är på väg är kontrollerna stängda och säger att
 * något händer i stället för att se döda ut.
 */
const pending = ref(false);

watch(
    () => props.filter,
    (filter) => {
        q.value = filter.q ?? '';
        selectedTags.value = [...filter.tags];
        category.value = filter.category;
    },
    { immediate: true, deep: true },
);

/*
 * Skickar filtret som querysträng. Ett fält som inte betyder något — en blank
 * `q`, inga taggar, ingen kategori — lämnas UTANFÖR strängen i stället för att
 * skickas tomt: `?q=` och `?q=   ` är samma sak som ingen `q`, och en URL utan
 * brus går att läsa och dela.
 *
 * `preserveScroll` för att listan inte ska hoppa till toppen varje gång ett
 * kryss sätts, och `preserveState` för att fälten ska stå kvar.
 */
function apply(overrides = {}) {
    const query = overrides.q ?? q.value;
    const tags = overrides.tags ?? selectedTags.value;
    const chosen = overrides.category === undefined ? category.value : overrides.category;

    const params = {};

    if (String(query ?? '').trim() !== '') {
        params.q = String(query).trim();
    }

    if (tags.length > 0) {
        params.tags = tags;
    }

    if (chosen !== null && chosen !== '') {
        params.category = chosen;
    }

    router.get(`/containers/${props.containerUlid}`, params, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => { pending.value = true; },
        onFinish: () => { pending.value = false; },
    });
}

/* Ett kryss tar bort ETT filter och behåller resten. */
function remove(entry) {
    if (entry.key === 'q') {
        apply({ q: '' });

        return;
    }

    if (entry.key === 'tag') {
        apply({ tags: selectedTags.value.filter((ulid) => ulid !== entry.value) });

        return;
    }

    apply({ category: null });
}
</script>

<template>
    <form
        class="mt-6 flex flex-wrap items-end gap-3"
        role="search"
        :aria-label="t('item.index.filter_heading')"
        @submit.prevent="apply()"
    >
        <label for="item-filter-q" class="flex flex-col gap-1 text-sm font-medium text-slate-800">
            {{ t('item.index.filter_q') }}
            <input
                id="item-filter-q"
                v-model="q"
                type="text"
                name="q"
                class="rounded border border-slate-300 bg-white px-3 py-2 font-normal"
            >
        </label>

        <!-- Kategorin: samma trädväljare som 57b:s formulär, byggd ur samma
             flata lista. Ingen kategori i pärmen, inget fält. -->
        <label
            v-if="options.length > 0"
            for="item-filter-category"
            class="flex flex-col gap-1 text-sm font-medium text-slate-800"
        >
            {{ t('item.index.filter_category') }}
            <select
                id="item-filter-category"
                v-model="category"
                name="category"
                class="rounded border border-slate-300 bg-white px-3 py-2 font-normal"
            >
                <option :value="null">{{ t('item.index.filter_category_all') }}</option>
                <option v-for="option in options" :key="option.ulid" :value="option.ulid">
                    {{ option.name }}
                </option>
            </select>
        </label>

        <!-- Taggarna: kryssrutor med sin färgprick, samma prick som TagRow
             och ItemTagList ritar. Ingen tagg i omfånget, inget fält. -->
        <fieldset v-if="tags.length > 0" class="flex flex-col gap-1">
            <legend class="text-sm font-medium text-slate-800">{{ t('item.index.filter_tags') }}</legend>

            <div class="flex flex-wrap gap-x-4 gap-y-1">
                <label
                    v-for="tag in tags"
                    :key="tag.ulid"
                    :for="`item-filter-tag-${tag.ulid}`"
                    class="flex min-h-11 min-w-11 items-center gap-2 text-sm font-normal text-slate-800"
                >
                    <input
                        :id="`item-filter-tag-${tag.ulid}`"
                        v-model="selectedTags"
                        type="checkbox"
                        name="tags[]"
                        :value="tag.ulid"
                    >
                    <span
                        aria-hidden="true"
                        class="inline-block h-3 w-3 shrink-0 rounded-full border border-slate-500"
                        :style="tag.color ? { backgroundColor: tag.color } : null"
                    />
                    {{ tag.name }}
                </label>
            </div>
        </fieldset>

        <button
            type="submit"
            :disabled="pending"
            class="inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white"
        >
            {{ pending ? t('common.pending.default') : t('item.index.filter_submit') }}
        </button>
    </form>

    <p v-if="filter.dropped" class="mt-2 text-sm text-slate-700">
        {{ t('item.index.filter_dropped') }}
    </p>

    <div v-if="entries.length > 0" class="mt-4 flex flex-wrap items-center gap-2">
        <span class="text-sm font-medium text-slate-800">{{ t('item.index.filter_active') }}</span>

        <span
            v-for="entry in entries"
            :key="`${entry.key}-${entry.value ?? ''}`"
            class="flex items-center gap-1 rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-700"
        >
            {{ entry.label }}
            <button
                type="button"
                :disabled="pending"
                :aria-label="t('item.index.filter_remove', { filter: entry.label })"
                class="inline-flex min-h-11 min-w-11 items-center justify-center font-medium text-slate-700"
                @click="remove(entry)"
            >
                &times;
            </button>
        </span>

        <button
            type="button"
            :disabled="pending"
            class="inline-flex min-h-11 items-center text-sm text-blue-700 underline"
            @click="apply({ q: '', tags: [], category: null })"
        >
            {{ pending ? t('common.pending.default') : t('item.index.filter_clear') }}
        </button>
    </div>
</template>
