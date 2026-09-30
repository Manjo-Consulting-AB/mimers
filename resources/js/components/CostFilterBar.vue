<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { categoryOptions } from './categoryTree.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Kostnadsflikens filterfält — Pro-delen, se issue 176 · [[ADR-0050
 * Desktopdesignen]] § 9 och `docs/Design/kostnader.png`.
 *
 * **Filtret är querysträng, och formuläret submittar med GET** (Beslut 1).
 * `router.get` mot SAMMA rutt som sidan ligger på, med `preserveState` så att
 * fälten står kvar. Ingen POST, ingen egen filtrerad vy: ett filtrerat läge är
 * en länk som går att spara, dela och backa ur med webbläsarens bakåtknapp.
 * Adressen stavas ut — komponenten känner inte sin egen rutt, och `props` bär
 * bara containerns ULID, samma grepp som ItemFilterBar.
 *
 * **Komponenten filtrerar ingenting.** Den skickar fem värden till servern och
 * ritar det svar den får tillbaka; ingen rad sållas här. Servern äger urvalet,
 * och det är samma fem värden CostReport::build() och ListContainerCosts läser
 * — ett filter som sållade i klienten hade gett en tabell som visade något
 * annat än grafen bredvid.
 *
 * **Grinden sitter på servern** (Beslut 2). Den här komponenten ritas bara för
 * en Pro-användare, och den ritar därför ingen egen låst yta: en gratisanvändare
 * får uppgraderingsytan i Costs.vue i stället, och en period hon skriver i
 * adressfältet når aldrig hit. Att dölja fältet i klienten hade varit en grind
 * i presentationen och ingen grind.
 *
 * **Fälten speglas ur `filter`-proppen med `watch`**, inte bara vid montering:
 * servern fyller BÅDA gränserna ur innevarande kalendermånad först när
 * användaren inte namngav någon, och lämnar den andra öppen — `null` — när
 * bara den ena skickades. Ett öppet fält står därför tomt, och väljaren visar
 * aldrig ett datum som inte gäller. Utan speglingen hade fältet stått tomt
 * medan tabellen visade månaden — vyn och talen hade sagt olika saker om
 * samma fråga.
 *
 * **Ett fält utan alternativ ritas inte.** Är items, kategorier eller
 * leverantörer tomma ritas ingen väljare för den: en meny med bara *Alla* är
 * brus, och en mottagare ska inte kunna filtrera på något hon inte ser
 * (samma regel som ItemFilterBar § Beslut 5).
 *
 * **Ingen sträng i JavaScript** (issue 52 · [[ADR-0013 Språk och i18n]]):
 * etiketterna, *Alla*, knappen och rensningen kommer ur `t()` under
 * `container.costs.*` och `common.pending.default`.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    /* Filtret så som servern tillämpade det: { from, to, item, category, supplier }. */
    filter: { type: Object, required: true },
    /* Alternativen: { items, categories, suppliers }, alla redan omfångsfiltrerade. */
    options: { type: Object, required: true },
});

const { t } = useTranslations();

const categories = computed(() => categoryOptions(props.options.categories));

const from = ref('');
const to = ref('');
const item = ref('');
const category = ref('');
const supplier = ref('');

/*
 * Vänteläget för hela fältet (issue 68a § Beslut 4 och 5): submit och
 * "rensa" gör samma sorts anrop — en GET — så en enda flagga räcker. Medan
 * svaret är på väg är kontrollerna stängda och knappen säger att något händer
 * i stället för att se död ut.
 */
const pending = ref(false);

watch(
    () => props.filter,
    (filter) => {
        from.value = filter.from ?? '';
        to.value = filter.to ?? '';
        item.value = filter.item ?? '';
        category.value = filter.category ?? '';
        supplier.value = filter.supplier ?? '';
    },
    { immediate: true, deep: true },
);

/*
 * Skickar filtret som querysträng. Ett fält som inte betyder något — en tom
 * datumruta, *Alla* i en väljare — lämnas UTANFÖR strängen i stället för att
 * skickas tomt: `?supplier=` är samma sak som ingen leverantör, och en URL
 * utan brus går att läsa och dela.
 *
 * `preserveScroll` för att tabellen inte ska hoppa till toppen varje gång en
 * väljare ändras, och `preserveState` för att fälten ska stå kvar.
 */
