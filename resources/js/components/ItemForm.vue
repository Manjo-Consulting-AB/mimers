<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import ItemCoverPicker from './ItemCoverPicker.vue';
import ItemIcon from './ItemIcon.vue';
import ItemIconPicker from './ItemIconPicker.vue';
import ParentPicker from './ParentPicker.vue';
import TaxonomyPicker from './TaxonomyPicker.vue';
import UiButton from './UiButton.vue';
import UiConfirmDialog from './UiConfirmDialog.vue';
import UiInput from './UiInput.vue';
import UiSelect from './UiSelect.vue';
import UiTextarea from './UiTextarea.vue';
import FieldTypeSelect from './fields/FieldTypeSelect.vue';
import FieldValueField from './fields/FieldValueField.vue';
import { hasStoredValue, initialFieldValue, normalizeFields } from './fields/fieldPresentation.js';
import { categoryOptions } from './categoryTree.js';
import { useCreationUpload } from '../composables/useCreationUpload.js';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * Formuläret för ett item — se issue 57b § Beslut 4, 5, 6, 7 och 9.
 *
 * Egen komponent och inte ett formulär i var sin sida: skapandet och
 * redigeringen bär samma tio fält, och den enda skillnaden är `account`
 * (bara vid skapandet), startvärdena, metoden och knappens ord. Två avskrifter
 * av samma fält hade glidit isär vid första ändringen — samma skäl som
 * AccountSettingsForm ligger i components/.
 *
 * Komponenten ligger i components/ och inte i pages/Containers/Items/: Inertia
 * löser upp sidnamn mot `./pages/**\/*.vue` (resources/js/app.js), så en
 * .vue-fil bland vyerna blir en sida som går att rendera utan att någon rutt
 * pekar på den. Den här är ingen sida — den är en del av Create och Edit.
 *
 * **Kategorin och taggarna är samma väljare i två lägen** (§ Beslut 5, och
 * sedan issue 336 [[ADR-0068 Itemets sida och skapandet]] § 13). Ett item
 * ligger i HÖGST en kategori (`single`), men kan bära hur många taggar som
 * helst (`multiple`) — [[ADR-0004 Fria taggar och kategorier]] — och
 * TaxonomyPicker visar den skillnaden. Kategoriträdet byggs av
 * resources/js/components/categoryTree.js, precis som kategorisidan bygger
 * det; inget nytt beroende. Att en ny tagg inte går att skapa härifrån är
 * avsiktligt: taggsidan finns, och en väg till samma skrivning på två ställen
 * är två regler att hålla i takt.
 *
 * **Båda fälten skickas ALLTID** (§ Beslut 6). `category: null` tömmer
 * kategorin och `tags: []` tömmer taggmängden, och det är hela skillnaden mot
 * en partiell PATCH från `/api` — se App\Http\Controllers\ItemController::
 * update(), som därför inte har någon `has()`-gren.
 *
 * **`account` finns bara vid skapandet** (§ Beslut 4). `UpdateItemRequest` tar
 * inte emot fältet, och formuläret ritar det inte i redigeringsläget: vem som
 * skapade raden är historik. Kontolistan kommer ur den delade propen
 * `auth.accounts` — en egen fråga för samma lista är en fråga för mycket.
 *
 * **Datumfälten är `<input type="date">`** (§ Beslut 7). Webbläsaren skickar
 * då `Y-m-d`, vilket är exakt vad `date_format:Y-m-d` i den delade
 * FormRequesten kräver — ingen egen datumtolkning i JavaScript, och ingen
 * `maxlength` på `description`, som är TEXT och inte har någon längdregel.
 *
 * **Anteckningen finns inte i formuläret** (M42 · issue 325 ·
 * [[ADR-0066 Itemets anteckning]] § 9, och sedan issue 338
 * [[ADR-0068 Itemets sida och skapandet]] § 8). Den skrivs i sin egen ruta på
 * itemets översikt, och formuläret skickar aldrig `notes`: två textfält bredvid
 * varandra gjorde skillnaden mot beskrivningen otydlig. `StoreItemRequest` och
 * `UpdateItemRequest` tar fortfarande emot fältet, så `/api` fungerar som förut.
 *
 * **Skapandet har två nivåer** (issue 338 · [[ADR-0068 Itemets sida och
 * skapandet]] § 8). Direkt synliga är namn, plats, kategori, taggar och ikon.
 * Resten — beskrivningen, containerns tomma fält, itemets egna tomma fält och,
 * med fler än ett konto, kontot — står i en `<details>` med rubriken *More
 * details*, hopfälld från början. Sedan issue 420
 * ([[ADR-0089 Egna fält på items]] § Riktning 5) är de sex fasta fälten
 * containerns fält och ritas av samma `FieldValueField` som alla andra.
 * Fälten ligger alltid i DOM:en, och det är `<details>` som fäller ihop dem,
 * inte en `v-if` (samma linje som AccessLevelField). Kommer servern tillbaka
 * med ett fel på ett av dem fälls avsnittet ut av sig självt, så att felet går
 * att se och att flytta fokus till. Redigeringen behåller sina fält i dagens
 * ordning, utan rubriken.
 *
 * Ingen egen validering: reglerna bor i StoreItemRequest/UpdateItemRequest och
 * felen renderas av FormField vid sitt fält ([[ADR-0021 Frontendteknik]]).
 *
 * **Placeringen är ett val** (issue 58 § Beslut 7, issue 153 ·
 * [[ADR-0048 Mobilen och plusknappen]] § 3). Detaljvyns *Nytt item under det
 * här* skickar `?parent={ulid}`, och länken fyller därför placeringsraden:
 * containern, föräldern och hur raden ska läsas. Sedan issue 153 går valet
 * att ändra — *Ändra* öppnar ParentPicker, som ritar containerns träd ur
 * `structure`-proppen och skriver sitt svar i samma `parent`-fält. Servern
 * prövar det valet på nytt i `store()`: `can_create` per nod är
 * `ItemPolicy::create`, samma grind som förut, och en förfalskad `parent`
 * avvisas där.
 *
 * Raden ritas bara i skapandeläget: `UpdateItemRequest` tar inte emot
 * `parent`, och ett item som redan finns byter inte förälder här. Ett item
 * utan förälder visar containern och *Top level* — toppnivån är en plats och
 * inte ett tomt fält, samma linje som `category_none`.
 *
 * **Omslagsbilden är en väljare och bara i redigeringsläget** (issue 93 ·
 * [[ADR-0041 Itemets vy]] § Beslut). `images` är itemets bilder ur
 * App\Actions\Item\ResolveItemCover::images() — äldst först och redan
 * filtrerade till `kind = 'image'` — och formuläret väljer ur den listan,
 * sorterar den aldrig om och letar aldrig själv bland bilagorna: en andra
 * regel om vad som är en bild glider ifrån den första. `cover` är den VALDA
 * bildens ULID, aldrig den upplösta: väljer användaren ingenting är pekaren
 * null och upplösningens steg 2 gäller, och då ska väljaren visa just det.
 * Annars gick valet aldrig att ta tillbaka.
 *
 * **Fältet skickas bara när väljaren ritas.** Ingen bild i itemet betyder
 * inget fält — ett val mellan ett alternativ är ingen fråga (samma regel som
 * det enda kontot nedan) — och då finns heller inget val att uttrycka:
 * `UpdateItemRequest` säger `sometimes|nullable`, så ett UTELÄMNAT `cover`
 * lämnar pekaren orörd medan ett skickat `cover: null` rensar den. Utan den
 * regeln hade ett namnbyte på ett item vars enda bild ligger i papperskorgen
 * tyst raderat valet, och en återställning ur papperskorgen hade inte gett
 * tillbaka omslaget ([[ADR-0008 Soft delete och papperskorg]]).
 *
 * Skapandeläget skickar inte `cover` alls — itemet har inga bilagor än, och
 * `StoreItemRequest` tar inte emot fältet.
 *
 * **Ikonen väljs i båda lägena** (issue 302 · [[ADR-0060 Itemets ikon]] § 8,
 * och sedan issue 338 även vid skapandet). `icon` och `icon_color` skickas alltid:
 * `StoreItemRequest` och `UpdateItemRequest` tar emot dem, och ett uttryckligt
 * `null` är ett item utan ikon. Väljaren (ItemIconPicker.vue) håller inget eget
 * utkast: varje klick skriver det valda paret här, och *Done* stänger bara arket.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    categories: { type: Array, required: true },
    tags: { type: Array, required: true },
    /* Itemets bilder, äldst först; tom i skapandeläget. */
    images: { type: Array, default: () => [] },
    /* Den valda bildens ULID, eller null när inget val är gjort. */
    cover: { type: String, default: null },
    /* Kontolistan ur den delade propen `auth.accounts`; tom i redigeringsläget. */
    accounts: { type: Array, default: () => [] },
    /* Det förvalda kontot — containerns ägarkonto när användaren är medlem i det. */
    account: { type: String, default: '' },
    /* Itemet som redigeras, eller null när ett nytt item skapas. */
    item: { type: Object, default: null },
    /* Föräldern ur `?parent`, eller null för ett item på toppnivån. */
    parent: { type: Object, default: null },
    /* Barnet ur `?child` (issue 1298), eller null: det nya itemet blir dess förälder. */
    child: { type: Object, default: null },
    /* Kategorins ULID ur `?category` (issue 339), förvald vid skapandet. */
    preselected: { type: String, default: null },
    /* Itemet som just skapades med *Save and add another*: `{ulid, name}` eller null. */
    created: { type: Object, default: null },
    /* Containerns namn, till placeringsraden och väljarens översta rad. */
    containerName: { type: String, default: '' },
    /* Väljarens träd, med `can_create` per nod (issue 153). */
    structure: { type: Array, default: () => [] },
    /* Får användaren lägga itemet på toppnivån? Ur `can_create_root`. */
    canCreateRoot: { type: Boolean, default: false },
    /* Får användaren skapa taggar och kategorier? Ur `can.manage_taxonomy`. */
    canManageTaxonomy: { type: Boolean, default: false },
    /*
     * Fälten som hör till itemet (issue 419 · [[ADR-0089 Egna fält på items]]
     * § Riktning 1 och 2): containerns på skapandesidan (ItemFieldResource)
     * och containerns plus itemets egna i redigeringen (ItemResource:s
     * `item.fields`). Formen slätas ut av normalizeFields() — språket här är
     * ett, så mallen inte behöver veta vilken resurs raderna kom ur.
     */
    fields: { type: Array, default: () => [] },
    /*
     * Taket för itemets EGNA fält, `fields.max_per_item`. Noll betyder att
     * knappen *Add field* inte ritas — skapandeformuläret har inget item att
     * sätta egna fält på.
     */
    fieldLimit: { type: Number, default: 0 },
    /* Fältets typer, `ItemField::TYPES`, till typvalet i *Add field*. */
    types: { type: Array, default: () => [] },
    /*
     * Inbäddat läge (issue 1163 · [[ADR-0074 Registrering i vyn]] § 1–3):
     * formuläret ligger i `InlineCreate` på en lista och står kvar efter en
     * sparning. Utan flaggan fungerar formuläret som förut.
     */
    inline: { type: Boolean, default: false },
    /* Ytan sparningen ska landa på igen (`page.url`); bara i det inbäddade läget. */
    returnTo: { type: String, default: null },
});

