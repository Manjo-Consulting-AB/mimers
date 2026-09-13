<script setup>
import { computed } from 'vue';

/*
 * Formulärfältet, se issue 51 § Beslut 9.
 *
 * Ett fält är en label, en inmatning och serverns fel — ingenting annat, och
 * ingen klientvalidering. `error` kommer ur `form.errors.<fält>`, som
 * Inertia fyller från sessionens felpåse när en FormRequest nekar.
 *
 * Slotten får `describedBy`: felets id när felet finns, annars undefined.
 * Inmatningen i slotten sätter `:aria-describedby="describedBy"` så
 * skärmläsaren läser felet tillsammans med fältet — det är enda skälet
 * komponenten alls behöver veta vad inmatningen heter. Mönstret, komplett:
 *
 *   <FormField v-slot="{ describedBy }" label="E-post" id="email" :error="form.errors.email">
 *       <input id="email" :aria-describedby="describedBy" v-model="form.email" type="email" required>
 *   </FormField>
 *
 * `required` i markupen är tillåtet — det ger tangentbords- och
 * skärmläsarstöd. En egen regel i JavaScript är det inte: valideringen bor
 * på servern, i samma FormRequest som /api använder
 * ([[ADR-0021 Frontendteknik]]). Den som skriver `<input>` plus en egen
 * `<p v-if="errors.x">` i sin vy har byggt den sextonde varianten.
 */
const props = defineProps({
    label: { type: String, required: true },
    id: { type: String, required: true },
    error: { type: String, default: null },
});

const describedBy = computed(() => (props.error ? `${props.id}-error` : undefined));
</script>

<template>
    <div class="flex flex-col gap-1">
        <label :for="id" class="text-sm font-medium text-slate-800">{{ label }}</label>

        <slot :described-by="describedBy" />

        <p v-if="error" :id="`${id}-error`" class="text-sm text-red-700">{{ error }}</p>
    </div>
</template>
