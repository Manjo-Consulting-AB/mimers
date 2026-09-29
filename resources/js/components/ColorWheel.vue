<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { hexToHsv, hsvToHex } from '../support/colorWheel.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Färgväljaren — hjulet och popovern runt det, se issue 165 § Beslut 1, 4, 7
 * och 8.
 *
 * **Ett HSV-hjul och inget rutnät.** Nyansen ligger runt kanten och mättnaden
 * från mitten och ut, och ljusheten är ett eget reglage under hjulet — samma
 * form som Photoshop, Figma och Chrome använder, och det "en cirkel att
 * klicka i" betyder. Hjulets två lager är två CSS-gradienter och ingen
 * canvas: nyansringen är en `conic-gradient` byggd ur `hsvToHex`, och vit
 * över mitten en `radial-gradient`. Att blanda in vitt i RGB är exakt vad
 * mättnad är i HSV — s = 0 är vit, s = 100 är ren nyans — så överlägget
 * stämmer utan en enda räknad pixel.
 *
 * **Hjulets vinkel och markörens plats är samma tal.** `conic-gradient(from
 * 90deg, …)` lägger nyans 0 i öster och ökar medurs, och markörens `left/top`
 * räknas med `cos`/`sin` ur samma nyans. De två får inte glida isär: en
 * markör som sitter en kvart fel är en väljare som ljuger om vad ett klick
 * betyder.
 *
 * **Modulen räknar, komponenten ritar.** All omvandling bor i
 * resources/js/support/colorWheel.js (Beslut 2), och därför finns här ingen
 * aritmetik för färg alls — bara tre tal som flyttas.
 *
 * **Värdet ut är `#rrggbb` med gemener och ingenting annat** (Beslut 4).
 * Markören sänder `update:modelValue` medan den dras, så pricken i fältet
 * visar färgen medan användaren letar. Servern är oförändrad och förblir den
 * som avgör vad som är giltigt.
 *
 * **Ett ogiltigt värde i textfältet rör inte hjulet** (Beslut 5):
 * `hexToHsv` svarar `null`, och då står markören där den stod. Ett giltigt
 * värde flyttar den dit — också när det skrivs för hand, och därför läses
 * `modelValue` av varje gång den ändras och inte bara när hjulet öppnas.
 *
 * **Fokusfällan är webbläsarens** (issue 68b § Beslut 2): `showModal()` håller
 * fokus i dialogen och Esc fyrar `close`, precis som i UiSheet och
 * ParentPicker. Ingen egen tabbhanterare och inget `aria-modal`. Fokus
 * lämnas tillbaka till pricken genom `trigger`, som är elementet som öppnade
 * hjulet.
 *
 * **Ingen standardfärg hittas på.** Öppnas hjulet på ett fält utan färg finns
 * ingen markör alls — `hasColor` är falsk — och det första trycket eller
 * första piltangenten är det som sätter färgen. Att visa en markör i rött
 * hade varit att välja åt användaren, och det är hela Beslut 8 i issue 56a.
 *
 * Dialogen flyttas till `<body>`: fältet ritas inuti TagRows `<form>`, och
 * Enter inne i en dialog som ligger i ett formulär hade blivit en implicit
 * inlämning av hela raden.
 */
const props = defineProps({
    /* Fältets värde: `#rrggbb`, eller null/'' för ingen färg. */
    modelValue: { type: String, default: null },
    /* Elementet som öppnade hjulet: fokus tillbaka dit när det stängs. */
    trigger: { type: Object, default: null },
    /* Fältets id, så hjulets etiketter är unika i en lista av taggar. */
    id: { type: String, required: true },
});

const emit = defineEmits(['update:modelValue', 'close']);

const { t } = useTranslations();

const dialogElement = ref(null);
const wheelElement = ref(null);

/* Sant medan en pekare hålls nere. Med `setPointerCapture` fortsätter
   `pointermove` att komma till hjulet även när pekaren lämnat cirkeln. */
let dragging = false;

/* Nyans, mättnad och ljushet — hjulets enda tillstånd. Utgångspunkten är
   värdet fältet kom med, eller en omålad prick: nyans 0, ingen mättnad, full
   ljushet. */
const hsv = ref(hexToHsv(props.modelValue) ?? { h: 0, s: 0, v: 100 });

