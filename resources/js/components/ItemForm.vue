<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import ItemIcon from './ItemIcon.vue';
import ItemIconPicker from './ItemIconPicker.vue';
import ParentPicker from './ParentPicker.vue';
import TaxonomyPicker from './TaxonomyPicker.vue';
import UiButton from './UiButton.vue';
import UiInput from './UiInput.vue';
import UiSelect from './UiSelect.vue';
import UiTextarea from './UiTextarea.vue';
import { categoryOptions } from './categoryTree.js';
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
 * **Anteckningen lämnar redigeringsläget** (M42 · issue 325 ·
 * [[ADR-0066 Itemets anteckning]] § 9). Fältet ritas bara när ett item
 * SKAPAS: när ett item ändras sparas anteckningen i sin egen ruta på
 * översikten och formuläret skickar inte `notes` alls. Annars hade
 * formulärets gamla text skrivit över det som sparats av sig självt.
 * `UpdateItemRequest` tar fortfarande emot fältet, så `/api` och webbens
 * PATCH fungerar som förut.
 *
 * Fältet är ett eget fält bredvid beskrivningen (issue 96) och inte en
 * fortsättning på den: beskrivningen säger vad itemet ÄR, anteckningen vad
 * användaren VET om det. `notes` är TEXT som `description` och har därför
 * ingen `maxlength`; ett tömt fält skickas som tom sträng, vilket
 * `sometimes|nullable` gör till `null`.
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
 * **Ikonen väljs i redigeringsläget och bara där** (issue 302 ·
 * [[ADR-0060 Itemets ikon]] § 8). Ett item som just skapas har ingen ikon att
 * visa, och sektionen ritas därför i samma gren som omslagsbilden: `item` är
 * inte null. De nuvarande värdena är itemets egna kolumner i `ItemResource`,
 * och de skickas alltid med PATCH — `UpdateItemRequest` säger
 * `sometimes|nullable`, så ett uttryckligt `null` tömmer båda. Väljaren
 * (ItemIconPicker.vue) håller inget eget utkast: varje klick skriver det
 * valda paret här, och *Done* stänger bara arket.
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
    /* Containerns namn, till placeringsraden och väljarens översta rad. */
    containerName: { type: String, default: '' },
    /* Väljarens träd, med `can_create` per nod (issue 153). */
    structure: { type: Array, default: () => [] },
    /* Får användaren lägga itemet på toppnivån? Ur `can_create_root`. */
    canCreateRoot: { type: Boolean, default: false },
    /* Får användaren skapa taggar och kategorier? Ur `can.manage_taxonomy`. */
    canManageTaxonomy: { type: Boolean, default: false },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

const options = computed(() => categoryOptions(props.categories));

/*
 * Ett enda konto ritas som en läsbar rad i stället för en väljare (Beslut 4):
 * ett val mellan ett alternativ är ingen fråga. Värdet är ändå förvalt, så
 * formuläret skickar rätt konto.
 */
const singleAccount = computed(() => (props.accounts.length === 1 ? props.accounts[0] : null));

/*
 * Väljaren ritas bara när det finns något att välja mellan: i
 * redigeringsläget, och bara om itemet har bilder (issue 93). Ett item som
 * just skapas har inga bilagor, och `StoreItemRequest` har ingen regel för
 * fältet.
 */
const showCover = computed(() => props.item !== null && props.images.length > 0);

const fields = {
    name: props.item?.name ?? '',
    description: props.item?.description ?? '',
    /*
     * `notes` följer bara med när itemet skapas (M42 · issue 325 ·
     * [[ADR-0066 Itemets anteckning]] § 9). I redigeringsläget ägs
     * anteckningen av sin egen ruta på översikten, och ett `notes` här hade
     * skrivit över det som sparats av sig självt.
     */
    ...(props.item === null ? { notes: '' } : {}),
    manufacturer: props.item?.manufacturer ?? '',
    model: props.item?.model ?? '',
    serial_number: props.item?.serial_number ?? '',
    purchased_at: props.item?.purchased_at ?? '',
    warranty_until: props.item?.warranty_until ?? '',
    position_note: props.item?.position_note ?? '',
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
     * Ikonen och färgen (issue 302): itemets egna kolumner, bara i
     * redigeringsläget. Ett item som skapas har inget val att bära, och
     * `StoreItemRequest` får dem inte heller av det här formuläret.
     */
    ...(props.item !== null ? {
        icon: props.item.icon ?? null,
        icon_color: props.item.icon_color ?? null,
    } : {}),
};

const form = useForm(props.item === null
    ? { account: props.account, parent: props.parent?.ulid ?? null, ...fields }
    : fields);

/*
 * Platsen: containern och föräldern, och det val väljaren gör (issue 153).
 * Den egna ref:en och inte `props.parent` direkt, därför att valet ändrar
 * raden medan formuläret står öppet — proppen är vad länken gav, och den
 * ritas bara en gång.
 */
