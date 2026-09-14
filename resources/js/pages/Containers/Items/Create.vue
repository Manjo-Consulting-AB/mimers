<script setup>
import { computed } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import ContainerLayout from '../../../layouts/ContainerLayout.vue';
import ItemForm from '../../../components/ItemForm.vue';
import { useTranslations } from '../../../composables/useTranslations.js';

/*
 * Skapa ett item, se issue 57b § Beslut 1, 4 och 5.
 *
 * Sidan ligger i ContainerLayout och bär den prop layouten kräver: `container`
 * ur App\Http\Resources\ContainerResource.
 *
 * Formuläret bor i resources/js/components/ItemForm.vue — skapandet och
 * redigeringen bär samma nio fält, och den här sidan är skalet plus kontovalet.
 *
 * **Kontolistan kommer ur den delade propen `auth.accounts`** (§ Beslut 4) och
 * inte ur en egen sidprop: en fråga för samma lista är en fråga för mycket,
 * samma linje som issue 54 § Beslut 5. Är användaren medlem i EXAKT ett konto
 * förvalt det; annars förvalts pärmens ägarkonto när hon är medlem i det, och
 * hennes första konto annars. Ett påhittat förval hade blivit fel hälften av
 * gångerna, och att lämna fältet tomt hade varit ett krav servern ställer utan
 * att vyn svarar på det. Serverns svar på ett konto hon inte är medlem i är
 * 403, och vyn gissar sig inte förbi det.
 *
 * `categories` och `tags` är pärmens egna, hämtade med `ListCategories` och
 * `ListTags` i kontrollern — samma Actions som kategorisidan och taggsidan
 * anropar. Är någon av dem tom pekar ItemForm på respektive sida i stället för
 * att rita en tom väljare.
 *
 * **`parent` kommer ur `?parent={ulid}`** (issue 58 § Beslut 7) och är `null`
 * för ett item på toppnivån. Det är samma sida och samma formulär: den enda
 * skillnaden är raden med förälderns namn och att det nya itemet knyts som
 * barn i samma transaktion. Kontrollern auktoriserar mot föräldern när den
 * finns — den här filen ritar bara vad den fick.
 */
const props = defineProps({
    container: { type: Object, required: true },
    categories: { type: Array, required: true },
    tags: { type: Array, required: true },
    /* Föräldern ur `?parent`, eller null för ett item på toppnivån. */
    parent: { type: Object, default: null },
});

const { t } = useTranslations();
const page = usePage();

const accounts = computed(() => page.props.auth?.accounts ?? []);

/* Pärmens ägarkonto förvalt när användaren är medlem i det (Beslut 4). */
const account = computed(() => {
    const owner = accounts.value.find((candidate) => candidate.ulid === props.container.account);

    return owner?.ulid ?? accounts.value[0]?.ulid ?? '';
});
</script>

<template>
    <ContainerLayout :container="container">
        <Head :title="t('item.create.title')" />

        <h1 class="text-2xl font-semibold">{{ t('item.create.heading') }}</h1>

        <ItemForm
            class="mt-8"
            :container-ulid="container.ulid"
            :categories="categories"
            :tags="tags"
            :accounts="accounts"
            :account="account"
            :parent="parent"
        />
    </ContainerLayout>
</template>
