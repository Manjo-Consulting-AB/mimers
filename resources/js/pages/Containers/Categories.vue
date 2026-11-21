<script setup>
import { computed, ref } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
import ContainerSettingsLayout from '../../layouts/ContainerSettingsLayout.vue';
import CategoryCreateForm from '../../components/CategoryCreateForm.vue';
import CategoryPresetCard from '../../components/CategoryPresetCard.vue';
import CategoryTree from '../../components/CategoryTree.vue';
import { buildCategoryTree, movePayload } from '../../components/categoryTree.js';
import { useTranslations } from '../../composables/useTranslations.js';

defineOptions({
    layout: (props) => [
        [AppLayout, {}],
        [ContainerLayout, { hero: 'compact', container: props.container }],
        [ContainerSettingsLayout, { container: props.container }],
    ],
});

/*
 * Kategoriträdet, se issue 56a § Beslut 1, 2, 4 och 8.
 *
 * Sidan ligger i ContainerSettingsLayout och bär den prop layouten kräver:
 * `container` ur App\Http\Resources\ContainerResource.
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
 * barn eller items — och ritas vid DEN BERÖRDA RADEN (Beslut 4).
 * Felpåsen kan inte säga vilken rad felet gäller, så sidan minns ULID:n för
 * den rad vars radering senast skickades och skickar den ner i trädet; bara
 * den `<li>`:n ritar rutan. Är ULID:n `null` — ett fel som överlevde en
 * omladdning, vilket i praktiken inte händer eftersom Inertias felpåse är per
 * svar — ritas rutan här ovanför trädet som fallback. Formulärfel som hör till
 * ett fält (`name`, `position`, `parent`) ritas av raden själv.
 *
 * `can.manage` styr om skrivytorna ritas. Flaggan är presentation; grinden är
 * policyn, och varje skrivning auktoriserar med `Gate::authorize()` oavsett
 * vad sidan visade.
 *
 * **En kategori flyttas genom att dras** (issue 1484). Draget ägs här och inte
 * i rad eller träd: målet kan sitta på vilken nivå som helst, och bara sidan
 * har hela den platta listan. Raden säger `dragstart` med sin ULID och `drop`
 * med målets; sidan räknar ut förälder och position med movePayload() i
 * categoryTree.js och skickar SAMMA `PATCH` som radens väljare. Ytan *Top
 * level* ovanför trädet syns bara under ett pågående drag och skickar
 * `parent: null`. Ett fel ur serverns flyttprövning ritas på raden som drogs.
 *
 * **Förslaget på en tom container** kom med issue 56b § Beslut 4:
 * resources/js/components/CategoryPresetCard.vue ritas i stället för
 * tomtexten när trädet är tomt, användaren får skriva och hon inte redan tackat
 * nej i den här sessionen (`presetDismissed`, den enda prop servern skickar om
 * förslaget). Kortet är ett `<section>` och ingen modal — skapa-formuläret
 * ovanför fungerar oförändrat bredvid det.
 */
const props = defineProps({
    container: { type: Object, required: true },
    categories: { type: Array, required: true },
    /* ULID → { subcategories, items }, ur App\Http\Controllers\CategoryController
       — talen raderingsfrågan ritar (issue 150). Samma form som taggsidans. */
    counts: { type: Object, required: true },
    can: { type: Object, required: true },
    /* Sant när användaren tackat nej till förslaget för den här containern i den
       här sessionen — se App\Http\Controllers\CategoryController. */
    presetDismissed: { type: Boolean, required: true },
});

const { t } = useTranslations();
const page = usePage();

const tree = computed(() => buildCategoryTree(props.categories));

/*
 * ULID:n för den rad vars radering senast skickades — klientstate, med flit.
 * Servern lägger felet på formulärnyckeln `category` och har ingen radidentitet
 * att ge; se filens kommentar och Beslut 4.
 */
const deleteErrorUlid = ref(null);

/*
 * Dragets tillstånd, ägt av sidan (issue 1484). `draggingUlid` är raden som
 * dras, `moveErrorUlid` raden vars flytt nekades, och `moving` säger att en
 * flytt skrivs just nu — då vilar radernas egna knappar.
 */
