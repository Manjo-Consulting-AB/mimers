<script setup>
import { computed, ref, useId, watch } from 'vue';
import {
    imageCrop,
    imageDragFocus,
    imageFocusRange,
    imageNudgeFocus,
} from '../composables/useImageCrop.js';
import { coverDragFocus, coverNudgeFocus, coverStyle } from './coverPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Redigeraren för ett utsnitt, se [[ADR-0090 Dashboardens hjälte]] § 4,
 * [[ADR-0076 Profilbilden]] § 7 och [[M59 Profilbilden]] § 382.
 *
 * **En komponent, två former.** Profilbilden ritas i en rund ruta, hjältarna
 * och containerns bild i en bred. Redigeraren lyftes hit ur
 * resources/js/pages/Settings/Profile.vue i issue 433, och används av
 * profilsidan (`shape="round"`) och av containerns ark
 * (resources/js/components/ContainerCoverSheet.vue, `shape="wide"`). Ramen
 * har samma form som ytan bilden ritas i (ADR-0090 § 4).
 *
 * **Den runda formen ritar med `imageCrop`.** Den är profilbildens räkning
 * orörd: fokuspunkten står i rutans mitt så långt bilden räcker, zoomen
 * förstoras runt den, och ingen tom kant uppstår. Samma funktion ritar
 * avataren (resources/js/components/UserAvatar.vue), så det man ser i masken
 * är exakt vad avataren visar.
 *
 * **Den breda formen ritar med `coverStyle`.** Containerns bild är en
 * `object-position` plus en `transform: scale()` (ADR-0047 § Beslut och
 * ADR-0090 § 5), och `ContainerCover.vue` ritar den med samma funktion. Hade
 * ramen räknat i bildpunkter i stället hade förhandsvisningen och hjälten
 * visat olika utsnitt av samma bild.
 *
 * **Punkten flyttas med pekaren och med piltangenterna.** Båda utgår från
 * den effektiva punkten — den som faktiskt syns — så ett drag eller ett
 * piltryck rör bilden direkt i stället för att först beta av ett dött område.
 * Den runda formen klamras till det intervall som går att nå vid aktuell zoom
 * (`imageFocusRange`); den breda formen kan visa hela 0–100, eftersom
 * `object-position` alltid täcker rutan. Piltangenterna flyttar 2 procent,
 * 10 med Shift; zoomen klamrar om punkten i den runda formen, så utkastet
 * alltid är nåbart.
 *
 * **Allt är lokalt till dess att Save trycks.** Reset sätter mitten och
 * 100 %, Cancel stänger utan att skriva. Komponenten sparar ingenting själv:
 * den sänder ut utkastet med `save`, och anroparen gör anropet — profilens
 * PATCH mot `/settings/profile/avatar/crop`, arkets mot
 * `/containers/{container}/cover/focus`.
 *
 * **Bilden är en adress, inte ett objekt.** Proppen `src` bär adressen
 * anroparen valt variant för (profilen `?variant=medium`, containern
 * `coverUrl(..., 'medium')`), och `fallback-src` bär en mindre variant som
 * ritas när den begärda saknas — profilbilden har inget `medium` under
 * 1024 px. Det är samma väg tillbaka som `UserAvatar` tar.
 *
 * Ingen sträng står i filen: etiketterna kommer ur `t('crop.*')`, som delas
 * av båda anroparytorna.
 */
const props = defineProps({
    /* Bilden som redigeras. Anroparen väljer variant. */
    src: { type: String, required: true },

    /* Mindre variant som ritas när `src` inte går att ladda, eller null. */
    fallbackSrc: { type: String, default: null },

    /* Sparad fokuspunkt (`{x, y}` i procent) eller null för mitten. */
    focus: { type: Object, default: null },

    /* Sparad zoom i procent (100–300) eller null för 100. */
    zoom: { type: Number, default: null },

    /* `round` för profilbilden, `wide` för hjältarna och containerns bild. */
    shape: { type: String, default: 'wide' },

    /* Sant medan anroparens anrop är i luften: knapparna stängs. */
    pending: { type: Boolean, default: false },

    /* Anroparens färdigöversatta fel, eller null. */
    error: { type: String, default: null },
});

const emit = defineEmits(['save', 'cancel']);

const { t } = useTranslations();

/*
 * Ramens mått. Den runda är profilbildens 256 px — panelen i UiSheet är
 * 288 px bred med 16 px luft, så den ryms med marginal. Den breda är 256 ×
 * 128, alltså 2:1: hjälten är bredare än så, men en ram som var bredare än
 * panelen hade krävt en egen bredd och gömt bilden bakom en scroll.
 */
const SIZES = {
    round: { width: 256, height: 256 },
    wide: { width: 256, height: 128 },
};

const size = computed(() => SIZES[props.shape] ?? SIZES.wide);
const isRound = computed(() => props.shape === 'round');

/* Utkastet, som allt lokalt arbete sker i. */
const draftFocus = ref({
    x: props.focus?.x ?? 50,
    y: props.focus?.y ?? 50,
});
const draftZoom = ref(props.zoom ?? 100);

