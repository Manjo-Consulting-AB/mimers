<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import ContainerLayout from '../../../layouts/ContainerLayout.vue';
import ItemAttachmentSection from '../../../components/ItemAttachmentSection.vue';
import ItemLinkSection from '../../../components/ItemLinkSection.vue';
import ItemTagList from '../../../components/ItemTagList.vue';
import { itemFields } from '../../../components/itemPresentation.js';
import { useTranslations } from '../../../composables/useTranslations.js';

/*
 * Itemets detaljvy, se issue 57a § Beslut 4, 5, 6, 7 och 8, och issue 58.
 *
 * Sidan ligger i ContainerLayout och bär den prop layouten kräver: `container`
 * ur App\Http\Resources\ContainerResource.
 *
 * **Itemets egna fält, kategorin, taggarna, relationerna och bilagorna.**
 * Bilagesektionen kom med issue 60 och bor i
 * resources/js/components/ItemAttachmentSection.vue; listan kommer med
 * detaljvyns props och har ingen egen rutt. Schemana är 63, kostnaderna 45–47
 * och utlåningen 67 — ingen av dem har en yta här. Relationssektionen bor i
 * resources/js/components/ItemLinkSection.vue: båda bär sitt eget formulär och
 * sina egna fel, precis som ContainerAccessRow gör för åtkomsterna, så ett
 * fältfel på en relation eller en fil inte färgar resten av sidan. Ordningen på
 * ytorna är itemets egna uppgifter, sedan relationerna, sedan bilagorna.
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
 * högre) och `can.create` är `ItemPolicy::create()` på ITEMET. En användare
 * med bara `read` får alla falska och ser ingen skrivyta alls; en
 * `write`-mottagare ser redigeringen men inte raderingsknappen.
 *
 * **`can.create` ritar två ytor** (issue 58 § Beslut 7): länken *Nytt item
 * under det här*, som går till skapandeformuläret med `?parent`, och
 * relationsformuläret inuti ItemLinkSection. Båda är samma grind som
 * `ItemController::store()` prövar mot föräldern, så en `create`-mottagare
 * som nått det här itemet ser dem och en `read`-mottagare inte.
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
    /*
     * Itemets bilagor ur App\Http\Resources\AttachmentResource, nyast först —
     * samma lista och samma ordning som `/api` ger (issue 60 § Beslut 2).
     */
    attachments: { type: Array, required: true },
    /*
     * Det TEKNISKA taket på en fil, ur `config('files.max_upload_bytes')`
     * (issue 60b § Beslut 5). Bilagesektionen avvisar en för stor fil med
     * det här talet innan bytena skickas; servern prövar samma tak igen i
     * StoreAttachmentRequest.
     */
    maxUploadBytes: { type: Number, required: true },
    /*
     * Relationerna grupperade i överordnade, underordnade och syskon — redan
     * filtrerade per omfång av servern (issue 58 § Beslut 2 och 3).
     */
    links: { type: Object, required: true },
    /* Items användaren får ändra och som inte redan är kopplade. */
    counterparts: { type: Array, required: true },
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

            <!--
                Barn-itemet (issue 58 § Beslut 7). Föräldern kommer ur länken
                och formuläret visar den som en rad text — den här vyn är
                detaljvyn för just det itemet, så frågan "under vad?" är redan
                besvarad och ställs inte igen.
            -->
            <Link
                v-if="can.create"
                :href="`/containers/${container.ulid}/items/create?parent=${item.ulid}`"
                class="font-medium text-blue-700 hover:underline"
            >
                {{ t('item.links.create_child.action') }}
            </Link>
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

        <ItemLinkSection
            :container-ulid="container.ulid"
            :item-ulid="item.ulid"
            :links="links"
            :counterparts="counterparts"
            :can="can"
        />

        <!-- Bilagorna under relationerna (issue 60 § Beslut 1): de är itemets
             innehåll och inte en egen vy. `container.account` är pärmens
             ägarkonto — sektionens förval när användaren är medlem i det. -->
        <ItemAttachmentSection
            :container-ulid="container.ulid"
            :item-ulid="item.ulid"
            :attachments="attachments"
            :max-upload-bytes="maxUploadBytes"
            :container-account="container.account"
            :can="can"
        />
    </ContainerLayout>
</template>