const emit = defineEmits(['dirty', 'created', 'cancel']);

const formElement = ref(null);

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();
const { failure: creationFailure } = useCreationUpload();

const options = computed(() => categoryOptions(props.categories));

/*
 * Kontot ritas bara när det finns något att välja mellan (Beslut 4 och issue
 * 338): ett val mellan ett alternativ är ingen fråga. Värdet är ändå förvalt,
 * så formuläret skickar rätt konto.
 */
const showAccount = computed(() => props.item === null && props.accounts.length > 1);

/*
 * Väljaren ritas bara när det finns något att välja mellan: i
 * redigeringsläget, och bara om itemet har bilder (issue 93). Ett item som
 * just skapas har inga bilagor, och `StoreItemRequest` har ingen regel för
 * fältet.
 */
const showCover = computed(() => props.item !== null && props.images.length > 0);

/*
 * De egna fälten, se issue 419 och [[ADR-0089 Egna fält på items]]
 * § Riktning 1–3. Varje fält blir en rad i formuläret, och värdet skickas som
 * `fields: [{field: <ULID>, value: …}]` — kroppen
 * App\Actions\Field\ItemFieldInput prövar mot fältets typ.
 *
 * **Tomma fält skickas också, som en tom sträng.** Servern tolkar `''` som
 * "töm fältet" och tar bort raden (issue 418), och att skicka ALLA fält gör
 * att felens index i `fields.N.value` pekar på samma rad som användaren ser.
 * Att bara skicka de ifyllda hade gjort indexet till en funktion av vad hon
 * råkade skriva.
 */
const incomingFields = computed(() => normalizeFields(props.fields));

const initialFieldRows = () => incomingFields.value.map((field) => ({
    field: field.id,
    value: initialFieldValue(field),
}));

