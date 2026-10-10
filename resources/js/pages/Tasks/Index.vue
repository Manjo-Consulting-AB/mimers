<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import GtdListPanel from '../../components/GtdListPanel.vue';
import TaskFilterBar from '../../components/TaskFilterBar.vue';
import TaskGroup from '../../components/TaskGroup.vue';
import TaskContainerPanel from '../../components/TaskContainerPanel.vue';
import TaskOverviewPanel from '../../components/TaskOverviewPanel.vue';
import TodoRow from '../../components/TodoRow.vue';
import UiTabs from '../../components/UiTabs.vue';
import UpcomingTasksToggle from '../../components/UpcomingTasksToggle.vue';
import { useTranslations } from '../../composables/useTranslations.js';

defineOptions({ layout: AppLayout });

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
 * **Sidan är paginerad** (issue 123). Den visar högst femtio rader och får
 * `previousUrl` och `nextUrl` färdiga av servern — vyn bygger ingen adress
 * själv och vet inte vilken markör som står i den. Ordningen är `due_at` med
 * `ulid` som andra nyckel, och grupperingen är det som gör listan begriplig
 * (Beslut 7). Sedan M28 · issue 782 väljer filterraden sorteringens riktning
 * (Beslut 3); vyn ritar fortfarande grupperna i den ordning servern gav dem,
 * så en fallande lista ritar *Upcoming* först utan att vyn vet varför.
 *
 * **En sida kan börja mitt i en grupp** (issue 123). Servern grupperar de
 * rader sidan bär, per rad, så en grupp som sträcker sig över en sidgräns får
 * sin rubrik en gång per sida. Vyn ritar varje icke-tom grupp precis som förr
 * och minns ingenting mellan sidorna: rubriken följer av raderna här, inte av
 * en räknare. Rubriken med antalet och ihopfällningen ritas av `TaskGroup`
 * (M24 · issue 231), och antalet är raderna på DEN HÄR sidan — rubriken säger
 * vad som står under den.
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
 *
 * **Flikraden och panelen är M26 · issue 237** (ADR-0052 § 1 och 5).
 * `?list=` väljer lista, och flikraden är `UiTabs` med listan i varje `href`:
 * den aktiva fliken följer adressen, precis som filtret i issue 59a § Beslut 1
 * och förekomsten i issue 95. Vyn tolkar inte värdet — servern äger urvalet,
 * och ett okänt värde blir *Active* där. `list` styr bara VILKET innehåll som
 * ritas: *Done* kommer i `completed` och de övriga i `groups`. Panelen
 * (`GtdListPanel`) ritar antalet per lista ur `counts`, och den står till
 * höger över `lg:` och under listan under `lg:`.
 *
 * **Filterraden står ovanför listan** (M28 · issue 782, Beslut 5). Den är
 * resources/js/components/TaskFilterBar.vue, den filtrerar ingenting själv —
 * den skickar fyra värden i adressen och servern svarar — och den ritar bara de
 * val fliken inte redan bestämt (Beslut 2). Panelen påverkas inte: `counts`
 * kommer ur `gtdCounts()`, som inte känner filtren (Beslut 4).
 *
 * **Sidan är den ENDA ytan som skickar `movable`** (M28 · issue 784,
 * [[ADR-0053 Flytt och kopiering]] § 6): en uppgift flyttas i listan, och
 * raden ritar sin `…`-meny ur den flaggan. De andra ytorna som ritar `TodoRow`
 * — containerns och itemets flikar och dashboardens panel — skickar inget och
 * får ingen meny. Raden villkorar själv på `entry.can.move` och
 * `entry.in_inbox`, så flaggan är ytan och inte rätten.
 *
 * **Högerspalten bär tre kort** (M28 · issue 783): *Quick overview*,
 * *Lists* och *My containers*, i den ordningen, och spalten står under
 * listan under `lg:` precis som `GtdListPanel` gjorde förut. De tre
 * panelerna visar alltid hela bilden (Beslut 3): talen kommer ur
 * `groupCounts()`, `gtdCounts()` och `containerCounts()`, och ingen av dem
 * känner filtren eller fliken. Vyn räknar ingenting — den ritar de tal och
 * de rader servern gav, och översiktens rader följer `overview`-proppens
 * nyckelordning så att de står stilla även när listan sorteras om.
 */
