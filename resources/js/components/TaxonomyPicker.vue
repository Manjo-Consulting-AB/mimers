<script setup>
import { computed, ref } from 'vue';
import UiIcon from './UiIcon.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Väljaren för taggar och kategorier, se [[ADR-0068 Itemets sida och
 * skapandet]] § 13 och [[M45 Itemets sida]] § 336.
 *
 * **En komponent för båda.** Kategorin tar ett värde (`single`) och taggarna
 * flera (`multiple`); datamodellen för de två ändras inte, och det är hela
 * skillnaden mellan lägena. Issue 337 och 343 bygger på den här filen — nya
 * taggar i väljaren och itemflikens filterrad — så gränssnittet är avsiktligt
 * smalt: alternativen, värdet och läget, och ett `update:modelValue`.
 *
 * **Värdet är ULID:n, aldrig noden.** `single` bär en ULID eller `null`,
 * `multiple` en lista med ULID:n. Samma former som formuläret skickade när
 * fälten var en `<select>` och kryssrutor, så vad som postas till servern är
 * oförändrat.
 *
 * **Alternativen är `{ulid, name, color?}`.** Kategoriernas namn kan komma
 * indragna med hårda mellanslag (`categoryOptions()` ur categoryTree.js): i
 * listan står indraget kvar, för det är det som gör trädet läsbart, men
 * chipet visar namnet utan det.
 *
 * **Sökningen filtrerar i minnet, på namnet.** Alternativen är hela
 * containerns taxonomi och finns redan i sidan — ingen fråga till servern
 * medan man skriver, samma linje som ParentPicker. I `multiple` visas redan
 * valda värden inte i listan: de står som chips, och ett val man redan har
 * hade bara varit ett andra sätt att ta bort det.
 *
 * **Tangentbordet** är combobox-mönstret. Pil ned och upp flyttar
 * markeringen, Enter väljer den markerade raden, Esc stänger, och Backspace i
 * ett tomt sökfält tar bort det sista chipet — i `single` tömmer det valet.
 * **Enter stoppas alltid** (`preventDefault`): väljaren ligger i ItemForms
 * `<form>`, och ett Enter som slank igenom hade skickat in formuläret med ett
 * sökord som enda orsak.
 *
 * **Ett val sker på `mousedown`, inte `click`.** Fältet förlorar fokus på
 * mousedown, och en lista som stänger sig på fokusbytet hinner försvinna
 * innan trycket blir ett klick. `mousedown.prevent` håller fokus kvar i
 * fältet. Tangentbordet når alla val via fältet, så ingen rad behöver vara
 * ett tabbstopp.
 *
 * Etiketten och felet ägs av FormField (issue 51 § Beslut 9): `id` hamnar på
 * sökfältet, så att etikettens `for` pekar på det. Färgerna är roller ur
 * @theme; taggens egen färg är data och ritas som inline-stil, som i
 * ItemForm förut.
 */
const props = defineProps({
    /* Sökfältets id: FormField binder etiketten till det. */
    id: { type: String, required: true },
    describedBy: { type: String, default: null },
    /* Alternativen — `{ulid, name, color?}`. */
    options: { type: Array, default: () => [] },
    /* `single`: en ULID eller null. `multiple`: en lista med ULID:n. */
    modelValue: { type: [String, Array], default: null },
    mode: {
        type: String,
        default: 'multiple',
        validator: (value) => ['single', 'multiple'].includes(value),
    },
    /*
     * Visar raden *Create "X"*. Föräldern räknar ut det ur
     * `can.manage_taxonomy` (`Gate::allows('update', $container)`), samma
     * grind som ändpunkterna prövar — väljaren avgör ingenting själv.
     */
    canCreate: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'create']);

const { t } = useTranslations();

const query = ref('');
const open = ref(false);
const activeIndex = ref(-1);
const root = ref(null);

const isSingle = computed(() => props.mode === 'single');

/* De valda ULID:erna som en lista, i båda lägena. */
const selectedUlids = computed(() => {
    if (isSingle.value) {
        return typeof props.modelValue === 'string' ? [props.modelValue] : [];
    }

    return Array.isArray(props.modelValue) ? props.modelValue : [];
});

/* Chipsen, i den ordning värdet har. Ett värde utan alternativ ritas inte. */
const chips = computed(() => selectedUlids.value
    .map((ulid) => props.options.find((option) => option.ulid === ulid))
    .filter((option) => option !== undefined));

const visibleOptions = computed(() => {
    const needle = query.value.trim().toLowerCase();

    return props.options.filter((option) => {
        if (! isSingle.value && selectedUlids.value.includes(option.ulid)) {
            return false;
        }

        return needle === '' || option.name.toLowerCase().includes(needle);
    });
});

/*
 * Raden *Create "X"*: bara med behörighet, bara med text, och bara när texten
 * inte exakt (skiftlägesokänsligt) är ett alternativ. Jämförelsen gäller ALLA
 * alternativ, även de som redan är valda och därför inte syns i listan.
 */
const createName = computed(() => query.value.trim());

const showCreate = computed(() => {
    if (! props.canCreate || createName.value === '') {
        return false;
    }

    const needle = createName.value.toLowerCase();

    return ! props.options.some((option) => plainName(option).toLowerCase() === needle);
});

/* Antalet rader som går att markera: alternativen plus skaparaden. */
const rowCount = computed(() => visibleOptions.value.length + (showCreate.value ? 1 : 0));

const createIndex = computed(() => visibleOptions.value.length);

const listboxId = computed(() => `${props.id}-listbox`);

function optionId(option) {
    return `${props.id}-option-${option.ulid}`;
}

const createRowId = computed(() => `${props.id}-option-create`);

