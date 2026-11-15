<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Valutafältet, se issue 1253 · [[ADR-0078 Valutor ur ISO 4217]] § 1 och 2.
 *
 * **En kombinerad ruta, inte en `<select>` och inte en fritext.** Bara ett
 * värde ur listan kan skickas: den som skriver en kod som inte finns, eller
 * lämnar fältet med ett namn i rutan, får tillbaka det senast valda värdet.
 * Servern prövar samma sak i App\Rules\IsoCurrency — regeln här är
 * användarvänlighet, aldrig auktoritet.
 *
 * **Sökningen filtrerar i minnet, med kod före namn.** Koder som BÖRJAR med
 * texten står först, sedan de vars namn innehåller den. Ordningen är listans
 * egen (sorterad på kod), så samma sökord ger samma rad varje gång. Ingen
 * förfrågan per tangenttryck: listan är 180 rader och kommer färdig.
 *
 * **Listan är en lat propp.** `currencies` delas av
 * App\Http\Middleware\HandleInertiaRequests som `Inertia::optional()`, och
 * fältet hämtar den med en partiell omladdning första gången det får fokus —
 * samma mönster som `itemTargets` i ItemTargetPicker. En sida där inget
 * valutafält öppnats frågar aldrig efter den. Får fältet en begränsad lista i
 * `options` (containerns urval, issue 1254) används den i stället, och då
 * behövs ingen hämtning alls: raderna bär redan kod och namn.
 *
 * **Ett tomt värde är ett giltigt svar där det är tillåtet** (`allowEmpty`):
 * containerns valuta ärver kontots när rutan är tom ([[ADR-0037 Valutans
 * arv]]), och den raden står först i listan. Namnet på valet kommer ur
 * `lang/en/ui.php` som all annan text — fältet bär inga egna ord.
 *
 * **Tangentbordet:** pilar flyttar markeringen, Enter väljer, Escape stänger
 * listan utan att röra värdet, och ett klick väljer. Escape stoppas bara när
 * listan är öppen, så tangenten stänger det infällbara formuläret när fältet
 * inte har något att stänga själv. `@keydown` med en nyckelkontroll och ingen
 * `.esc`-modifierare: GenomgangTest förbjuder den formen, eftersom
 * `<dialog>` äger fokusfällan (samma linje som InlineCreate).
 */
