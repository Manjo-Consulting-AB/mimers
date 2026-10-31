<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import FormField from './FormField.vue';
import ItemStructureTree from './ItemStructureTree.vue';
import UiIcon from './UiIcon.vue';
import UiInput from './UiInput.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Föräldraväljaren, se [[ADR-0048 Mobilen och plusknappen]] § 3 och
 * [[M23 Mobilen och kartan]] § 153.
 *
 * **Trädet är ItemStructureTree i sitt pick-läge.** Väljaren bygger inget
 * eget träd: samma komponent som detaljvyns vänsterpanel ritar samma noder,
 * och skillnaden är elementet — ett led är ett val i stället för en länk. Att
 * rita en andra trädvy hade varit en andra regel om ordning, indrag och
 * tomhet, och den hade glidit ifrån den första vid första ändringen.
 *
 * **Sökningen filtrerar på namn, i minnet.** Trädet kommer färdigfiltrerat på
 * omfång ur `structure`-proppen (App\Actions\Item\ResolveItemTree), så ett
 * item användaren inte når finns inte här att söka fram — och väljaren
 * frågar därför aldrig servern medan hon skriver (samma linje som
 * SearchField, issue 59b § Beslut 5). Ett led behålls när det matchar eller
 * när något UNDER det gör det: en träff utan sin väg vore ett item utan
 * plats, och ledet ovanför är hur hon ser var det hör hemma.
 *
 * **Raden överst är containern**, alltså toppnivån: *Ändra* ska kunna ta
 * tillbaka ett val, och utan den raden vore ett item skapat ur en länk
 * fastlåst under sin förälder. Raden är valbar bara när servern säger att
 * användaren får lägga en rot i containern (`can_create_root`,
 * ContainerPolicy::createItem) — annars hade väljaren erbjudit ett val
 * `store()` svarar 403 på.
 *
 * **Ett val bekräftas och sker inte vid trycket.** Ett tryck på ett led i ett
 * träd som går att söka är inte nödvändigtvis ett beslut, och den valda raden
 * markeras medan användaren letar vidare. *Välj här* skickar valet till
 * formuläret, som skriver det i `parent` — samma fält som länken fyllde
 * förut.
 *
 * **Valet prövas på servern.** `can_create` per nod är samma
 * `ItemPolicy::create` som `store()` prövar på föräldern; flaggan stänger
 * knappen och är en bekvämlighet ovanpå grinden, aldrig en grind i sig. Ett
 * item hon bara får läsa syns i trädet och går inte att välja.
 *
 * **Fokusfällan är webbläsarens** (issue 68b § Beslut 2): `showModal()` håller
 * fokus i dialogen medan den är öppen och Esc fyrar `close`, precis som i
 * UiSheet och MobileMenu. Ingen egen tabbhanterare och inget `aria-modal` —
 * den fällan är den som fastnar. Fokus lämnas tillbaka till *Ändra* genom
 * `trigger`, som är elementet som öppnade väljaren.
 *
 * **Flyttläget** (`move`, issue 345 · [[ADR-0069 Flytta ett item inom
 * containern]] § 11) är SAMMA väljare med andra regler, inte en andra
 * komponent: trädet, sökningen och dialogen är desamma.
 * - Itemet självt och dess ättlingar räknas fram ur `structure` här i
 *   minnet (`blocked`) och går inte att välja. Ingen fråga går till servern.
 * - Den nuvarande platsen — förälderns nod, eller containerraden när itemet
 *   står på toppnivån — är markerad med *Current place* och går inte att
 *   välja.
 * - En nod går att välja med `can_update`, containerraden med
 *   `can_create_root`.
 * - Har itemet flera föräldrar (`parents`) kommer först ett steg som frågar
 *   vilken placering som flyttas; svaret blir `from_parent`.
 * - *Move here* stänger inte dialogen: anroparen skickar flytten och stänger
 *   väljaren när den lyckas. Ett fel från servern (`error`) ritas i dialogen,
 *   så att användaren kan välja om utan att börja om.
 * Skapandets läge är oförändrat: alla nya proppar har neutrala förval.
 *
 * Rubriken, sökfältets etikett och knapparnas ord kommer ur `lang/en/ui.php`:
 * komponenten är ingen `Ui*.vue`, men den bär ingen egen mening för det.
 */
