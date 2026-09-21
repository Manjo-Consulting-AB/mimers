<script setup>
import { Head } from '@inertiajs/vue3';
import ContainerLayout from '../../../layouts/ContainerLayout.vue';
import ItemForm from '../../../components/ItemForm.vue';
import { useTranslations } from '../../../composables/useTranslations.js';

/*
 * Redigera ett item, se issue 57b § Beslut 1, 5 och 6.
 *
 * Sidan ligger i ContainerLayout och bär den prop layouten kräver: `container`
 * ur App\Http\Resources\ContainerResource.
 *
 * **Ingen kontoväljare här** (§ Beslut 4). `UpdateItemRequest` tar inte emot
 * `account`, och vem som skapade raden är historik — ett fält som ser ut att gå
 * att ändra men inte gör det är sämre än ett fält som inte finns. Det är hela
 * skillnaden mot Create.vue, och därför skickas varken `accounts` eller
 * `account` vidare till formuläret.
 *
 * `categories` och `tags` är containerns egna, hämtade med `ListCategories` och
 * `ListTags` i kontrollern. `item.category` är kategorins ULID och
 * `item.tags` är itemets taggar — formuläret förvalt dem, och PATCH skickar
 * alltid tillbaka båda fälten (§ Beslut 6).
 *
 * `images` och `cover` är omslagsbilden (issue 93 · [[ADR-0041 Itemets vy]]
 * § Beslut). `images` är itemets bilder, färdigfiltrerade och sorterade av
 * App\Actions\Item\ResolveItemCover::images(), och `cover` är den VALDA
 * bildens ULID — inte den upplösta, se ItemForm — uppslagen ur samma lista så
 * att en pekare på en mjukraderad bilaga blir null i stället för en ULID vyn
 * inte kan rita en rad för. Båda ligger bredvid `ItemResource` och inte i
 * den: `/api`:s format har inte bett om fältet. Varken Create.vue eller
 * formuläret räknar om listan; väljaren ritar den som den kommer.
 */
defineProps({
    container: { type: Object, required: true },
    item: { type: Object, required: true },
    categories: { type: Array, required: true },
    tags: { type: Array, required: true },
    images: { type: Array, required: true },
    cover: { type: String, default: null },
});

const { t } = useTranslations();
</script>

<template>
    <ContainerLayout :container="container">
        <Head :title="t('item.edit.title')" />

        <h1 class="text-2xl font-semibold">{{ t('item.edit.heading') }}</h1>

        <ItemForm
            class="mt-8"
            :container-ulid="container.ulid"
            :categories="categories"
            :tags="tags"
            :item="item"
            :images="images"
            :cover="cover"
        />
    </ContainerLayout>
</template>
