<script setup>
import { computed, ref, watch } from 'vue';
import FormField from './FormField.vue';
import ItemIcon from './ItemIcon.vue';
import UiInput from './UiInput.vue';
import UiSheet from './UiSheet.vue';
import { ICON_COLORS, iconNames } from './itemIconPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Väljaren för itemets ikon, se issue 302 och [[ADR-0060 Itemets ikon]] § 5
 * och 8.
 *
 * **Namnlistan kommer ur samma glob som `ItemIcon` ritar ur.** `iconNames()`
 * läser globbens nycklar och hämtar ingen ikonfil, så väljaren kan räkna upp
 * hela Lucide utan att en enda ikon ligger i bundlen. Ikonerna i rutnätet
 * ritas av `ItemIcon`, alltså av samma komponent som raden och trädet
 * använder — en andra ritare hade kunnat välja en annan storlek eller en
 * annan fallback än den ytan valet syns på.
 *
 * **Rutnätet ritar 120 ikoner åt gången.** Lucide har omkring 1 900 filer,
 * och att rita alla på en gång hade hämtat alla på en gång — precis det som
 * den lata globben finns för att undvika. *Show more* höjer taket med 120,
 * och en ny sökning börjar om från 120: annars hade ett kvarglömt tak gjort
 * att en smal sökning ritade allt den matchade.
 *
 * **Sökningen är en delsträng i namnet, utan hänsyn till skiftläge.** Namnen
 * är Lucides och engelska (ADR-0060 § 8), och sökningen översätter dem inte.
 *
 * **Valet sker vid klicket och ägs av formuläret.** Varje klick skickar upp
 * det nya paret till `ItemForm`, som skriver det i `icon` och `icon_color`;
 * väljaren håller inget eget utkast. Därför visar `aria-pressed` alltid det
 * som faktiskt kommer att sparas, och Esc eller ett tryck utanför tappar
 * inget val användaren redan gjort. *Done* stänger arket.
 *
 * **En ikon utan färg får `slate`.** Färgen är frivillig i valet men inte i
 * paret: en ikon utan nyckel hade ritats neutral och sett ut som inget val
 * alls. `slate` är palettens neutrala nyckel (ADR-0060 § 5).
 *
 * **Sökfältet hindrar Enter från att skicka itemformuläret.** Arket ritas
 * inuti `ItemForm`s `<form>`, och Enter i ett textfält är en implicit
 * inlämning — utan `prevent` hade ett sökord sparat itemet. Samma fälla som
 * ParentPicker löser genom att flytta sin dialog till `<body>`; `UiSheet` gör
 * ingen sådan flytt, och därför står skyddet här.
 *
 * Rubriken, sökfältets etikett, färgernas ord och knapparna kommer ur
 * `lang/en/ui.php`: väljaren är ingen `Ui*.vue`, men den bär ingen egen
 * mening för det.
 */
const props = defineProps({
    /* Sant medan arket är öppet. ItemForm äger tillståndet. */
    open: { type: Boolean, default: false },
    /* Det nuvarande Lucide-namnet, eller null. */
    icon: { type: String, default: null },
    /* Den nuvarande palettnyckeln, eller null. */
    color: { type: String, default: null },
    /* Elementet som öppnade arket: fokus tillbaka dit när det stängs. */
    trigger: { type: Object, default: null },
});

const emit = defineEmits(['choose', 'remove', 'close']);

const { t } = useTranslations();

/* Taket per omgång. Lucide har omkring 1 900 ikoner; 120 ryms i arket. */
const PAGE_SIZE = 120;

const all = iconNames();

const query = ref('');
const shown = ref(PAGE_SIZE);

const filtered = computed(() => {
    const needle = query.value.trim().toLowerCase();

    if (needle === '') {
        return all;
    }

    return all.filter((name) => name.toLowerCase().includes(needle));
});

const visible = computed(() => filtered.value.slice(0, shown.value));

const hasMore = computed(() => filtered.value.length > shown.value);

watch(query, () => {
    shown.value = PAGE_SIZE;
});

/* Arket öppnas på itemets nuvarande val, med en tom sökning varje gång. */
watch(
    () => props.open,
    (open) => {
        if (open) {
            query.value = '';
            shown.value = PAGE_SIZE;
        }
    },
);

function chooseIcon(name) {
    emit('choose', { icon: name, color: props.color ?? 'slate' });
}

function chooseColor(tone) {
    emit('choose', { icon: props.icon, color: tone });
}

function remove() {
    emit('remove');
}

function showMore() {
    shown.value += PAGE_SIZE;
}

/*
 * Färgernas prickar. Klasserna står som hela strängar: Tailwind läser
 * källkoden statiskt och ser inte en klass som byggs med mallsträng.
 */
const SWATCHES = {
    slate: 'bg-tint-slate',
    blue: 'bg-tint-blue',
    sky: 'bg-tint-sky',
    teal: 'bg-tint-teal',
    green: 'bg-tint-green',
    amber: 'bg-tint-amber',
    orange: 'bg-tint-orange',
    violet: 'bg-tint-violet',
};
</script>

<template>
    <UiSheet
        :open="open"
        :heading="t('item.form.icon_heading')"
        :trigger="trigger"
        @close="emit('close')"
    >
        <FormField
            v-slot="{ describedBy }"
            :label="t('item.form.icon_search')"
            id="icon-search"
        >
            <UiInput
                id="icon-search"
                v-model="query"
                :described-by="describedBy"
                type="search"
                @keydown.enter.prevent
            />
        </FormField>

        <div class="grid grid-cols-6 gap-1">
            <button
                v-for="name in visible"
                :key="name"
                type="button"
                class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control text-ink outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                :class="name === icon ? 'bg-accent-soft' : null"
                :aria-label="name"
                :aria-pressed="name === icon ? 'true' : 'false'"
                @click="chooseIcon(name)"
            >
                <ItemIcon :name="name" :color="color" :size="20" />
            </button>
        </div>

        <button
            v-if="hasMore"
            type="button"
            class="inline-flex min-h-11 items-center justify-center rounded-control border border-border bg-surface px-3 text-meta font-medium text-ink outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
            @click="showMore"
        >
            {{ t('item.form.icon_show_more') }}
        </button>

        <div class="flex flex-col gap-2">
            <p class="text-body font-medium text-ink">{{ t('item.form.icon_color') }}</p>

            <div class="flex flex-wrap gap-1">
                <button
                    v-for="tone in ICON_COLORS"
                    :key="tone"
                    type="button"
                    class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                    :aria-label="t(`item.form.icon_colors.${tone}`)"
                    :aria-pressed="tone === color ? 'true' : 'false'"
                    @click="chooseColor(tone)"
                >
                    <span
                        aria-hidden="true"
                        class="inline-block h-5 w-5 rounded-full border border-border"
                        :class="SWATCHES[tone]"
                    />
                </button>
            </div>
        </div>

        <div class="flex items-center justify-between gap-2">
            <button
                type="button"
                class="inline-flex min-h-11 items-center justify-center rounded-control px-3 text-meta font-medium text-ink outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                @click="remove"
            >
                {{ t('item.form.icon_remove') }}
            </button>

            <button
                type="button"
                class="inline-flex min-h-11 items-center justify-center rounded-control bg-accent px-4 text-body font-medium text-ink-on-accent outline-none hover:bg-accent/90 focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                @click="emit('close')"
            >
                {{ t('item.form.icon_done') }}
            </button>
        </div>
    </UiSheet>
</template>
