<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Historikflitens filterfält, se issue 179 · [[ADR-0050 Desktopdesignen]] § 17
 * och `docs/Design/Historik.png`.
 *
 * **Filtret är querysträng, och formuläret submittar med GET** (Beslut 4).
 * `router.get` mot SAMMA rutt som sidan ligger på, med `preserveState` så att
 * fälten står kvar. Ingen POST, ingen egen filtrerad vy: ett filtrerat läge är
 * en länk som går att spara, dela och backa ur med webbläsarens bakåtknapp.
 * Adressen stavas ut — komponenten känner inte sin egen rutt, och `props` bär
 * bara containerns ULID, samma grepp som ItemFilterBar och CostFilterBar.
 *
 * **Komponenten filtrerar ingenting.** Den skickar fem värden till servern och
 * ritar det svar den får tillbaka; ingen rad sållas här. Servern äger urvalet,
 * och det är samma fem värden App\Actions\Audit\ListAuditEvents läser — ett
 * filter som sållade i klienten hade gett en lista som visade något annat än
 * grupperna runt den.
 *
 * **Fälten speglas ur `filter`-proppen med `watch`**, inte bara vid montering:
 * servern lämnar ett fält användaren inte satte som `null`, och chipsen kan
 * inte ta bort något här. Utan speglingen hade ett fält stått kvar med ett
 * värde servern inte tillämpade.
 *
 * **Ett fält utan alternativ ritas inte.** Är typerna, användarna eller
 * itemslistan tomma ritas ingen väljare för den: en meny med bara *Alla* är
 * brus, och en mottagare ska inte kunna filtrera på något hon inte ser (samma
 * regel som ItemFilterBar § Beslut 5). Listorna kommer färdigfiltrerade ur
 * `options`-proppen och byggs aldrig om här.
 *
 * **Typens ord är `audit.subject.<värde>`** och inga kopior: etiketten slås
 * upp ur samma katalog som resten av meningen användaren läser, och en ny
 * `subject_type` visar sin nyckel i stället för att tyst bli tom.
 *
 * **Ingen sträng i JavaScript** (issue 52 · [[ADR-0013 Språk och i18n]]):
 * etiketterna, *Alla*, knappen och rensningen kommer ur `t()` under
 * `audit.history.*` och `common.pending.default`.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    /* Filtret så som servern tillämpade det: { type, user, item, from, to }. */
    filter: { type: Object, required: true },
    /* Alternativen: { types, users, items }, alla redan filtrerade av servern. */
    options: { type: Object, required: true },
});

const { t } = useTranslations();

const types = computed(() => props.options.types.map((value) => ({
    value,
    label: t(`audit.subject.${value}`),
})));

const type = ref('');
const user = ref('');
const item = ref('');
const from = ref('');
const to = ref('');

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
        type.value = filter.type ?? '';
        user.value = filter.user ?? '';
        item.value = filter.item ?? '';
        from.value = filter.from ?? '';
        to.value = filter.to ?? '';
    },
    { immediate: true, deep: true },
);

/*
 * Skickar filtret som querysträng. Ett fält som inte betyder något — en tom
 * datumruta, *Alla* i en väljare — lämnas UTANFÖR strängen i stället för att
 * skickas tomt: `?item=` är samma sak som inget item, och en URL utan brus går
 * att läsa och dela.
 *
 * `preserveScroll` för att listan inte ska hoppa till toppen varje gång en
 * väljare ändras, och `preserveState` för att fälten ska stå kvar.
 */
function apply() {
    const params = {};

    if (type.value !== '') {
        params.type = type.value;
    }

    if (user.value !== '') {
        params.user = user.value;
    }

    if (item.value !== '') {
        params.item = item.value;
    }

    if (from.value !== '') {
        params.from = from.value;
    }

    if (to.value !== '') {
        params.to = to.value;
    }

    router.get(`/containers/${props.containerUlid}/history`, params, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => { pending.value = true; },
        onFinish: () => { pending.value = false; },
    });
}

/* Rensningen tömmer fälten och skickar samma anrop som submit — en GET utan
   parametrar, alltså hela historiken. */
function clear() {
    type.value = '';
    user.value = '';
    item.value = '';
    from.value = '';
    to.value = '';

    apply();
}
</script>

<template>
    <form
        class="mt-6 flex flex-wrap items-end gap-3"
        role="search"
        :aria-label="t('audit.history.filter_aria')"
        @submit.prevent="apply()"
    >
        <label
            v-if="types.length > 0"
            for="history-filter-type"
            class="flex flex-col gap-1 text-meta font-medium text-ink"
        >
            {{ t('audit.history.filter_type') }}
            <select
                id="history-filter-type"
                v-model="type"
                name="type"
                :disabled="pending"
                class="min-h-11 rounded-control border border-border bg-surface px-3 font-normal text-ink"
            >
                <option value="">{{ t('audit.history.filter_all') }}</option>
                <option v-for="choice in types" :key="choice.value" :value="choice.value">
                    {{ choice.label }}
                </option>
            </select>
        </label>

        <label
            v-if="options.users.length > 0"
            for="history-filter-user"
            class="flex flex-col gap-1 text-meta font-medium text-ink"
        >
            {{ t('audit.history.filter_user') }}
            <select
                id="history-filter-user"
                v-model="user"
                name="user"
                :disabled="pending"
                class="min-h-11 rounded-control border border-border bg-surface px-3 font-normal text-ink"
            >
                <option value="">{{ t('audit.history.filter_all') }}</option>
                <option v-for="actor in options.users" :key="actor.ulid" :value="actor.ulid">
                    {{ actor.name }}
                </option>
            </select>
        </label>

        <label
            v-if="options.items.length > 0"
            for="history-filter-item"
            class="flex flex-col gap-1 text-meta font-medium text-ink"
        >
            {{ t('audit.history.filter_item') }}
            <select
                id="history-filter-item"
                v-model="item"
                name="item"
                :disabled="pending"
                class="min-h-11 rounded-control border border-border bg-surface px-3 font-normal text-ink"
            >
                <option value="">{{ t('audit.history.filter_all') }}</option>
                <option v-for="choice in options.items" :key="choice.ulid" :value="choice.ulid">
                    {{ choice.name }}
                </option>
            </select>
        </label>

        <label for="history-filter-from" class="flex flex-col gap-1 text-meta font-medium text-ink">
            {{ t('audit.history.filter_from') }}
            <input
                id="history-filter-from"
                v-model="from"
                type="date"
                name="from"
                :disabled="pending"
                class="min-h-11 rounded-control border border-border bg-surface px-3 font-normal text-ink"
            >
        </label>

        <label for="history-filter-to" class="flex flex-col gap-1 text-meta font-medium text-ink">
            {{ t('audit.history.filter_to') }}
            <input
                id="history-filter-to"
                v-model="to"
                type="date"
                name="to"
                :disabled="pending"
                class="min-h-11 rounded-control border border-border bg-surface px-3 font-normal text-ink"
            >
        </label>

        <button
            type="submit"
            :disabled="pending"
            class="inline-flex min-h-11 items-center rounded-control bg-accent px-4 font-medium text-ink-on-accent"
        >
            {{ pending ? t('common.pending.default') : t('audit.history.filter_submit') }}
        </button>

        <button
            type="button"
            :disabled="pending"
            class="inline-flex min-h-11 items-center text-body text-accent underline"
            @click="clear"
        >
            {{ t('audit.history.filter_clear') }}
        </button>
    </form>
</template>