const draggingUlid = ref(null);
const moveErrorUlid = ref(null);
const moving = ref(false);

function dragStart(ulid) {
    draggingUlid.value = ulid;
    moveErrorUlid.value = null;
}

function dragEnd() {
    draggingUlid.value = null;
}

/* Ett släpp på en rad: raden blir förälder, eller ingenting om movePayload()
   säger nej (sig själv, nuvarande förälder eller en egen ättling). */
function drop(targetUlid) {
    const dragged = draggingUlid.value;

    draggingUlid.value = null;
    sendMove(dragged, movePayload(props.categories, dragged, targetUlid));
}

/* Ytan *Top level*: `null` som mål gör kategorin till en rotkategori. */
function dropTopLevel() {
    const dragged = draggingUlid.value;

    draggingUlid.value = null;
    sendMove(dragged, movePayload(props.categories, dragged, null));
}

function sendMove(draggedUlid, payload) {
    if (draggedUlid === null || payload === null) {
        return;
    }

    moveErrorUlid.value = null;
    moving.value = true;

    router.patch(`/containers/${props.container.ulid}/categories/${draggedUlid}`, payload, {
        preserveScroll: true,
        onStart: () => { moving.value = true; },
        onFinish: () => { moving.value = false; },
        onError: () => { moveErrorUlid.value = draggedUlid; },
    });
}

/*
 * Den färdiga uppsättningen visas bara på en TOM container (Beslut 4) — den frågan
 * är redan ställd av listan — och bara för den som får skriva. Servern har
 * bara sagt sitt om nej:et; uppsättningen själv väljs i klienten av
 * CategoryPresetCard, ur localen och användarens eget val (issue 84).
 * Containerns art skickas INTE med: den är fri och pekar inte ut någon mall.
 */
const showsPreset = computed(
    () => props.can.manage && props.categories.length === 0 && !props.presetDismissed,
);
</script>

<template>
    <Head :title="t('container.categories.title')" />

    <h1 class="text-2xl font-semibold">{{ t('container.categories.heading') }}</h1>
    <p class="mt-1 text-sm text-slate-600">{{ t('container.categories.description') }}</p>

    <!-- Skapaformuläret står ovanför listan (M69 fynd 2026-11-21): den som
         kommer hit för att lägga till en kategori ska inte behöva skrolla
         förbi hela trädet först. -->
    <section v-if="can.manage" class="mt-6">
        <h2 class="text-lg font-semibold">{{ t('container.categories.create_heading') }}</h2>

        <CategoryCreateForm class="mt-4" :container-ulid="container.ulid" :categories="categories" />
    </section>

    <p
        v-if="deleteErrorUlid === null && page.props.errors.category"
        role="alert"
        class="mt-4 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"
    >
        {{ page.props.errors.category }}
    </p>

    <!-- Ytan för översta nivån (issue 1484) syns bara under ett pågående
         drag: annars hade den varit en tom rad ovanför trädet. -->
    <div
        v-if="draggingUlid !== null"
        class="mt-6 rounded border-2 border-dashed border-blue-400 bg-blue-50 px-3 py-4 text-center text-sm font-medium text-blue-800"
        @dragover.prevent
        @drop.prevent="dropTopLevel"
    >
        {{ t('container.categories.drop_top_level') }}
    </div>

    <ul v-if="tree.length > 0" class="mt-6">
        <CategoryTree
            :nodes="tree"
            :container-ulid="container.ulid"
            :categories="categories"
            :counts="counts"
            :delete-error-ulid="deleteErrorUlid"
            :dragging-ulid="draggingUlid"
            :move-error-ulid="moveErrorUlid"
            :disabled="moving"
            @delete="deleteErrorUlid = $event"
            @dragstart="dragStart"
            @dragend="dragEnd"
            @drop="drop"
        />
    </ul>

    <CategoryPresetCard
        v-else-if="showsPreset"
        :container-ulid="container.ulid"
    />

    <p v-else class="mt-6 text-sm text-slate-600">{{ t('container.categories.empty') }}</p>
</template>
