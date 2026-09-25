<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import TodoRow from '../../components/TodoRow.vue';
import UpcomingTasksToggle from '../../components/UpcomingTasksToggle.vue';
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
 * **Sidan är paginerad och har ingen sorteringsväljare** (issue 123). Den
 * visar högst femtio rader och får `previousUrl` och `nextUrl` färdiga av
 * servern — vyn bygger ingen adress själv och vet inte vilken markör som står
 * i den. Ordningen är `due_at` stigande med `ulid` som andra nyckel, och
 * grupperingen är det som gör listan begriplig (Beslut 7).
 *
 * **En sida kan börja mitt i en grupp** (issue 123). Servern grupperar de
 * rader sidan bär, per rad, så en grupp som sträcker sig över en sidgräns får
 * sin rubrik en gång per sida. Vyn ritar varje icke-tom grupp precis som förr
 * och minns ingenting mellan sidorna: rubriken följer av raderna här, inte av
 * en räknare.
 *
 * **Det tomma läget gäller sidan, inte listan.** En sida utan rader visar
 * samma mening som en tom lista gjorde, och länkarna ritas utanför den
 * grenen, så en sida som blivit tom går att ta sig tillbaka från.
 *
 * **De två tomma lägena är olika, och ingen av dem vet om omfånget**
 * (Beslut 6). `hasContainers` är serverns svar på "har hon någon container alls" —
 * den som inte har någon får en mening och en länk till att skapa en, den som
 * har containers utan öppna uppgifter får en annan. Ingen av meningarna nämner
 * ett tal eller antyder att rader dolts: en omfångsbegränsad mottagare med
 * tom lista får ordagrant samma mening som en ägare vars uppgifter är gjorda
 * (issue 73 § Beslut 6, issue 74).
 *
 * **Rubrikraden bär växeln för framtida uppgifter** (issue 134). Den är
 * resources/js/components/UpcomingTasksToggle.vue, samma komponent som
 * panelen ritar, och den postar till `PUT /settings/tasks`. Sidan filtrerar
 * fortfarande ingenting: växeln styr serverns urval, och `groups` kommer
 * redan avgränsad när `showUpcomingTasks` är falsk.
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
    /* Växelns sparade läge, se resources/js/components/UpcomingTasksToggle.vue. */
    showUpcomingTasks: { type: Boolean, required: true },
    /* Adressen till föregående sida, eller null när den här är den första. */
    previousUrl: { type: String, default: null },
    /* Adressen till nästa sida, eller null när den här är den sista. */
    nextUrl: { type: String, default: null },
});

const { t } = useTranslations();

const isEmpty = computed(() => Object.values(props.groups).every((entries) => entries.length === 0));
</script>

<template>
    <AppLayout>
        <Head :title="t('todo.title')" />

        <!-- Rubrikraden bär växeln (issue 134): frågan "vilka uppgifter ska
             listan visa?" ställs där listan står, och svaret gäller både den
             här sidan och dashboardens panel. -->
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <h1 class="text-2xl font-semibold">{{ t('todo.heading') }}</h1>

            <UpcomingTasksToggle :enabled="props.showUpcomingTasks" />
        </div>

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

        <nav v-if="previousUrl || nextUrl" class="mt-8 flex items-center gap-4">
            <Link
                v-if="previousUrl"
                :href="previousUrl"
                class="inline-flex min-h-11 items-center text-blue-700 hover:underline"
            >
                {{ t('todo.pagination.previous') }}
            </Link>

            <Link
                v-if="nextUrl"
                :href="nextUrl"
                class="ml-auto inline-flex min-h-11 items-center text-blue-700 hover:underline"
            >
                {{ t('todo.pagination.next') }}
            </Link>
        </nav>
    </AppLayout>
</template>
