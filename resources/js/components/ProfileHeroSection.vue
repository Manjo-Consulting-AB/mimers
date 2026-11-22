<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import ImageCropEditor from './ImageCropEditor.vue';
import UiSheet from './UiSheet.vue';
import { coverStyle } from './coverPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Personens hjältebild, se [[ADR-0090 Dashboardens hjälte]] § 2 och 4 samt
 * issue 434.
 *
 * **Avsnittet ligger under profilbilden på `/settings/profile`.** Det gör
 * samma tre saker som profilbildens avsnitt — laddar upp, tar bort och
 * beskär — men bilden hamnar i dashboardens hjälte i stället för i avataren.
 * Därför är det en egen komponent: profilsidans avatar-avsnitt är orört, och
 * den här ytan kan flyttas eller ritas om utan att röra det.
 *
 * **Bilden läses ur den delade proppen, inte ur en sidpropp.** `hero` är
 * `{ ulid, has_thumb, focus, zoom }` eller null och kommer ur
 * App\Http\Resources\AuthUserResource; efter en uppladdning svarar servern
 * `back()` med färska proppar, så ytan byter bild utan att ladda om något
 * själv.
 *
 * **Förhandsbilden ritas med `coverStyle`.** Det är samma funktion som
 * dashboardens hjälte kommer att rita bilden med (issue 435), så
 * förhandsvisningen och den färdiga hjälten visar samma utsnitt. Den ritar
 * originalet — `/files/{ulid}` — eftersom `has_thumb` bara säger att
 * miniatyren finns, inte att `medium` gör det.
 *
 * **Redigeraren är den delade `ImageCropEditor` med `shape="wide"`.** Ytan,
 * masken, reglaget och de tre vägarna ut bor där; här bor bara arket
 * (UiSheet: fokusfällan, Esc, trycket utanför och rubriken), adressen och
 * sparningen.
 */
const { t } = useTranslations();
const page = usePage();

const hero = computed(() => page.props.auth.user.hero ?? null);

const uploading = ref(false);
const removing = ref(false);
const heroError = ref(null);

const busy = computed(() => uploading.value || removing.value);

const previewSrc = computed(() => (hero.value === null
    ? null
    : `/files/${hero.value.ulid}`));

function onFileSelected(event) {
    const file = event.target.files?.[0] ?? null;

    // Fältet töms så att SAMMA fil går att välja igen efter ett fel.
    event.target.value = '';

    if (file !== null) {
        uploadHero(file);
    }
}

function uploadHero(file) {
    heroError.value = null;

    router.post('/settings/profile/hero', { file }, {
        forceFormData: true,
        preserveScroll: true,
        onStart: () => {
            uploading.value = true;
        },
        onFinish: () => {
            uploading.value = false;
        },
        onError: (errors) => {
            heroError.value = errors.file ?? null;
        },
    });
}

/*
 * Ingen bekräftelse: bilden laddas lätt upp igen, samma linje som för
 * profilbilden.
 */
function removeHero() {
    heroError.value = null;

    router.delete('/settings/profile/hero', {
        preserveScroll: true,
        onStart: () => {
            removing.value = true;
        },
        onFinish: () => {
            removing.value = false;
        },
    });
}

const editorOpen = ref(false);
const editorTrigger = ref(null);
const editorPending = ref(false);
const editorError = ref(null);

const editorSrc = computed(() => previewSrc.value);
const editorFallbackSrc = computed(() => (hero.value?.has_thumb
    ? `/files/${hero.value.ulid}?variant=thumb`
    : null));

function openEditor(event) {
    editorTrigger.value = event.currentTarget ?? null;
    editorError.value = null;
    editorOpen.value = true;
}

function closeEditor() {
    editorOpen.value = false;
}

function saveCrop(crop) {
    editorError.value = null;

    router.patch('/settings/profile/hero/crop', crop, {
        preserveScroll: true,
        onStart: () => {
            editorPending.value = true;
        },
        onFinish: () => {
            editorPending.value = false;
        },
        onSuccess: () => {
            closeEditor();
        },
        onError: (errors) => {
            editorError.value = errors.x ?? errors.y ?? errors.zoom ?? null;
        },
    });
}
</script>

<template>
    <section class="mt-8 flex max-w-lg flex-col gap-4">
        <h2 class="text-lg font-semibold">{{ t('settings.profile.hero.heading') }}</h2>

        <div class="flex flex-col gap-3">
            <!-- Förhandsbilden, med samma utsnitt som dashboardens hjälte. -->
            <div v-if="hero" class="h-32 w-full overflow-hidden rounded-control">
                <img
                    :src="previewSrc"
                    :style="coverStyle(hero)"
                    alt=""
                    class="h-full w-full object-cover"
                >
            </div>

            <div class="flex flex-col gap-2">
                <label for="hero-file" class="text-sm font-medium text-slate-800">
                    {{ t('settings.profile.hero.upload') }}
                </label>

                <input
                    id="hero-file"
                    type="file"
                    accept="image/*"
                    :disabled="busy"
                    class="text-sm text-slate-700"
                    @change="onFileSelected"
                >

                <div class="flex flex-wrap gap-2">
                    <button
                        v-if="hero"
                        type="button"
                        :disabled="busy"
                        class="self-start inline-flex min-h-11 items-center rounded border border-slate-300 bg-white px-4 font-medium text-slate-800 disabled:opacity-50"
                        @click="openEditor"
                    >
                        {{ t('settings.profile.hero.adjust') }}
                    </button>

                    <button
                        v-if="hero"
                        type="button"
                        :disabled="busy"
                        class="self-start inline-flex min-h-11 items-center rounded border border-slate-300 bg-white px-4 font-medium text-slate-800 disabled:opacity-50"
                        @click="removeHero"
                    >
                        {{ t('settings.profile.hero.remove') }}
                    </button>
                </div>
            </div>

            <p v-if="uploading" role="status" class="text-sm text-slate-600">
                {{ t('settings.profile.hero.uploading') }}
            </p>

            <!-- Serverns färdigöversatta mening: kvoten, en fil som inte är en
                 bild eller en person utan personkonto. -->
            <p v-if="heroError" role="alert" class="text-sm text-danger">{{ heroError }}</p>
        </div>

        <!--
            Redigeraren: samma ark och samma yta som profilbildens, med
            `shape="wide"` eftersom hjälten är bred. Utsnittet sparas med en
            PATCH mot ruttens adress.
        -->
        <UiSheet
            :open="editorOpen"
            :heading="t('settings.profile.hero.crop_heading')"
            :trigger="editorTrigger"
            @close="closeEditor"
        >
            <ImageCropEditor
                v-if="editorOpen"
                :src="editorSrc"
                :fallback-src="editorFallbackSrc"
                :focus="hero?.focus ?? null"
                :zoom="hero?.zoom ?? null"
                shape="wide"
                :pending="editorPending"
                :error="editorError"
                @save="saveCrop"
                @cancel="closeEditor"
            />
        </UiSheet>
    </section>
</template>
