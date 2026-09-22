<script setup>
import { computed } from 'vue';

/*
 * Väljaren, se issue 98 § Beslut och [[ADR-0042 Designsystemet]] § Beslut.
 *
 * Alternativen kommer i slotten — kontrollen känner inte till dem, och ska
 * inte göra det: `categoryTree.js`, `AccessLevel::LADDER` och itemets
 * bildlista bygger var sin lista, och en kontroll som byggde en fjärde vore
 * en andra sanning om vad som går att välja.
 *
 * **`v-model` internt, av samma skäl som i UiInput men med ett till:**
 * `setSelected` jämför alternativens värden med det bundna värdet, och det
 * är vad som gör `<option :value="null">` vald när modellen är `null`. Ett
 * rå `:value` på ett `<select>` sätter DOM-värdet till strängen "null", och
 * då står väljaren tom i stället för på *Ingen kategori*.
 *
 * Etiketten, felet och `aria-describedby` ägs av FormField (issue 51
 * § Beslut 9), och ingen validering bor här: `required` är markup, reglerna
 * bor i samma FormRequest som `/api` använder.
 *
 * Fokusringen är `--color-focus` och får aldrig tas bort; skälet står i
 * FormField och UiButton.
 */
const props = defineProps({
    id: { type: String, required: true },
    describedBy: { type: String, default: null },
    modelValue: { type: [String, Number], default: null },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const value = computed({
    get: () => props.modelValue,
    set: (varde) => emit('update:modelValue', varde),
});
</script>

<template>
    <select
        :id="id"
        v-model="value"
        :aria-describedby="describedBy"
        :disabled="disabled"
        class="min-h-11 rounded-control border border-border bg-surface px-3 py-2 text-body text-ink outline-none focus:ring-2 focus:ring-focus focus:ring-offset-2 disabled:bg-surface-sunken"
    >
        <slot />
    </select>
</template>
