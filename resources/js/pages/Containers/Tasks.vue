<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
import GtdListPanel from '../../components/GtdListPanel.vue';
import TaskGroup from '../../components/TaskGroup.vue';
import TodoRow from '../../components/TodoRow.vue';
import UiEmptyState from '../../components/UiEmptyState.vue';
import UiTabs from '../../components/UiTabs.vue';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Containerns uppgiftsflik — listan, se issue 174 · [[ADR-0050
 * Desktopdesignen]] § 4 och 16, och M24 · issue 719.
 *
 * Sidan ligger i ContainerLayout och bär den prop layouten kräver: `container`
 * ur App\Http\Resources\ContainerResource — samma kontrakt som översikten,
 * itemlistan, historiken och inställningssidan. Hjälten är `compact` som på
 * varje flik utom översikten (issue 170), och `can` bär hjältens *Redigera
 * container*.
 *
 * **En lista, och grupperna kommer färdiga från `/tasks`** (Beslut 1 och 3).
 * `groups` är fyra färdiga listor i ritningsordning — försenat, idag, denna
 * vecka, kommande — ur App\Actions\Schedule\ListTodo::forContainer() UTAN
 * gräns, och vyn itererar objektets nycklar som de kommer. Den räknar aldrig
 * en grupp själv: en `computed` som jämför `due_at` mot `Date.now()` hade
 * flyttat en uppgift till fel hög så fort klientens klocka gick fel, och en
 * förekomst hamnar i samma grupp här som på `/tasks` (issue 64 § Beslut 3,
 * issue 135). Sektionerna staplas i EN kolumn på alla bredder — fliken är en
 * lista, inte en tavla.
 *
 * **Fliken släpper växeln för framtida uppgifter** (Beslut 2): kontrollern
 * skickar `onlyCurrent: false` till `forContainer()`, för en container
 * beskriver containerns tillstånd och inte användarens filter. Är växeln av på
 * `/tasks` står raden ändå här.
 *
 * **En tom grupp ritas inte** (Beslut 3) — varken rubrik eller lista. Det
 * gäller också *Klart*. Är alla fem tomma ritas UiEmptyState i stället, med
 * `container.tasks.empty`: en rubrik utan rader hade sagt att gruppen finns
 * men är tom, och en lista utan innehåll är samma svar utan brus.
 *
 * **Den femte sektionen är `completed`** (Beslut 3): avbockade förekomster i
 * containern, nyast först och högst tjugo. Raden är `TodoRow` som de öppna
 * (M24 · issue 230, Beslut 5): komponenten ser `completed_at` och ritar en
 * fylld bock utan knapp och utan formulär, med `eventDate()` i stället för
 * `dueDate()` — en avbockning är en HÄNDELSE och inte en förfallodag, och
 * `dueDate()` hade svarat *Om 3 dagar* om något som gjordes i förrgår (issue
 * 104). Två formuleringar av samma rad blir en.
 *
 * **Underhållsfiltret står i querysträngen** (Beslut 4). Kryssrutan postar
 * ingen kropp: `router.get` mot SAMMA rutt som sidan ligger på, med
 * `maintenance` satt eller utelämnad, så ett filtrerat läge är en adress man
 * kan spara, dela och backa ur (issue 59a § Beslut 1). Vyn filtrerar ingenting
 * själv — den skickar ett värde och ritar det svar den får, och
 * `props.maintenance` speglas med `watch` så att kryssrutan följer servern
 * efter en bakåtknapp eller en omladdning. Vänteläget stänger kontrollen
 * medan svaret är på väg (issue 68a § Beslut 4).
 *
 * **Snabblänkarna ritas ur `can`** (Beslut 5). `can.calendar` och
 * `can.export` är serverns svar på samma policyfråga som
 * CalendarFeedController och ExportController ställer i sina index(), och
 * länken ritas bara när svaret är ja: en genväg till en yta man inte får
 * öppna är en död länk, och en yta ingen hittar är samma sak som en yta som
 * inte finns. Etiketterna är flikradens egna ord — samma mål, samma namn.
 *
 * **Containern står i hjälten och upprepas inte i raden** (Beslut 4): `TodoRow`
 * får `:show-container="false"`, så raden bär schemats titel, itemet och
 * förfallet — containern vet läsaren redan var hon är.
 *
 * Ingen sträng i JavaScript (issue 52 · [[ADR-0013 Språk och i18n]]): rubriken,
 * flikens namn i webbläsaren, grupprubrikerna, kryssrutan och snabblänkarnas
 * ord kommer ur `t()`. Grupprubrikerna är `todo.group.*` — samma grupp, samma
 * ord, en nyckel — och de två genvägarna bär `container.nav.*`, som flikraden
 * gjorde.
 *
 * **Rubriken med antalet och ihopfällningen ritas av `TaskGroup`** (M24 ·
 * issue 231): vyn skickar in gruppens namn, antalet och tonen, och komponenten
 * ritar knappen, chevronen och raderna. Det gäller de fyra öppna grupperna OCH
 * *Done*, som bär `container.tasks.done` i stället för en `todo.group.*`.
 *
 * **Flikraden och panelen är M26 · issue 237** (ADR-0052 § 1 och 5).
 * `?list=` väljer lista, flikraden är `UiTabs`, och `list` styr vilket
 * innehåll som ritas: *Done* kommer i `completed` och de övriga i `groups`.
 * Den gamla *Done*-gruppen sist på fliken är nu en egen flik (Beslut 4) — på
 * *Active* och de lagrade listorna är `completed` därför tom. Panelen
 * (`GtdListPanel`) ritar antalet per lista ur `counts`, och den står till
 * höger över `lg:` och under listan under `lg:` (Beslut 3). Flikarnas
 * adresser bär även underhållsfiltret, så ett filter inte tyst försvinner när
 * man byter flik.
 */
