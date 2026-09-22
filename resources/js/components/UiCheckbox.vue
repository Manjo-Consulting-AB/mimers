<script setup>
import { computed } from 'vue';

/*
 * Kryssrutan, se issue 98 och [[ADR-0042 Designsystemet]] § Beslut.
 *
 * **Etiketten ligger i slotten och `for`/`id` binder den till rutan.**
 * FormField äger etiketten ovan fältet (issue 51 § Beslut 9); en kryssruta
 * bär sin text bredvid sig, och då är `<label for>` bindningen i stället.
 * Rutan får sin text av anroparen — `item.form.tags` ritar taggens namn och
 * färgprick i slotten — och kontrollen känner inte till något av dem.
 *
 * **Två modeller, en komponent.** `modelValue` är en boolean för en enskild
 * ruta och en array för en grupp; `value` är vad rutan bidrar med till
 * arrayen. En taggmängd är en array av ULID:er, och `tags: []` tömmer den
 * (issue 57b § Beslut 6) — en boolean hade inte kunnat uttrycka det.
 *
 * Ingen validering bor här, och inget eget felmeddelande: kryssrutan är en
 * grupp och inte ett fält, så gruppens fel ritas av anroparen på samma sätt
 * som FormField ritar fältets.
 *
 * Fokusringen är `--color-focus` och får aldrig tas bort; skälet står i
 * FormField och UiButton. Den är `focus-visible:`, som på knappen och de tre
 * andra kontrollerna — rutan slipper ringen efter ett musklick.
 */
const props = defineProps({
    id: { type: String, required: true },
    modelValue: { type: [Array, Boolean], default: false },
    /* Värdet rutan bidrar med när modellen är en array. */
    value: { type: String, default: null },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const checked = computed(() => (Array.isArray(props.modelValue)
    ? props.modelValue.includes(props.value)
    : props.modelValue === true));

function toggle(event) {
    if (! Array.isArray(props.modelValue)) {
        emit('update:modelValue', event.target.checked);

        return;
    }

    const utan = props.modelValue.filter((varde) => varde !== props.value);

    emit('update:modelValue', event.target.checked ? [...utan, props.value] : utan);
}
</script>

<template>
    <label :for="id" class="flex min-h-11 items-center gap-2 text-body text-ink">
        <input
            :id="id"
            type="checkbox"
            :checked="checked"
            :disabled="disabled"
            class="size-4 shrink-0 accent-accent outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 disabled:opacity-50"
            @change="toggle"
        >

        <slot />
    </label>
</template>
