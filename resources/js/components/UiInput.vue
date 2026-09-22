<script setup>
import { computed } from 'vue';

/*
 * Inmatningsfältet, se issue 98 och [[ADR-0042 Designsystemet]]
 * § Beslut.
 *
 * **Kontrollen fyller FormField, den ersätter den inte.** Etiketten, felet
 * och `aria-describedby` ägs av FormField (issue 51 § Beslut 9), och
 * ordningen står kvar: `:described-by` in i kontrollen, `:aria-describedby`
 * ut på elementet. Kontrollen vet inte vad fältet heter — den vet bara att
 * ett fel finns och vad det har för id.
 *
 * **Ingen validering bor här.** `required`, `min` och `max` faller rakt
 * igenom till elementet: de är markup och ger tangentbords- och
 * skärmläsarstöd. En regel i JavaScript är det inte. Reglerna bor i samma
 * FormRequest som `/api` använder ([[ADR-0021 Frontendteknik]]), och svaret
 * kommer tillbaka i felpåsen och ritas av FormField.
 *
 * **`v-model` internt och inte `:value` + `@input`.** vModelText gör ett
 * `null` till en tom sträng, och `null` är vad ett tomt datumfält bär i
 * formuläret — utan det hade `<input type="date">` visat ordet "null".
 *
 * Fokusringen är `--color-focus`, två pixlar med två pixlars förskjutning,
 * och får aldrig tas bort; skälet står i FormField och UiButton. Den är
 * `focus-visible:`, som på knappen och de tre andra kontrollerna.
 */
const props = defineProps({
    id: { type: String, required: true },
    describedBy: { type: String, default: null },
    modelValue: { type: [String, Number], default: '' },
    type: { type: String, default: 'text' },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const value = computed({
    get: () => props.modelValue,
    set: (varde) => emit('update:modelValue', varde),
});
</script>

<template>
    <input
        :id="id"
        v-model="value"
        :type="type"
        :aria-describedby="describedBy"
        :disabled="disabled"
        class="min-h-11 rounded-control border border-border bg-surface px-3 py-2 text-body text-ink outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 disabled:bg-surface-sunken"
    >
</template>