const uid = useId();
const zoomId = `crop-zoom-${uid}`;

const natural = ref({ width: 0, height: 0 });
const failed = ref(false);
const fallback = ref(false);

const source = computed(() => (fallback.value ? props.fallbackSrc : props.src));
const showsImage = computed(() => source.value !== null && !failed.value);
const ready = computed(() => natural.value.width > 0 && natural.value.height > 0);

/*
 * En annan bild börjar om från början: måtten, felet och valet att rita
 * fallbacken hör till just den bilden. Utan nollställningen hade en
 * miniatyr ritats uppskalad i redigeraren efter ett byte.
 */
watch(() => [props.src, props.fallbackSrc], () => {
    failed.value = false;
    fallback.value = false;
    natural.value = { width: 0, height: 0 };
});

function onLoad(event) {
    natural.value = {
        width: event.target.naturalWidth,
        height: event.target.naturalHeight,
    };
}

function onError() {
    if (props.fallbackSrc !== null && !fallback.value) {
        fallback.value = true;
        natural.value = { width: 0, height: 0 };

        return;
    }

    failed.value = true;
}

/*
 * Den runda formens utsnitt, ur samma funktion som avataren. Medan bilden
 * laddas är `natural` noll och funktionen svarar en neutral fyllning; bilden
 * ritas då DOLD, så att den inte sträcks ut till rutan en blink. `visibility`
 * och inte `display`: elementet behåller sin plats så `load` fortfarande
 * landar.
 */
const roundCrop = computed(() => imageCrop({
    imageWidth: natural.value.width,
    imageHeight: natural.value.height,
    box: size.value.width,
    focusX: draftFocus.value.x,
    focusY: draftFocus.value.y,
    zoom: draftZoom.value,
}));

const roundStyle = computed(() => ({
    width: `${roundCrop.value.width}px`,
    height: `${roundCrop.value.height}px`,
    left: `${roundCrop.value.left}px`,
    top: `${roundCrop.value.top}px`,
    visibility: ready.value ? 'visible' : 'hidden',
}));

/* Den breda formens utsnitt: exakt samma stil som `ContainerCover`. */
const wideStyle = computed(() => coverStyle({
    focus: draftFocus.value,
    zoom: draftZoom.value,
}));

/*
 * Masken: en cirkel i mitten lämnas genomskinlig, resten av ytan bär den
 * mörka tonen — hörnen utanför avataren mörkläggs. `closest-side` ger
 * cirkeln ytans halva bredd, samma radie som avataren; utan storleksangivelse
 * hade `circle` fått `farthest-corner` och maskat en ring innanför kanten.
 */
const maskStyle = {
    maskImage: 'radial-gradient(circle closest-side at center, transparent 99%, black 100%)',
    WebkitMaskImage: 'radial-gradient(circle closest-side at center, transparent 99%, black 100%)',
};

const dragging = ref(false);
const dragStart = ref(null);

function startDrag(event) {
    if (!ready.value || props.pending) {
        return;
    }

    event.preventDefault();

    if (isRound.value) {
        const bounds = imageFocusRange({
            imageWidth: natural.value.width,
            imageHeight: natural.value.height,
            box: size.value.width,
            focusX: draftFocus.value.x,
            focusY: draftFocus.value.y,
            zoom: draftZoom.value,
        });

        dragStart.value = {
            x: event.clientX,
            y: event.clientY,
            focusX: bounds.focusX,
            focusY: bounds.focusY,
        };
    } else {
        dragStart.value = {
            x: event.clientX,
            y: event.clientY,
            focusX: draftFocus.value.x,
            focusY: draftFocus.value.y,
        };
    }

    dragging.value = true;

    event.currentTarget.setPointerCapture?.(event.pointerId);
}

function dragMove(event) {
    if (!dragging.value || dragStart.value === null) {
        return;
    }

    const start = dragStart.value;

    if (isRound.value) {
        draftFocus.value = imageDragFocus({
            imageWidth: natural.value.width,
            imageHeight: natural.value.height,
            box: size.value.width,
            focusX: start.focusX,
            focusY: start.focusY,
            zoom: draftZoom.value,
            deltaX: event.clientX - start.x,
            deltaY: event.clientY - start.y,
        });

        return;
    }

    draftFocus.value = coverDragFocus({
        focus: { x: start.focusX, y: start.focusY },
        zoom: draftZoom.value,
        imageWidth: natural.value.width,
        imageHeight: natural.value.height,
        boxWidth: size.value.width,
        boxHeight: size.value.height,
        deltaX: event.clientX - start.x,
        deltaY: event.clientY - start.y,
    });
}

function endDrag(event) {
    if (!dragging.value) {
        return;
    }

    dragging.value = false;
    dragStart.value = null;

    if (event.currentTarget.hasPointerCapture?.(event.pointerId)) {
        event.currentTarget.releasePointerCapture(event.pointerId);
    }
}

