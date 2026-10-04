<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
import TodoRow from '../../components/TodoRow.vue';
import UiEmptyState from '../../components/UiEmptyState.vue';
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
 */
const props = defineProps({
    /* Containern ur App\Http\Resources\ContainerResource. */
    container: { type: Object, required: true },
    /* De fyra öppna grupperna, i ritningsordning: overdue, today, this_week, upcoming. */
    groups: { type: Object, required: true },
    /* Avbockade förekomster i containern, nyast först, högst tjugo. */
    completed: { type: Array, required: true },
    /* Underhållsfiltrets läge, ur `?maintenance=1`. */
    maintenance: { type: Boolean, required: true },
    /*
     * `{ update, calendar, export }` — hjältens *Redigera container* och de
     * två snabblänkarna. Varje flagga är serverns svar på samma policyfråga
     * som målruttens egen grind ställer; rutterna prövar dem på nytt.
     */
    can: { type: Object, default: null },
});

const { t } = useTranslations();

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
    const params = onlyMaintenance.value ? { maintenance: 1 } : {};

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
</script>

<template>
    <ContainerLayout hero="compact" :container="container" :can="can">
        <Head :title="t('container.tasks.title')" />

        <h1 class="text-2xl font-semibold">{{ t('container.tasks.heading') }}</h1>

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
            Det tomma läget (Beslut 3). Först när alla fem är tomma — de fyra
            öppna grupperna OCH *Klart* — annars hade en lista med bara
            avbockade rader sagt att containern saknar uppgifter.
        -->
        <div v-if="!hasAnyTask" class="mt-8">
            <UiEmptyState>
                {{ t('container.tasks.empty') }}
            </UiEmptyState>
        </div>

        <!--
            Listan (Beslut 1 och 3): grupperna i den ordning servern gav dem,
            sedan *Klart*. En sektion ritas bara när den har rader — en tom
            grupp har ingen rubrik att visa.
        -->
        <div v-else class="mt-6 flex flex-col gap-8">
            <template v-for="(entries, group) in groups" :key="group">
                <section v-if="entries.length > 0" class="min-w-0">
                    <h2 class="text-sm font-medium text-slate-700">{{ t(`todo.group.${group}`) }}</h2>

                    <ul class="mt-2 flex flex-col divide-y divide-slate-200">
                        <TodoRow
                            v-for="entry in entries"
                            :key="entry.ulid"
                            :entry="entry"
                            :show-container="false"
                        />
                    </ul>
                </section>
            </template>

            <!--
                *Klart* (Beslut 3). Raden bär samma upplysningar som de öppna
                raderna — schemats titel, itemet, ett datum — men datumet är
                `completed_at`, och avbockningsknappen ritas inte: det finns
                ingenting kvar att bocka av, och rutten hade svarat att
                förekomsten inte är öppen.
            -->
            <section v-if="completed.length > 0" class="min-w-0">
                <h2 class="text-sm font-medium text-slate-700">{{ t('container.tasks.done') }}</h2>

                <ul class="mt-2 flex flex-col divide-y divide-slate-200">
                    <TodoRow
                        v-for="entry in completed"
                        :key="entry.ulid"
                        :entry="entry"
                        :show-container="false"
                    />
                </ul>
            </section>
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