const props = defineProps({
    /* Containern ur App\Http\Resources\ContainerResource. */
    container: { type: Object, required: true },
    /* De fyra öppna grupperna, i ritningsordning: overdue, today, this_week, upcoming. */
    groups: { type: Object, required: true },
    /*
     * *Done* — de avbockade förekomsterna, nyast först, en sida i taget, när
     * `list` är `done`. Tom annars: gruppen är nu en egen flik (Beslut 4).
     */
    completed: { type: Array, required: true },
    /* Fliken ur `?list=`: en av listorna, eller null för *Active*. */
    list: { type: String, default: null },
    /* Antalet per lista, ur ListTodo::gtdCounts() — panelens tal (Beslut 3). */
    counts: { type: Object, required: true },
    /* Underhållsfiltrets läge, ur `?maintenance=1`. */
    maintenance: { type: Boolean, required: true },
    /* Adressen till föregående sida av *Done*, eller null. */
    previousUrl: { type: String, default: null },
    /* Adressen till nästa sida av *Done*, eller null. */
    nextUrl: { type: String, default: null },
    /*
     * `{ update, calendar, export }` — hjältens *Redigera container* och de
     * två snabblänkarna. Varje flagga är serverns svar på samma policyfråga
     * som målruttens egen grind ställer; rutterna prövar dem på nytt.
     */
    can: { type: Object, default: null },
    /*
     * Plusknappens mål på den här fliken (M28 · testarnas fynd 2026-10-07):
     * formuläret för en ny uppgift, med `?return` tillbaka hit — samma mål som
     * *New task* i rubrikraden. Servern ritar det alltid, för inboxen är alltid
     * ett möjligt mål (App\Support\Frontend\CreateTarget::forContainerTab()).
     */
    create: { type: Object, default: null },
});

const { t } = useTranslations();

/* Flikens basadress — samma rutt som sidan ligger på. */
const taskBase = () => `/containers/${props.container.ulid}/tasks`;

/*
 * Flikraden (Beslut 4; M27 · issue 244), i ritningsordning: *Active*, *In
 * progress*, de tre lagrade listorna, *Calendar* och *Done*. *Active* är
 * adressen UTAN `list` — den är vilotillståndet. Är underhållsfiltret på
 * följer det med i varje fliks adress, så filtret inte tappas när man byter
 * flik; filtret är en del av adressen (Beslut 4, issue 59a § Beslut 1) och
 * ska följa med, inte nollas.
 */
const tabs = computed(() => {
    // Filtret följer med i flikens adress: `?maintenance=1` när det är på.
    const filter = props.maintenance ? 'maintenance=1' : '';

    const link = (key) => {
        // *Active* är adressen UTAN `list` — den är vilotillståndet.
        if (key === 'active') {
            return filter === '' ? taskBase() : `${taskBase()}?${filter}`;
        }

        const params = filter === '' ? `list=${key}` : `list=${key}&${filter}`;

        return `${taskBase()}?${params}`;
    };

    return [
        { key: 'active', label: t('todo.tabs.active'), href: link('active') },
        // *In progress* läggs till efter *Active* (M27 · issue 244, ADR-0054
        // § 5). *Inbox* finns INTE här: inboxen hör inte till någon container,
        // och fliken *Inbox* bor bara på `/tasks`.
        { key: 'in_progress', label: t('todo.tabs.in_progress'), href: link('in_progress') },
        { key: 'next', label: t('todo.list.next'), href: link('next') },
        { key: 'waiting', label: t('todo.list.waiting'), href: link('waiting') },
        { key: 'calendar', label: t('todo.tabs.calendar'), href: link('calendar') },
        { key: 'someday', label: t('todo.list.someday'), href: link('someday') },
        { key: 'done', label: t('todo.tabs.done'), href: link('done') },
    ];
});