const props = defineProps({
    /* Sant medan väljaren är öppen. ItemForm äger tillståndet. */
    open: { type: Boolean, default: false },
    /* Containerns namn: raden överst, och platsen för ett item på toppnivån. */
    containerName: { type: String, default: '' },
    /* Trädet ur `structure`-proppen — `{ulid, name, can_create, children}`. */
    structure: { type: Array, default: () => [] },
    /* ULID:n för den nuvarande föräldern, eller null för toppnivån. */
    selected: { type: String, default: null },
    /* Får användaren lägga ett item på toppnivån? Ur `can_create_root`. */
    canCreateRoot: { type: Boolean, default: false },
    /* Elementet som öppnade väljaren: fokus tillbaka dit när den stängs. */
    trigger: { type: Object, default: null },
    /* Sant i flyttläget (issue 345). */
    move: { type: Boolean, default: false },
    /* Flyttens item: ULID och namn. */
    itemUlid: { type: String, default: null },
    itemName: { type: String, default: '' },
    /* Itemets föräldrar, `{ulid, name}` — tom när det står på toppnivån. */
    parents: { type: Array, default: () => [] },
    /* Felet från servern i flyttläget, eller null. */
    error: { type: String, default: null },
    /* Sant medan flytten skickas. */
    pending: { type: Boolean, default: false },
});

const emit = defineEmits(['choose', 'close']);

const { t } = useTranslations();

const dialogElement = ref(null);
const query = ref('');

/*
 * Det valda ledet — en nod ur trädet, eller null för containern. Noden och
 * inte ULID:n: bekräftelsen skickar namn och ULID vidare, och formuläret
 * ritar namnet i placeringsraden utan att leta i trädet efter det.
 */
const chosen = ref(null);

const chosenUlid = computed(() => chosen.value?.ulid ?? null);

/*
 * Får det valda ledet väljas? Containern följer sin egen grind och noderna
 * sin `can_create` — samma två flaggor servern skickade, lästa och inte
 * gissade.
 */
const canChoose = computed(() => {
    if (props.move) {
        return canMove.value;
    }

    return chosen.value === null
        ? props.canCreateRoot
        : chosen.value.can_create === true;
});

/*
 * Flyttläget. `picked` är sant när användaren tryckt på ett led — utan den
 * vore `chosen === null` både "inget val" och "containern vald". `placement` är
 * ULID:n för den placering som flyttas (null = toppnivån) och `step` om vi står
 * i frågan om placering eller i trädet.
 */
const picked = ref(false);
const placement = ref(null);
const step = ref('tree');

/* Itemet och dess ättlingar, ur trädet. Ett item under två föräldrar finns på båda ställena. */
const blocked = computed(() => {
    const ulids = new Set();

    if (! props.move || props.itemUlid === null) {
        return [];
    }

    const walk = (nodes, inside) => {
        for (const node of nodes) {
            const within = inside || node.ulid === props.itemUlid;

            if (within) {
                ulids.add(node.ulid);
            }

            walk(node.children, within);
        }
    };

    walk(props.structure, false);

    return [...ulids];
});

/* Containerraden är den nuvarande platsen när placeringen är toppnivån. */
const rootIsCurrent = computed(() => props.move && placement.value === null);

const rootActive = computed(() => (props.move ? picked.value && chosen.value === null : chosen.value === null));

const canMove = computed(() => {
    if (! picked.value) {
        return false;
    }

    if (chosen.value === null) {
        return props.canCreateRoot && ! rootIsCurrent.value;
    }

    return chosen.value.can_update === true
        && ! blocked.value.includes(chosen.value.ulid)
        && chosen.value.ulid !== placement.value;
});

const heading = computed(() => (props.move
    ? t('item.move.heading', { name: props.itemName })
    : t('item.form.parent_heading')));

const filtered = computed(() => filterNodes(props.structure, query.value.trim().toLowerCase()));

/* Sant när sökningen är tom på träffar — raden överst står kvar oavsett. */
const noMatches = computed(() => query.value.trim() !== '' && filtered.value.length === 0);

/*
 * Leden som matchar, och deras förfäder. Barnen till ett led som SJÄLVT
 * matchar filtreras som alla andra: sökningen svarar på namnet man skrev, och
 * att visa en hel gren för att grenens rot matchade hade fyllt rutan med
 * sådant man inte sökte.
 */
