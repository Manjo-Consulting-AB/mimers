<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import ContainerLayout from '../../../layouts/ContainerLayout.vue';
import ItemTagList from '../../../components/ItemTagList.vue';
import { itemFields } from '../../../components/itemPresentation.js';
import { useTranslations } from '../../../composables/useTranslations.js';

/*
 * Itemets detaljvy, se issue 57a § Beslut 4, 5, 6, 7 och 8.
 *
 * Sidan ligger i ContainerLayout och bär den prop layouten kräver: `container`
 * ur App\Http\Resources\ContainerResource.
 *
 * **Bara itemets egna fält, kategorin och taggarna.** Relationssektionen är
 * issue 58, bilagorna 60, schemana 63, kostnaderna 45–47 och utlåningen 67 —
 * ingen av dem har en yta här, och ingenting i den här filen läser
 * `attachment`.
 *
 * **Ett tomt fält utelämnas, aldrig påhittat** (Beslut 8). `fields` filtrerar
 * bort `null` och tomma strängar, så en rad utan beskrivning visar ingen
 * beskrivningsrad — den visar inte en tom etikett och inte ett streck.
 *
 * **Datumen är DATE-kolumner** och formateras med formatDateOnly(), som
 * bygger datumet i lokal tid i stället för att tolka strängen som UTC — se
 * modulens docblock. De räknas aldrig om till en annan tidszon.
 *
 * **`can` ritar skrivytorna** (Beslut 6 och issue 57b § Beslut 2 och 8). Varje
 * flagga är sin egen grind: `can.update` är `ItemPolicy::update()` (`write`-
 * pinnen), `can.delete` är `ItemPolicy::delete()` (`delete`-pinnen, en pinne
 * högre) och `can.create` är `ItemPolicy::create()` på ITEMET, som ritar
 * barn-itemets skapayta i issue 58. En användare med bara `read` får alla
 * falska och ser ingen skrivyta alls; en `write`-mottagare ser redigeringen
 * men inte raderingsknappen.
 *
 * **Raderingen bekräftas och säger vad som händer** (§ Beslut 8). Den är mjuk
 * — `deleted_at` sätts och ingenting annat ([[ADR-0008 Soft delete och
 * papperskorg]]) — så texten säger papperskorgen och de 30 dagarna, aldrig
 * "raderas permanent", vilket vore osant. Ingen kaskadtext om bilagor, scheman
 * eller kostnader: de följer itemet, och papperskorgen är issue 62.
 *
 * Kategorinamnet slås upp i `categories` (ULID → namn), byggd bredvid
 * resursen i kontrollern — se App\Http\Controllers\ItemController. ItemResource
 * bär bara kategorins ULID.
 */
const props = defineProps({
    container: { type: Object, required: true },
    item: { type: Object, required: true },
    /* Kategori-ULID → namn; tom när itemet saknar kategori. */
    categories: { type: Object, required: true },
    can: { type: Object, required: true },
});

const { t } = useTranslations();
const page = usePage();

const locale = computed(() => page.props.locale);

/*
 * Itemets egna fält, filtrerade och formaterade i
 * resources/js/components/itemPresentation.js — se den modulens docblock för
 * varför tomma rader utelämnas och varför datumen inte räknas om.
 */
const fields = computed(() =>
    itemFields(props.item, locale.value).map((field) => ({
        ...field,
        label: t(`item.show.${field.key}`),
    })),
);

const categoryName = computed(() => props.categories[props.item.category] ?? null);

/*
 * Raderingen. Bekräftelsen är webbläsarens egen dialog med serverns mening ur
 * `lang/` — ingen modal komponent och ingen sträng i JavaScript.
 *
 * `router.delete` och inte en <Link method="delete">: bekräftelsen måste
 * kunna AVBRYTA navigeringen, och en knapp vars enda väg vidare är ett
 * klick-handtag är lättare att läsa än en länk vars klick går att stoppa.
 * CSRF-tokenet skickar Inertia åt oss.
 */
function destroy() {
    if (! window.confirm(t('item.destroy.confirm'))) {
        return;
    }

    router.delete(`/containers/${props.container.ulid}/items/${props.item.ulid}`);
}
</script>

<template>
    <ContainerLayout :container="container">
        <Head :title="item.name" />

        <h1 class="text-2xl font-semibold">{{ item.name }}</h1>

        <div class="mt-4 flex gap-4 text-sm">
            <Link
                v-if="can.update"
                :href="`/containers/${container.ulid}/items/${item.ulid}/edit`"
                class="font-medium text-blue-700 hover:underline"
            >
                {{ t('item.edit.action') }}
            </Link>

            <button
                v-if="can.delete"
                type="button"
                class="font-medium text-red-700 hover:underline"
                @click="destroy"
            >
                {{ t('item.destroy.action') }}
            </button>
        </div>

        <dl class="mt-8 grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-2">
            <div v-for="field in fields" :key="field.key">
                <dt class="text-sm font-medium text-slate-600">{{ field.label }}</dt>
                <dd class="mt-1 whitespace-pre-line text-slate-900">{{ field.value }}</dd>
            </div>

            <div v-if="categoryName">
                <dt class="text-sm font-medium text-slate-600">{{ t('item.show.category') }}</dt>
                <dd class="mt-1 text-slate-900">{{ categoryName }}</dd>
            </div>
        </dl>

        <section v-if="item.tags.length > 0" class="mt-8">
            <h2 class="text-sm font-medium text-slate-600">{{ t('item.show.tags') }}</h2>

            <ItemTagList class="mt-2" :tags="item.tags" />
        </section>
    </ContainerLayout>
</template>