/*
 * Fältet har ett sparat värde: då står det direkt i formuläret. Ett tomt fält
 * ligger under *More details* vid skapandet, som containerns övriga tomma
 * fält gör. Frågan ställs mot det SPARADE värdet och inte mot det man skriver
 * just nu, så en rad inte hoppar mellan avsnitten mitt i en mening. Ett
 * ja/nej-fält har alltid ett värde och står alltid direkt
 * ([[ADR-0099 Kryssrutefält]]): `hasStoredValue()` svarar ja för typen.
 */
const visibleFields = computed(() => incomingFields.value.filter(hasStoredValue));
const foldedFields = computed(() => incomingFields.value.filter((field) => ! hasStoredValue(field)));

const fields = {
    name: props.item?.name ?? '',
    description: props.item?.description ?? '',
    category: props.item?.category ?? null,
    tags: props.item?.tags?.map((tag) => tag.ulid) ?? [],
    /*
     * Omslagsbilden: nyckeln finns bara när väljaren ritas (issue 93).
     * Ritas hon inte finns inget val att uttrycka, och ett utelämnat `cover`
     * lämnar pekaren orörd därför att UpdateItemRequest säger `sometimes` —
     * se docblocket.
     */
    ...(showCover.value ? { cover: props.cover ?? null } : {}),
    /*
     * Ikonen och färgen (issue 302, och vid skapandet issue 338): itemets egna
     * kolumner, eller null för ett nytt item.
     */
    icon: props.item?.icon ?? null,
    icon_color: props.item?.icon_color ?? null,
};

const form = useForm(props.item === null
    ? {
        account: props.account,
        parent: props.parent?.ulid ?? null,
        child: props.child?.ulid ?? null,
        ...fields,
        /* Kategorin ur `?category` efter *Save and add another* (issue 339). */
        category: props.preselected,
        fields: initialFieldRows(),
    }
    : { ...fields, fields: initialFieldRows() });

/*
 * Nya och borttagna egna fält. *Add field* skriver på servern och sidan ritas
 * om med `preserveState`, så propen får nya rader medan formuläret står kvar:
 * de ifyllda värdena flyttas med, en ny rad får sin tomma sträng och en
 * borttagen rad faller bort. Utan raden här hade en nyskapad rad aldrig fått
 * ett värde att skriva i.
 *
 * **Den nya raden flyttas också in i formulärets `defaults`.** Annars blir
 * formuläret smutsigt av att servern svarade — användaren har inte ändrat
 * något — och lämna-dialogen dyker upp efter ett lyckat *Add field* eller en
 * borttagning. Defaultvärdet per rad sparas i `fieldRowDefaults`: det
 * användaren redan skrivit i en befintlig rad är fortfarande osparat och ska
 * fortsätta räknas som en ändring.
 */
const fieldRowDefaults = new Map(initialFieldRows().map((row) => [row.field, row.value]));

watch(incomingFields, (next) => {
    const current = new Map(form.fields.map((row) => [row.field, row.value]));

    form.fields = next.map((field) => ({
        field: field.id,
        value: current.has(field.id) ? current.get(field.id) : initialFieldValue(field),
    }));

    for (const field of next) {
        if (! fieldRowDefaults.has(field.id)) {
            fieldRowDefaults.set(field.id, initialFieldValue(field));
        }
    }

    form.defaults('fields', form.fields.map((row) => ({
        field: row.field,
        value: fieldRowDefaults.get(row.field),
    })));
}, { deep: true });

const fieldValue = (id) => form.fields.find((row) => row.field === id)?.value ?? '';

function setFieldValue(id, value) {
    const row = form.fields.find((candidate) => candidate.field === id);

    if (row !== undefined) {
        row.value = value;
    }
}

/*
 * Serverns fel för ett eget fält ligger på `fields.<index>.value` — index är
 * radens plats i listan som skickades, alltså samma plats den ritas på.
 * `fields.<index>.field` bär `field.not_applicable` och hör till samma rad.
 */
const fieldError = (id) => {
    const index = form.fields.findIndex((row) => row.field === id);

    if (index === -1) {
        return null;
    }

    return form.errors[`fields.${index}.value`] ?? form.errors[`fields.${index}.field`] ?? null;
};

const leaveVisit = ref(null);
let leaveConfirmed = false;

function guardNavigation(event) {
    const visit = event.detail.visit;

    if (
        props.inline
        || leaveConfirmed
        || ! form.isDirty
        || visit.prefetch
        || visit.method !== 'get'
        || visit.url.pathname === window.location.pathname
    ) {
        return;
    }

    leaveVisit.value = visit;

    return false;
}

function confirmLeave() {
    const visit = leaveVisit.value;

    leaveVisit.value = null;
    leaveConfirmed = true;

    try {
        router.visit(visit.url.href, {
            method: 'get',
            replace: visit.replace,
            preserveScroll: visit.preserveScroll,
        });
    } finally {
        leaveConfirmed = false;
    }
}

function warnBeforeUnload(event) {
    event.preventDefault();
    event.returnValue = '';
}

const removeBeforeListener = router.on('before', guardNavigation);

/* `InlineCreate` frågar via sin `dirty`-prop i stället för `leaveVisit` (issue 1163). */
watch(
    () => form.isDirty,
    (dirty) => emit('dirty', dirty),
    { immediate: true },
);

watch(
    () => form.isDirty,
    (dirty) => {
        if (dirty) {
            window.addEventListener('beforeunload', warnBeforeUnload);

            return;
        }

        window.removeEventListener('beforeunload', warnBeforeUnload);
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    removeBeforeListener();
    window.removeEventListener('beforeunload', warnBeforeUnload);
});

/*
 * Platsen: containern och föräldern, och det val väljaren gör (issue 153).
 * Den egna ref:en och inte `props.parent` direkt, därför att valet ändrar
 * raden medan formuläret står öppet — proppen är vad länken gav, och den
 * ritas bara en gång.
 */
const location = ref(props.parent);

/*
 * Barnet (issue 1298 · [[ADR-0080 Ny förälder från kartan]] § 2): en rad och
 * ingen väljare. Raden och det postade fältet töms på samma ställe.
 */
const aboveChild = ref(props.child);

function removeChild() {
    aboveChild.value = null;
    form.child = null;
}

const pickerOpen = ref(false);
const changeTrigger = ref(null);

function openPicker(element) {
    changeTrigger.value = element;
    pickerOpen.value = true;
}

/*
 * Valet ur väljaren: en nod ur trädet, eller null för toppnivån. Både raden
 * och det postade fältet skrivs här, så att de två aldrig kan visa olika
 * saker — raden är vad användaren ser, `parent` vad `store()` prövar.
 */
