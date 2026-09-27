<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import FormField from './FormField.vue';
import ItemStructureTree from './ItemStructureTree.vue';
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
const canChoose = computed(() => (chosen.value === null
    ? props.canCreateRoot
    : chosen.value.can_create === true));

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

    emit('choose', chosen.value);

    dialogElement.value?.close();
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
            :aria-label="t('item.form.parent_heading')"
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
                            {{ t('item.form.parent_heading') }}
                        </h2>

                        <button
                            type="button"
                            class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control border border-border text-ink-muted"
                            :aria-label="t('item.form.parent_cancel')"
                            @click="close"
                        >
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.5"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="h-5 w-5"
                                aria-hidden="true"
                            >
                                <path d="m6 6 12 12"></path>
                                <path d="m18 6-12 12"></path>
                            </svg>
                        </button>
                    </div>

                    <!--
                        `autofocus` styr var fokus landar när `showModal()` öppnar
                        dialogen. Utan den tar webbläsaren första tabbbara element,
                        och det är tryckytan utanför panelen — en stängknapp i full
                        skärmstorlek som ingen ser.
                    -->
                    <FormField
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

                    <div class="flex max-h-80 flex-col gap-1 overflow-y-auto">
                        <!--
                            Toppnivån: containern själv. Ett item skapat ur en
                            länk ska gå att lyfta upp igen, och raden är valbar
                            bara när servern tillåter en rot i containern.
                        -->
                        <button
                            type="button"
                            :disabled="!canCreateRoot"
                            :aria-current="chosenUlid === null ? 'true' : null"
                            class="flex min-h-11 items-center gap-2 rounded-control px-2 text-left text-body outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 disabled:opacity-50"
                            :class="chosenUlid === null
                                ? 'bg-accent-soft font-semibold text-accent'
                                : 'text-ink-muted hover:bg-surface-sunken'"
                            @click="chosen = null"
                        >
                            {{ containerName }}
                            <span class="text-meta">{{ t('item.form.location_root') }}</span>
                        </button>

                        <ItemStructureTree
                            pick
                            :nodes="filtered"
                            :selected="chosenUlid"
                            @choose="chosen = $event"
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
                    <button
                        type="button"
                        :disabled="!canChoose"
                        class="inline-flex min-h-11 items-center justify-center self-end rounded-control bg-accent px-4 font-medium text-ink-on-accent outline-none hover:bg-accent/90 focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 disabled:opacity-50"
                        @click="choose"
                    >
                        {{ t('item.form.parent_choose') }}
                    </button>
                </div>
            </div>
        </dialog>
    </Teleport>
</template>
