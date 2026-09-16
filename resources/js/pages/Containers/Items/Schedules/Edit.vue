<script setup>
import { Head, Link } from '@inertiajs/vue3';
import ContainerLayout from '../../../../layouts/ContainerLayout.vue';
import ScheduleForm from '../../../../components/ScheduleForm.vue';
import { useTranslations } from '../../../../composables/useTranslations.js';

/*
 * Redigera ett schema, se issue 63a § Beslut 1, 3, 4 och 5.
 *
 * Sidan ligger i ContainerLayout och bär samma två propar som Create.vue:
 * `container` ur App\Http\Resources\ContainerResource och `item` som
 * `{ulid, name}`. `schedule` är App\Http\Resources\ScheduleResource rakt av —
 * samma format som `/api` ger — och formuläret förvalt sina fält ur den.
 *
 * **Ingen pausknapp här** (Beslut 6). `is_active` är ett fält i raden på
 * detaljvyn och inte i det här formuläret: pausen ska vara ett klick i
 * listan, där man ser vad den gör, och en PATCH som bär bara `is_active` är
 * samma rutt som den här sidan postar till. Ett kryss i formuläret hade gjort
 * pausen till en del av en större sparoperation — och ett pausat schema ska
 * inte kunna smyga med i en titeländring.
 */
defineProps({
    container: { type: Object, required: true },
    item: { type: Object, required: true },
    schedule: { type: Object, required: true },
});

const { t } = useTranslations();
</script>

<template>
    <ContainerLayout :container="container">
        <Head :title="t('item.schedule.update.title')" />

        <h1 class="text-2xl font-semibold">{{ t('item.schedule.update.heading') }}</h1>

        <p class="mt-1 text-sm text-slate-600">{{ item.name }}</p>

        <Link
            :href="`/containers/${container.ulid}/items/${item.ulid}`"
            class="mt-2 inline-flex min-h-11 items-center text-sm font-medium text-blue-700 hover:underline"
        >
            {{ t('item.schedule.back') }}
        </Link>

        <ScheduleForm
            class="mt-8"
            :container-ulid="container.ulid"
            :item-ulid="item.ulid"
            :schedule="schedule"
        />
    </ContainerLayout>
</template>