function chooseParent(node) {
    location.value = node;
    form.parent = node?.ulid ?? null;
}

/*
 * Ikonen (issue 302). Väljaren skickar hela paret, så ikonen och färgen
 * aldrig kan visa två olika val; *Remove icon* tömmer båda, för en färg utan
 * ikon är inget val.
 */
const iconPickerOpen = ref(false);
const iconTrigger = ref(null);

function openIconPicker(element) {
    iconTrigger.value = element;
    iconPickerOpen.value = true;
}

function chooseIcon({ icon, color }) {
    form.icon = icon;
    form.icon_color = color;
}

function removeIcon() {
    form.icon = null;
    form.icon_color = null;
}

/*
 * *More details* (issue 338): hopfälld från början, och utfälld av sig själv
 * när servern svarar med ett fel på ett fält under rubriken. `toggle` håller
 * `detailsOpen` i takt med det användaren själv gör med `<summary>`.
 */
const detailFields = [
    'description',
    'account',
];
const detailsOpen = ref(false);

/* Ett fel på ett eget fält ligger under samma rubrik, så det fäller ut
   avsnittet som de fasta fälten gör (issue 419). */
const fieldDetailsHasError = computed(
    () => Object.keys(form.errors).some((name) => name.startsWith('fields.')),
);

const detailsHasError = computed(
    () => detailFields.some((name) => form.errors[name] !== undefined) || fieldDetailsHasError.value,
);

watch(detailsHasError, (hasError) => {
    if (hasError) {
        detailsOpen.value = true;
    }
});

/*
 * Taggfelet ligger på `tags.0`, `tags.1`, ... och inte på `tags` — regeln bor
 * på `tags.*` i den delade FormRequesten (issue 13b § Beslut 5). Väljaren
 * är ett fält och inte en grupp av fält, så den första taggnyckeln i felpåsen blir
 * gruppens fel.
 */
const tagError = computed(() => {
    const key = Object.keys(form.errors).find((name) => name === 'tags' || name.startsWith('tags.'));

    return key === undefined ? null : form.errors[key];
});

/*
 * Även platsen kan komma tillbaka med ett fel: `parent` prövas av
 * StoreItemRequest mot containern och mot mjukradering, och `store()` prövar
 * ItemPolicy::create på nytt — så en ULID som hunnit bli ogiltig mellan
 * sidladdning och postning (itemet kastat, eller åtkomsten indragen medan
 * formuläret stod öppet) ger ett äkta 422 på `parent`. Sedan issue 153 är
 * fältet användarvalt ur en väljare man kan bläddra i, så fönstret är reellt;
 * före dess kom det bara från en nyss auktoriserad länk.
 *
 * Platsraden är komponentens enda fält utan FormField, och därför den enda
 * felraden som måste ritas för hand. Id:t är `parent-error`, samma som
 * FormField hade gett, så att useErrorFocus.focusFirstError hittar hit — utan
 * det blir ett avvisat val en tyst omladdning.
 */
const parentError = computed(() => form.errors.parent ?? null);

/* Serverns fel på `child` — en cykel, eller ett barn hon inte får ändra. */
const childError = computed(() => form.errors.child ?? null);

/*
 * Knappens ord byter medan servern svarar (issue 68a § Beslut 4 och 5): en
 * knapp vars etikett står still medan svaret är på väg ser ut som en död sida.
 * Ordet "Skapa"/"Spara" kommer tillbaka när anropet är klart.
 */
const submitLabel = computed(() => (form.processing
    ? t('common.pending.default')
    : (props.item === null ? t('item.create.submit') : t('item.edit.submit'))));

/*
 * Nya taggar och kategorier ur väljaren (issue 337 · [[ADR-0068 Itemets sida
 * och skapandet]] § 14). Anropet går till de BEFINTLIGA ändpunkterna —
 * `POST /containers/{c}/tags` och `/categories` — som prövar `update` på
 * containern; ingen ny rutt eller grind. `preserveState` behåller formulärets
 * inmatning, och ändpunktens `back()` ritar om sidan med de nya listorna.
 * Det nya värdet slås upp på namnet i den uppdaterade sidans prop och väljs.
 *
 * Valideringsfelet kommer i `onError` och hålls här, skilt från `form.errors`:
 * ändpunktens fältnamn (`name`) är inte formulärets egna.
 */
const taxonomyErrors = ref({ category: null, tags: null });
const taxonomyCreating = ref(false);

function createTaxonomy(kind, name) {
    const isTag = kind === 'tags';

    taxonomyErrors.value[kind] = null;

    router.post(`/containers/${props.containerUlid}/${isTag ? 'tags' : 'categories'}`, { name }, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => {
            taxonomyCreating.value = true;
        },
        onSuccess: (page) => {
            const needle = name.trim().toLowerCase();
            const created = page.props[isTag ? 'tags' : 'categories']
                .find((option) => option.name.trim().toLowerCase() === needle
                    && (isTag || (option.parent ?? null) === null));

            if (created === undefined) {
                return;
            }

            if (isTag) {
                form.tags = [...form.tags, created.ulid];
            } else {
                form.category = created.ulid;
            }
        },
        onError: (errors) => {
            taxonomyErrors.value[kind] = Object.values(errors)[0] ?? null;
        },
        onFinish: () => {
            taxonomyCreating.value = false;
        },
    });
}

/*
 * Itemets EGNA fält (issue 419 · [[ADR-0089 Egna fält på items]]
 * § Riktning 2): knappen *Add field*, och en borttagning per fält.
 *
 * **Knappen finns bara i redigeringen.** Ett eget fält hör till ett item, och
 * ett nytt item finns inte förrän det sparats; containerfälten ritas i båda
 * lägena och behöver ingen knapp.
 *
 * **Anropet går till den BEFINTLIGA ändpunkten** — `POST
 * /containers/{c}/items/{item}/fields` ur issue 417 — med `preserveState`,
 * samma grep som tagg- och kategorieskapandet ovan: formulärets osparade
 * inmatning står kvar, och sidan ritar om med den nya raden. Grinden är
 * `ItemPolicy::update`, som redigeringssidan redan kräver.
 *
 * Rubriken och typen skickas som de är; `position` utelämnas, och
 * App\Actions\Field\CreateField sätter nästa lediga. Felet — gränsen eller
 * rubrikkrocken — kommer tillbaka på nyckeln `field`.
 */
const ownFields = computed(() => incomingFields.value.filter((field) => field.scope === 'item'));

