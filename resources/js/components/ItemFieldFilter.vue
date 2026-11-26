<script setup>
import { computed } from 'vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Fälten i filterraden — issue 424 · [[ADR-0089 Egna fält på items]]
 * § Riktning 1.
 *
 * **En kontroll per typ.** Text filtreras med *Equals*, tal med *From* och
 * *To*, och datum med ett intervall: två datumfält plus en snabbvalslista
 * med relativa värden (*Within 30 days*). De relativa värdena räknas på
 * SERVERN mot användarens dag ([[ADR-0044 Användarens dag]]) — komponenten
 * skickar bara ordet `today`, `within:N` eller `past:N`, aldrig ett datum
 * den räknat själv. Det är samma regel som `useRelativeDate` följer.
 *
 * Ett ja/nej-fält ([[ADR-0099 Kryssrutefält]]) är ett val *Any / Yes / No*
 * som skickar `eq=1` eller `eq=0`; *Any* tar bort filtret.
 *
 * **Komponenten filtrerar ingenting och gör inget router-anrop.** Den bär
 * användarens val som ett objekt `{ulid: {eq, min, max}}` och lämnar det
 * vidare; `ItemFilterBar` äger adressen och skickar den till servern.
 * `pending` stänger fälten medan svaret är på väg.
 *
 * **Ingen sträng i JavaScript** ([[ADR-0013 Språk och i18n]]): varje text
 * kommer ur `t()` under `item.index.filter_*`.
 *
 * Panelens form finns inte i mockuparna (issuens `ambiguity` high). Den
 * följer filterradens egen: en hopfällbar rad per container, med fälten i
 * containerns ordning.
 */