function filterNodes(nodes, needle) {
    if (needle === '') {
        return nodes;
    }

    const kept = [];

    for (const node of nodes) {
        const children = filterNodes(node.children, needle);

        if (children.length > 0 || node.name.toLowerCase().includes(needle)) {
            kept.push({ ...node, children });
        }
    }

    return kept;
}

/* Noden för en ULID, eller null när trädet inte bär den. */
function findNode(nodes, ulid) {
    for (const node of nodes) {
        if (node.ulid === ulid) {
            return node;
        }

        const found = findNode(node.children, ulid);

        if (found !== null) {
            return found;
        }
    }

    return null;
}

/*
 * Väljaren öppnas på det val formuläret redan har — den nuvarande föräldern
 * markerad, eller containern när itemet ligger på toppnivån — och sökningen
 * börjar tom varje gång. Ett kvarglömt sökord hade gömt det val användaren
 * öppnade väljaren för att se.
 */
watch(
    () => props.open,
    async (open) => {
        if (open) {
            query.value = '';
            chosen.value = props.selected === null ? null : findNode(props.structure, props.selected);

            if (props.move) {
                chosen.value = null;
                picked.value = false;
                placement.value = props.parents.length === 1 ? props.parents[0].ulid : null;
                step.value = props.parents.length > 1 ? 'placement' : 'tree';
            }

            await nextTick();

            dialogElement.value?.showModal();

            return;
        }

        dialogElement.value?.close();
    },
);

function choose() {
    if (! canChoose.value) {
        return;
    }

    if (props.move) {
        emit('choose', chosen.value, placement.value);

        return;
    }

    emit('choose', chosen.value);

    dialogElement.value?.close();
}

/* Steget före trädet: vilken placering flyttas? */
function pickPlacement(parent) {
    placement.value = parent.ulid;
    chosen.value = null;
    picked.value = false;
    step.value = 'tree';
}

function pickRoot() {
    chosen.value = null;
    picked.value = true;
}

function pickNode(node) {
    chosen.value = node;
    picked.value = true;
}

function close() {
    dialogElement.value?.close();
}

/*
 * Stängningen, oavsett väg in: Esc fyrar `close` precis som knapparna gör.
 * Fokus tillbaka till *Ändra* — utan den står fokus kvar i en dialog som
 * inte finns.
 */
function onClosed() {
    emit('close');

    props.trigger?.focus();
}
</script>

