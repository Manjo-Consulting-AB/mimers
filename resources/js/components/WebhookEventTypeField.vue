<script setup>
import { computed } from 'vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Kryssrutorna för vilka händelser en webhook prenumererar på, se issue 65b
 * § Beslut 6.
 *
 * **Listan kommer ur App\Models\WebhookEndpoint::EVENT_TYPES** och skickas
 * hit som prop av App\Http\Controllers\WebhookEndpointController — den
 * skrivs aldrig av i JavaScript. Två listor blir två sanningar, samma skäl
 * som gör `kinds` i ContainerController::create() till en prop.
 *
 * **Namn och förklaring ur lang/.** `webhook.event_type.<typ>.label` och
 * `.description`, samma form som notistyperna i 65a § Beslut 7: en rad som
 * heter `transfer.requested` är en rad ingen ställer in. Saknas nyckeln syns
 * nyckeln själv, aldrig en tom rad (issue 52 § Beslut 4).
 *
 * **Minst en krävs, och det sägs av servern.** Ingen klientregel hindrar en
 * tom lista — StoreWebhookEndpointRequest svarar `event_types`-fältet med
 * `min:1`, och den meningen är den användaren möter. En egen regel här hade
 * varit en andra sanning om samma krav (FormField § docblock).
 *
 * Kryssrutor och inte en flervalslista: varje typ bär en förklaring, och en
 * förklaring får inte plats i en nedfällbar lista (samma skäl som
 * AccessLevelField § Beslut 4 väljer radio framför select).
 */
const props = defineProps({
    /* Typerna ur WebhookEndpoint::EVENT_TYPES, i konstanternas ordning. */
    types: { type: Array, required: true },

    modelValue: { type: Array, required: true },

    /* Serverns fel för fältet, ur form.errors.event_types. */
    error: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

const { t } = useTranslations();

const describedBy = computed(() => (props.error ? 'event-types-error' : undefined));

/* Punkt i typnamnet (`task.due`) blir bindestreck i id:t — en punkt i ett id
   är tillåtet men gör `#task.due` till två selektorer för varje läsare. */
const inputId = (type) => `event-type-${type.replace(/\./g, '-')}`;

function toggle(type, checked) {
    emit('update:modelValue', checked
        ? [...props.modelValue, type]
        : props.modelValue.filter((value) => value !== type));
}
</script>

<template>
    <fieldset class="flex flex-col gap-3" :aria-describedby="describedBy">
        <legend class="text-sm font-medium text-slate-800">{{ t('webhook.event_types_label') }}</legend>

        <label
            v-for="type in types"
            :key="type"
            :for="inputId(type)"
            class="flex cursor-pointer gap-2"
        >
            <input
                :id="inputId(type)"
                type="checkbox"
                class="mt-0.5"
                :checked="modelValue.includes(type)"
                @change="toggle(type, $event.target.checked)"
            >

            <span class="flex flex-col">
                <span class="text-sm">{{ t(`webhook.event_type.${type}.label`) }}</span>
                <span class="text-xs text-slate-600">{{ t(`webhook.event_type.${type}.description`) }}</span>
            </span>
        </label>

        <p v-if="error" id="event-types-error" tabindex="-1" class="text-sm text-red-700 outline-none">
            {{ error }}
        </p>
    </fieldset>
</template>