function onKeydown(event) {
    const step = event.shiftKey ? 10 : 2;
    let stepX = 0;
    let stepY = 0;

    if (event.key === 'ArrowLeft') {
        stepX = -step;
    } else if (event.key === 'ArrowRight') {
        stepX = step;
    } else if (event.key === 'ArrowUp') {
        stepY = -step;
    } else if (event.key === 'ArrowDown') {
        stepY = step;
    } else {
        return;
    }

    event.preventDefault();

    if (isRound.value) {
        draftFocus.value = imageNudgeFocus({
            imageWidth: natural.value.width,
            imageHeight: natural.value.height,
            box: size.value.width,
            focusX: draftFocus.value.x,
            focusY: draftFocus.value.y,
            zoom: draftZoom.value,
            stepX,
            stepY,
        });

        return;
    }

    draftFocus.value = coverNudgeFocus({
        focus: draftFocus.value,
        stepX,
        stepY,
    });
}

/*
 * Zoomen ändras: i den runda formen klamras punkten om till det intervall som
 * går att nå vid den nya zoomen — annars kunde utkastet peka på en punkt som
 * bilden inte längre kan visa. I den breda formen står punkten kvar där den
 * stod (transformens origo är samma punkt), så inget behöver klamras.
 */
watch(draftZoom, () => {
    if (!isRound.value || !ready.value) {
        return;
    }

    draftFocus.value = imageNudgeFocus({
        imageWidth: natural.value.width,
        imageHeight: natural.value.height,
        box: size.value.width,
        focusX: draftFocus.value.x,
        focusY: draftFocus.value.y,
        zoom: draftZoom.value,
    });
});

function reset() {
    draftFocus.value = { x: 50, y: 50 };
    draftZoom.value = 100;
}

function save() {
    emit('save', {
        x: draftFocus.value.x,
        y: draftFocus.value.y,
        zoom: draftZoom.value,
    });
}
</script>

<template>
    <div class="mx-auto" :style="{ width: `${size.width}px` }">
        <!--
            Ytan. `touch-none` låter pekaren äga dragningen i stället för att
            sidan skrollar, och `tabindex="0"` gör piltangenterna nåbara.
        -->
        <div
            tabindex="0"
            role="application"
            :aria-label="t('crop.label')"
            class="relative touch-none overflow-hidden outline-none focus-visible:ring-2 focus-visible:ring-focus"
            :class="isRound ? 'rounded-control' : 'rounded-card'"
            :style="{ width: `${size.width}px`, height: `${size.height}px` }"
            @pointerdown="startDrag"
            @pointermove="dragMove"
            @pointerup="endDrag"
            @pointercancel="endDrag"
            @keydown="onKeydown"
        >
            <!--
                Den runda formen: bilden placeras i bildpunkter ur `imageCrop`,
                och `max-w-none` hindrar Tailwinds preflight från att krympa
                den vid zoom.
            -->
            <template v-if="isRound">
                <img
                    v-if="showsImage"
                    :src="source"
                    alt=""
                    draggable="false"
                    class="pointer-events-none absolute max-w-none select-none"
                    :style="roundStyle"
                    @load="onLoad"
                    @error="onError"
                >

                <span
                    aria-hidden="true"
                    class="pointer-events-none absolute inset-0 bg-ink opacity-50"
                    :style="maskStyle"
                ></span>

                <span
                    aria-hidden="true"
                    class="pointer-events-none absolute inset-0 rounded-pill border-2 border-white"
                ></span>
            </template>

            <!--
                Den breda formen: samma `object-position` och `transform` som
                `ContainerCover` ritar containerns bild med. Rutan klipper
                förstoringen.
            -->
            <img
                v-else-if="showsImage"
                :src="source"
                alt=""
                draggable="false"
                class="pointer-events-none absolute inset-0 h-full w-full max-w-none select-none object-cover"
                :style="wideStyle"
                @load="onLoad"
                @error="onError"
            >
        </div>
    </div>

    <div class="mt-3 flex flex-col gap-1">
        <label :for="zoomId" class="text-sm font-medium text-ink">
            {{ t('crop.zoom') }}
        </label>

        <input
            :id="zoomId"
            v-model.number="draftZoom"
            type="range"
            min="100"
            max="300"
            step="10"
            :disabled="pending"
            class="min-h-11"
        >
    </div>

    <div class="mt-1 flex flex-wrap gap-2">
        <button
            type="button"
            :disabled="pending"
            class="inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
            @click="save"
        >
            {{ pending ? t('common.pending.default') : t('crop.save') }}
        </button>

        <button
            type="button"
            :disabled="pending"
            class="inline-flex min-h-11 items-center rounded border border-slate-300 bg-white px-4 font-medium text-slate-800 disabled:opacity-50"
            @click="reset"
        >
            {{ t('crop.reset') }}
        </button>

        <button
            type="button"
            :disabled="pending"
            class="inline-flex min-h-11 items-center rounded border border-slate-300 bg-white px-4 font-medium text-slate-800 disabled:opacity-50"
            @click="emit('cancel')"
        >
            {{ t('crop.cancel') }}
        </button>
    </div>

    <p v-if="error" role="alert" class="mt-1 text-sm text-danger">{{ error }}</p>
</template>
