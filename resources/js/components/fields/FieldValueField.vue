<script setup>
import { computed } from 'vue';
import FormField from '../FormField.vue';
import UiInput from '../UiInput.vue';
import { fieldInputType } from './fieldPresentation.js';

/*
 * Ett eget fält i formuläret: rubriken, inmatningen och serverns fel, se
 * issue 419 och [[ADR-0089 Egna fält på items]] § Riktning 1–3.
 *
 * Rubriken är användarens egen text och kommer ur fältet, aldrig ur `lang/` —
 * systemet vet inte vad *Liter* eller *Årgång* betyder. Därför ritas ingen
 * etikett här och ingen översättning slås upp: `field.label` står som den är.
 *
 * Inmatningen väljs av typen (`fieldInputType()` i fieldPresentation.js): ett
 * talfält är `type="number"` med `step="any"` — ett tal med decimaler ska
 * gå att skriva, och utan `step` hade webbläsaren krävt heltal — och ett
 * datumfält är webbläsarens datumväljare, som skickar `Y-m-d` precis som
 * `ItemFieldInput::valueRules()` kräver. Ingen dag räknas om här.
 *
 * Felet kommer ur `form.errors['fields.N.value']` och ritas av FormField vid
 * rätt fält. Modulen vet inte vilket index den har — ItemForm äger listan —
 * så felet kommer färdigt som prop.
 *
 * Fokusringen och träffytan är UiInputs, samma kontroll som formulärets egna
 * fält använder.
 */
const props = defineProps({
    /* Fältet: `{id, label, type}` ur fieldPresentation.normalizeFields(). */
    field: { type: Object, required: true },
    /* Värdet, eller en tom sträng. Ett talfält bär decimalsträngen. */
    modelValue: { type: [String, Number], default: '' },
    /* Serverns fel för just det här fältet, eller null. */
    error: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

const id = computed(() => `field-${props.field.id}-value`);

const inputType = computed(() => fieldInputType(props.field.type));

/* Bara talfältet behöver `step`; `any` släpper igenom decimaler. */
const step = computed(() => (props.field.type === 'number' ? 'any' : undefined));

const value = computed({
    get: () => props.modelValue,
    set: (varde) => emit('update:modelValue', varde),
});
</script>

<template>
    <FormField
        v-slot="{ describedBy }"
        :label="field.label"
        :id="id"
        :error="error"
    >
        <div class="flex items-center gap-2">
            <UiInput
                :id="id"
                v-model="value"
                :type="inputType"
                :step="step"
                :described-by="describedBy"
                :name="id"
            />

            <slot name="action" />
        </div>
    </FormField>
</template>
