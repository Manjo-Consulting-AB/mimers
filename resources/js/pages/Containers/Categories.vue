<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
import CategoryCreateForm from '../../components/CategoryCreateForm.vue';
import CategoryTree from '../../components/CategoryTree.vue';
import { buildCategoryTree } from '../../components/categoryTree.js';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Kategoriträdet, se issue 56a § Beslut 1, 2, 4 och 8.
 *
 * Sidan ligger i ContainerLayout och bär den prop layouten kräver: `container`
 * ur App\Http\Resources\ContainerResource.
 *
 * **Trädet byggs här, ur en platt lista** (Beslut 2). `categories` är exakt vad
 * `/api/containers/{container}/categories` svarar — sorterat, platt, med
 * `parent` per rad — och hierarkin byggs av
 * resources/js/components/categoryTree.js. Servern har ingen vy-specifik
 * trädform, och en API-klient bygger samma träd ur samma svar.
 *
 * **Skillnaden mot taggen syns på sidan** (Beslut 8).
 * [[ADR-0004 Fria taggar och kategorier]]: kategorin är var saken hör hemma,
 * taggarna är allt annat man vill kunna filtrera på. Rubriken och raden under
 * den säger det, och taggsidan bär den omvända meningen — en vy som visar två
 * likadana listor river det beslutet.
 *
 * **`errors.category`** är ett domänfel ur en nekad radering — kategorin har
 * barn eller items — och ritas som EN ruta över trädet (Beslut 4).
 * Felpåsen kan inte säga vilken rad felet gäller, och en ruta per rad hade
 * upprepat samma mening lika många gånger som trädet har noder. Formulärfel
 * som hör till ett fält (`name`, `position`, `parent`) ritas av raden själv.
 *
 * `can.manage` styr om skrivytorna ritas. Flaggan är presentation; grinden är
 * policyn, och varje skrivning auktoriserar med `Gate::authorize()` oavsett
 * vad sidan visade.
 */
const props = defineProps({
    container: { type: Object, required: true },
    categories: { type: Array, required: true },
    can: { type: Object, required: true },
});

const { t } = useTranslations();
const page = usePage();

const tree = computed(() => buildCategoryTree(props.categories));
</script>

<template>
    <ContainerLayout :container="container">
        <Head :title="t('container.categories.title')" />

        <h1 class="text-2xl font-semibold">{{ t('container.categories.heading') }}</h1>
        <p class="mt-1 text-sm text-slate-600">{{ t('container.categories.description') }}</p>

        <p
            v-if="page.props.errors.category"
            role="alert"
            class="mt-4 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"
        >
            {{ page.props.errors.category }}
        </p>

        <ul v-if="tree.length > 0" class="mt-6">
            <CategoryTree :nodes="tree" :container-ulid="container.ulid" :categories="categories" />
        </ul>

        <p v-else class="mt-6 text-sm text-slate-600">{{ t('container.categories.empty') }}</p>

        <section v-if="can.manage" class="mt-10">
            <h2 class="text-lg font-semibold">{{ t('container.categories.create_heading') }}</h2>

            <CategoryCreateForm class="mt-4" :container-ulid="container.ulid" :categories="categories" />
        </section>
    </ContainerLayout>
</template>
