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
 *
 * `tabindex="-1"` på felmeddelandet sedan issue 53a § Beslut 10: ett
 * element utan tabindex går inte att sätta fokus på, och det är hit fokus
 * ska när servern svarar — se resources/js/pages/Auth/useErrorFocus.js.
 * Fältet blir inte tabbbart av det, bara fokuserbart med kod.
 *
 * **Fokusringen på felmeddelandet får aldrig tas bort.** `outline-none` utan
 * en ring som tar över lämnar fokus osynligt: den som använder tangentbord
 * eller skärmläsare får ingen signal om var svaret hamnade. Issue 68a och 68b
 * gick igenom hela frontenden med tangentbord, och en enda nollställd outline
 * river det arbetet. Ringen är `--color-focus` ur
 * [[ADR-0042 Designsystemet]] § Beslut — två pixlar med två pixlars
 * förskjutning — och `focus:` och inte `focus-visible:` därför att fokuset
 * sätts av kod och inte av en tabb; `:focus-visible` behöver inte slå till
 * alls för ett anrop till `.focus()`.
 *
 * Färgerna kommer ur samma ADR: `text-ink` för etiketten, `text-danger` för
 * felet.
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
        <label :for="id" class="text-body font-medium text-ink">{{ label }}</label>

        <slot :described-by="describedBy" />

        <p
            v-if="error"
            :id="`${id}-error`"
            tabindex="-1"
            class="text-body text-danger outline-none focus:ring-2 focus:ring-focus focus:ring-offset-2"
        >
            {{ error }}
        </p>
    </div>
</template>