<template>
    <!--
        Dialogen flyttas till `<body>` och står inte kvar i formuläret.
        Väljaren ritas inuti ItemForms `<form>`, och ett `<dialog>` bryter
        ingen formägarhet: Enter i sökfältet hade varit en implicit
        inlämning av hela formuläret, alltså ett item skapat av ett
        sökord. En modal hör dessutom högst upp i dokumentet och inte inuti
        den form som råkade innehålla öppnaren.
    -->
    <Teleport to="body">
        <dialog
            ref="dialogElement"
            :aria-label="heading"
            class="m-0 h-full max-h-none w-full max-w-none bg-transparent p-0 backdrop:bg-ink/50"
            @close="onClosed"
        >
            <div class="flex h-full w-full flex-col justify-end md:items-center md:justify-center">
                <!--
                    Ytan utanför panelen. Dialogen täcker hela skärmen, så trycket
                    utanför landar här och inte i en bakgrund utan mål.
                -->
                <button
                    type="button"
                    class="absolute inset-0 min-h-11"
                    :aria-label="t('item.form.parent_cancel')"
                    @click="close"
                ></button>

                <div
                    class="relative flex max-h-full w-full flex-col gap-3 overflow-y-auto rounded-t-card bg-surface p-4 md:max-w-md md:rounded-card md:border md:border-border"
                >
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-title font-semibold text-ink">
                            {{ heading }}
                        </h2>

                        <button
                            type="button"
                            class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control border border-border text-ink-muted"
                            :aria-label="t('item.form.parent_cancel')"
                            @click="close"
                        >
                            <UiIcon name="close" :size="20" class="h-5 w-5" />
                        </button>
                    </div>

                    <!--
                        `autofocus` styr var fokus landar när `showModal()` öppnar
                        dialogen. Utan den tar webbläsaren första tabbbara element,
                        och det är tryckytan utanför panelen — en stängknapp i full
                        skärmstorlek som ingen ser.
                    -->
                    <!--
                        Flyttläget med flera föräldrar: frågan om placering kommer
                        före trädet. Ett val här är ett beslut — det går vidare
                        direkt — och *Back* i trädet leder hit igen.
                    -->
                    <div v-if="move && step === 'placement'" class="flex flex-col gap-1">
                        <p class="px-2 text-body font-medium text-ink">
                            {{ t('item.move.placement_heading') }}
                        </p>

                        <button
                            v-for="parent in parents"
                            :key="parent.ulid"
                            type="button"
                            class="flex min-h-11 items-center rounded-control px-2 text-left text-body text-ink-muted outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                            @click="pickPlacement(parent)"
                        >
                            {{ parent.name }}
                        </button>
                    </div>

                    <button
                        v-if="move && step === 'tree' && parents.length > 1"
                        type="button"
                        class="inline-flex min-h-11 items-center self-start rounded-control px-2 text-body text-accent outline-none hover:underline focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                        @click="step = 'placement'"
                    >
                        {{ t('item.move.placement_back') }}
                    </button>

                    <FormField
                        v-if="! move || step === 'tree'"
                        v-slot="{ describedBy }"
                        :label="t('item.form.parent_search')"
                        id="parent-picker-search"
                    >
                        <UiInput
                            id="parent-picker-search"
                            v-model="query"
                            type="search"
                            :described-by="describedBy"
                            autofocus
                        />
                    </FormField>

                    <div v-if="! move || step === 'tree'" class="flex max-h-80 flex-col gap-1 overflow-y-auto">
                        <!--
                            Toppnivån: containern själv. Ett item skapat ur en
                            länk ska gå att lyfta upp igen, och raden är valbar
                            bara när servern tillåter en rot i containern.
                        -->
                        <button
                            type="button"
                            :disabled="!canCreateRoot || rootIsCurrent"
                            :aria-current="rootActive ? 'true' : null"
                            class="flex min-h-11 items-center gap-2 rounded-control px-2 text-left text-body outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 disabled:opacity-50"
                            :class="rootActive
                                ? 'bg-accent-soft font-semibold text-accent'
                                : 'text-ink-muted hover:bg-surface-sunken'"
                            @click="pickRoot"
                        >
                            {{ containerName }}
                            <span class="text-meta">{{ t('item.form.location_root') }}</span>
                            <span v-if="rootIsCurrent" class="text-meta">{{ t('item.move.current') }}</span>
                        </button>

                        <ItemStructureTree
                            pick
                            :nodes="filtered"
                            :selected="move && ! picked ? null : chosenUlid"
                            :pick-flag="move ? 'can_update' : 'can_create'"
                            :blocked="blocked"
                            :current="move ? placement : null"
                            @choose="pickNode"
                        />

                        <p v-if="noMatches" class="px-2 text-body text-ink-muted">
                            {{ t('item.form.parent_empty') }}
                        </p>
                    </div>

                    <!--
                        En rå `<button>` och inte en UiButton: knappen bär en
                        `@click` och en `:disabled`, och GenomgangTest tillåter
                        bara webbläsarens egna element som klickbar yta — en
                        komponents rot är inte alltid en knapp. Klassen är
                        primärvariantens, så ytan är densamma.
                    -->
                    <!--
                        Felet från servern i flyttläget (`to_parent`). Ett
                        `role="alert"` läses upp när det dyker upp, och dialogen
                        står kvar öppen så att valet kan göras om.
                    -->
                    <p v-if="move && error" role="alert" class="text-body text-danger">
                        {{ error }}
                    </p>

                    <button
                        v-if="! move || step === 'tree'"
                        type="button"
                        :disabled="!canChoose || pending"
                        class="inline-flex min-h-11 items-center justify-center self-end rounded-control bg-accent px-4 font-medium text-ink-on-accent outline-none hover:bg-accent/90 focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 disabled:opacity-50"
                        @click="choose"
                    >
                        {{ move ? t('item.move.choose') : t('item.form.parent_choose') }}
                    </button>
                </div>
            </div>
        </dialog>
    </Teleport>
</template>
