<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import UiButton from './UiButton.vue';
import UiCheckbox from './UiCheckbox.vue';
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
 * **Kategorin är en väljare ur trädet, taggarna är kryssrutor** (§ Beslut 5).
 * Ett item ligger i HÖGST en kategori, men kan bära hur många taggar som
 * helst — [[ADR-0004 Fria taggar och kategorier]] — och formen visar den
 * skillnaden. Kategoriträdet byggs av resources/js/components/categoryTree.js,
 * precis som kategorisidan bygger det; ingen kombibox, ingen autocomplete och
 * inget nytt beroende. Att en ny tagg inte går att skapa härifrån är
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
 * **Anteckningen är ett eget fält bredvid beskrivningen** (issue 96 ·
 * [[ADR-0041 Itemets vy]] § Beslut). Två textrutor, två kolumner, två
 * betydelser: beskrivningen säger vad itemet ÄR, anteckningen vad användaren
 * VET om det. Formuläret fyller aldrig den ena ur den andra och visar dem
 * inte som en text — slås de ihop blir beskrivningen en uppsättning eller
 * anteckningen en rubrik. `notes` är TEXT som `description` och har därför
 * ingen `maxlength` heller; ett tömt fält skickas som tom sträng, vilket
 * `sometimes|nullable` gör till `null`.
 *
 * Ingen egen validering: reglerna bor i StoreItemRequest/UpdateItemRequest och
 * felen renderas av FormField vid sitt fält ([[ADR-0021 Frontendteknik]]).
 *
 * **Föräldern kommer ur länken och är inget val** (issue 58 § Beslut 7).
 * Detaljvyns *Nytt item under det här* skickar `?parent={ulid}`, kontrollern
 * har redan auktoriserat mot föräldern, och formuläret visar den som en rad
 * text — en väljare hade varit en andra fråga om något användaren redan
 * besvarat. `parent` skickas bara i skapandeläget: `UpdateItemRequest` tar
 * inte emot fältet, och ett item som redan finns byter inte förälder här.
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
    notes: props.item?.notes ?? '',
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
};

const form = useForm(props.item === null
    ? { account: props.account, parent: props.parent?.ulid ?? null, ...fields }
    : fields);

/*
 * Taggfelet ligger på `tags.0`, `tags.1`, ... och inte på `tags` — regeln bor
 * på `tags.*` i den delade FormRequesten (issue 13b § Beslut 5). Kryssrutorna
 * är en grupp och inte ett fält, så den första taggnyckeln i felpåsen blir
 * gruppens fel.
 */
const tagError = computed(() => {
    const key = Object.keys(form.errors).find((name) => name === 'tags' || name.startsWith('tags.'));

    return key === undefined ? null : form.errors[key];
});

/*
 * Knappens ord byter medan servern svarar (issue 68a § Beslut 4 och 5): en
 * knapp vars etikett står still medan svaret är på väg ser ut som en död sida.
 * Ordet "Skapa"/"Spara" kommer tillbaka när anropet är klart.
 */
const submitLabel = computed(() => (form.processing
    ? t('common.pending.default')
    : (props.item === null ? t('item.create.submit') : t('item.edit.submit'))));

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
        <!-- Föräldern ur länken, som en rad text och inte som en väljare
             (issue 58 § Beslut 7). Värdet är ändå med i postningen. -->
        <p v-if="item === null && parent" class="text-body text-ink-muted">
            {{ t('item.form.parent', { name: parent.name }) }}
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

        <!-- Anteckningen: ett eget fält bredvid beskrivningen och inte en
             fortsättning på den (issue 96). Beskrivningen säger vad itemet
             ÄR, anteckningen vad användaren VET om det — ingen av dem fylls
             ur den andra, och `notes: null` tömmer fältet. -->
        <FormField
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

        <!-- Kategorin: en väljare ur trädet, indraget efter djup. -->
        <FormField
            v-if="options.length > 0"
            v-slot="{ describedBy }"
            :label="t('item.form.category')"
            id="category"
            :error="form.errors.category"
        >
            <UiSelect
                id="category"
                v-model="form.category"
                :described-by="describedBy"
                name="category"
            >
                <option :value="null">{{ t('item.form.category_none') }}</option>
                <option v-for="option in options" :key="option.ulid" :value="option.ulid">
                    {{ option.name }}
                </option>
            </UiSelect>
        </FormField>

        <!-- Tom container: en rad som pekar på kategorisidan, aldrig en tom väljare. -->
        <p v-else class="text-body text-ink-muted">
            {{ t('item.form.categories_empty') }}
            <Link
                :href="`/containers/${containerUlid}/categories`"
                class="text-accent underline"
            >
                {{ t('item.form.categories_empty_link') }}
            </Link>
        </p>

        <!-- Taggarna: kryssrutor med sin färgprick, en per tagg i containern. -->
        <fieldset
            v-if="tags.length > 0"
            class="flex flex-col gap-2"
            :aria-describedby="tagError ? 'tags-error' : undefined"
        >
            <legend class="text-body font-medium text-ink">{{ t('item.form.tags') }}</legend>

            <UiCheckbox
                v-for="tag in tags"
                :key="tag.ulid"
                v-model="form.tags"
                :id="`item-tag-${tag.ulid}`"
                :value="tag.ulid"
            >
                <span
                    aria-hidden="true"
                    class="inline-block h-3 w-3 shrink-0 rounded-full border border-ink-subtle"
                    :style="tag.color ? { backgroundColor: tag.color } : null"
                />
                {{ tag.name }}
            </UiCheckbox>

            <p
                v-if="tagError"
                id="tags-error"
                role="alert"
                tabindex="-1"
                class="text-body text-danger outline-none focus:ring-2 focus:ring-focus focus:ring-offset-2"
            >
                {{ tagError }}
            </p>
        </fieldset>

        <p v-else class="text-body text-ink-muted">
            {{ t('item.form.tags_empty') }}
            <Link :href="`/containers/${containerUlid}/tags`" class="text-accent underline">
                {{ t('item.form.tags_empty_link') }}
            </Link>
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
