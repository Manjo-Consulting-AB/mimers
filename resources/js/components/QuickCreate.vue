<script setup>
import { ref, useId } from 'vue';
import UiIcon from './UiIcon.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Snabbraden, se [[ADR-0074 Registrering i vyn]] § 1–3 · M52 · issue 356.
 *
 * **Presentation och inget annat.** Skalet vet ingenting om uppgifter, items
 * eller filer: det postar inget, har ingen `useForm` och ingen rutt. Vyn äger
 * värdet (`v-model`), skrivvägen och felet, och svarar på `submit`.
 *
 * **`submit` skickas bara för ett värde som inte är tomt efter `trim()` och
 * bara när `processing` är falskt** — ett tomt Enter och ett dubbeltryck
 * under pågående skrivning når aldrig vyn.
 *
 * **Escape tömmer först fältet och tar sedan bort fokus** (Beslut 3): är
 * fältet ifyllt skickas `update:modelValue` med `''`, är det tomt går fokus.
 *
 * **Slotten `leading`** ligger till vänster om fältet; där lägger vyn sitt
 * platsval. `focus()` är exponerad så att vyn kan hålla fokus i fältet efter
 * en lyckad sparning.
 *
 * Råa `<button>` och inte `UiButton`, av samma skäl som i `Tasks/Create.vue`:
 * GenomgangTest tillåter bara webbläsarens egna element som klickbar yta.
 * Klasserna följer designsystemets formspråk.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    /* Fältets `aria-label`, färdigöversatt av vyn. */
    label: { type: String, required: true },
    placeholder: { type: String, default: '' },
    /* Sparknappens text, färdigöversatt av vyn. */
    submitLabel: { type: String, required: true },
    /* Sant medan vyns skrivning pågår. */
    processing: { type: Boolean, default: false },
    /* Felet under raden, eller null. */
    error: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue', 'submit']);

const { t } = useTranslations();

const uid = useId();
const field = ref(null);

function focus() {
    field.value?.focus();
}

function onSubmit() {
    if (props.processing || props.modelValue.trim() === '') {
        return;
    }

    emit('submit');
}

function onKeydown(event) {
    if (event.key !== 'Escape') {
        return;
    }

    if (props.modelValue !== '') {
        emit('update:modelValue', '');

        return;
    }

    field.value?.blur();
}

defineExpose({ focus });
</script>

<template>
    <div>
        <form
            class="flex min-h-11 items-center gap-2 rounded-control border border-border bg-surface px-3"
            @submit.prevent="onSubmit"
        >
            <slot name="leading" />

            <UiIcon name="add" :size="16" class="shrink-0 text-ink-muted" />

            <input
                :id="`${uid}-field`"
                ref="field"
                type="text"
                :value="modelValue"
                :aria-label="label"
                :placeholder="placeholder"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="error ? `${uid}-error` : undefined"
                class="min-h-11 min-w-0 flex-1 bg-transparent text-body text-ink outline-none placeholder:text-ink-muted"
                @input="emit('update:modelValue', $event.target.value)"
                @keydown="onKeydown"
            />

            <button
                type="submit"
                :disabled="processing"
                class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-control px-3 text-meta font-medium text-accent outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus disabled:opacity-60"
            >
                {{ processing ? t('common.pending.default') : submitLabel }}
            </button>
        </form>

        <p
            v-if="error"
            :id="`${uid}-error`"
            role="alert"
            class="mt-1 text-meta text-danger"
        >
            {{ error }}
        </p>
    </div>
</template>
