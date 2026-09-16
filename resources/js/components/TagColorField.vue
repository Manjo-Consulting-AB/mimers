<script setup>
import FormField from './FormField.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Färgen på en tagg, se issue 56a § Beslut 8.
 *
 * **Färgen är valfri, och `null` betyder "ingen färg" — inte "välj en åt mig".**
 * App\Http\Resources\TagResource säger uttryckligen att valet är
 * presentationens och därmed den här issuen; valet är att inte välja. Därför
 * ingen standardfärg i fältet när värdet är `null`: pricken är omålad
 * (genomskinlig med kant), inmatningen är tom, och kryssrutan "ingen färg" är
 * det som säger att det är ett giltigt val och inte ett tomt fält.
 *
 * Värdet är antingen `#rrggbb` eller `null`. Formuläret i raden skickar `null`
 * för både kryssrutan och ett tomt fält — regeln `/^#[0-9a-fA-F]{6}$/` bor i
 * StoreTagRequest/UpdateTagRequest och är den som avgör, aldrig den här filen.
 */
const props = defineProps({
    modelValue: { type: String, default: null },
    id: { type: String, required: true },
    error: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

const { t } = useTranslations();
</script>

<template>
    <FormField v-slot="{ describedBy }" :label="t('container.tags.color')" :id="id" :error="error">
        <div class="flex flex-wrap items-center gap-2">
            <span
                aria-hidden="true"
                class="inline-block h-5 w-5 shrink-0 rounded-full border border-slate-300"
                :style="modelValue ? { backgroundColor: modelValue } : null"
            />

            <input
                :id="id"
                :value="modelValue ?? ''"
                :aria-describedby="describedBy"
                :disabled="modelValue === null"
                type="text"
                name="color"
                :placeholder="t('container.tags.color_placeholder')"
                class="rounded border border-slate-300 bg-white px-3 py-2 disabled:bg-slate-100"
                @input="emit('update:modelValue', $event.target.value)"
            >

            <label class="flex min-h-11 items-center gap-1 text-sm text-slate-700">
                <input
                    type="checkbox"
                    :checked="modelValue === null"
                    @change="emit('update:modelValue', $event.target.checked ? null : '')"
                >
                {{ t('container.tags.no_color') }}
            </label>
        </div>
    </FormField>
</template>
