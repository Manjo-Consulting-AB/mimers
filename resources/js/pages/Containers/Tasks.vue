<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
import TodoRow from '../../components/TodoRow.vue';
import UiListRow from '../../components/UiListRow.vue';
import { scheduleUrl } from '../../components/occurrencePresentation.js';
import { useRelativeDate } from '../../composables/useRelativeDate.js';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Containerns uppgiftsflik — tavlan, se issue 174 · [[ADR-0050
 * Desktopdesignen]] § 4 och 16.
 *
 * Sidan ligger i ContainerLayout och bär den prop layouten kräver: `container`
 * ur App\Http\Resources\ContainerResource — samma kontrakt som översikten,
 * itemlistan, historiken och inställningssidan. Hjälten är `compact` som på
 * varje flik utom översikten (issue 170), och `can` bär hjältens *Redigera
 * container*.
 *
 * **Fyra kolumner, och de tre första är `/tasks` egna grupper** (Beslut 2).
 * `groups` kommer som tre färdiga listor i ritningsordning — försenat, idag,
 * kommande — ur App\Actions\Schedule\ListTodo::forContainer() UTAN gräns, och
 * vyn itererar objektets nycklar som de kommer. Den räknar aldrig en grupp
 * själv: en `computed` som jämför `due_at` mot `Date.now()` hade flyttat en
 * uppgift till fel hög så fort klientens klocka gick fel, och en förekomst
 * hamnar i samma kolumn här som på `/tasks` (issue 64 § Beslut 3, issue 135).
 *
 * **Den fjärde kolumnen är `completed`** (Beslut 3): avbockade förekomster i
 * containern, nyast först och högst tjugo. Raden är INTE `TodoRow` — den
 * ritar en avbockningsknapp, och en avbockad rad har ingenting att bocka av.
 * Den är `UiListRow` (issue 99) med samma tre upplysningar som de öppna
 * raderna bär — itemet, schemat och ett datum — men datumet är
 * `completed_at`, och det skrivs av `eventDate()`: en avbockning är en
 * HÄNDELSE och inte en förfallodag, och `dueDate()` hade svarat *Om 3 dagar*
 * om något som gjordes i förrgår (issue 104).
 *
 * **Under `md:` är tavlan en lista** (ADR-0050 § 16). Samma markup ligger i en
 * kolumn i stället för fyra: rutnätet slås på först över brytpunkten, och
 * under den staplas sektionerna i kolumnordningen med sin rubrik — exakt den
 * lista issuen ber om, utan en andra uppsättning markup. Träffytan är 44 px
 * (`min-h-11`) i varje rad och varje länk (issue 68a § Beslut 3).
 *
 * **Filtret är underhållsfiltret, och det står i querysträngen** (Beslut 4).
 * Kryssrutan postar ingen kropp: `router.get` mot SAMMA rutt som sidan ligger
 * på, med `maintenance` satt eller utelämnad, så ett filtrerat läge är en
 * adress man kan spara, dela och backa ur (issue 59a § Beslut 1). Vyn
 * filtrerar ingenting själv — den skickar ett värde och ritar det svar den
 * får, och `props.maintenance` speglas med `watch` så att kryssrutan följer
 * servern efter en bakåtknapp eller en omladdning. Vänteläget stänger
 * kontrollen medan svaret är på väg (issue 68a § Beslut 4).
 *
 * **Snabblänkarna ritas ur `can`** (Beslut 5). `can.calendar` och
 * `can.export` är serverns svar på samma policyfråga som
 * CalendarFeedController och ExportController ställer i sina index(), och
 * länken ritas bara när svaret är ja: en genväg till en yta man inte får
 * öppna är en död länk, och en yta ingen hittar är samma sak som en yta som
 * inte finns. Etiketterna är flikradens egna ord — samma mål, samma namn.
 *
 * **Tomma kolumner ritas som rubriker utan rader.** Tavlan är fyra kolumner
 * oavsett hur många uppgifter containern har, och en kolumn som försvann när
 * den var tom hade gjort tavlans form beroende av innehållet: den som undrar
 * var de avbockade uppgifterna tog vägen hittar rubriken och ser att svaret är
 * "inga", inte att ytan saknas.
 *
 * Ingen sträng i JavaScript (issue 52 · [[ADR-0013 Språk och i18n]]): rubriken,
 * flikens namn i webbläsaren, kolumnrubrikerna, kryssrutan och snabblänkarnas
 * ord kommer ur `t()`. De tre öppna kolumnernas rubriker är `todo.group.*` —
 * samma grupp, samma ord, en nyckel — och de två genvägarna bär
 * `container.nav.*`, som flikraden gjorde.
 */
const props = defineProps({
    /* Containern ur App\Http\Resources\ContainerResource. */
    container: { type: Object, required: true },
    /* De tre öppna kolumnerna, i ritningsordning: overdue, today, upcoming. */
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
const { eventDate } = useRelativeDate();

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
 * Adresserna byggs i vyn, som i varje annan rad: rutten `containers.calendar`
 * och `containers.export` är containerns egna undersidor, och samma två
 * adresser står i resources/js/layouts/containerSections.js. Schemats adress
 * kommer ur occurrencePresentation.js — en enda stavning av samma rutt.
 */
const calendarUrl = () => `/containers/${props.container.ulid}/calendar`;
const exportUrl = () => `/containers/${props.container.ulid}/export`;

const itemUrl = (entry) => `/containers/${entry.container.ulid}/items/${entry.item.ulid}`;

const completedAt = (entry) => eventDate(entry.completed_at);
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
            Tavlan. Under `md:` staplas sektionerna i kolumnordningen — samma
            markup, en kolumn — och över brytpunkten blir de fyra.
        -->
        <div class="mt-6 flex flex-col gap-8 md:grid md:grid-cols-4 md:gap-4">
            <section v-for="(entries, group) in groups" :key="group" class="min-w-0">
                <h2 class="text-sm font-medium text-slate-700">{{ t(`todo.group.${group}`) }}</h2>

                <ul v-if="entries.length > 0" class="mt-2 flex flex-col divide-y divide-slate-200">
                    <TodoRow v-for="entry in entries" :key="entry.ulid" :entry="entry" />
                </ul>
            </section>

            <!--
                *Klart* (Beslut 3). Raden bär samma tre upplysningar som de
                öppna raderna — itemet, schemat, ett datum — men datumet är
                `completed_at`, och avbockningsknappen ritas inte: det finns
                ingenting kvar att bocka av, och rutten hade svarat att
                förekomsten inte är öppen.
            -->
            <section class="min-w-0">
                <h2 class="text-sm font-medium text-slate-700">{{ t('container.tasks.done') }}</h2>

                <ul v-if="completed.length > 0" class="mt-2 flex flex-col divide-y divide-slate-200">
                    <UiListRow v-for="entry in completed" :key="entry.ulid">
                        <template #title>
                            <Link
                                :href="itemUrl(entry)"
                                class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline"
                            >
                                {{ entry.item.name }}
                            </Link>
                        </template>

                        <template #subtitle>
                            <Link
                                :href="scheduleUrl(entry.container.ulid, entry.item.ulid, entry.schedule.ulid)"
                                class="inline-flex min-h-11 items-center text-blue-700 hover:underline"
                            >
                                {{ entry.schedule.title }}
                            </Link>
                        </template>

                        <template #meta>
                            <time :datetime="entry.completed_at">{{ completedAt(entry).text }}</time>
                        </template>
                    </UiListRow>
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