const props = defineProps({
    /* Listorna per grupp, i ritningsordning: overdue, today, upcoming. */
    groups: { type: Object, required: true },
    /*
     * *Done* — de avbockade förekomsterna, nyast först, när `list` är `done`.
     * Tom annars: den gamla *Done*-gruppen är nu en egen flik (Beslut 4).
     */
    completed: { type: Array, default: () => [] },
    /* Fliken ur `?list=`: en av listorna, eller null för *Active*. */
    list: { type: String, default: null },
    /*
     * Filtren ur adressen, så som servern tillämpade dem — se
     * resources/js/components/TaskFilterBar.vue (M28 · issue 782).
     */
    filters: { type: Object, required: true },
    /* Filterradens containrar: `[{ ulid, name }]`, utan inboxen. */
    containers: { type: Array, default: () => [] },
    /* Antalet per lista, ur ListTodo::gtdCounts() — panelens tal (Beslut 3). */
    counts: { type: Object, required: true },
    /*
     * Översiktens tal per datumgrupp, ur ListTodo::groupCounts() — samma
     * nycklar som `groups` plus `done` (M28 · issue 783, Beslut 1).
     */
    overview: { type: Object, required: true },
    /*
     * Containerkortets rader, ur ListTodo::containerCounts():
     * `[{ ulid, name, count, href }]`, i namnordning och utan inboxen
     * (M28 · issue 783, Beslut 2).
     */
    myContainers: { type: Array, default: () => [] },
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

/*
 * Flikraden, i ritningsordning (Beslut 4; M27 · issue 244): *Active*, *In
 * progress*, *Inbox*, de tre lagrade listorna, *Calendar* och *Done*.
 * *Active* är adressen UTAN `list` — den är vilotillståndet, och det är
 * därför `UiTabs` kan skilja den från de övriga.
 * Adresserna byggs här och inte på servern: de är vyns navigation, och
 * markörerna (som servern äger) hör till bläddringen och inte till fliken.
 */
const tabs = computed(() => [
    { key: 'active', label: t('todo.tabs.active'), href: '/tasks' },
    // *In progress* läggs till efter *Active* (M27 · issue 244, ADR-0054 § 5).
    // Fliken finns på BÅDA ytorna; *Inbox* bara här, för inboxen hör inte
    // till någon container.
    { key: 'in_progress', label: t('todo.tabs.in_progress'), href: '/tasks?list=in_progress' },
    { key: 'inbox', label: t('todo.list.inbox'), href: '/tasks?list=inbox' },
    { key: 'next', label: t('todo.list.next'), href: '/tasks?list=next' },
    { key: 'waiting', label: t('todo.list.waiting'), href: '/tasks?list=waiting' },
    { key: 'calendar', label: t('todo.tabs.calendar'), href: '/tasks?list=calendar' },
    { key: 'someday', label: t('todo.list.someday'), href: '/tasks?list=someday' },
    { key: 'done', label: t('todo.tabs.done'), href: '/tasks?list=done' },
]);

/*
 * Panelens rader är flikarna UTAN *Active*: panelen räknar listorna, och
 * *Active* är summan av dem och inte en lista bland dem (Beslut 3).
 */
const panelRows = computed(() => tabs.value.filter((tab) => tab.key !== 'active'));

/*
 * Översiktens rader (M28 · issue 783, Beslut 1): de fem datumgrupperna i
 * ritningsordning, plus *Done* sist — den enda rad som står utanför ringen.
 *
 * Nycklarna kommer ur `overview`-proppen och inte ur en egen uppräkning här:
 * serverns `groupCounts()` lägger dem i ritningsordning, och en lista i
 * JavaScript hade kunnat glida ifrån grupperna på listan. Ordningen står
 * därför stilla oavsett filtrering och sortering — panelerna visar alltid
 * hela bilden (Beslut 3) — och orden är desamma som grupprubrikerna och
 * fliken *Done* bär, för det är samma grupper.
 */
const overviewRows = computed(() => Object.keys(props.overview).map((key) => ({
    key,
    label: key === 'done' ? t('todo.tabs.done') : t(`todo.group.${key}`),
    done: key === 'done',
})));

/*
 * *Done* ritas ur `completed` i stället för grupperna (Beslut 1 och 4).
 * Jämförelsen är mot den normaliserade listan servern gav, så ett okänt
 * värde — som blev `null` — ritar *Active* precis som servern valde.
 */
const isDone = computed(() => props.list === 'done');

const isEmpty = computed(
    () => props.completed.length === 0 && Object.values(props.groups).every((entries) => entries.length === 0),
);

/*
 * *New task* (M27 · issue 246 § Beslut 2). Sidan är en av de tre ytorna som
 * bär knappen, och den leder till formuläret med INBOXEN förvald: här finns
 * ingen plats att ärva, och en uppgift som fångas utan sammanhang hör i
 * inboxen (ADR-0054 § 8).
 *
 * Adressen bär `return` med den här sidan (Beslut 3), så svaret landar i
 * samma flik — `page.url` är adressen med sin querysträng, och listan man
 * står i följer därför med tillbaka.
 *
 * **Det är en `<Link>` och inte en `<UiButton>`.** Knappen navigerar, och
 * GenomgangTest tillåter bara `<Link>` — Inertias enda namngivna undantag —
 * bland komponenttaggarna: en `@click` på en komponent är en klickyta provet
 * inte kan se är tabbbar. Klasserna är `UiButton`s `primary`, så formen är
 * densamma som i designsystemet.
 */
const page = usePage();

const createUrl = computed(() => `/tasks/create?return=${encodeURIComponent(page.url)}`);
</script>

<template>
    <Head :title="t('todo.title')" />

    <!-- Rubrikraden bär växeln (issue 134) och *New task* (M27 · issue
         246): frågan "vilka uppgifter ska listan visa?" ställs där listan
         står, och svaret gäller både den här sidan och dashboardens panel.
         Knappen ritas alltid — inboxen är alltid ett möjligt mål (Beslut
         2). -->
    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <h1 class="text-2xl font-semibold">{{ t('todo.heading') }}</h1>

        <div class="flex flex-wrap items-center gap-2">
            <UpcomingTasksToggle :enabled="props.showUpcomingTasks" />

            <Link
                :href="createUrl"
                class="inline-flex min-h-11 items-center justify-center rounded-control bg-accent px-4 font-medium text-ink-on-accent outline-none hover:bg-accent/90 focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
            >
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    class="mr-2 h-4 w-4"
                    aria-hidden="true"
                >
                    <path d="M12 5v14"></path>
                    <path d="M5 12h14"></path>
                </svg>

                {{ t('todo.new') }}
            </Link>
        </div>
    </div>

    <!--
        Flikraden (Beslut 4). Listan står i adressen, och `UiTabs` tänder
        den flik vars `href` matchar den — vyn håller inget val i minnet.
    -->
    <UiTabs class="mt-4" :tabs="tabs" :label="t('todo.tabs.label')" />

    <!--
        Listan till vänster och panelen till höger över `lg:`, panelen
        under listan under `lg:` (Beslut 3). Panelen är en syskonkolumn
        och inte en del av listan: dess tal gäller alla listor, inte den
        valda fliken.
    -->
    <div class="mt-8 flex flex-col gap-8 lg:flex-row lg:items-start">
        <div class="lg:flex-1">
            <!--
                Filterraden (Beslut 5): containern, listan, statusen och
                sorteringen, i adressen bredvid fliken. Den står ovanför
                listan och ritar bara de val fliken inte redan bestämt
                (Beslut 2).
            -->
            <TaskFilterBar
                class="mb-8"
                :list="list"
                :filters="filters"
                :containers="containers"
            />

            <template v-if="isEmpty">
                <p class="text-slate-700">
                    <template v-if="hasContainers">{{ t('todo.empty.nothing') }}</template>

                    <template v-else>
                        {{ t('todo.empty.no_containers') }}
                        <Link href="/containers/create" class="inline-flex min-h-11 items-center text-blue-700 hover:underline">
                            {{ t('todo.empty.create') }}
                        </Link>
                    </template>
                </p>
            </template>

            <!--
                *Done* (Beslut 1 och 4): de avbockade raderna, nyast
                först, i stället för datumgrupperna. Rubriken är flikens
                eget ord — samma nyckel som fliken bär.
            -->
            <TaskGroup
                v-else-if="isDone"
                :heading="t('todo.tabs.done')"
                :count="completed.length"
            >
                <TodoRow v-for="entry in completed" :key="entry.ulid" :entry="entry" movable />
            </TaskGroup>

            <!--
                Grupperna (Beslut 3 och 7). Rubriken med antalet och
                ihopfällningen ritas av TaskGroup (M24 · issue 231) —
                samma komponent som containerns och itemets flik. Antalet
                är raderna på DEN HÄR sidan, eftersom listan är paginerad:
                rubriken säger vad som står under den och ingenting om
                resten av serien.
            -->
            <template v-else>
                <div class="flex flex-col gap-8">
                    <template v-for="(entries, group) in groups" :key="group">
                        <TaskGroup
                            v-if="entries.length > 0"
                            :heading="t(`todo.group.${group}`)"
                            :count="entries.length"
                            :tone="group === 'overdue' ? 'danger' : null"
                        >
                            <TodoRow v-for="entry in entries" :key="entry.ulid" :entry="entry" movable />
                        </TaskGroup>
                    </template>
                </div>
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
        </div>

        <div class="flex flex-col gap-6 lg:w-72">
            <!--
                Högerspalten, i ritningsordning (M28 · issue 783, Beslut
                4): *Quick overview*, *Lists* och *My containers*. Alla tre
                är syskonkolumner och inte en del av listan — deras tal
                gäller användarens hela läge och inte den valda fliken
                eller filtret (Beslut 3). Under `lg:` står spalten under
                listan, som `GtdListPanel` gjorde förut.
            -->
            <TaskOverviewPanel :counts="overview" :rows="overviewRows" />

            <GtdListPanel :counts="counts" :rows="panelRows" />

            <TaskContainerPanel :rows="myContainers" />
        </div>
    </div>
</template>
