<script setup>
import { ref } from 'vue';
import ColorWheel from './ColorWheel.vue';
import FormField from './FormField.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Färgen på en tagg, se issue 56a § Beslut 8 och issue 165.
 *
 * **Färgen är valfri, och `null` betyder "ingen färg" — inte "välj en åt mig".**
 * App\Http\Resources\TagResource säger uttryckligen att valet är
 * presentationens och därmed den här issuen; valet är att inte välja. Därför
 * ingen standardfärg i fältet när värdet är `null`: pricken är omålad
 * (genomskinlig med kant), inmatningen är tom, och kryssrutan "ingen färg" är
 * det som säger att det är ett giltigt val och inte ett tomt fält.
 *
 * **Pricken är sedan issue 165 en knapp som öppnar färgväljaren.** Träffytan
 * är 44 px som kryssrutan bredvid, och knappen annonserar vad den gör:
 * `aria-haspopup="dialog"` och `aria-expanded` med öppet-läget. Etiketten
 * kommer ur `lang/en/ui.php` som varje annan text här — pricken bär ingen
 * egen mening.
 *
 * **Hjulet kan inte öppnas för en tagg utan färg** (Beslut 6). Är kryssrutan
 * ikryssad är pricken `disabled`, precis som textfältet, så vägen till en
 * färg är två steg: kryssa av, välj. Det är samma regel som förut och inte en
 * ny — `null` är ett svar, och ett hjul som öppnades på det svaret hade
 * behövt en standardfärg att visa.
 *
 * **Hjulet bor i en egen komponent** (ColorWheel.vue) och ritas bara medan
 * det är öppet. Fältet här äger tillståndet och värdet; hjulets utseende,
 * pekarhantering och stängning hör dit. Texten i textfältet är kvar och är
 * det tillgängliga alternativet: en giltig hex flyttar markören, en ogiltig
 * lämnar hjulet som det är.
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

/* Sant medan färgväljaren är öppen. */
const open = ref(false);

/* Pricken: knappen hjulet öppnas av, och elementet fokus går tillbaka till. */
const dot = ref(null);
</script>

<template>
    <FormField v-slot="{ describedBy }" :label="t('container.tags.color')" :id="id" :error="error">
        <div class="flex flex-wrap items-center gap-2">
            <button
                ref="dot"
                type="button"
                class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-control outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 disabled:opacity-50"
                :aria-label="t('container.tags.color_open')"
                aria-haspopup="dialog"
                :aria-expanded="open"
                :disabled="modelValue === null"
                @click="open = true"
            >
                <span
                    aria-hidden="true"
                    class="inline-block h-5 w-5 rounded-full border border-slate-500"
                    :style="modelValue ? { backgroundColor: modelValue } : null"
                />
            </button>

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

            <label :for="`${id}-none`" class="flex min-h-11 items-center gap-1 text-sm text-slate-700">
                <input
                    :id="`${id}-none`"
                    type="checkbox"
                    :checked="modelValue === null"
                    @change="emit('update:modelValue', $event.target.checked ? null : '')"
                >
                {{ t('container.tags.no_color') }}
            </label>

            <ColorWheel
                v-if="open"
                :id="id"
                :model-value="modelValue"
                :trigger="dot"
                @update:model-value="emit('update:modelValue', $event)"
                @close="open = false"
            />
        </div>
    </FormField>
</template>
