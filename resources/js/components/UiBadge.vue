<script setup>
import { computed } from 'vue';

/*
 * Brickan — status och kategori, se issue 99 och [[ADR-0042 Designsystemet]]
 * § Beslut.
 *
 * **Fyra tillstånd: `ok`, `warning`, `danger` och `neutral`.** De är ADR:ens
 * egna färgroller — `--color-success`, `--color-warning`, `--color-danger`
 * och den tysta metarollen. Ett femte tillstånd är en nyans någon hittade på,
 * inte en bricka.
 *
 * **Brickan är ett ORD med en färg, aldrig en färg med ett ord i.** Den som
 * inte ser nyansen ska kunna läsa raden (issue 68b § Beslut 7), och texten
 * kommer därför ur slotten och aldrig ur en ikon.
 *
 * **Ytan är `--color-surface-sunken` i alla fyra.** `@theme` har ingen ljus
 * variant av fara eller varning, och `resources/css/app.css` ligger utanför
 * den här issuen: en egen ljus nyans här hade varit en femte färg vid sidan
 * av tokens, alltså exakt det rollnamnen finns för att slippa. Det är texten
 * som bär rollen.
 */
const props = defineProps({
    state: { type: String, default: 'neutral' },
});

const STATES = {
    ok: 'text-success',
    warning: 'text-warning',
    danger: 'text-danger',
    neutral: 'text-ink-muted',
};

const classes = computed(() => STATES[props.state]);
</script>

<template>
    <span
        class="inline-flex items-center rounded-pill border border-border bg-surface-sunken px-2 py-0.5 text-meta font-medium"
        :class="classes"
    >
        <slot />
    </span>
</template>