/* Panelens rader är flikarna UTAN *Active* (Beslut 3). */
const panelRows = computed(() => tabs.value.filter((tab) => tab.key !== 'active'));

/* Kryssrutans läge, speglat ur proppen — se docblocken ovan. */
const onlyMaintenance = ref(props.maintenance);

/* Vänteläget för filtret: en enda kontroll, en enda flagga (issue 68a). */
const pending = ref(false);

watch(
    () => props.maintenance,
    (value) => {
        onlyMaintenance.value = value;
    },
);

/*
 * Är alla fem tomma? Den frågan ställs på serverns svar och inte på en egen
 * räkning: grupperna är redan avgränsade av filtret, och vyn lägger inget
 * villkor till dem (Beslut 3).
 */
const hasAnyTask = computed(
    () => props.completed.length > 0 || Object.values(props.groups).some((entries) => entries.length > 0),
);

/*
 * Skickar filtrets läge som querysträng. Är kryssrutan av lämnas parametern
 * UTANFÖR adressen i stället för att skickas som `?maintenance=0`: en tom
 * parameter är brus, och en adress utan brus går att läsa och dela.
 */
function apply() {
    // Fliken följer med (Beslut 4): filtret är ett tillägg till listan och
    // ska inte tyst byta vilken lista man står på. Är listan *Active* lämnas
    // parametern utanför, som i varje annan adress.
    const params = {};

    if (props.list !== null) {
        params.list = props.list;
    }

    if (onlyMaintenance.value) {
        params.maintenance = 1;
    }

    router.get(`/containers/${props.container.ulid}/tasks`, params, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => { pending.value = true; },
        onFinish: () => { pending.value = false; },
    });
}

/*
 * Snabblänkarnas adresser byggs i vyn, som i varje annan rad: rutten
 * `containers.calendar` och `containers.export` är containerns egna undersidor,
 * och samma två adresser står i resources/js/layouts/containerSections.js.
 * Uppgiftsradens adresser bor i `TodoRow` (M24 · issue 230).
 */
const calendarUrl = () => `/containers/${props.container.ulid}/calendar`;
const exportUrl = () => `/containers/${props.container.ulid}/export`;

/*
 * *New task* (M27 · issue 246 § Beslut 2). Fliken är en av de tre ytorna som
 * bär knappen, och den leder till formuläret med INBOXEN förvald: en container
 * är inte ett item, så det finns ingen plats att ärva. Användaren byter med
 * *Change…* (resources/js/pages/Tasks/Create.vue).
 *
 * Adressen bär `return` med den här sidan (Beslut 3), så svaret landar i
 * samma flik — `page.url` är adressen med sin querysträng, och listan och
 * underhållsfiltret man står i följer därför med tillbaka.
 *
 * **Det är en `<Link>` och inte en `<UiButton>`.** Knappen navigerar, och
 * GenomgangTest tillåter bara `<Link>` bland komponenttaggarna: en `@click` på
 * en komponent är en klickyta provet inte kan se är tabbbar. Klasserna är
 * `UiButton`s `primary`, så formen är densamma som i designsystemet.
 */
const page = usePage();

const createUrl = computed(() => `/tasks/create?return=${encodeURIComponent(page.url)}`);
</script>

