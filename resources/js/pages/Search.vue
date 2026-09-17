<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Den globala sökningen, se issue 59b § Beslut 1, 3, 4, 6 och 8. Sidan
 * ligger under AppLayout och på toppnivå: frågan spänner över alla containers
 * användaren når, och det är därför den har en egen URL.
 *
 * **Sökrutan ritas inte här.** Den bor i AppLayout (SearchField) och syns på
 * varje inloggad sida — sidan här ritar bara svaret (Beslut 5).
 *
 * **Tre lägen, och bara ett av dem körde en fråga** (Beslut 4):
 *
 *   - `q` är null             → utgångsläget: vad man kan söka på, och att
 *                               sökningen matchar hela ord
 *   - `q` finns, inga träffar → meningen som nämner sökordet och slutar där
 *   - `q` finns, träffar      → listan
 *
 * Utgångsläget är inte ett fel: `/search` utan `q` betyder att någon klickat
 * på sökfältet, och servern har då inte ställt någon fråga alls.
 *
 * **Varje träff bär sin container** (Beslut 3). `container` ligger BREDVID
 * ItemResource — resursen bär ingen container med flit, för `/api` har inte bett om
 * den, och ett fält som bara webben behöver hör inte inuti den
 * ([[ADR-0021 Frontendteknik]] § Konsekvenser). Itemets namn länkar till
 * detaljvyn; containerns namn till containerns förstasida.
 *
 * **Sorteringen är serverns** (`orderBy('name')` i
 * App\Actions\Item\SearchAccessibleItems) — vyn sorterar aldrig om, och den
 * filtrerar ingenting: urvalet och omfånget är actionens.
 *
 * **Den tomma träfflistan vet ingenting om omfånget** (Beslut 6). Meningen
 * nämner sökordet och ingenting annat: inget tal om hur många rader som
 * fanns, ingen antydan om att något dolts, ingen uppräkning av vilka containers
 * som genomsöktes. En användare utan åtkomst till någonting alls får
 * ordagrant samma mening som en vars sökord inte matchar.
 *
 * **Ingen "menade du"-rad** (Beslut 7). Svensk stemming finns inte i MVP
 * ([[ADR-0012 Sök]]), och ingen kompensation byggs här: en klientomskrivning
 * av sökordet är en andra sökmotor och blir kvar långt efter att den riktiga
 * bytts ut. Utgångsläget säger i stället att sökningen matchar hela ord.
 */
defineProps({
    /* Sökordet servern ställde frågan med, eller null när ingen fråga kördes. */
    q: { type: String, default: null },
    /* ItemResource per träff, med containerns { ulid, name, kind } bredvid. */
    results: { type: Array, required: true },
});

const { t } = useTranslations();
</script>

<template>
    <AppLayout>
        <Head :title="t('search.title')" />

        <h1 class="text-2xl font-semibold">{{ t('search.heading') }}</h1>

        <template v-if="q === null">
            <p class="mt-4 text-slate-700">{{ t('search.intro') }}</p>
            <p class="mt-2 text-sm text-slate-600">{{ t('search.whole_words') }}</p>
        </template>

        <template v-else>
            <p v-if="results.length === 0" class="mt-8 text-slate-700">
                {{ t('search.empty', { q }) }}
            </p>

            <ul v-else class="mt-8 flex flex-col divide-y divide-slate-200">
                <li v-for="result in results" :key="result.ulid" class="py-4">
                    <Link
                        :href="`/containers/${result.container.ulid}/items/${result.ulid}`"
                        class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline"
                    >
                        {{ result.name }}
                    </Link>

                    <p class="mt-1 flex flex-wrap items-center gap-x-2 text-sm text-slate-600">
                        <span>{{ t('search.in_container') }}</span>

                        <Link
                            :href="`/containers/${result.container.ulid}`"
                            class="inline-flex min-h-11 items-center text-blue-700 hover:underline"
                        >
                            {{ result.container.name }}
                        </Link>

                        <span>{{ t(`container.kind.${result.container.kind}`) }}</span>
                    </p>
                </li>
            </ul>
        </template>
    </AppLayout>
</template>
