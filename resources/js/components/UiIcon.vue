<script setup>
import { computed, h } from 'vue';
import { icons } from '../icons/index.js';

/*
 * Ikonen, se issue 282 och [[ADR-0057 Ikoner och typsnitt]] § 2.
 *
 * **Rollen är nyckeln, aldrig Lucides namn.** `name` slås upp i den slutna
 * listan i `resources/js/icons/index.js`; en roll som inte finns ritas inte.
 * Provet i `tests/Feature/Frontend/IkonerTest.php` fäller ett `name` som
 * saknas i listan.
 *
 * **Storleken, linjebredden och färgen bor här.** Tre storlekar: 16px för
 * metarader, 20px som förval och 24px för taltutor och hjälten. Linjebredden
 * är 1,75 och inte Lucides förval på 2 — bildens linjer är tunnare — och
 * `currentColor` gör att ikonen följer textens roll.
 *
 * **Alltid `aria-hidden`.** En ikon upprepar alltid en text, och en knapp med
 * bara en ikon får sin etikett av den som ritar knappen, aldrig härifrån.
 *
 * **`tile` ritar rutans kvadrat och bär tonen.** Värdet är en av de fem
 * tonerna — `accent`, `success`, `warning`, `danger` eller `neutral` — och
 * tonen följer en regel, datumet eller domänen, aldrig omväxlingen.
 * Klasserna pekar på tokens ur `resources/css/app.css`: `accent` och
 * `neutral` på `--color-accent-soft` och `--color-surface-sunken`, de övriga
 * på de mjuka tonerna ur § 6.
 *
 * **Ingen `v-html`.** Noderna ur listan ritas med `h()`.
 */
const props = defineProps({
    name: { type: String, required: true },
    size: { type: Number, default: 20 },
    tile: { type: String, default: null },
});

const SIZES = [16, 20, 24];

const TONES = {
    accent: 'bg-accent-soft text-accent',
    success: 'bg-success-soft text-success',
    warning: 'bg-warning-soft text-warning',
    danger: 'bg-danger-soft text-danger',
    neutral: 'bg-surface-sunken text-ink-muted',
};

const icon = computed(() => {
    const nodes = icons[props.name];

    if (!nodes) {
        return null;
    }

    // Tre storlekar och ingen fjärde. Ett värde utanför listan faller tillbaka
    // på förvalet i stället för att ritas i en storlek ingen har beslutat.
    const size = SIZES.includes(props.size) ? props.size : 20;

    return h(
        'svg',
        {
            xmlns: 'http://www.w3.org/2000/svg',
            width: size,
            height: size,
            viewBox: '0 0 24 24',
            fill: 'none',
            stroke: 'currentColor',
            'stroke-width': 1.75,
            'stroke-linecap': 'round',
            'stroke-linejoin': 'round',
            'aria-hidden': 'true',
        },
        nodes.map(([tag, attributes]) => h(tag, attributes)),
    );
});

const tileClasses = computed(() => TONES[props.tile] ?? TONES.neutral);
</script>

<template>
    <span
        v-if="tile"
        class="inline-flex items-center justify-center rounded-control p-1.5"
        :class="tileClasses"
    >
        <component :is="icon" v-if="icon" />
    </span>
    <component :is="icon" v-else-if="icon" />
</template>