/* Sant när fältet bär en färg att rita. Ett tomt fält och ett ogiltigt värde
   är samma sak för hjulet: ingen markör, och inget val förrän användaren
   gör ett (Beslut 5 och 6). */
const hasColor = ref(hexToHsv(props.modelValue) !== null);

/* Värdet markören står på, som hex. Används bara när `hasColor` är sann. */
const currentHex = computed(() => hsvToHex(hsv.value.h, hsv.value.s, hsv.value.v));

/* Det senast utsända värdet, så vår egen skrivning inte läses tillbaka som en
   ändring utifrån och flyttar markören ett steg. */
let sent = null;

watch(
    () => props.modelValue,
    (value) => {
        if (value === sent) {
            return;
        }

        const parsed = hexToHsv(value);

        if (parsed === null) {
            return;
        }

        hsv.value = parsed;
        hasColor.value = true;
    },
);

onMounted(() => {
    dialogElement.value?.showModal();

    /* Fokus till hjulet och inte till tryckytan utanför panelen, som är
       dialogens första tabbbara element — en stängknapp i full skärmstorlek
       som ingen ser. Härifrån når tangentbordet färgen med en gång. */
    wheelElement.value?.focus();
});

function close() {
    dialogElement.value?.close();
}

/*
 * Stängningen, oavsett väg in: Esc fyrar `close` precis som *Klar* och
 * trycket utanför gör. Fokus tillbaka till pricken — utan den står fokus
 * kvar i en dialog som inte finns.
 */
function onClosed() {
    emit('close');

    props.trigger?.focus();
}

/*
 * Ett val skickas vidare och sparas lokalt. Markören får sin plats direkt och
 * inte först när föräldern svarat: `hasColor` sätts här, så det första
 * trycket i ett tomt fält ritar markören i samma bildruta som det skedde.
 *
 * Nyansen viras runt ett varv innan den sparas. Både `atan2` och
 * vänsterpilen kan ge ett negativt tal, och `aria-valuenow` ska ligga i det
 * intervall `aria-valuemin` och `aria-valuemax` lovar. `hsvToHex` hade virat
 * talet själv, men tillståndet här hade burit ett värde utanför skalan — och
 * markören hade stått rätt medan skärmläsaren läste fel.
 */
function commit(next) {
    hsv.value = {
        h: ((next.h % 360) + 360) % 360,
        s: next.s,
        v: next.v,
    };
    hasColor.value = true;
    sent = hsvToHex(hsv.value.h, hsv.value.s, hsv.value.v);

    emit('update:modelValue', sent);
}

/*
 * Pekarens plats som nyans och mättnad. Vinkeln blir nyansen och avståndet
 * från mitten blir mättnaden, klippt till kanten — den som drar utanför
 * cirkeln får full mättnad och den nyans hon pekar åt, i stället för ett
 * värde utanför skalan.
 */
function pickFromPointer(event) {
    const wheel = wheelElement.value;

    if (wheel === null) {
        return;
    }

    const rect = wheel.getBoundingClientRect();
    const radius = rect.width / 2;
    const dx = event.clientX - (rect.left + radius);
    const dy = event.clientY - (rect.top + radius);

    commit({
        h: (Math.atan2(dy, dx) * 180) / Math.PI,
        s: Math.min(1, Math.hypot(dx, dy) / radius) * 100,
        v: hsv.value.v,
    });
}

function onPointerDown(event) {
    dragging = true;
    wheelElement.value?.setPointerCapture(event.pointerId);
    pickFromPointer(event);
}

function onPointerMove(event) {
    if (! dragging) {
        return;
    }

    pickFromPointer(event);
}

function onPointerUp(event) {
    dragging = false;

    if (wheelElement.value?.hasPointerCapture(event.pointerId)) {
        wheelElement.value.releasePointerCapture(event.pointerId);
    }
}

/*
 * Piltangenterna flyttar markören: vänster och höger runt kanten, upp och ned
 * in och ut. Steget är större med skift nedtryckt, som i ett vanligt reglage.
 * Ljusheten har sitt eget reglage och styrs inte härifrån.
 */
function onKeydown(event) {
    const step = event.shiftKey ? 10 : 2;
    const next = { ...hsv.value };

    if (event.key === 'ArrowLeft') {
        next.h -= step;
    } else if (event.key === 'ArrowRight') {
        next.h += step;
    } else if (event.key === 'ArrowUp') {
        next.s = Math.min(100, next.s + step);
    } else if (event.key === 'ArrowDown') {
        next.s = Math.max(0, next.s - step);
    } else {
        return;
    }

    event.preventDefault();
    commit(next);
}

