<script setup>
import { computed, h, shallowRef, watch } from 'vue';
import UiIcon from './UiIcon.vue';
import { ICON_COLORS, loadIcon } from './itemIconPresentation.js';

/*
 * Itemets ikon, se issue 300 och [[ADR-0060 Itemets ikon]] § 1–6.
 *
 * **Namnet är ett Lucide-namn, inte en roll.** `name` slås upp i globben i
 * `itemIconPresentation.js` och ritas med `h()` i samma nodform som `UiIcon`:
 * `[tag, attribut]`, aldrig en sträng med markup och aldrig `v-html`.
 *
 * **Ikonen laddas när den ritas.** Globben är lat, så den här komponenten är
 * det enda stället en item-ikon hämtas. Medan filen hämtas, och när namnet är
 * `null` eller inte finns, ritas `UiIcon` med rollen `item` i den neutrala
 * tonen — i samma storlek som den färdiga ikonen, så att raden inte hoppar.
 *
 * **Färgen är en palettnyckel.** `color` är en av de åtta i `ICON_COLORS`;
 * en saknad eller okänd nyckel ritas som `slate` (ADR-0060 § 5). Med `tile`
 * ritas rutan i `--color-tint-<nyckel>-soft` och linjen i
 * `--color-tint-<nyckel>`. Utan `tile` får linjen färgen och ingen ruta ritas.
 *
 * **Storleken, linjebredden och `aria-hidden` följer `UiIcon`.** Tre
 * storlekar, 1,75 i linjebredd och alltid `aria-hidden` (ADR-0057 § 2).
 */
const props = defineProps({
    name: { type: String, default: null },
    color: { type: String, default: null },
    size: { type: Number, default: 20 },
    tile: { type: Boolean, default: false },
});

const SIZES = [16, 20, 24];

/*
 * Klasserna står som hela strängar: Tailwind läser källkoden statiskt och ser
 * inte en klass som byggs med mallsträng. Nycklarna är paletten ur ADR-0060
 * § 5 och pekar på `--color-tint-*` i `resources/css/app.css`.
 */
const TONES = {
    slate: { line: 'text-tint-slate', box: 'bg-tint-slate-soft' },
    blue: { line: 'text-tint-blue', box: 'bg-tint-blue-soft' },
    sky: { line: 'text-tint-sky', box: 'bg-tint-sky-soft' },
    teal: { line: 'text-tint-teal', box: 'bg-tint-teal-soft' },
    green: { line: 'text-tint-green', box: 'bg-tint-green-soft' },
    amber: { line: 'text-tint-amber', box: 'bg-tint-amber-soft' },
    orange: { line: 'text-tint-orange', box: 'bg-tint-orange-soft' },
    violet: { line: 'text-tint-violet', box: 'bg-tint-violet-soft' },
};

const nodes = shallowRef(null);

// Tre storlekar och ingen fjärde, som UiIcon. Ett värde utanför listan faller
// tillbaka på förvalet i stället för att ritas i en storlek ingen beslutat.
const size = computed(() => (SIZES.includes(props.size) ? props.size : 20));

const color = computed(() => (ICON_COLORS.includes(props.color) ? props.color : 'slate'));
const tone = computed(() => TONES[color.value]);

const icon = computed(() => {
    if (!nodes.value) {
        return null;
    }

    return h(
        'svg',
        {
            xmlns: 'http://www.w3.org/2000/svg',
            width: size.value,
            height: size.value,
            viewBox: '0 0 24 24',
            fill: 'none',
            stroke: 'currentColor',
            'stroke-width': 1.75,
            'stroke-linecap': 'round',
            'stroke-linejoin': 'round',
            'aria-hidden': 'true',
            class: tone.value.line,
        },
        nodes.value.map(([tag, attributes]) => h(tag, attributes)),
    );
});

watch(
    () => props.name,
    async (name) => {
        // Fallbacken ritas direkt; en ikon som byts ut får aldrig lämna kvar
        // den förra. Samma storlek i alla lägen gör att raden står still.
        nodes.value = null;

        const loader = loadIcon(name);

        if (!loader) {
            return;
        }

        const loaded = await loader();

        // Ett namn som hann bytas medan filen hämtades ska inte skrivas över.
        if (props.name === name) {
            nodes.value = loaded;
        }
    },
    { immediate: true },
);
</script>

<template>
    <span
        v-if="tile && icon"
        class="inline-flex items-center justify-center rounded-control p-1.5"
        :class="tone.box"
    >
        <component :is="icon" />
    </span>
    <UiIcon v-else-if="tile" name="item" :size="size" tile="neutral" />
    <component :is="icon" v-else-if="icon" />
    <UiIcon v-else name="item" :size="size" class="text-ink-muted" />
</template>
