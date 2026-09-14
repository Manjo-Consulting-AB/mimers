<script setup>
import { Head } from '@inertiajs/vue3';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
import TagCreateForm from '../../components/TagCreateForm.vue';
import TagRow from '../../components/TagRow.vue';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Tagglistan, se issue 56a § Beslut 1, 6 och 8.
 *
 * Sidan ligger i ContainerLayout och bär den prop layouten kräver: `container`
 * ur App\Http\Resources\ContainerResource.
 *
 * **Skillnaden mot kategorin syns på sidan** (Beslut 8).
 * [[ADR-0004 Fria taggar och kategorier]]: kategorin är var saken hör hemma,
 * taggarna är allt annat man vill kunna filtrera på. Rubriken och raden under
 * den säger det, och kategorisidan bär den omvända meningen. Formen skiljer
 * dem också: taggen är platt och får en färg, kategorin är ett träd och får en
 * position.
 *
 * **`counts` är ett uppslag tagg-ULID → antal items, och det är per OMFÅNG**
 * (Beslut 6). Talet bor i kontrollerns prop och INTE i `TagResource` —
 * `/api` har inte bett om det. Nyckeln är ULID:n, för det är den TagResource
 * bär och den vyn slår upp på; ULID:en är uppslagsnyckeln och visas aldrig som
 * text.
 *
 * Listan är sorterad på namn av servern (`ListTags`), precis som `/api` gör.
 * Ingen sortering här: två sorteringar av samma lista glider isär.
 */
defineProps({
    container: { type: Object, required: true },
    tags: { type: Array, required: true },
    counts: { type: Object, required: true },
    can: { type: Object, required: true },
});

const { t } = useTranslations();
</script>

<template>
    <ContainerLayout :container="container">
        <Head :title="t('container.tags.title')" />

        <h1 class="text-2xl font-semibold">{{ t('container.tags.heading') }}</h1>
        <p class="mt-1 text-sm text-slate-600">{{ t('container.tags.description') }}</p>

        <ul v-if="tags.length > 0" class="mt-6 flex flex-col gap-3">
            <TagRow
                v-for="tag in tags"
                :key="tag.ulid"
                :container-ulid="container.ulid"
                :tag="tag"
                :count="counts[tag.ulid]"
            />
        </ul>

        <p v-else class="mt-6 text-sm text-slate-600">{{ t('container.tags.empty') }}</p>

        <section v-if="can.manage" class="mt-10">
            <h2 class="text-lg font-semibold">{{ t('container.tags.create_heading') }}</h2>

            <TagCreateForm class="mt-4" :container-ulid="container.ulid" />
        </section>
    </ContainerLayout>
</template>
