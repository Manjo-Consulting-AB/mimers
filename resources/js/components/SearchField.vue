<script setup>
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Sökfältet, se issue 59b § Beslut 5. Det ritas i AppLayout och syns därför på
 * varje inloggad sida — en sökning som bara finns på söksidan är en sökning
 * ingen hittar. Sedan issue 630 ritas det också på söksidan själv, i `page`-
 * varianten, precis under förklaringen.
 *
 * **Två varianter, `header` (förval) och `page`** (issue 630 § Beslut 2).
 * Skillnaden är id, etikett och bredd — allt annat är detsamma:
 *
 *   - `header`  → `id="search-q"`, etiketten `label-hidden`, `max-w-xs`. Det är
 *                 fältet i skalets topprad, och den dolda etiketten låter
 *                 input och knapp stå på toppradens mittlinje.
 *   - `page`    → `id="search-page-q"`, etiketten SYNLIG, `max-w-xl`. Det är
 *                 fältet på `/search`, under de två meningarna.
 *
 * Två `id` behövs därför att båda fälten finns samtidigt på `/search`: det i
 * toppraden och det på sidan. Förvalet `header` gör att
 * `<SearchField v-if="user" />` i AppLayout står kvar ordagrant.
 *
 * **Ett vanligt GET-formulär mot /search.** Ingen Inertia-länk, ingen
 * `router.get`, ingen `useForm`: webbläsaren skickar querysträngen själv, och
 * Enter i fältet är hela interaktionen. Servern svarar med samma sida i ett
 * sökt läge, och URL:en går att spara och dela (Beslut 1).
 *
 * **Inget anrop medan användaren skriver** (Beslut 5). Ingen debounce, ingen
 * autocomplete, ingen dropdown med träffar: [[ADR-0012 Sök]] valde
 * databasdrivrutinen och en `LIKE`-formulering, och att skicka den frågan per
 * tangenttryck är att be delad hosting om problem.
 *
 * **Ordet står kvar i rutan.** `q` är den prop söksidan bär (SearchController),
 * och ingen annan sida skickar en `q` — fältet läser den därför rakt av och
 * visar en tom ruta överallt annars.
 *
 * **Felet vid fältet kommer från servern.** Är `q` längre än 255 tecken svarar
 * SearchController med ett vanligt valideringsfel, och Inertia lägger det i
 * `errors` (Beslut 4). FormField ritar det, precis som i varje annat
 * webbformulär — ingen egen regel i JavaScript, ingen klientvalidering.
 *
 * **Knappen säger att den väntar** (issue 68a § Beslut 4). Formuläret är
 * fortfarande en vanlig GET — transporten är orörd — men `pending` sätts i
 * submit-händelsen, så knappen är inaktiverad och bär en väntetext från det
 * att webbläsaren tar över tills den nya sidan ritas. Utan det står knappen
 * still på en långsam uppkoppling, och den som inte ser att något händer
 * trycker igen.
 *
 * 44 px träffyta på både fältet och knappen (issue 68a § Beslut 3): fältet
 * sitter i toppnavigeringen och träffas med tummen.
 */
const { t } = useTranslations();
const page = usePage();

/*
 * Varianterna (issue 630 § Beslut 2). `header` är förvalet, så skalets
 * `<SearchField v-if="user" />` behöver inte skicka någonting.
 */
const props = defineProps({
    variant: { type: String, default: 'header' },
});

const isPage = computed(() => props.variant === 'page');

/* Två id därför att båda fälten finns samtidigt på /search. */
const fieldId = computed(() => (isPage.value ? 'search-page-q' : 'search-q'));

const query = computed(() => page.props.q ?? '');
const error = computed(() => page.props.errors?.q ?? null);
const pending = ref(false);
</script>

<template>
    <form
        method="get"
        action="/search"
        class="w-full"
        :class="isPage ? 'max-w-xl' : 'max-w-xs'"
        @submit="pending = true"
    >
        <FormField
            v-slot="{ describedBy }"
            :label="t('search.field.label')"
            :id="fieldId"
            :error="error"
            :label-hidden="!isPage"
        >
            <div class="flex gap-2">
                <input
                    :id="fieldId"
                    name="q"
                    type="search"
                    :value="query"
                    :placeholder="t('search.field.placeholder')"
                    :aria-describedby="describedBy"
                    class="min-h-11 w-full rounded border border-slate-300 px-2 text-sm"
                >

                <button
                    type="submit"
                    :disabled="pending"
                    class="inline-flex min-h-11 items-center rounded bg-blue-700 px-3 text-sm font-medium text-white disabled:opacity-50"
                >
                    {{ pending ? t('common.pending.default') : t('search.field.submit') }}
                </button>
            </div>
        </FormField>
    </form>
</template>
