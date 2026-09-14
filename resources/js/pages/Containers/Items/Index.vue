<script setup>
import { Head, Link } from '@inertiajs/vue3';
import ContainerLayout from '../../../layouts/ContainerLayout.vue';
import ItemTagList from '../../../components/ItemTagList.vue';
import { useTranslations } from '../../../composables/useTranslations.js';

/*
 * Pärmens itemlista — pärmens förstasida, se issue 57a § Beslut 1, 4, 6, 8
 * och 9.
 *
 * Sidan ligger i ContainerLayout och bär den prop layouten kräver: `container`
 * ur App\Http\Resources\ContainerResource.
 *
 * **Sorteringen kommer från servern** (`orderBy('name')` i
 * App\Actions\Item\ListItem) och vyn sorterar aldrig om (Beslut 8).
 *
 * **Sidan visar antalet rader den ritar** och ingenting mer (Beslut 4). Ingen
 * totalsumma, ingen "av N", ingen rad om att något dolts: en omfångsbegränsad
 * mottagare ser bara det hon når, och ett tal om hur många som filtrerats bort
 * är precis det issue 73 § Beslut 6 förbjuder. Är listan tom säger sidan att
 * pärmen är tom — inte att den kanske är det.
 *
 * **Kategorinamnet slås upp i `categories`**, som kontrollern bygger ur de
 * redan eager-laddade relationerna (ULID → namn). `ItemResource` bär bara
 * kategorins ULID, och uppslaget hör därför BREDVID resursen och inte inuti
 * den — samma linje som issue 54 § Beslut 9. Saknas uppslaget utelämnas
 * raden; vyn hittar aldrig på ett värde.
 *
 * **Serienumret ritas inte här** (Beslut 8). Det hör till detaljvyn.
 *
 * **Ingen miniatyr** (Beslut 9). Bilagorna är issue 60 och 61, och att rita en
 * miniatyr här hade betytt en egen väg till filoriginet innan
 * [[ADR-0019 Filleverans]] fått sin yta. Rutan nedan lämnar platsen så att
 * issue 61 kan fylla den utan att raden byter form.
 *
 * `can.create` är presentationsflaggan för 57b:s skapayta. Den ritas inte här:
 * issuen lägger två GET-rutter och inga skrivande, och en knapp till en rutt
 * som inte finns är precis den knapp Beslut 7 förbjuder.
 */
defineProps({
    container: { type: Object, required: true },
    items: { type: Array, required: true },
    /* Kategori-ULID → namn, för de kategorier raderna pekar på. */
    categories: { type: Object, required: true },
    can: { type: Object, required: true },
});

const { t } = useTranslations();
</script>

<template>
    <ContainerLayout :container="container">
        <Head :title="t('item.index.title')" />

        <h1 class="text-2xl font-semibold">{{ t('item.index.heading') }}</h1>

        <p v-if="items.length === 0" class="mt-8 text-slate-700">{{ t('item.index.empty') }}</p>

        <ul v-else class="mt-8 flex flex-col divide-y divide-slate-200">
            <li v-for="item in items" :key="item.ulid" class="flex items-start gap-4 py-4">
                <div
                    aria-hidden="true"
                    class="h-12 w-12 shrink-0 rounded border border-slate-200 bg-slate-50"
                />

                <div class="min-w-0 flex-1">
                    <Link
                        :href="`/containers/${container.ulid}/items/${item.ulid}`"
                        class="font-medium text-blue-700 hover:underline"
                    >
                        {{ item.name }}
                    </Link>

                    <p
                        v-if="categories[item.category] || item.manufacturer || item.model"
                        class="mt-1 flex flex-wrap gap-x-4 text-sm text-slate-600"
                    >
                        <span v-if="categories[item.category]">{{ categories[item.category] }}</span>
                        <span v-if="item.manufacturer">{{ item.manufacturer }}</span>
                        <span v-if="item.model">{{ item.model }}</span>
                    </p>

                    <ItemTagList class="mt-2" :tags="item.tags" />
                </div>
            </li>
        </ul>
    </ContainerLayout>
</template>
