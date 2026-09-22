<script setup>
import { computed } from 'vue';

/*
 * Tom-tillståndet, se issue 99 och [[ADR-0042 Designsystemet]] § Beslut.
 *
 * **Två lägen, och skillnaden är om något alls finns eller om inget
 * matchar.** `empty` är första gången: här finns ingenting än, och ytan
 * bjuder in till att skapa det första — den bär en ram och en åtgärd.
 * `filtered` är svaret på en fråga som inte gav träff: här FINNS rader, och
 * det är filtret som ska ändras. Den är tystare, för ett tomt filter är inte
 * ett tomt konto, och att rita dem lika hade sagt till en användare med
 * fyrtio items att hon inte har några.
 *
 * **Orden kommer ur slotarna.** Komponenten vet inte vad som saknas; den vet
 * bara att något gör det. Anroparen skickar `t()`-strängarna — ingen text
 * står i den här filen (SprakTest), och ingen sträng byggs ur ett värde.
 */
const props = defineProps({
    mode: { type: String, default: 'empty' },
});

const MODES = {
    empty: 'gap-2 rounded-card border border-dashed border-border bg-surface px-6 py-10',
    filtered: 'gap-1 rounded-card bg-surface-muted px-4 py-6',
};

const classes = computed(() => MODES[props.mode]);
</script>

<template>
    <div class="flex flex-col items-center text-center" :class="classes">
        <p class="text-title text-ink">
            <slot name="title" />
        </p>

        <p class="text-body text-ink-muted">
            <slot />
        </p>

        <div v-if="$slots.action" class="mt-2">
            <slot name="action" />
        </div>
    </div>
</template>
