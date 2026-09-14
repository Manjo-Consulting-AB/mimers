<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Sökfältet, se issue 59b § Beslut 5. Det bor i AppLayout och syns därför på
 * varje inloggad sida — en sökning som bara finns på söksidan är en sökning
 * ingen hittar.
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
 */
const { t } = useTranslations();
const page = usePage();

const query = computed(() => page.props.q ?? '');
const error = computed(() => page.props.errors?.q ?? null);
</script>

<template>
    <form method="get" action="/search" class="w-full max-w-xs">
        <FormField v-slot="{ describedBy }" :label="t('search.field.label')" id="search-q" :error="error">
            <div class="flex gap-2">
                <input
                    id="search-q"
                    name="q"
                    type="search"
                    :value="query"
                    :placeholder="t('search.field.placeholder')"
                    :aria-describedby="describedBy"
                    class="w-full rounded border border-slate-300 px-2 py-1 text-sm"
                >

                <button type="submit" class="rounded bg-blue-700 px-3 py-1 text-sm font-medium text-white">
                    {{ t('search.field.submit') }}
                </button>
            </div>
        </FormField>
    </form>
</template>
