<script setup>
import { computed } from 'vue';
import UiSelect from '../UiSelect.vue';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Typvalet för ett eget fält, se issue 419 och [[ADR-0089 Egna fält på
 * items]] § Riktning 1 och 3.
 *
 * **Typerna kommer som prop och skrivs aldrig av här.** Listan är
 * `ItemField::TYPES` på servern (`text`, `number`, `date`), skickad ur
 * kontrollern, och en avskrift i JavaScript hade varit en andra sanning om
 * vad ett fält får vara. Meningen per typ kommer ur `lang/en/ui.php`
 * (`field.type.<typ>`), som all annan text på ytan.
 *
 * `disabled` är fältets lästa läge: ett fält med värden får inte byta typ
 * ([[ADR-0089 Egna fält på items]] § Riktning 3). Vyn säger varför bredvid —
 * en väljare som bara är grå utan förklaring ser ut som ett fel. Låset
 * prövas på servern av App\Actions\Field\FieldRules; den här flaggan är
 * presentation.
 */
const props = defineProps({
    id: { type: String, required: true },
    describedBy: { type: String, default: null },
    modelValue: { type: String, default: '' },
    /* Typerna, ur `types`-propen (ItemField::TYPES). */
    types: { type: Array, required: true },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const { t } = useTranslations();

const value = computed({
    get: () => props.modelValue,
    set: (varde) => emit('update:modelValue', varde),
});
</script>

<template>
    <UiSelect
        :id="id"
        v-model="value"
        :described-by="describedBy"
        :disabled="disabled"
        :name="id"
    >
        <option v-for="type in types" :key="type" :value="type">
            {{ t(`field.type.${type}`) }}
        </option>
    </UiSelect>
</template>