function apply() {
    const params = {};

    if (from.value !== '') {
        params.from = from.value;
    }

    if (to.value !== '') {
        params.to = to.value;
    }

    if (item.value !== '') {
        params.item = item.value;
    }

    if (category.value !== '') {
        params.category = category.value;
    }

    if (supplier.value !== '') {
        params.supplier = supplier.value;
    }

    router.get(`/containers/${props.containerUlid}/costs`, params, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => { pending.value = true; },
        onFinish: () => { pending.value = false; },
    });
}

/* Rensningen tömmer fälten och skickar samma anrop som submit — en GET utan
   parametrar, alltså den fasta perioden. Servern fyller månadens gränser. */
function clear() {
    from.value = '';
    to.value = '';
    item.value = '';
    category.value = '';
    supplier.value = '';

    apply();
}
</script>

<template>
    <form
        class="mt-6 flex flex-wrap items-end gap-3"
        role="search"
        :aria-label="t('container.costs.filter_aria')"
        @submit.prevent="apply()"
    >
        <label for="cost-filter-from" class="flex flex-col gap-1 text-meta font-medium text-ink">
            {{ t('container.costs.filter_from') }}
            <input
                id="cost-filter-from"
                v-model="from"
                type="date"
                name="from"
                :disabled="pending"
                class="min-h-11 rounded-control border border-border bg-surface px-3 font-normal text-ink"
            >
        </label>

        <label for="cost-filter-to" class="flex flex-col gap-1 text-meta font-medium text-ink">
            {{ t('container.costs.filter_to') }}
            <input
                id="cost-filter-to"
                v-model="to"
                type="date"
                name="to"
                :disabled="pending"
                class="min-h-11 rounded-control border border-border bg-surface px-3 font-normal text-ink"
            >
        </label>

        <!-- Kategorin: samma trädväljare som itemlistans filterrad, byggd ur
             samma flata lista. Ingen kategori i containern, inget fält. -->
        <label
            v-if="categories.length > 0"
            for="cost-filter-category"
            class="flex flex-col gap-1 text-meta font-medium text-ink"
        >
            {{ t('container.costs.filter_category') }}
            <select
                id="cost-filter-category"
                v-model="category"
                name="category"
                :disabled="pending"
                class="min-h-11 rounded-control border border-border bg-surface px-3 font-normal text-ink"
            >
                <option value="">{{ t('container.costs.filter_all') }}</option>
                <option v-for="option in categories" :key="option.ulid" :value="option.ulid">
                    {{ option.name }}
                </option>
            </select>
        </label>

        <!-- Itemet. Listan är de items användaren når, inte de hon får skapa
             på: filtret är läsning, och itemväljaren bakom *Lägg till kostnad*
             är en annan fråga med en annan grind. -->
        <label
            v-if="options.items.length > 0"
            for="cost-filter-item"
            class="flex flex-col gap-1 text-meta font-medium text-ink"
        >
            {{ t('container.costs.item') }}
            <select
                id="cost-filter-item"
                v-model="item"
                name="item"
                :disabled="pending"
                class="min-h-11 rounded-control border border-border bg-surface px-3 font-normal text-ink"
            >
                <option value="">{{ t('container.costs.filter_all') }}</option>
                <option v-for="choice in options.items" :key="choice.ulid" :value="choice.ulid">
                    {{ choice.name }}
                </option>
            </select>
        </label>

        <!-- Leverantören: containerns egna värden, ur samma lista som
             kostnadsformulärets autocomplete. -->
        <label
            v-if="options.suppliers.length > 0"
            for="cost-filter-supplier"
            class="flex flex-col gap-1 text-meta font-medium text-ink"
        >
            {{ t('container.costs.supplier') }}
            <select
                id="cost-filter-supplier"
                v-model="supplier"
                name="supplier"
                :disabled="pending"
                class="min-h-11 rounded-control border border-border bg-surface px-3 font-normal text-ink"
            >
                <option value="">{{ t('container.costs.filter_all') }}</option>
                <option v-for="namn in options.suppliers" :key="namn" :value="namn">
                    {{ namn }}
                </option>
            </select>
        </label>

        <button
            type="submit"
            :disabled="pending"
            class="inline-flex min-h-11 items-center rounded-control bg-accent px-4 font-medium text-ink-on-accent"
        >
            {{ pending ? t('common.pending.default') : t('container.costs.filter_submit') }}
        </button>

        <button
            type="button"
            :disabled="pending"
            class="inline-flex min-h-11 items-center text-body text-accent underline"
            @click="clear"
        >
            {{ t('container.costs.filter_clear') }}
        </button>
    </form>
</template>