/*
 * Nyansringen längs kanten och vit över mitten. Stoppen var 60:e grad och
 * inte tätare: nyansrampen i RGB är rät mellan varje sådant steg, så
 * gradienten är exakt och inte en avrundning.
 */
const wheelBackground = `radial-gradient(circle closest-side, #ffffff, rgba(255, 255, 255, 0)), conic-gradient(from 90deg, ${
    [0, 60, 120, 180, 240, 300, 360].map((angle) => `${hsvToHex(angle, 100, 100)} ${angle}deg`).join(', ')
})`;

/* Markörens plats i procent av cirkeln: nyansen är vinkeln, mättnaden
   avståndet från mitten. Samma vinkel som gradientens `from 90deg`. */
const markerStyle = computed(() => {
    const radians = (hsv.value.h * Math.PI) / 180;
    const distance = hsv.value.s / 100;

    return {
        left: `${50 + distance * 50 * Math.cos(radians)}%`,
        top: `${50 + distance * 50 * Math.sin(radians)}%`,
    };
});

function onLightness(event) {
    commit({ ...hsv.value, v: Number(event.target.value) });
}
</script>

<template>
    <Teleport to="body">
        <dialog
            ref="dialogElement"
            :aria-label="t('container.tags.color_heading')"
            class="m-0 h-full max-h-none w-full max-w-none bg-transparent p-0 backdrop:bg-ink/50"
            @close="onClosed"
        >
            <div class="flex h-full w-full flex-col justify-end md:items-center md:justify-center">
                <!--
                    Ytan utanför panelen. Dialogen täcker hela skärmen, så
                    trycket utanför landar här och inte i en bakgrund utan
                    mål.
                -->
                <button
                    type="button"
                    class="absolute inset-0 min-h-11"
                    :aria-label="t('container.tags.color_close')"
                    @click="close"
                ></button>

                <div
                    class="relative flex max-h-full w-full flex-col gap-3 overflow-y-auto rounded-t-card bg-surface p-4 md:w-80 md:rounded-card md:border md:border-border"
                >
                    <h2 class="text-title font-semibold text-ink">
                        {{ t('container.tags.color_heading') }}
                    </h2>

                    <div class="flex justify-center">
                        <!--
                            `touch-action: none` står som ett stilattribut och
                            inte som en Tailwind-klass: det är regeln som
                            hindrar sidan från att rulla medan pekaren drar i
                            hjulet, och den ska gå att läsa rakt av i filen.
                        -->
                        <div
                            ref="wheelElement"
                            role="slider"
                            tabindex="0"
                            :aria-label="t('container.tags.color_wheel')"
                            :aria-valuemin="0"
                            :aria-valuemax="360"
                            :aria-valuenow="Math.round(hsv.h)"
                            :aria-valuetext="hasColor ? currentHex : t('container.tags.no_color')"
                            style="touch-action: none"
                            class="relative h-48 w-48 cursor-crosshair select-none rounded-full border border-border outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                            :style="{ backgroundImage: wheelBackground }"
                            @pointerdown="onPointerDown"
                            @pointermove="onPointerMove"
                            @pointerup="onPointerUp"
                            @pointercancel="onPointerUp"
                            @keydown="onKeydown"
                        >
                            <span
                                v-if="hasColor"
                                aria-hidden="true"
                                class="pointer-events-none absolute h-4 w-4 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white shadow ring-1 ring-ink"
                                :style="markerStyle"
                            />
                        </div>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label :for="`${id}-lightness`" class="text-body text-ink-muted">
                            {{ t('container.tags.color_lightness') }}
                        </label>

                        <input
                            :id="`${id}-lightness`"
                            type="range"
                            min="0"
                            max="100"
                            :value="hsv.v"
                            class="w-full"
                            @input="onLightness"
                        >
                    </div>

                    <button
                        type="button"
                        class="inline-flex min-h-11 items-center justify-center self-end rounded-control bg-accent px-4 font-medium text-ink-on-accent"
                        @click="close"
                    >
                        {{ t('container.tags.color_done') }}
                    </button>
                </div>
            </div>
        </dialog>
    </Teleport>
</template>