<template>
    <ContainerLayout hero="compact" :container="container" :can="can" :create="create">
        <Head :title="t('container.tasks.title')" />

        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
            <h1 class="text-2xl font-semibold">{{ t('container.tasks.heading') }}</h1>

            <!-- *New task* (M27 · issue 246 § Beslut 2). Knappen ritas alltid:
                 inboxen är alltid ett möjligt mål, och containern behöver
                 därför ingen `can`-flagga. -->
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

        <!--
            Flikraden (Beslut 4). Listan står i adressen, och `UiTabs` tänder
            den flik vars `href` matchar den — vyn håller inget val i minnet.
            Är underhållsfiltret på bär varje fliks adress det med.
        -->
        <UiTabs class="mt-4" :tabs="tabs" :label="t('todo.tabs.label')" />

        <!--
            Underhållsfiltret (Beslut 4). En kryssruta och ingen
            skicka-knapp: valet är ett värde i adressen, och svaret ritar
            servern. `:disabled` medan svaret är på väg, så kontrollen inte
            ser död ut (issue 68a § Beslut 4).
        -->
        <label
            for="tasks-maintenance"
            class="mt-4 flex min-h-11 w-fit items-center gap-2 text-sm font-medium text-slate-800"
        >
            <input
                id="tasks-maintenance"
                v-model="onlyMaintenance"
                type="checkbox"
                name="maintenance"
                :disabled="pending"
                @change="apply"
            >
            {{ t('container.tasks.filter_maintenance') }}
        </label>

        <!--
            Listan till vänster och panelen till höger över `lg:`, panelen
            under listan under `lg:` (Beslut 3).
        -->
        <div class="mt-6 flex flex-col gap-8 lg:flex-row lg:items-start">
            <div class="lg:flex-1">
                <!--
                    Det tomma läget (Beslut 3). Först när alla listor är tomma
                    — de fyra öppna grupperna OCH *Done* — annars hade en
                    lista med bara avbockade rader sagt att containern saknar
                    uppgifter.
                -->
                <div v-if="!hasAnyTask">
                    <UiEmptyState>
                        {{ t('container.tasks.empty') }}
                    </UiEmptyState>
                </div>

                <!--
                    Listan (Beslut 1 och 3): grupperna i den ordning servern
                    gav dem, sedan *Done*. En sektion ritas bara när den har
                    rader — en tom grupp har ingen rubrik att visa. Rubriken
                    med antalet och ihopfällningen ritas av TaskGroup (M24 ·
                    issue 231): samma komponent som de två andra ytorna, så de
                    inte glider isär. `completed` är tom utanför *Done*-fliken,
                    så gruppen ritas bara där (Beslut 4).
                -->
                <div v-else class="flex flex-col gap-8">
                    <template v-for="(entries, group) in groups" :key="group">
                        <TaskGroup
                            v-if="entries.length > 0"
                            :heading="t(`todo.group.${group}`)"
                            :count="entries.length"
                            :tone="group === 'overdue' ? 'danger' : null"
                        >
                            <TodoRow
                                v-for="entry in entries"
                                :key="entry.ulid"
                                :entry="entry"
                                :show-container="false"
                            />
                        </TaskGroup>
                    </template>

                    <!--
                        *Done* (Beslut 3 och 4). Raden bär samma upplysningar
                        som de öppna raderna — schemats titel, itemet, ett
                        datum — men datumet är `completed_at`, och
                        avbockningsknappen ritas inte: det finns ingenting kvar
                        att bocka av, och rutten hade svarat att förekomsten
                        inte är öppen. Rubriken är containerns fliks ord, och
                        gruppen har ingen egen ton.
                    -->
                    <TaskGroup
                        v-if="completed.length > 0"
                        :heading="t('container.tasks.done')"
                        :count="completed.length"
                    >
                        <TodoRow
                            v-for="entry in completed"
                            :key="entry.ulid"
                            :entry="entry"
                            :show-container="false"
                        />
                    </TaskGroup>
                </div>

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

            <GtdListPanel class="lg:w-72" :counts="counts" :rows="panelRows" />
        </div>

        <!--
            Snabblänkarna (Beslut 5), ur `can` och aldrig ur en hårdkodad
            sanning: flaggan är serverns svar på målrutternas egen grind, och
            en genväg ingen får följa ritas inte alls.
        -->
        <section v-if="can && (can.calendar || can.export)" class="mt-8">
            <h2 class="text-sm font-medium text-slate-700">{{ t('container.tasks.shortcuts') }}</h2>

            <ul class="mt-2 flex flex-col gap-2 md:flex-row md:gap-4">
                <li v-if="can.calendar">
                    <Link
                        :href="calendarUrl()"
                        class="inline-flex min-h-11 items-center text-blue-700 hover:underline"
                    >
                        {{ t('container.nav.calendar') }}
                    </Link>
                </li>

                <li v-if="can.export">
                    <Link
                        :href="exportUrl()"
                        class="inline-flex min-h-11 items-center text-blue-700 hover:underline"
                    >
                        {{ t('container.nav.export') }}
                    </Link>
                </li>
            </ul>
        </section>
    </ContainerLayout>
</template>
