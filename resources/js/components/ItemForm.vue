<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import { categoryOptions } from './categoryTree.js';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * Formuläret för ett item — se issue 57b § Beslut 4, 5, 6, 7 och 9.
 *
 * Egen komponent och inte ett formulär i var sin sida: skapandet och
 * redigeringen bär samma nio fält, och den enda skillnaden är `account`
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
 * Ingen egen validering: reglerna bor i StoreItemRequest/UpdateItemRequest och
 * felen renderas av FormField vid sitt fält ([[ADR-0021 Frontendteknik]]).
 *
 * **Föräldern kommer ur länken och är inget val** (issue 58 § Beslut 7).
 * Detaljvyns *Nytt item under det här* skickar `?parent={ulid}`, kontrollern
 * har redan auktoriserat mot föräldern, och formuläret visar den som en rad
 * text — en väljare hade varit en andra fråga om något användaren redan
 * besvarat. `parent` skickas bara i skapandeläget: `UpdateItemRequest` tar
 * inte emot fältet, och ett item som redan finns byter inte förälder här.
 */
const props = defineProps({
    containerUlid: { type: String, required: true },
    categories: { type: Array, required: true },
    tags: { type: Array, required: true },
    /* Kontolistan ur den delade propen `auth.accounts`; tom i redigeringsläget. */
    accounts: { type: Array, default: () => [] },
    /* Det förvalda kontot — pärmens ägarkonto när användaren är medlem i det. */
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

const fields = {
    name: props.item?.name ?? '',
    description: props.item?.description ?? '',
    manufacturer: props.item?.manufacturer ?? '',
    model: props.item?.model ?? '',
    serial_number: props.item?.serial_number ?? '',
    purchased_at: props.item?.purchased_at ?? '',
    warranty_until: props.item?.warranty_until ?? '',
    position_note: props.item?.position_note ?? '',
    category: props.item?.category ?? null,
    tags: props.item?.tags?.map((tag) => tag.ulid) ?? [],
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
        <p v-if="item === null && parent" class="text-sm text-slate-600">
            {{ t('item.form.parent', { name: parent.name }) }}
        </p>

        <FormField
            v-slot="{ describedBy }"
            :label="t('item.form.name')"
            id="name"
            :error="form.errors.name"
        >
            <input
                id="name"
                v-model="form.name"
                :aria-describedby="describedBy"
                type="text"
                name="name"
                required
                class="rounded border border-slate-300 bg-white px-3 py-2"
            >
        </FormField>

        <FormField
            v-slot="{ describedBy }"
            :label="t('item.form.description')"
            id="description"
            :error="form.errors.description"
        >
            <textarea
                id="description"
                v-model="form.description"
                :aria-describedby="describedBy"
                name="description"
                rows="4"
                class="rounded border border-slate-300 bg-white px-3 py-2"
            />
        </FormField>

        <FormField
            v-slot="{ describedBy }"
            :label="t('item.form.manufacturer')"
            id="manufacturer"
            :error="form.errors.manufacturer"
        >
            <input
                id="manufacturer"
                v-model="form.manufacturer"
                :aria-describedby="describedBy"
                type="text"
                name="manufacturer"
                class="rounded border border-slate-300 bg-white px-3 py-2"
            >
        </FormField>

        <FormField
            v-slot="{ describedBy }"
            :label="t('item.form.model')"
            id="model"
            :error="form.errors.model"
        >
            <input
                id="model"
                v-model="form.model"
                :aria-describedby="describedBy"
                type="text"
                name="model"
                class="rounded border border-slate-300 bg-white px-3 py-2"
            >
        </FormField>

        <FormField
            v-slot="{ describedBy }"
            :label="t('item.form.serial_number')"
            id="serial_number"
            :error="form.errors.serial_number"
        >
            <input
                id="serial_number"
                v-model="form.serial_number"
                :aria-describedby="describedBy"
                type="text"
                name="serial_number"
                class="rounded border border-slate-300 bg-white px-3 py-2"
            >
        </FormField>

        <FormField
            v-slot="{ describedBy }"
            :label="t('item.form.purchased_at')"
            id="purchased_at"
            :error="form.errors.purchased_at"
        >
            <input
                id="purchased_at"
                v-model="form.purchased_at"
                :aria-describedby="describedBy"
                type="date"
                name="purchased_at"
                class="rounded border border-slate-300 bg-white px-3 py-2"
            >
        </FormField>

        <FormField
            v-slot="{ describedBy }"
            :label="t('item.form.warranty_until')"
            id="warranty_until"
            :error="form.errors.warranty_until"
        >
            <input
                id="warranty_until"
                v-model="form.warranty_until"
                :aria-describedby="describedBy"
                type="date"
                name="warranty_until"
                class="rounded border border-slate-300 bg-white px-3 py-2"
            >
        </FormField>

        <FormField
            v-slot="{ describedBy }"
            :label="t('item.form.position_note')"
            id="position_note"
            :error="form.errors.position_note"
        >
            <input
                id="position_note"
                v-model="form.position_note"
                :aria-describedby="describedBy"
                type="text"
                name="position_note"
                class="rounded border border-slate-300 bg-white px-3 py-2"
            >
        </FormField>

        <!-- Kategorin: en väljare ur trädet, indraget efter djup. -->
        <FormField
            v-if="options.length > 0"
            v-slot="{ describedBy }"
            :label="t('item.form.category')"
            id="category"
            :error="form.errors.category"
        >
            <select
                id="category"
                v-model="form.category"
                :aria-describedby="describedBy"
                name="category"
                class="rounded border border-slate-300 bg-white px-3 py-2"
            >
                <option :value="null">{{ t('item.form.category_none') }}</option>
                <option v-for="option in options" :key="option.ulid" :value="option.ulid">
                    {{ option.name }}
                </option>
            </select>
        </FormField>

        <!-- Tom pärm: en rad som pekar på kategorisidan, aldrig en tom väljare. -->
        <p v-else class="text-sm text-slate-600">
            {{ t('item.form.categories_empty') }}
            <Link
                :href="`/containers/${containerUlid}/categories`"
                class="text-blue-700 underline"
            >
                {{ t('item.form.categories_empty_link') }}
            </Link>
        </p>

        <!-- Taggarna: kryssrutor med sin färgprick, en per tagg i pärmen. -->
        <fieldset v-if="tags.length > 0" class="flex flex-col gap-2">
            <legend class="text-sm font-medium text-slate-800">{{ t('item.form.tags') }}</legend>

            <label
                v-for="tag in tags"
                :key="tag.ulid"
                class="flex min-h-11 min-w-11 items-center gap-2 text-sm text-slate-800"
            >
                <input v-model="form.tags" type="checkbox" name="tags[]" :value="tag.ulid">
                <span
                    aria-hidden="true"
                    class="inline-block h-3 w-3 shrink-0 rounded-full border border-slate-300"
                    :style="tag.color ? { backgroundColor: tag.color } : null"
                />
                {{ tag.name }}
            </label>

            <p v-if="tagError" id="tags-error" tabindex="-1" class="text-sm text-red-700 outline-none">
                {{ tagError }}
            </p>
        </fieldset>

        <p v-else class="text-sm text-slate-600">
            {{ t('item.form.tags_empty') }}
            <Link :href="`/containers/${containerUlid}/tags`" class="text-blue-700 underline">
                {{ t('item.form.tags_empty_link') }}
            </Link>
        </p>

        <!-- Ett enda konto: värdet är förvalt och visas som text (Beslut 4). -->
        <div v-if="item === null && singleAccount" class="flex flex-col gap-1">
            <p class="text-sm font-medium text-slate-800">{{ t('item.form.account') }}</p>
            <p>{{ singleAccount.name }}</p>
        </div>

        <FormField
            v-else-if="item === null"
            v-slot="{ describedBy }"
            :label="t('item.form.account')"
            id="account"
            :error="form.errors.account"
        >
            <select
                id="account"
                v-model="form.account"
                :aria-describedby="describedBy"
                name="account"
                required
                class="rounded border border-slate-300 bg-white px-3 py-2"
            >
                <option v-for="account in accounts" :key="account.ulid" :value="account.ulid">
                    {{ account.name }}
                </option>
            </select>
        </FormField>

        <button
            type="submit"
            :disabled="form.processing"
            class="self-start inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
        >
            {{ submitLabel }}
        </button>
    </form>
</template>
