<script setup>
import { computed } from 'vue';
import UiBadge from './UiBadge.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Nivåfältet — de fyra stegen i laddern, se issue 55a § Beslut 4 och
 * [[ADR-0028 Åtkomst på itemnivå]] § Beslut.
 *
 * **Fyra nivåer, fyra likvärdiga val** (issue 439). Fältet svarar på frågan
 * *Vad ska den här personen kunna göra i containern?* — därför står alla fyra
 * stegen i ladderns ordning som samma sorts radio-kort, med samma ram och
 * samma tyngd. Den tidigare uppdelningen i två vanliga och två *Avancerat*
 * är borta: den gömde två av fyra val bakom en yta ingen letade efter, och
 * beskrivningarna säger nu vad mottagaren får göra i stället för vad
 * systemet gör.
 *
 * **Read bär märkningen *Recommended*.** Det är det val som ger minst och som
 * de flesta delningar börjar i; brickan är ett ord, inte en färg
 * ([[ADR-0042 Designsystemet]] § Beslut), och den sitter i etikettraden så
 * att den inte konkurrerar med beskrivningen.
 *
 * **Egen komponent och inte ett fält i en vy.** Inbjudningsformuläret,
 * inbjudningsraden och åtkomstraden använder samma fält, så nivåvalet ändras
 * på ett ställe. Nivåerna kommer som prop ur `AccessLevel::LADDER` — samma
 * teknik som `Container::KINDS` (issue 54 § Beslut 8): laddern finns på ett
 * ställe, och en avskrift i JavaScript blir en andra sanning om vilka nivåer
 * som finns.
 *
 * Radio och inte en <select>: varje nivå bär en mening om vad den faktiskt
 * tillåter, och en mening får inte plats i en nedfällbar lista. Etiketten och
 * beskrivningen kommer ur `sharing.level.<nivå>`.
 *
 * Ingen klientvalidering och inget eget felmeddelande: `error` kommer ur
 * `form.errors.level`, som Inertia fyller från sessionens felpåse när
 * servern nekat — samma mönster som FormField.
 *
 * **`disabled` stänger fältet medan ett val sparas** (issue 1250). Raden på
 * delningssidan sparar nivån direkt, och ett andra klick innan det första
 * svarat hade blivit två skrivningar i rad — samma skäl som `IconPending`
 * i Containers/Edit.vue. Fältet ritas kvar och stängs av, det vill säga
 * döljs inte: den som tabbar igenom raden ska hitta samma fyra nivåer,
 * bara inte kunna välja en till.
 */
const props = defineProps({
    levels: { type: Array, required: true },
    modelValue: { type: String, required: true },
    id: { type: String, required: true },
    error: { type: String, default: null },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const { t } = useTranslations();

const inputId = (level) => `${props.id}-${level}`;
const errorId = computed(() => `${props.id}-error`);
</script>

<template>
    <fieldset class="flex flex-col gap-3">
        <legend class="text-body font-medium text-ink">{{ t('sharing.accesses.level') }}</legend>

        <label
            v-for="level in levels"
            :key="level"
            :for="inputId(level)"
            class="flex min-h-11 cursor-pointer gap-2 rounded-control border border-border bg-surface p-2"
        >
            <input
                :id="inputId(level)"
                type="radio"
                :name="id"
                :value="level"
                :checked="modelValue === level"
                :disabled="disabled"
                class="mt-1"
                @change="emit('update:modelValue', level)"
            >
            <span class="flex flex-col gap-1">
                <span class="flex flex-wrap items-center gap-2">
                    <span class="text-body text-ink">{{ t(`sharing.level.${level}.label`) }}</span>
                    <UiBadge v-if="level === 'read'">{{ t('sharing.level.recommended') }}</UiBadge>
                </span>
                <span class="text-meta text-ink-muted">{{ t(`sharing.level.${level}.description`) }}</span>
            </span>
        </label>

        <!--
            `role="alert"` och `tabindex="-1"`: felet läses upp när svaret
            kommer tillbaka, och går att sätta fokus på med kod. Fokus flyttas
            inte automatiskt till det — useErrorFocus letar upp `<fält>-error`
            för fältet `level`, och id:t här är per rad (samma fält finns på
            flera rader samtidigt, så ett gemensamt id vore en dubblett).
        -->
        <p
            v-if="error"
            :id="errorId"
            role="alert"
            tabindex="-1"
            class="text-body text-danger outline-none"
        >
            {{ error }}
        </p>
    </fieldset>
</template>
