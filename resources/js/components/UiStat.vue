<script setup>
/*
 * Taltutan, se issue 99 och [[ADR-0042 Designsystemet]] § Beslut.
 *
 * **Den ritar ett tal, den räknar det inte.** `value` kommer färdig ur
 * anroparens prop — containerns hjälte får sina ur `counts` i
 * App\Http\Controllers\ContainerController::show() (issue 89), samma tal som
 * översikten alltid visat. Ingen fråga ställs här och ingen `SUM` räknas:
 * en taltuta som räknade själv hade varit en andra väg till samma siffra, och
 * de två hade glidit isär ([[ADR-0039 Containerns översikt]]
 * § Konsekvenser).
 *
 * **Talet är `heading`-steget och etiketten `meta`.** Det är skillnaden mot
 * en bricka: brickan bär ett tillstånd i ett ord, taltutan en siffra med sitt
 * namn under. Etiketten är en färdig sträng och ingen nyckel — uppslaget gör
 * anroparen, för det är den som vet vad talet heter.
 *
 * Talet är ett `<span>` och inte en rubrik: siffran är innehåll, inte ytans
 * struktur, och sex taltutor hade gett sex rubriknivåer i dokumentordningen.
 *
 * **`icon` och `tone` kom med issue 283.** `icon` ritar en `UiIcon` i 24px
 * före talet; utan den ritas taltutan med samma markup som förut.
 * `tone="on-image"` är formen för en taltuta ovanpå ett foto: glaset ur
 * [[ADR-0057 Ikoner och typsnitt]] § 6 — `--color-glass` med
 * `--color-glass-border` — och texten i `--color-ink-on-accent`.
 */
import { computed } from 'vue';
import UiIcon from './UiIcon.vue';

const props = defineProps({
    value: { type: [Number, String], required: true },
    label: { type: String, required: true },
    icon: { type: String, default: null },
    tone: { type: String, default: null },
});

const TONES = {
    surface: {
        box: 'border-border bg-surface',
        value: 'text-ink',
        label: 'text-ink-subtle',
    },
    'on-image': {
        box: 'border-glass-border bg-glass',
        value: 'text-ink-on-accent',
        label: 'text-ink-on-accent',
    },
};

const toneClasses = computed(() => TONES[props.tone] ?? TONES.surface);
</script>

<template>
    <div class="flex min-w-32 flex-col rounded-card border px-4 py-3" :class="toneClasses.box">
        <span
            v-if="icon"
            class="flex items-center gap-2 text-heading font-semibold"
            :class="toneClasses.value"
        >
            <UiIcon :name="icon" :size="24" />
            {{ value }}
        </span>
        <span v-else class="text-heading font-semibold" :class="toneClasses.value">{{ value }}</span>

        <span class="text-meta" :class="toneClasses.label">{{ label }}</span>
    </div>
</template>
