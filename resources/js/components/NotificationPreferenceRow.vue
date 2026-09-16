<script setup>
import { computed } from 'vue';
import { MODES, modeOf } from './notificationPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * En notistyp och dess tre lägen, se issue 65a § Beslut 2 och 3.
 *
 * **Tre radioknappar, inte två kryssrutor.** `enabled` och `digest` är två
 * kolumner men ETT val — se notificationPresentation.js, som äger
 * översättningen mellan dem. Radio och inte en <select>: varje läge bär en
 * mening om vad det betyder, och en mening får inte plats i en nedfällbar
 * lista (samma skäl som AccessLevelField § Beslut 4).
 *
 * **"Standard" betyder att användaren inte har uttryckt någon åsikt.** Raden
 * märks ur `preference.is_default`, som kommer ur serverns svar (31b
 * § Beslut 2) och aldrig räknas om här — men bara så länge läget står kvar på
 * det serverade värdet. Den som just klickat i ett annat läge HAR uttryckt en
 * åsikt, och en märkning som satt kvar då hade påstått motsatsen.
 *
 * **Namn och förklaring ur lang/.** `notifications.type.<typ>.label` och
 * `.description` — en rad som heter `schedule_occurrence_due` är en rad ingen
 * ställer in (Beslut 7). Saknas nyckeln syns nyckeln själv, aldrig en tom
 * rad (issue 52 § Beslut 4).
 *
 * Ingen klientvalidering och inget eget felmeddelande: kroppen vyn skickar är
 * byggd ur MODES och kan inte bära ett ogiltigt värde. Ett fel som ändå
 * kommer tillbaka hamnar på `preferences.N.<fält>` och visas av sidan.
 */
const props = defineProps({
    preference: { type: Object, required: true },
    mode: { type: String, required: true },
});

const emit = defineEmits(['update:mode']);

const { t } = useTranslations();

/* Punkt i typnamnet (`task.due`) blir bindestreck i id:t — en punkt i ett
   id är tillåtet men gör `#task.due` till två selektorer för varje läsare. */
const fieldId = computed(() => `notification-${props.preference.type.replace(/\./g, '-')}`);

const inputId = (mode) => `${fieldId.value}-${mode}`;

const edited = computed(() => props.mode !== modeOf(props.preference));
</script>

<template>
    <fieldset class="rounded border border-slate-200 bg-white p-4">
        <legend class="flex flex-wrap items-center gap-2 px-1">
            <span class="text-sm font-medium text-slate-800">
                {{ t(`notifications.type.${preference.type}.label`) }}
            </span>

            <span
                v-if="preference.is_default && !edited"
                class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600"
            >
                {{ t('notifications.default_badge') }}
            </span>
        </legend>

        <p class="mt-1 text-sm text-slate-600">
            {{ t(`notifications.type.${preference.type}.description`) }}
        </p>

        <div class="mt-3 flex flex-col gap-2">
            <label
                v-for="option in MODES"
                :key="option"
                :for="inputId(option)"
                class="flex cursor-pointer gap-2"
            >
                <input
                    :id="inputId(option)"
                    type="radio"
                    :name="fieldId"
                    :value="option"
                    :checked="mode === option"
                    class="mt-0.5"
                    @change="emit('update:mode', option)"
                >
                <span class="flex flex-col">
                    <span class="text-sm">{{ t(`notifications.mode.${option}.label`) }}</span>
                    <span class="text-xs text-slate-600">{{ t(`notifications.mode.${option}.description`) }}</span>
                </span>
            </label>
        </div>
    </fieldset>
</template>
