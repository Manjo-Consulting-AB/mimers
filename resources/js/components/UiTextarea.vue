<script setup>
import { computed } from 'vue';

/*
 * Textrutan, se issue 98 § Beslut och [[ADR-0042 Designsystemet]] § Beslut.
 *
 * Samma kontrakt som UiInput: etiketten, felet och `aria-describedby` ägs av
 * FormField (issue 51 § Beslut 9), ingen validering bor här, och `rows`
 * bestämmer höjden — `description` och `notes` är TEXT utan längdregel, så
 * det finns ingen `maxlength` att sätta och ingen gräns att rita.
 *
 * Fokusringen är `--color-focus` och får aldrig tas bort; skälet står i
 * FormField och UiButton.
 */
const props = defineProps({
    id: { type: String, required: true },
    describedBy: { type: String, default: null },
    modelValue: { type: String, default: '' },
    rows: { type: [String, Number], default: 4 },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const value = computed({
    get: () => props.modelValue,
    set: (varde) => emit('update:modelValue', varde),
});
</script>

<template>
    <textarea
        :id="id"
        v-model="value"
        :rows="rows"
        :aria-describedby="describedBy"
        :disabled="disabled"
        class="rounded-control border border-border bg-surface px-3 py-2 text-body text-ink outline-none focus:ring-2 focus:ring-focus focus:ring-offset-2 disabled:bg-surface-sunken"
    />
</template>
