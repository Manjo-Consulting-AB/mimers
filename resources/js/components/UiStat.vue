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
 */
defineProps({
    value: { type: [Number, String], required: true },
    label: { type: String, required: true },
});
</script>

<template>
    <div class="flex min-w-32 flex-col rounded-card border border-border bg-surface px-4 py-3">
        <span class="text-heading font-semibold text-ink">{{ value }}</span>
        <span class="text-meta text-ink-subtle">{{ label }}</span>
    </div>
</template>