const activeDescendant = computed(() => {
    if (! open.value) {
        return undefined;
    }

    if (showCreate.value && activeIndex.value === createIndex.value) {
        return createRowId.value;
    }

    const option = visibleOptions.value[activeIndex.value];

    return option !== undefined ? optionId(option) : undefined;
});

/* Namnet utan indraget — det som står i chipet och i knappens etikett. */
function plainName(option) {
    return option.name.trim();
}

function openList() {
    open.value = true;
}

function closeList() {
    open.value = false;
    activeIndex.value = -1;
}

function onInput() {
    open.value = true;
    activeIndex.value = rowCount.value > 0 ? 0 : -1;
}

function choose(option) {
    if (isSingle.value) {
        emit('update:modelValue', option.ulid);
    } else {
        emit('update:modelValue', [...selectedUlids.value, option.ulid]);
    }

    query.value = '';
    closeList();
}

/* Ber föräldern skapa namnet; den väljer det nya värdet när servern svarat. */
function create() {
    emit('create', createName.value);

    query.value = '';
    closeList();
}

function remove(ulid) {
    if (isSingle.value) {
        emit('update:modelValue', null);

        return;
    }

    emit('update:modelValue', selectedUlids.value.filter((value) => value !== ulid));
}

function move(step) {
    const count = rowCount.value;

    if (! open.value) {
        open.value = true;
    }

    if (count === 0) {
        activeIndex.value = -1;

        return;
    }

    if (activeIndex.value === -1) {
        activeIndex.value = step > 0 ? 0 : count - 1;

        return;
    }

    activeIndex.value = (activeIndex.value + step + count) % count;
}

function onKeydown(event) {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        move(1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        move(-1);
    } else if (event.key === 'Enter') {
        event.preventDefault();

        const option = visibleOptions.value[activeIndex.value];

        if (open.value && showCreate.value && activeIndex.value === createIndex.value) {
            create();
        } else if (open.value && option !== undefined) {
            choose(option);
        }
    } else if (event.key === 'Escape') {
        if (open.value) {
            event.preventDefault();
            closeList();
        }
    } else if (event.key === 'Backspace' && query.value === '' && chips.value.length > 0) {
        remove(chips.value[chips.value.length - 1].ulid);
    }
}

/* Fokus lämnar hela väljaren: stäng listan. */
function onFocusout(event) {
    if (! root.value?.contains(event.relatedTarget)) {
        closeList();
    }
}
</script>

<template>
    <div ref="root" class="relative" @focusout="onFocusout">
        <div
            class="flex min-h-11 flex-wrap items-center gap-1 rounded-control border border-border bg-surface px-2 py-1 focus-within:ring-2 focus-within:ring-focus focus-within:ring-offset-2"
        >
            <button
                v-for="chip in chips"
                :key="chip.ulid"
                type="button"
                :aria-label="t('item.form.taxonomy_remove', { name: plainName(chip) })"
                class="inline-flex min-h-11 items-center gap-1 rounded-control bg-accent-soft px-2 text-body text-accent outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                @click="remove(chip.ulid)"
            >
                <span
                    v-if="chip.color"
                    aria-hidden="true"
                    class="inline-block h-3 w-3 shrink-0 rounded-full border border-ink-subtle"
                    :style="{ backgroundColor: chip.color }"
                />
                {{ plainName(chip) }}
                <UiIcon name="close" :size="16" class="h-4 w-4" />
            </button>

            <input
                :id="id"
                v-model="query"
                type="text"
                role="combobox"
                autocomplete="off"
                aria-autocomplete="list"
                :aria-expanded="open ? 'true' : 'false'"
                :aria-controls="listboxId"
                :aria-activedescendant="activeDescendant"
                :aria-describedby="describedBy"
                :placeholder="chips.length === 0 ? t('item.form.taxonomy_search') : ''"
                class="min-h-11 min-w-24 flex-1 bg-transparent px-1 text-body text-ink outline-none"
                @focus="openList"
                @input="onInput"
                @keydown="onKeydown"
            >
        </div>

        <ul
            v-show="open"
            :id="listboxId"
            role="listbox"
            class="absolute z-10 mt-1 max-h-60 w-full overflow-y-auto rounded-control border border-border bg-surface py-1"
        >
            <li
                v-for="(option, index) in visibleOptions"
                :id="optionId(option)"
                :key="option.ulid"
                role="option"
                :aria-selected="selectedUlids.includes(option.ulid) ? 'true' : 'false'"
                class="flex min-h-11 cursor-pointer items-center gap-2 px-3 text-body text-ink"
                :class="index === activeIndex ? 'bg-accent-soft text-accent' : 'hover:bg-surface-sunken'"
                @mousedown.prevent="choose(option)"
            >
                <span
                    v-if="option.color"
                    aria-hidden="true"
                    class="inline-block h-3 w-3 shrink-0 rounded-full border border-ink-subtle"
                    :style="{ backgroundColor: option.color }"
                />
                {{ option.name }}
            </li>

            <li
                v-if="showCreate"
                :id="createRowId"
                role="option"
                aria-selected="false"
                class="flex min-h-11 cursor-pointer items-center gap-2 px-3 text-body text-accent"
                :class="activeIndex === createIndex ? 'bg-accent-soft' : 'hover:bg-surface-sunken'"
                @mousedown.prevent="create"
            >
                {{ t('item.form.taxonomy_create', { name: createName }) }}
            </li>

            <li v-if="rowCount === 0" role="presentation" class="px-3 py-2 text-body text-ink-muted">
                {{ t('item.form.taxonomy_no_matches') }}
            </li>
        </ul>
    </div>
</template>
