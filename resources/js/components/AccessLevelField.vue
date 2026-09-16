<script setup>
import { computed } from 'vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Nivåfältet — de fyra stegen i laddern, se issue 55a § Beslut 4 och
 * [[ADR-0028 Åtkomst på itemnivå]] § Beslut.
 *
 * **Fyra nivåer, två synliga.** `read` och `write` är de två vanliga valen;
 * `create` och `delete` ligger bakom en <details>-yta med rubriken
 * *Avancerat*, stängd som standard. Fyra val är för mycket för en ägare som
 * delar med sambon, och två är för lite för kundfallet i ADR-0028 — det är
 * hela skälet till uppdelningen.
 *
 * **Stängd är inte dold.** Fälten renderas alltid och ligger i DOM:en; det är
 * <details> som fäller ihop dem, inte en `v-if`. Den som öppnar *Avancerat*
 * med tangentbordet — summary är fokuserbart och fälls ut med Enter eller
 * mellanslag — hittar exakt samma radio-knappar och samma etiketter som de
 * två vanliga valen. Ett villkorat fält hade varit ett fält som inte gick att
 * nå alls utan mus.
 *
 * **Egen komponent och inte ett fält i en vy.** 55b använder samma fält i sitt
 * inbjudningsformulär, och issue 57 vill ha det när ett enskilt item delas.
 * Nivåerna kommer som prop ur `AccessLevel::LADDER` — samma teknik som
 * `Container::KINDS` (issue 54 § Beslut 8): laddern finns på ett ställe, och
 * en avskrift i JavaScript blir en andra sanning om vilka nivåer som finns.
 * `advanced` är däremot en presentationsdom och bor här: vilka två som är
 * "avancerade" är inte en egenskap hos laddern.
 *
 * Radio och inte en <select>: varje nivå bär en mening om vad den faktiskt
 * tillåter, formulerad ur regel 3, och en mening får inte plats i en
 * nedfällbar lista. Etiketten och beskrivningen kommer ur
 * `sharing.level.<nivå>`.
 *
 * Ingen klientvalidering och inget eget felmeddelande: `error` kommer ur
 * `form.errors.level`, som Inertia fyller från sessionens felpåse när
 * servern nekat — samma mönster som FormField.
 */
const props = defineProps({
    levels: { type: Array, required: true },
    modelValue: { type: String, required: true },
    id: { type: String, required: true },
    error: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

const { t } = useTranslations();

/* Presentationsdomen: dessa två hamnar bakom *Avancerat*. */
const ADVANCED = ['create', 'delete'];

const common = computed(() => props.levels.filter((level) => !ADVANCED.includes(level)));
const advanced = computed(() => props.levels.filter((level) => ADVANCED.includes(level)));

const inputId = (level) => `${props.id}-${level}`;
const errorId = computed(() => `${props.id}-error`);
</script>

<template>
    <fieldset class="flex flex-col gap-3">
        <legend class="text-sm font-medium text-slate-800">{{ t('sharing.accesses.level') }}</legend>

        <label
            v-for="level in common"
            :key="level"
            :for="inputId(level)"
            class="flex min-h-11 cursor-pointer gap-2 rounded border border-slate-300 bg-white p-2"
        >
            <input
                :id="inputId(level)"
                type="radio"
                :name="id"
                :value="level"
                :checked="modelValue === level"
                class="mt-1"
                @change="emit('update:modelValue', level)"
            >
            <span class="flex flex-col">
                <span class="text-sm">{{ t(`sharing.level.${level}.label`) }}</span>
                <span class="text-xs text-slate-600">{{ t(`sharing.level.${level}.description`) }}</span>
            </span>
        </label>

        <details class="rounded border border-slate-300 bg-white">
            <summary class="cursor-pointer p-2 text-sm text-slate-700">{{ t('sharing.advanced') }}</summary>

            <div class="flex flex-col gap-2 border-t border-slate-200 p-2">
                <label
                    v-for="level in advanced"
                    :key="level"
                    :for="inputId(level)"
                    class="flex min-h-11 cursor-pointer items-center gap-2"
                >
                    <input
                        :id="inputId(level)"
                        type="radio"
                        :name="id"
                        :value="level"
                        :checked="modelValue === level"
                        class="mt-1"
                        @change="emit('update:modelValue', level)"
                    >
                    <span class="flex flex-col">
                        <span class="text-sm">{{ t(`sharing.level.${level}.label`) }}</span>
                        <span class="text-xs text-slate-600">{{ t(`sharing.level.${level}.description`) }}</span>
                    </span>
                </label>
            </div>
        </details>

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
            class="text-sm text-red-700 outline-none"
        >
            {{ error }}
        </p>
    </fieldset>
</template>