const atFieldLimit = computed(
    () => props.item !== null && props.fieldLimit > 0 && ownFields.value.length >= props.fieldLimit,
);

const canAddField = computed(() => props.item !== null && props.fieldLimit > 0 && ! atFieldLimit.value);

const addFormOpen = ref(false);
const addLabel = ref('');
const addType = ref(props.types[0] ?? 'text');
const addPending = ref(false);
/* Gränsen och rubrikkrocken — domänens fel, utan egen fältnyckel. */
const addFieldError = ref(null);
/* Formatfelen på rubriken och typen, som hör till sina egna fält. */
const addLabelError = ref(null);

function openAddForm() {
    addFieldError.value = null;
    addLabelError.value = null;
    addFormOpen.value = true;
}

function createOwnField() {
    addFieldError.value = null;
    addLabelError.value = null;
    addPending.value = true;

    router.post(
        `/containers/${props.containerUlid}/items/${props.item.ulid}/fields`,
        { label: addLabel.value.trim(), type: addType.value },
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                addLabel.value = '';
                addType.value = props.types[0] ?? 'text';
                addFormOpen.value = false;
            },
            onError: (errors) => {
                addFieldError.value = errors.field ?? null;
                addLabelError.value = errors.label ?? errors.type ?? null;
            },
            onFinish: () => {
                addPending.value = false;
            },
        },
    );
}

/*
 * Borttagningen frågar först, som containerns fältsida: definitionen går till
 * papperskorgen tillsammans med sina värden, och frågan säger det. Fältet
 * försvinner ur formuläret när servern svarat, genom watch:en på propen.
 */
const fieldToRemove = ref(null);
const removeTrigger = ref(null);
const removePending = ref(false);

function askRemoveField(field, event) {
    removeTrigger.value = event.currentTarget;
    fieldToRemove.value = field;
}

function removeOwnField() {
    if (fieldToRemove.value === null) {
        return;
    }

    removePending.value = true;

    router.delete(
        `/containers/${props.containerUlid}/items/${props.item.ulid}/fields/${fieldToRemove.value.id}`,
        {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => {
                removePending.value = false;
                fieldToRemove.value = null;
            },
        },
    );
}

/*
 * *Cancel* (issue 339 · [[ADR-0068 Itemets sida och skapandet]] § 9): tillbaka
 * dit användaren kom ifrån — förälderns sida när formuläret har en förälder,
 * annars itemfliken.
 */
const cancelUrl = computed(() => (props.parent
    ? `/containers/${props.containerUlid}/items/${props.parent.ulid}`
    : `/containers/${props.containerUlid}/items`));

const createdUrl = computed(() => (props.created
    ? `/containers/${props.containerUlid}/items/${props.created.ulid}`
    : null));

/*
 * *Save and add another* skickar samma formulär med `another: true`. Fältet
 * läggs på i `transform` och inte i `form`, så att det aldrig hamnar i
 * formulärets tillstånd eller i `isDirty`.
 */
function submitAnother() {
    uploadImageAfterCreate();

    form.transform((data) => ({ ...data, another: true }))
        .post(`/containers/${props.containerUlid}/items`, { onError: focusFirstError });
}

/*
 * Bilden vid skapandet (issue 341 · [[ADR-0068 Itemets sida och skapandet]]
 * § 11). Bilden ligger i en egen ref och aldrig i `form`: den följer inte med
 * skapandets POST och hamnar inte i `isDirty`. Itemet skapas först, som förut,
 * och när svaret kommit står sidan på itemet — då laddas bilden upp med ETT
 * `router.post` mot den befintliga bilageändpunkten, med samma fält som
 * ItemAttachmentSection skickar (`file` och `account`). Ingen hash och ingen
 * `cover` skickas: servern räknar hashen, kvoten räknas på kontot formuläret
 * skapade itemet med, och omslaget är itemets äldsta bild (ADR-0041).
 *
 * Lyssnaren sätts per sparande och tas bort av sig själv. Den bor utanför
 * komponentens livscykel med flit: på *Save* byts sidan mot itemets vy och
 * formuläret avmonteras innan `success` hunnit levereras.
 */
const image = ref(null);
const imageInput = ref(null);
const imageError = ref(null);
const imageUploading = ref(false);

function chooseImage(event) {
    const file = event.target.files?.[0] ?? null;

    imageError.value = null;

    if (file !== null && ! file.type.startsWith('image/')) {
        event.target.value = '';
        image.value = null;
        imageError.value = t('item.form.image_only');

        return;
    }

    image.value = file;
}

function clearImage() {
    image.value = null;
    imageError.value = null;

    if (imageInput.value !== null) {
        imageInput.value.value = '';
    }
}

function uploadImageAfterCreate() {
    const file = image.value;
    const account = form.account;

    if (file === null) {
        return;
    }

    const stops = [];
    const stop = () => stops.forEach((off) => off());

    stops.push(router.on('success', (event) => {
        stop();
        clearImage();
        uploadImage(file, account, event.detail.page);
    }));
    stops.push(router.on('error', stop));
    stops.push(router.on('finish', stop));
}

function uploadImage(file, account, page) {
    const itemUlid = page.props.item?.ulid ?? page.props.created?.ulid ?? null;

    if (itemUlid === null) {
        return;
    }

    const itemUrl = `/containers/${props.containerUlid}/items/${itemUlid}`;

    /*
     * Misslyckas uppladdningen finns itemet ändå. Felet bärs till itemets
     * Documents-flik, som ritar det, och sidan byts dit.
     */
    const fail = (message) => {
        creationFailure.value = { item: itemUlid, message };
        router.get(`${itemUrl}?tab=attachments`, {}, { replace: true, preserveScroll: true });
    };

    router.post(`${itemUrl}/attachments`, { file, account }, {
        forceFormData: true,
        preserveScroll: true,
        onStart: () => {
            imageUploading.value = true;
        },
        onError: (errors) => fail(errors.file ?? errors.account ?? null),
        onHttpException: () => fail(null),
        onNetworkError: () => fail(null),
        onFinish: () => {
            imageUploading.value = false;
        },
    });
}

/*
 * Sparningen i det inbäddade läget (issue 1163): `return` för servern tillbaka
 * till listan, och sidan behåller rullning och tillstånd. Formuläret töms men
 * kategorin och föräldern står kvar som nya startvärden, så att nästa item
 * hamnar på samma plats. Fokus går till namnet.
 */
function submitInline() {
    form.transform((data) => ({ ...data, return: props.returnTo }))
        .post(`/containers/${props.containerUlid}/items`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: async () => {
                form.defaults({ category: form.category, parent: form.parent });
                form.reset();

                await nextTick();

                formElement.value?.querySelector('#name')?.focus();
                emit('created');
            },
            onError: focusFirstError,
        });
}

