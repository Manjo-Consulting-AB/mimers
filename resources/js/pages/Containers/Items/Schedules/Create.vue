<script setup>
import { Head, Link } from '@inertiajs/vue3';
import ContainerLayout from '../../../../layouts/ContainerLayout.vue';
import ScheduleForm from '../../../../components/ScheduleForm.vue';
import { useTranslations } from '../../../../composables/useTranslations.js';

/*
 * Skapa ett schema på ett item, se issue 63a § Beslut 1, 3, 4 och 5.
 *
 * Sidan ligger i ContainerLayout och bär den prop layouten kräver: `container`
 * ur App\Http\Resources\ContainerResource. `item` är `{ulid, name}` — samma
 * form som föräldern i skapandeformuläret för item (issue 58 § Beslut 7) —
 * och räcker för rubriken och vägen tillbaka: ett schema har inga relationer
 * att välja ur, så sidan behöver inte itemets fält.
 *
 * Storleken på sidan är hela skillnaden mot detaljvyns sektion (Beslut 1):
 * schemat har åtta fält och två beroende par, och det ryms inte i en rad som
 * expanderar. Listan bor på itemet, formuläret på en egen sida — samma
 * uppdelning som 57b valde för itemet.
 *
 * Formuläret bor i resources/js/components/ScheduleForm.vue och delas med
 * Edit.vue; den här filen är skalet.
 */
defineProps({
    container: { type: Object, required: true },
    item: { type: Object, required: true },
});

const { t } = useTranslations();
</script>

<template>
    <ContainerLayout :container="container">
        <Head :title="t('item.schedule.create.title')" />

        <h1 class="text-2xl font-semibold">{{ t('item.schedule.create.heading') }}</h1>

        <p class="mt-1 text-sm text-slate-600">{{ item.name }}</p>

        <Link
            :href="`/containers/${container.ulid}/items/${item.ulid}`"
            class="mt-2 inline-block text-sm font-medium text-blue-700 hover:underline"
        >
            {{ t('item.schedule.back') }}
        </Link>

        <ScheduleForm
            class="mt-8"
            :container-ulid="container.ulid"
            :item-ulid="item.ulid"
        />
    </ContainerLayout>
</template>