const props = defineProps({
    /* Fältets id, kopplat till FormFields `<label for>`. */
    id: { type: String, required: true },
    /* Den valda koden, eller '' när fältet är tomt. */
    modelValue: { type: String, default: '' },
    /* FormFields `describedBy`, så felet läses tillsammans med fältet. */
    describedBy: { type: String, default: null },
    /*
     * En begränsad lista `[{code, name}]` (issue 1254), eller `null` för hela
     * ISO-listan. Utan proppen hämtas den delade `currencies` vid fokus.
     */
    options: { type: Array, default: null },
    /* Sant där tomt är ett svar — containerns "ärv kontot". */
    allowEmpty: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const { t } = useTranslations();
const page = usePage();

const inputElement = ref(null);
const open = ref(false);
const query = ref('');
const active = ref(0);
const loaded = ref(false);
const loading = ref(false);

/* Textrutan visar det valda värdet som `EUR · Euro`, eller det man skriver. */
const text = ref('');

const all = computed(() => page.props.currencies ?? []);

/* Den begränsade listan om den finns, annars hela ISO-listan. */
const source = computed(() => (props.options === null ? all.value : props.options));

function optionFor(code) {
    if (code === '') {
        return null;
    }

    return all.value.find((option) => option.code === code)
        ?? source.value.find((option) => option.code === code)
        ?? null;
}

/*
 * Det valda värdet som det skrivs i rutan. Koden är reserven: en rad med en
 * kod utanför listan ([[ADR-0078 Valutor ur ISO 4217]] § 3) visas som förut,
 * och en tom ruta är tom.
 */
const display = computed(() => {
    if (props.modelValue === '') {
        return '';
    }

    const hit = optionFor(props.modelValue);

    return hit === null ? props.modelValue : `${hit.code} · ${hit.name}`;
});

/* Utanför listan är rutan alltid det valda värdet — aldrig det man skrev. */
watch(display, (value) => {
    if (! open.value) {
        text.value = value;
    }
}, { immediate: true });

const filtered = computed(() => {
    const needle = query.value.trim().toLowerCase();

    if (needle === '') {
        return source.value;
    }

    // Kod som BÖRJAR med texten först — den som skriver `EU` vill ha EUR
    // innan hon ser Europas alla andra valutor — sedan namn som innehåller
    // den. Inom varje grupp står listans egen ordning kvar.
    const codes = source.value.filter((option) => option.code.toLowerCase().startsWith(needle));
    const names = source.value.filter((option) => ! option.code.toLowerCase().startsWith(needle)
        && option.name.toLowerCase().includes(needle));

    return [...codes, ...names];
});

/* "Ärv kontot" står först, och bara när rutan är tom och tomt är tillåtet. */
const visible = computed(() => (props.allowEmpty && query.value.trim() === ''
    ? [{ code: '', name: t('currency.inherit') }, ...filtered.value]
    : filtered.value));

const activeOptionId = computed(() => (open.value && visible.value[active.value] !== undefined
    ? `${props.id}-option-${active.value}`
    : undefined));

/* Hämtar den delade listan en gång — se filens docblock. */
function loadOptions() {
    if (loaded.value) {
        return;
    }

    loaded.value = true;

    if (props.options !== null) {
        return;
    }

    loading.value = true;

    router.reload({
        only: ['currencies'],
        onFinish: () => {
            loading.value = false;
        },
    });
}

async function onFocus(event) {
    loadOptions();

    open.value = true;
    query.value = '';
    active.value = 0;
    text.value = display.value;

    await nextTick();

    /* Hela värdet markerat: det man skriver ersätter det som står. */
    (event.target ?? inputElement.value)?.select?.();
}

function onInput(event) {
    text.value = event.target.value;
    query.value = event.target.value;
    open.value = true;
    active.value = 0;
}

/*
 * Ett värde ur listan, och bara det. Klicket kommer före `blur` eftersom
 * raden fångar `mousedown` — annars hade fältet hunnit återgå till det gamla
 * värdet innan valet nådde fram.
 */
function selectOption(option) {
    emit('update:modelValue', option.code);

    text.value = option.code === '' ? '' : `${option.code} · ${option.name}`;
    open.value = false;
    query.value = '';
    active.value = 0;

    inputElement.value?.focus();
}

/* Lämnar man fältet med något som inte är en kod, står det gamla värdet kvar. */
function onBlur() {
    const typed = text.value.trim().toUpperCase();
    const match = source.value.find((option) => option.code === typed);

    if (match !== undefined) {
        if (match.code !== props.modelValue) {
            emit('update:modelValue', match.code);
        }

        text.value = `${match.code} · ${match.name}`;
    } else {
        text.value = display.value;
    }

    open.value = false;
    query.value = '';
}

function move(step) {
    const last = visible.value.length - 1;

    if (last < 0) {
        return;
    }

    active.value = active.value + step < 0
        ? last
        : (active.value + step) % visible.value.length;
}

function onKeydown(event) {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        open.value = true;
        move(1);

        return;
    }

    if (event.key === 'ArrowUp') {
        event.preventDefault();
        open.value = true;
        move(-1);

        return;
    }

    if (event.key === 'Enter') {
        const option = visible.value[active.value];

        if (open.value && option !== undefined) {
            event.preventDefault();
            selectOption(option);
        }

        return;
    }

    if (event.key === 'Escape' && open.value) {
        event.preventDefault();
        event.stopPropagation();

        open.value = false;
        query.value = '';
        text.value = display.value;
    }
}
</script>

<template>
    <div class="relative">
        <input
            :id="id"
            ref="inputElement"
            :value="text"
            :aria-describedby="describedBy"
            :aria-expanded="open ? 'true' : 'false'"
            :aria-controls="`${id}-listbox`"
            :aria-activedescendant="activeOptionId"
            role="combobox"
            aria-autocomplete="list"
            type="text"
            autocomplete="off"
            spellcheck="false"
            class="min-h-11 w-full rounded-control border border-border bg-surface px-3 py-2 text-body text-ink outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
            @input="onInput"
            @focus="onFocus"
            @blur="onBlur"
            @keydown="onKeydown"
        >

        <ul
            v-if="open"
            :id="`${id}-listbox`"
            role="listbox"
            class="absolute left-0 top-full z-20 mt-1 max-h-60 w-full overflow-y-auto rounded-card border border-border bg-surface p-1 shadow-lg"
        >
            <li v-if="loading" class="px-3 py-2 text-body text-ink-muted">
                {{ t('common.pending.default') }}
            </li>

            <li v-for="(option, index) in visible" :key="option.code === '' ? 'inherit' : option.code" role="none">
                <button
                    :id="`${id}-option-${index}`"
                    type="button"
                    role="option"
                    :aria-selected="option.code === modelValue"
                    class="flex min-h-11 w-full items-center gap-2 rounded-control px-3 text-left text-body outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                    :class="index === active
                        ? 'bg-accent-soft text-accent'
                        : 'text-ink hover:bg-surface-sunken'"
                    @mousedown.prevent
                    @click="selectOption(option)"
                >
                    <span v-if="option.code === ''">{{ option.name }}</span>
                    <template v-else>
                        <span class="font-medium">{{ option.code }}</span>
                        <span class="text-ink-muted">{{ option.name }}</span>
                    </template>
                </button>
            </li>
        </ul>
    </div>
</template>
