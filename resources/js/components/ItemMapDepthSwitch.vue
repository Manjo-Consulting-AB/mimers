<script setup>
import ItemViewSwitch from './ItemViewSwitch.vue';

/*
 * Zoomnivåerna i den fulla kartan, *Focus · Neighbourhood* — se issue 393 ·
 * [[ADR-0082 Kartan är relationernas yta]] § 4 och 7.
 *
 * **Samma växel som `ItemViewSwitch`, med samma mönster:** nivån är en adress
 * (`depth`) och inget tillstånd, och servern har redan läst den. Komponenten
 * lägger bara på regeln att nivåerna finns från `md:` — under `md:` ritas ingen
 * växel, eftersom kartan där alltid är *Focus*. Orden kommer som proppar, så
 * att ingen text bor här.
 */
defineProps({
    /* Nivåerna i ritad ordning: `{ key, label, href }`. */
    levels: { type: Array, required: true },
    /* Nyckeln för nivån som gäller. */
    current: { type: String, required: true },
    /* Gruppens tillgängliga namn. */
    label: { type: String, required: true },
});
</script>

<template>
    <div class="hidden md:block">
        <ItemViewSwitch :views="levels" :current="current" :label="label" />
    </div>
</template>