const location = ref(props.parent);

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

function submit() {
    const url = `/containers/${props.containerUlid}/items`;

    if (props.item === null) {
        form.post(url, { onError: focusFirstError });

        return;
    }

    form.patch(`${url}/${props.item.ulid}`, { onError: focusFirstError });
}
</script>

<template>
    <form class="flex max-w-lg flex-col gap-4" @submit.prevent="submit">
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
             ikon]] § 8). Sektionen ritas bara i redigeringsläget — ett item
             som just skapas har ingen ikon att visa. -->
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

        <ItemIconPicker
            v-if="item !== null"
            :open="iconPickerOpen"
            :icon="form.icon"
            :color="form.icon_color"
            :trigger="iconTrigger"
            @choose="chooseIcon"
            @remove="removeIcon"
            @close="iconPickerOpen = false"
        />

        <!-- Omslagsbilden: en väljare ur itemets bilder, med "inget val" överst
             (issue 93). Fältet ritas bara i redigeringsläget och bara när
             itemet har bilder; `images` kommer färdigfiltrerad och sorterad
             ur App\Actions\Item\ResolveItemCover::images(). -->
        <FormField
            v-if="showCover"
            v-slot="{ describedBy }"
            :label="t('item.form.cover')"
            id="cover"
            :error="form.errors.cover"
        >
            <UiSelect
                id="cover"
                v-model="form.cover"
                :described-by="describedBy"
                name="cover"
            >
                <option :value="null">{{ t('item.form.cover_none') }}</option>
                <option v-for="image in images" :key="image.ulid" :value="image.ulid">
                    {{ image.filename }}
                </option>
            </UiSelect>
        </FormField>

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

        <!-- Anteckningen: ett eget fält bredvid beskrivningen (issue 96), och
             bara i skapandeläget (M42 · issue 325 · [[ADR-0066 Itemets
             anteckning]] § 9). Ett item som redan finns sparar sin anteckning
             i sin egen ruta på översikten; formuläret skickar den inte. -->
        <FormField
            v-if="item === null"
            v-slot="{ describedBy }"
            :label="t('item.form.notes')"
            id="notes"
            :error="form.errors.notes"
        >
            <UiTextarea
                id="notes"
                v-model="form.notes"
                :described-by="describedBy"
                name="notes"
            />
        </FormField>

        <FormField
            v-slot="{ describedBy }"
            :label="t('item.form.manufacturer')"
            id="manufacturer"
            :error="form.errors.manufacturer"
        >
            <UiInput
                id="manufacturer"
                v-model="form.manufacturer"
                :described-by="describedBy"
                name="manufacturer"
            />
        </FormField>

        <FormField
            v-slot="{ describedBy }"
            :label="t('item.form.model')"
            id="model"
            :error="form.errors.model"
        >
            <UiInput
                id="model"
                v-model="form.model"
                :described-by="describedBy"
                name="model"
            />
        </FormField>

        <FormField
            v-slot="{ describedBy }"
            :label="t('item.form.serial_number')"
            id="serial_number"
            :error="form.errors.serial_number"
        >
            <UiInput
                id="serial_number"
                v-model="form.serial_number"
                :described-by="describedBy"
                name="serial_number"
            />
        </FormField>

        <FormField
            v-slot="{ describedBy }"
            :label="t('item.form.purchased_at')"
            id="purchased_at"
            :error="form.errors.purchased_at"
        >
            <UiInput
                id="purchased_at"
                v-model="form.purchased_at"
                :described-by="describedBy"
                type="date"
                name="purchased_at"
            />
        </FormField>

        <FormField
            v-slot="{ describedBy }"
            :label="t('item.form.warranty_until')"
            id="warranty_until"
            :error="form.errors.warranty_until"
        >
            <UiInput
                id="warranty_until"
                v-model="form.warranty_until"
                :described-by="describedBy"
                type="date"
                name="warranty_until"
            />
        </FormField>

        <FormField
            v-slot="{ describedBy }"
            :label="t('item.form.position_note')"
            id="position_note"
            :error="form.errors.position_note"
        >
            <UiInput
                id="position_note"
                v-model="form.position_note"
                :described-by="describedBy"
                name="position_note"
            />
        </FormField>

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

        <!-- Ett enda konto: värdet är förvalt och visas som text (Beslut 4). -->
        <div v-if="item === null && singleAccount" class="flex flex-col gap-1">
            <p class="text-body font-medium text-ink">{{ t('item.form.account') }}</p>
            <p>{{ singleAccount.name }}</p>
        </div>

        <FormField
            v-else-if="item === null"
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

        <UiButton type="submit" :pending="form.processing" class="self-start">
            {{ submitLabel }}
        </UiButton>
    </form>
</template>
