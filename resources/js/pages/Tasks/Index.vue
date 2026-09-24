<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import TodoRow from '../../components/TodoRow.vue';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Todo-vyn — "vad ska jag göra?", se issue 64 § Beslut 1–8 och issue 122.
 *
 * Sidan är produktens andra huvudfråga, och den öppnas oftare än någon annan i
 * M10. Den låg på `/dashboard` fram till issue 122, då startsidan blev
 * dashboarden och todo-vyn flyttade hit, till `/tasks` (ruttnamn `tasks`).
 * Innehållet flyttade oförändrat med: samma urval, samma gruppering, samma
 * rader. Filen bytte namn från `Dashboard.vue` till `Tasks/Index.vue` därför
 * att sidnamnet är kontraktet mot `import.meta.glob` över `pages/` (issue 51) —
 * och `Dashboard` är nu dashboardens komponent.
 *
 * **Sidan filtrerar ingenting** (Beslut 2). Urvalet — öppen, synlig idag,
 * inte blockerad, i en åtkomlig container och inom användarens omfång — formuleras
 * EN gång, i `ScheduleOccurrence::scopeTodoFor()`, och den här filen har
 * varken en `computed` som sållar rader eller en klientmatchning. Servern
 * äger urvalet; sidan visar det.
 *
 * **Grupperingen är också serverns** (Beslut 3). `groups` kommer som tre
 * färdiga listor i den ordning de ska ritas — försenat först, sedan idag och
 * kommande — och vyn itererar objektets nycklar som de kommer. Den räknar
 * aldrig en grupp själv: en klient med fel klocka ska inte kunna flytta en
 * uppgift till fel hög, och en `computed` som jämför `due_at` mot `Date.now()`
 * hade varit precis den klockan.
 *
 * **Ingen paginering och ingen sorteringsväljare** (Beslut 3). Systemet är
 * kraftigt säsongsbetonat — i april förfaller allt samtidigt — så listan är
 * hela listan och grupperingen är det som gör den begriplig (Beslut 7).
 *
 * **De två tomma lägena är olika, och ingen av dem vet om omfånget**
 * (Beslut 6). `hasContainers` är serverns svar på "har hon någon container alls" —
 * den som inte har någon får en mening och en länk till att skapa en, den som
 * har containers utan öppna uppgifter får en annan. Ingen av meningarna nämner
 * ett tal eller antyder att rader dolts: en omfångsbegränsad mottagare med
 * tom lista får ordagrant samma mening som en ägare vars uppgifter är gjorda
 * (issue 73 § Beslut 6, issue 74).
 *
 * Dashboardens uppgiftspanel ritar samma två meningar och samma rader, ur
 * samma action, men bara de fem första — se
 * resources/js/components/DashboardTasksPanel.vue.
 */
const props = defineProps({
    /* Listorna per grupp, i ritningsordning: overdue, today, upcoming. */
    groups: { type: Object, required: true },
    /* Har användaren någon container alls? Skiljer de två tomma lägena åt. */
    hasContainers: { type: Boolean, required: true },
});

const { t } = useTranslations();

const isEmpty = computed(() => Object.values(props.groups).every((entries) => entries.length === 0));
</script>

<template>
    <AppLayout>
        <Head :title="t('todo.title')" />

        <h1 class="text-2xl font-semibold">{{ t('todo.heading') }}</h1>

        <template v-if="isEmpty">
            <p class="mt-8 text-slate-700">
                <template v-if="hasContainers">{{ t('todo.empty.nothing') }}</template>

                <template v-else>
                    {{ t('todo.empty.no_containers') }}
                    <Link href="/containers/create" class="inline-flex min-h-11 items-center text-blue-700 hover:underline">
                        {{ t('todo.empty.create') }}
                    </Link>
                </template>
            </p>
        </template>

        <template v-for="(entries, group) in groups" :key="group">
            <section v-if="entries.length > 0" class="mt-8">
                <h2 class="text-sm font-medium text-slate-700">{{ t(`todo.group.${group}`) }}</h2>

                <ul class="mt-2 flex flex-col divide-y divide-slate-200">
                    <TodoRow v-for="entry in entries" :key="entry.ulid" :entry="entry" />
                </ul>
            </section>
        </template>
    </AppLayout>
</template>
