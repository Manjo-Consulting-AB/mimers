<script setup>
import { computed, ref, watch } from 'vue';
import { avatarCrop } from '../composables/useAvatarCrop.js';

/*
 * Profilbilden i en rund (eller kvadratisk) ruta, se [[ADR-0076
 * Profilbilden]] § 6 och 7 samt [[M59 Profilbilden]] § 381 och § 382.
 *
 * **En komponent för varje yta som visar personens bild.** Sedan issue 381
 * ritade `AppLayout.vue` och `Settings/Profile.vue` var sin egen cirkel med
 * samma initialer, samma `?variant=thumb` och samma `@error`-fallback. Här
 * bor regeln en gång: toppraden, förhandsvisningen på profilsidan och
 * redigerarens yta frågar samma komponent.
 *
 * **Storleken avgör varianten.** Små rutor (toppraden och förhandsvisningen)
 * ber om `?variant=thumb`; redigerarens stora yta ber om `?variant=medium`,
 * som är skarp nog att beskåda på nära håll. En begärd variant som inte
 * finns svarar 404, och då faller komponenten tillbaka på miniatyren innan
 * initialerna tar över — en bild mindre än 1024 px har inget `medium`.
 *
 * **Utsnittet ritas med `avatarCrop`** ur
 * resources/js/composables/useAvatarCrop.js: fokuspunkten står i rutans mitt
 * så långt bilden räcker, zoomen förstoras runt den, och ingen tom kant
 * uppstår. Originalet lagras orört — ingen ny fil skapas och kvoten rörs
 * inte (ADR-0076 § 7).
 *
 * **Initialerna är reserven**, inte ett andra läge: de visas när personen
 * saknar bild, när miniatyren saknas (en `<img>` mot `?variant=thumb` utan
 * derivat är en trasig bild, se coverPresentation.js) och när bilden inte går
 * att ladda. Samma regel som issue 381 lade i skalet.
 *
 * `alt=""` med flit: bilden är pynt i en yta som redan bär personens namn
 * (`aria-label` på länken i toppraden), och en skärmläsare ska inte läsa
 * samma namn två gånger.
 */
const props = defineProps({
    /* Personen ur den delade propen: `name` och `avatar` läses här. */
    user: { type: Object, required: true },

    /* Rutans sida i bildpunkter. */
    size: { type: Number, default: 44 },

    /* `circle` är avataren; `square` är redigerarens yta bakom masken. */
    shape: { type: String, default: 'circle' },
});

const emit = defineEmits(['load']);

const natural = ref({ width: 0, height: 0 });
const failed = ref(false);
const fallbackToThumb = ref(false);

const avatar = computed(() => props.user?.avatar ?? null);

/*
 * Tröskeln är 96 px: toppraden (44) och förhandsvisningen (64) är små, och
 * redigerarens yta är betydligt större. `has_thumb`-grinden gäller bara
 * miniatyren — för `medium` vet resursen inte om derivatet finns, så den
 * begärs och faller tillbaka på fel.
 */
const variant = computed(() => (props.size >= 96 ? 'medium' : 'thumb'));

const requestedSrc = computed(() => {
    if (avatar.value === null) {
        return null;
    }

    if (variant.value === 'thumb' && !avatar.value.has_thumb) {
        return null;
    }

    return variant.value === 'thumb'
        ? `/files/${avatar.value.ulid}?variant=thumb`
        : `/files/${avatar.value.ulid}?variant=medium`;
});

const src = computed(() => {
    if (requestedSrc.value === null) {
        return null;
    }

    if (fallbackToThumb.value && avatar.value?.has_thumb) {
        return `/files/${avatar.value.ulid}?variant=thumb`;
    }

    return requestedSrc.value;
});

const showsImage = computed(() => src.value !== null && !failed.value);

watch(src, () => {
    failed.value = false;
    natural.value = { width: 0, height: 0 };
});

const focus = computed(() => avatar.value?.focus ?? { x: 50, y: 50 });
const zoom = computed(() => avatar.value?.zoom ?? 100);

/*
 * Utsnittet: måtten och placeringen ur den delade funktionen. Innan bilden
 * laddats är `natural` noll och funktionen svarar en neutral fyllning.
 */
const crop = computed(() => avatarCrop({
    imageWidth: natural.value.width,
    imageHeight: natural.value.height,
    box: props.size,
    focusX: focus.value.x,
    focusY: focus.value.y,
    zoom: zoom.value,
}));

const imageStyle = computed(() => ({
    width: `${crop.value.width}px`,
    height: `${crop.value.height}px`,
    left: `${crop.value.left}px`,
    top: `${crop.value.top}px`,
}));

const initials = computed(() => {
    const words = (props.user?.name ?? '').trim().split(/\s+/).filter(Boolean);

    if (words.length === 0) {
        return '';
    }

    const first = words[0].charAt(0);
    const last = words.length > 1 ? words[words.length - 1].charAt(0) : '';

    return (first + last).toUpperCase();
});

function onLoad(event) {
    natural.value = {
        width: event.target.naturalWidth,
        height: event.target.naturalHeight,
    };

    emit('load', natural.value);
}

/*
 * Ett `medium` som saknas är inget fel — bilden är bara mindre än derivatet.
 * Faller tillbaka på miniatyren en gång; faller den också tar initialerna
 * över. `@error` är samma väg tillbaka som issue 381 byggde.
 */
function onError() {
    if (variant.value === 'medium' && avatar.value?.has_thumb && !fallbackToThumb.value) {
        fallbackToThumb.value = true;

        return;
    }

    failed.value = true;
}
</script>

<template>
    <span
        class="relative inline-flex shrink-0 overflow-hidden bg-surface-sunken text-title font-semibold text-ink-muted"
        :class="props.shape === 'square' ? 'rounded-control' : 'rounded-pill'"
        :style="{ width: `${props.size}px`, height: `${props.size}px` }"
    >
        <!--
            `max-w-none` och den absoluta placeringen: Tailwinds preflight
            sätter `max-width: 100%` på varje bild, och vid zoom är bilden
            bredare än rutan. Utan `max-w-none` hade den krympt tillbaka och
            zoomen gjort ingenting.
        -->
        <img
            v-if="showsImage"
            :src="src"
            alt=""
            draggable="false"
            class="pointer-events-none absolute max-w-none select-none"
            :style="imageStyle"
            @load="onLoad"
            @error="onError"
        >

        <span v-else class="absolute inset-0 inline-flex items-center justify-center">
            {{ initials }}
        </span>
    </span>
</template>