function submit() {
    const url = `/containers/${props.containerUlid}/items`;

    if (props.inline && props.item === null) {
        submitInline();

        return;
    }

    if (props.item === null) {
        uploadImageAfterCreate();

        form.transform((data) => data).post(url, { onError: focusFirstError });

        return;
    }

    form.patch(`${url}/${props.item.ulid}`, { onError: focusFirstError });
}
</script>

<template>
    <form ref="formElement" class="flex max-w-lg flex-col gap-4" @submit.prevent="submit">
        <!-- Raden efter *Save and add another* (issue 339): vilket item som
             just skapades, med en länk till det. -->
        <p v-if="created && createdUrl" role="status" class="text-body">
            <Link
                :href="createdUrl"
                class="text-accent underline outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
            >
                {{ t('item.create.created', { name: created.name }) }}
            </Link>
        </p>

        <FormField
            v-slot="{ describedBy }"
            :label="t('item.form.name')"
            id="name"
            :error="form.errors.name"
        >
            <UiInput
                id="name"
                v-model="form.name"
                :described-by="describedBy"
                name="name"
                required
            />
        </FormField>

        <!--
            Placeringen: containern, föräldern och vägen till väljaren
            (issue 153 · [[ADR-0048 Mobilen och plusknappen]] § 3, bild 7 i
            docs/Design/mobil.png: namnet först och platsen under det). Raden
            står i skapandeläget också när länken inte gav någon förälder —
            toppnivån är en plats och inte ett tomt fält, och *Ändra* ska
            kunna ge itemet en förälder även då.
        -->
        <div v-if="item === null" class="flex items-start justify-between gap-2">
            <div class="flex flex-col gap-1">
                <p class="text-body font-medium text-ink">{{ t('item.form.location') }}</p>
                <p class="text-body">
                    {{ containerName }} /
                    {{ location ? location.name : t('item.form.location_root') }}
                </p>
                <p v-if="location" class="text-body text-ink-muted">
                    {{ t('item.form.location_hint', { name: location.name }) }}
                </p>

                <!-- Serverns fel på `parent`, på samma form som FormFields
                     rad: `role="alert"` läser upp det när det ritas, och
                     `tabindex="-1"` låter focusFirstError flytta hit. -->
                <p
                    v-if="parentError"
                    id="parent-error"
                    role="alert"
                    tabindex="-1"
                    class="text-body text-danger outline-none focus:ring-2 focus:ring-focus focus:ring-offset-2"
                >
                    {{ parentError }}
                </p>
            </div>

            <!--
                En rå `<button>` och inte en UiButton, av samma skäl som
                plusknappen i skalet: knappen skickar sitt EGET element vidare
                till väljaren, och den som fångar det är den som ritade den.
                Klassen är sekundärvariantens — linje, yta och 44 px
                träffyta — så ytan är densamma som UiButtons.
            -->
            <button
                type="button"
                class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-control border border-border bg-surface px-3 text-meta font-medium text-ink outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                :aria-describedby="parentError ? 'parent-error' : undefined"
                @click="openPicker($event.currentTarget)"
            >
                {{ t('item.form.location_change') }}
            </button>
        </div>

        <!-- Barnet (issue 1298): raden under placeringsraden, med en knapp som
             tar bort det. Felet på `child` ritas här, med id:t som
             useErrorFocus letar efter. -->
        <div v-if="item === null && (aboveChild || childError)" class="flex flex-col gap-1">
            <div v-if="aboveChild" class="flex items-center justify-between gap-2">
                <p class="text-body">{{ t('item.form.child_above', { name: aboveChild.name }) }}</p>

                <button
                    type="button"
                    class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-control border border-border bg-surface px-3 text-meta font-medium text-ink outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                    :aria-describedby="childError ? 'child-error' : undefined"
                    @click="removeChild"
                >
                    {{ t('item.form.child_remove') }}
                </button>
            </div>

            <p
                v-if="childError"
                id="child-error"
                role="alert"
                tabindex="-1"
                class="text-body text-danger outline-none focus:ring-2 focus:ring-focus focus:ring-offset-2"
            >
                {{ childError }}
            </p>
        </div>

        <ParentPicker
            v-if="item === null"
            :open="pickerOpen"
            :container-name="containerName"
            :structure="structure"
            :selected="location?.ulid ?? null"
            :can-create-root="canCreateRoot"
            :trigger="changeTrigger"
            @choose="chooseParent"
            @close="pickerOpen = false"
        />

        <!-- Ikonen: den nuvarande ikonen med sin ruta, eller den neutrala,
             och knappen som öppnar väljaren (issue 302 · [[ADR-0060 Itemets
             ikon]] § 8). I redigeringen står den under namnet, som förut. -->
        <div v-if="item !== null" class="flex flex-col gap-2">
            <p class="text-body font-medium text-ink">{{ t('item.form.icon') }}</p>

            <div class="flex items-center gap-2">
                <ItemIcon :name="form.icon" :color="form.icon_color" :size="24" tile />

                <button
                    type="button"
                    class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-control border border-border bg-surface px-3 text-meta font-medium text-ink outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                    @click="openIconPicker($event.currentTarget)"
                >
                    {{ t('item.form.icon_choose') }}
                </button>
            </div>
        </div>

        <template v-if="item === null">
            <!-- Kategorin: en sökbar väljare ur trädet, ett värde. -->
            <FormField
                v-if="options.length > 0 || canManageTaxonomy"
                v-slot="{ describedBy }"
                :label="t('item.form.category')"
                id="category"
                :error="form.errors.category ?? taxonomyErrors.category"
            >
                <TaxonomyPicker
                    id="category"
                    v-model="form.category"
                    :options="options"
                    mode="single"
                    :can-create="canManageTaxonomy && ! taxonomyCreating"
                    :disabled="taxonomyCreating"
                    @create="(name) => createTaxonomy('category', name)"
                    :described-by="describedBy"
                />
            </FormField>

            <!-- Tom container utan rätt att skapa: en rad, aldrig en tom väljare. -->
            <p v-else class="text-body text-ink-muted">
                {{ t('item.form.categories_empty') }}
            </p>

            <!-- Taggarna: samma väljare, flera värden. -->
            <FormField
                v-if="tags.length > 0 || canManageTaxonomy"
                v-slot="{ describedBy }"
                :label="t('item.form.tags')"
                id="tags"
                :error="tagError ?? taxonomyErrors.tags"
            >
                <TaxonomyPicker
                    id="tags"
                    v-model="form.tags"
                    :options="tags"
                    mode="multiple"
                    :can-create="canManageTaxonomy && ! taxonomyCreating"
                    :disabled="taxonomyCreating"
                    @create="(name) => createTaxonomy('tags', name)"
                    :described-by="describedBy"
                />
            </FormField>

            <p v-else class="text-body text-ink-muted">
                {{ t('item.form.tags_empty') }}
            </p>
        </template>

        <!-- Ikonen: den nuvarande ikonen med sin ruta, eller den neutrala,
             och knappen som öppnar väljaren (issue 302 · [[ADR-0060 Itemets
             ikon]] § 8). Vid skapandet (issue 338) står den sist av de
             synliga fälten, efter kategorin och taggarna. -->
        <div v-if="item === null" class="flex flex-col gap-2">
            <p class="text-body font-medium text-ink">{{ t('item.form.icon') }}</p>

            <div class="flex items-center gap-2">
                <ItemIcon :name="form.icon" :color="form.icon_color" :size="24" tile />

                <button
                    type="button"
                    class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-control border border-border bg-surface px-3 text-meta font-medium text-ink outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                    @click="openIconPicker($event.currentTarget)"
                >
                    {{ t('item.form.icon_choose') }}
                </button>
            </div>
        </div>

        <ItemIconPicker
            :open="iconPickerOpen"
            :icon="form.icon"
            :color="form.icon_color"
            :trigger="iconTrigger"
            @choose="chooseIcon"
            @remove="removeIcon"
            @close="iconPickerOpen = false"
        />

        <!-- Bilden vid skapandet (issue 341): en bild och ingen annan fil. Den
             laddas upp efter att itemet skapats och blir omslaget. -->
        <FormField
            v-if="item === null && ! inline"
            v-slot="{ describedBy }"
            :label="t('item.form.image')"
            id="image"
            :error="imageError"
        >
            <input
                id="image"
                ref="imageInput"
                type="file"
                name="image"
                accept="image/*"
                :aria-describedby="describedBy"
                :disabled="form.processing || imageUploading"
                class="block w-full text-sm"
                @change="chooseImage"
            >
        </FormField>

        <!-- Omslagsbilden: ett rutnät av itemets bilder som miniatyrer, med
             *No cover* först (issue 93, issue 342). Fältet ritas bara i redigeringsläget och bara när
             itemet har bilder; `images` kommer färdigfiltrerad och sorterad
             ur App\Actions\Item\ResolveItemCover::images(). -->
        <ItemCoverPicker
            v-if="showCover"
            v-model="form.cover"
            :label="t('item.form.cover')"
            :images="images"
            :error="form.errors.cover"
        />

        <!--
            De egna fälten som redan har ett värde (issue 419 ·
            [[ADR-0089 Egna fält på items]] § Riktning 1 och 2). De står
            direkt i skapandeformuläret, som namn och kategori; de tomma
            ligger under *More details* nedanför — en tom etikett är ingen
            uppgift, och frågan ställs mot det SPARADE värdet så raden inte
            hoppar mellan avsnitten medan man skriver.
        -->
        <template v-if="item === null">
            <FieldValueField
                v-for="field in visibleFields"
                :key="field.id"
                :field="field"
                :model-value="fieldValue(field.id)"
                :error="fieldError(field.id)"
                @update:model-value="(value) => setFieldValue(field.id, value)"
            />
        </template>

        <!--
            Beskrivningen och de övriga fälten (issue 338 · [[ADR-0068 Itemets
            sida och skapandet]] § 8). Vid skapandet står de i en `<details>`
            med rubriken *More details*, hopfälld från början och utfälld av
            sig själv vid ett serverfel på något av dem. I redigeringen är
            omslaget ett `display: contents`-element: fälten ligger direkt i
            formuläret i dagens ordning, och ingen rubrik ritas.
        -->
        <component
            :is="item === null ? 'details' : 'div'"
            :class="item === null ? 'rounded-control border border-border bg-surface' : 'contents'"
            v-bind="item === null ? { open: detailsOpen } : {}"
            @toggle="item === null && (detailsOpen = $event.target.open)"
        >
            <summary
                v-if="item === null"
                class="min-h-11 cursor-pointer rounded-control px-3 py-2.5 text-body font-medium text-ink outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
            >
                {{ t('item.form.more_details') }}
            </summary>

            <div :class="item === null ? 'flex flex-col gap-4 border-t border-border p-3' : 'contents'">
                <FormField
                    v-slot="{ describedBy }"
                    :label="t('item.form.description')"
                    id="description"
                    :error="form.errors.description"
                >
                    <UiTextarea
                        id="description"
                        v-model="form.description"
                        :described-by="describedBy"
                        name="description"
                    />
                </FormField>

                <!--
                    De egna fälten utan värde (issue 419), hopfällda som
                    resten av *More details* vid skapandet. Ett serverfel på
                    ett av dem fäller ut avsnittet — samma regel som de fasta
                    fälten, se `detailsHasError` ovan.
                -->
                <template v-if="item === null">
                    <FieldValueField
                        v-for="field in foldedFields"
                        :key="field.id"
                        :field="field"
                        :model-value="fieldValue(field.id)"
                        :error="fieldError(field.id)"
                        @update:model-value="(value) => setFieldValue(field.id, value)"
                    />
                </template>

                <!--
                    Redigeringen: ALLA fält, i den ordning servern gav dem —
                    containerns först och itemets egna efter. Här finns ingen
                    hopfällning att lägga dem i, och knappen *Add field* står
                    under dem. Ett EGET fält kan tas bort; ett containerfält
                    kan bara tas bort på containerns egen sida
                    ([[ADR-0089 Egna fält på items]] § Riktning 2).
                -->
                <template v-if="item !== null">
                    <FieldValueField
                        v-for="field in incomingFields"
                        :key="field.id"
                        :field="field"
                        :model-value="fieldValue(field.id)"
                        :error="fieldError(field.id)"
                        @update:model-value="(value) => setFieldValue(field.id, value)"
                    >
                        <template v-if="field.scope === 'item'" #action>
                            <button
                                type="button"
                                class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-control border border-border bg-surface px-3 text-meta font-medium text-danger outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                                @click="askRemoveField(field, $event)"
                            >
                                {{ t('item.fields.remove') }}
                            </button>
                        </template>
                    </FieldValueField>
                </template>

                <!-- Kontot: bara med fler än ett konto att välja mellan (Beslut 4 och
                     issue 338). Ett enda konto är förvalt och ritas inte alls. -->
                <FormField
                    v-if="showAccount"
                    v-slot="{ describedBy }"
                    :label="t('item.form.account')"
                    id="account"
                    :error="form.errors.account"
                >
                    <UiSelect
                        id="account"
                        v-model="form.account"
                        :described-by="describedBy"
                        name="account"
                        required
                    >
                        <option v-for="account in accounts" :key="account.ulid" :value="account.ulid">
                            {{ account.name }}
                        </option>
                    </UiSelect>
                </FormField>
            </div>
        </component>

        <!--
            Itemets egna fält, knappen *Add field* (issue 419 ·
            [[ADR-0089 Egna fält på items]] § Riktning 2). Bara i
            redigeringen: ett nytt item finns inte förrän det sparats.
            Anropet går till itemets egen fältändpunkt (issue 417) med
            `preserveState`, så formulärets osparade inmatning står kvar.
        -->
        <section v-if="item !== null" class="flex flex-col gap-3 border-t border-border pt-4">
            <template v-if="canAddField">
                <div v-if="addFormOpen" class="flex flex-col gap-3">
                    <FormField
                        v-slot="{ describedBy }"
                        :label="t('item.fields.label')"
                        id="item-field-label"
                        :error="addLabelError"
                    >
                        <!-- Enter skapar fältet och skickar INTE itemets
                             formulär: add-ytan ligger inuti det formuläret,
                             och ett naket Enter hade sparat itemet i
                             stället. -->
                        <UiInput
                            id="item-field-label"
                            v-model="addLabel"
                            :described-by="describedBy"
                            name="label"
                            @keydown.enter.prevent="createOwnField"
                        />
                    </FormField>

                    <FormField
                        v-slot="{ describedBy }"
                        :label="t('item.fields.type')"
                        id="item-field-type"
                    >
                        <FieldTypeSelect
                            id="item-field-type"
                            v-model="addType"
                            :types="types"
                            :described-by="describedBy"
                        />
                    </FormField>

                    <div class="flex flex-wrap items-center gap-2">
                        <UiButton :pending="addPending" @click="createOwnField">
                            {{ addPending ? t('common.pending.default') : t('item.fields.add') }}
                        </UiButton>

                        <UiButton variant="secondary" @click="addFormOpen = false">
                            {{ t('item.fields.add_cancel') }}
                        </UiButton>
                    </div>

                    <p
                        v-if="addFieldError"
                        role="alert"
                        class="rounded border border-danger bg-surface px-3 py-2 text-meta text-danger"
                    >
                        {{ addFieldError }}
                    </p>
                </div>

                <button
                    v-else
                    type="button"
                    class="inline-flex min-h-11 items-center justify-center self-start rounded-control border border-border bg-surface px-3 text-meta font-medium text-ink outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                    @click="openAddForm"
                >
                    {{ t('item.fields.add') }}
                </button>
            </template>

            <p v-else-if="atFieldLimit" class="text-meta text-ink-muted">
                {{ t('item.fields.limit_reached', { limit: fieldLimit }) }}
            </p>
        </section>

        <template v-if="item !== null">
            <!-- Kategorin: en sökbar väljare ur trädet, ett värde. -->
            <FormField
                v-if="options.length > 0 || canManageTaxonomy"
                v-slot="{ describedBy }"
                :label="t('item.form.category')"
                id="category"
                :error="form.errors.category ?? taxonomyErrors.category"
            >
                <TaxonomyPicker
                    id="category"
                    v-model="form.category"
                    :options="options"
                    mode="single"
                    :can-create="canManageTaxonomy && ! taxonomyCreating"
                    :disabled="taxonomyCreating"
                    @create="(name) => createTaxonomy('category', name)"
                    :described-by="describedBy"
                />
            </FormField>

            <!-- Tom container utan rätt att skapa: en rad, aldrig en tom väljare. -->
            <p v-else class="text-body text-ink-muted">
                {{ t('item.form.categories_empty') }}
            </p>

            <!-- Taggarna: samma väljare, flera värden. -->
            <FormField
                v-if="tags.length > 0 || canManageTaxonomy"
                v-slot="{ describedBy }"
                :label="t('item.form.tags')"
                id="tags"
                :error="tagError ?? taxonomyErrors.tags"
            >
                <TaxonomyPicker
                    id="tags"
                    v-model="form.tags"
                    :options="tags"
                    mode="multiple"
                    :can-create="canManageTaxonomy && ! taxonomyCreating"
                    :disabled="taxonomyCreating"
                    @create="(name) => createTaxonomy('tags', name)"
                    :described-by="describedBy"
                />
            </FormField>

            <p v-else class="text-body text-ink-muted">
                {{ t('item.form.tags_empty') }}
            </p>
        </template>

        <div class="flex flex-wrap items-center gap-2">
            <UiButton type="submit" :pending="form.processing">
                {{ submitLabel }}
            </UiButton>

            <template v-if="item === null">
                <UiButton
                    v-if="! inline"
                    variant="secondary"
                    :pending="form.processing"
                    @click="submitAnother"
                >
                    {{ t('item.create.submit_another') }}
                </UiButton>

                <button
                    v-if="inline"
                    type="button"
                    class="inline-flex min-h-11 items-center justify-center rounded-control border border-border bg-surface px-4 text-body font-medium text-ink outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                    @click="emit('cancel')"
                >
                    {{ t('item.create.cancel') }}
                </button>

                <Link
                    v-else
                    :href="cancelUrl"
                    class="inline-flex min-h-11 items-center justify-center rounded-control border border-border bg-surface px-4 text-body font-medium text-ink outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                >
                    {{ t('item.create.cancel') }}
                </Link>
            </template>
        </div>

        <!-- Borttagningen av ett EGET fält frågar först (issue 419): fältet
             och dess värden går till papperskorgen och kan återställas
             därifrån i 30 dagar. Fältet lämnar formuläret när servern svarat,
             genom watch:en på propen. -->
        <UiConfirmDialog
            :open="fieldToRemove !== null"
            :heading="t('item.fields.remove_heading', { label: fieldToRemove?.label ?? '' })"
            :body="t('item.fields.remove_body')"
            :confirm-label="t('item.fields.remove_confirm')"
            :pending="removePending"
            :trigger="removeTrigger"
            tone="danger"
            @confirm="removeOwnField"
            @cancel="fieldToRemove = null"
        />

        <UiConfirmDialog
            :open="leaveVisit !== null"
            :heading="t('item.form.leave_heading')"
            :body="t('item.form.leave_body')"
            :confirm-label="t('item.form.leave_confirm')"
            tone="danger"
            @confirm="confirmLeave"
            @cancel="leaveVisit = null"
        />
    </form>
</template>