const props = defineProps({
    /* Containerns fält, ur `filterFields`: `{ulid, label, type}`. */
    fields: { type: Array, required: true },
    /* Användarens val: `{ulid: {eq?, min?, max?}}`. */
    modelValue: { type: Object, default: () => ({}) },
    /* Stänger fälten medan servern svarar. */
    pending: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const { t } = useTranslations();

/*
 * Snabbvalen för ett datumfält. `value` är det val användaren ser som valt;
 * `min`/`max` är orden som skickas till servern. `today` som `min` och
 * `within:N` som `max` är "inom N dagar".
 */
const PRESETS = [
    { value: 'today', min: 'today', max: 'today' },
    { value: 'within:7', min: 'today', max: 'within:7' },
    { value: 'within:30', min: 'today', max: 'within:30' },
    { value: 'within:90', min: 'today', max: 'within:90' },
    { value: 'past:30', min: 'past:30', max: 'today' },
    { value: 'past:90', min: 'past:90', max: 'today' },
];

const values = computed(() => props.modelValue ?? {});

function operations(field) {
    return values.value[field.ulid] ?? {};
}

function withField(field, operations) {
    const next = { ...values.value };

    if (Object.keys(operations).length === 0) {
        delete next[field.ulid];
    } else {
        next[field.ulid] = operations;
    }

    emit('update:modelValue', next);
}

/*
 * Ett fältvärde sätts eller tas bort. Ett tomt fält betyder "inget filter",
 * så nyckeln lämnar objektet i stället för att skickas som en tom sträng.
 */
function setValue(field, operator, raw) {
    const value = String(raw ?? '').trim();
    const current = { ...operations(field) };

    if (value === '') {
        delete current[operator];
    } else {
        current[operator] = value;
    }

    withField(field, current);
}

/*
 * Snabbvalet sätter BÅDA operatorerna — intervallet är hela poängen — och
 * ett tomt val rensar dem.
 */
function setPreset(field, key) {
    const preset = PRESETS.find((candidate) => candidate.value === key);

    if (preset === undefined) {
        withField(field, {});

        return;
    }

    withField(field, { min: preset.min, max: preset.max });
}

/* Det valda snabbvalet, eller tomt när användaren skrivit egna datum. */
function selectedPreset(field) {
    const current = operations(field);
    const preset = PRESETS.find((candidate) => candidate.min === current.min && candidate.max === current.max);

    return preset === undefined ? '' : preset.value;
}

/* Datumfältet visar absoluta datum; ett relativt ord hör till snabbvalet. */
function dateValue(raw) {
    return /^\d{4}-\d{2}-\d{2}$/.test(String(raw ?? '')) ? raw : '';
}
</script>

<template>
    <details v-if="fields.length > 0" class="w-full">
        <summary class="min-h-11 cursor-pointer text-sm font-medium text-ink">
            {{ t('item.index.filter_fields') }}
        </summary>

        <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="field in fields" :key="field.ulid" class="flex flex-col gap-1">
                <label :for="`item-filter-field-${field.ulid}`" class="text-sm font-medium text-ink">
                    {{ field.label }}
                </label>

                <input
                    v-if="field.type === 'text'"
                    :id="`item-filter-field-${field.ulid}`"
                    type="text"
                    :value="operations(field).eq ?? ''"
                    :disabled="pending"
                    class="min-h-11 w-full rounded-control border border-border bg-surface px-3 py-2 font-normal text-ink"
                    @change="setValue(field, 'eq', $event.target.value)"
                >

                <select
                    v-else-if="field.type === 'boolean'"
                    :id="`item-filter-field-${field.ulid}`"
                    :value="operations(field).eq ?? ''"
                    :disabled="pending"
                    class="min-h-11 w-full rounded-control border border-border bg-surface px-3 py-2 font-normal text-ink"
                    @change="setValue(field, 'eq', $event.target.value)"
                >
                    <option value="">{{ t('item.index.filter_field_boolean_any') }}</option>
                    <option value="1">{{ t('field.value.yes') }}</option>
                    <option value="0">{{ t('field.value.no') }}</option>
                </select>

                <div v-else-if="field.type === 'number'" class="flex gap-2">
                    <input
                        :id="`item-filter-field-${field.ulid}`"
                        type="number"
                        step="any"
                        :value="operations(field).min ?? ''"
                        :placeholder="t('item.index.filter_field_min')"
                        :aria-label="t('item.index.filter_field_min')"
                        :disabled="pending"
                        class="min-h-11 w-full rounded-control border border-border bg-surface px-3 py-2 font-normal text-ink"
                        @change="setValue(field, 'min', $event.target.value)"
                    >
                    <input
                        :id="`item-filter-field-${field.ulid}-max`"
                        type="number"
                        step="any"
                        :value="operations(field).max ?? ''"
                        :placeholder="t('item.index.filter_field_max')"
                        :aria-label="t('item.index.filter_field_max')"
                        :disabled="pending"
                        class="min-h-11 w-full rounded-control border border-border bg-surface px-3 py-2 font-normal text-ink"
                        @change="setValue(field, 'max', $event.target.value)"
                    >
                </div>

                <template v-else>
                    <select
                        :id="`item-filter-field-${field.ulid}`"
                        :value="selectedPreset(field)"
                        :disabled="pending"
                        class="min-h-11 w-full rounded-control border border-border bg-surface px-3 py-2 font-normal text-ink"
                        @change="setPreset(field, $event.target.value)"
                    >
                        <option value="">{{ t('item.index.filter_field_any') }}</option>
                        <option value="today">{{ t('item.index.filter_field_today') }}</option>
                        <option
                            v-for="preset in PRESETS.filter((candidate) => candidate.value !== 'today')"
                            :key="preset.value"
                            :value="preset.value"
                        >
                            {{ preset.value.startsWith('past:')
                                ? t('item.index.filter_field_past', { days: preset.value.split(':')[1] })
                                : t('item.index.filter_field_within', { days: preset.value.split(':')[1] }) }}
                        </option>
                    </select>

                    <div class="flex gap-2">
                        <input
                            :id="`item-filter-field-${field.ulid}-min`"
                            type="date"
                            :value="dateValue(operations(field).min)"
                            :placeholder="t('item.index.filter_field_min')"
                            :aria-label="t('item.index.filter_field_min')"
                            :disabled="pending"
                            class="min-h-11 w-full rounded-control border border-border bg-surface px-3 py-2 font-normal text-ink"
                            @change="setValue(field, 'min', $event.target.value)"
                        >
                        <input
                            :id="`item-filter-field-${field.ulid}-max`"
                            type="date"
                            :value="dateValue(operations(field).max)"
                            :placeholder="t('item.index.filter_field_max')"
                            :aria-label="t('item.index.filter_field_max')"
                            :disabled="pending"
                            class="min-h-11 w-full rounded-control border border-border bg-surface px-3 py-2 font-normal text-ink"
                            @change="setValue(field, 'max', $event.target.value)"
                        >
                    </div>
                </template>
            </div>
        </div>
    </details>
</template>
