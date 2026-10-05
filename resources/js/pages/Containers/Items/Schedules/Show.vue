<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import ContainerLayout from '../../../../layouts/ContainerLayout.vue';
import ItemTargetPicker from '../../../../components/ItemTargetPicker.vue';
import OpenOccurrence from '../../../../components/OpenOccurrence.vue';
import ScheduleDependencySection from '../../../../components/ScheduleDependencySection.vue';
import { formatDate } from '../../../../components/accessPresentation.js';
import { formatDateOnly } from '../../../../components/itemPresentation.js';
import { occurrenceStatusLabel, scheduleUrl } from '../../../../components/occurrencePresentation.js';
import { recurrenceLabel } from '../../../../components/schedulePresentation.js';
import { useTranslations } from '../../../../composables/useTranslations.js';

/*
 * Schemats sida — regeln, den öppna förekomsten och historiken, se issue 63b
 * § Beslut 1, 2, 3, 4, 5 och 7.
 *
 * Sidan ligger i ContainerLayout och bär samma två propar som Create.vue och
 * Edit.vue: `container` ur App\Http\Resources\ContainerResource och `item`
 * som `{ulid, name}`. `schedule` är App\Http\Resources\ScheduleResource rakt
 * av, och `occurrences` är hela listan ur
 * App\Http\Resources\ScheduleOccurrenceResource — den ÖPPNA förekomsten
 * inräknad, precis som `Api\ScheduleOccurrenceController::index()` svarar.
 *
 * **Historiken ÄR loggen** ([[Scheman och uppgifter]] § schedule_occurrence):
 * svaret på "när bytte jag impellern senast" är de avklarade förekomsterna
 * själva, inte en historiktabell. Sidan ritar därför ingen egen lista vid
 * sidan av — den ritar listan, och den öppna raden lyfts ut till sin egen
 * ruta ovanför för att den går att göra något med.
 *
 * **Ingen sortering och ingen paginering i vyn** (Beslut 7). Ordningen är
 * serverns — `due_at` fallande med `id` fallande, samma som `/api` (22a
 * § Beslut 1) — och vyn filtrerar bara ut den öppna raden ur den. Den öppna
 * raden är definitionsmässigt den som ska göras nu, och att sortera om
 * listan här hade gett två sanningar om ordningen.
 *
 * **`overdue` läses ur serverns svar** (Beslut 3). Vyn jämför aldrig
 * `due_at` mot sin egen klocka; det är komponenten OpenOccurrence.vue som
 * ritar märkningen, och den läser samma härledda fält.
 *
 * **Efter en avbockning laddas sidan om från servern** (Beslut 8). Den nya
 * förekomstens `due_at` beräknas av App\Actions\Schedule\CloseOccurrence —
 * `fixed` från kalendern, `interval` från `completed_at` — och vyn gissar
 * den aldrig: svaret på en avbockning är en omdirigering tillbaka hit, och
 * listan ritas ur serverns svar.
 */
const props = defineProps({
    container: { type: Object, required: true },
    item: { type: Object, required: true },
    schedule: { type: Object, required: true },
    /* Alla förekomster, den öppna inräknad, i serverns ordning. */
    occurrences: { type: Array, required: true },
    /* Schemats beroenden — regeln (63c § Beslut 2). */
    scheduleDependencies: { type: Array, required: true },
    /* Den öppna förekomstens beroenden — undantaget (63c § Beslut 2). */
    occurrenceDependencies: { type: Array, required: true },
    hasOpenOccurrence: { type: Boolean, required: true },
    /* Motparterna användaren får ändra, en lista per nivå (63c § Beslut 3). */
    counterparts: { type: Object, required: true },
    can: { type: Object, required: true },
});

const { t } = useTranslations();
const page = usePage();

const locale = computed(() => page.props.locale);

const recurrence = computed(() => recurrenceLabel(t, props.schedule));

/*
 * Den öppna förekomsten lyfts ur listan och ritas för sig — den är den enda
 * som går att göra något med. Historiken är resten, i den ordning servern
 * gav den.
 */
const open = computed(() => props.occurrences.find((occurrence) => occurrence.status === 'open') ?? null);

const history = computed(() => props.occurrences.filter((occurrence) => occurrence.status !== 'open'));

/*
 * En avklarad rad och en överhoppad rad får inte se likadana ut (Beslut 5):
 * historiken är loggen, och den som läser den ska kunna se vad som faktiskt
 * blev gjort. Orden skiljer dem (occurrenceStatusLabel), och färgen gör det.
 */
const badgeClass = (occurrence) => (occurrence.status === 'completed'
    ? 'bg-emerald-100 text-emerald-900'
    : 'bg-amber-100 text-amber-900');

const date = (value) => formatDateOnly(value, locale.value);

/*
 * Beroenderadena, normaliserade till samma form för båda nivåerna (Beslut 1
 * och 4). Servern svarar med `/api`:s två resurser — de skiljer sig åt på
 * exakt två punkter: en förekomstmotpart har ett förfallodatum och ett
 * `satisfied`, och dess `depends_on.ulid` pekar på en FÖREKOMST och inte på
 * ett schema. Här blir båda samma rad, och sektionen behöver inte veta vilken
 * nivå den ritar.
 *
 * `schedule_ulid` är därför det enda fältet vyn får utöver resursen: länken
 * vidare ska gå till motpartens SCHEMA (Beslut 5), och på schemanivån är
 * `depends_on.ulid` redan det.
 */
const scheduleRows = computed(() => props.scheduleDependencies.map((row) => ({
    ulid: row.depends_on.ulid,
    title: row.depends_on.title,
    item: row.depends_on.item,
    schedule_ulid: row.depends_on.ulid,
    due: null,
    satisfied: null,
})));

const occurrenceRows = computed(() => props.occurrenceDependencies.map((row) => ({
    ulid: row.depends_on.ulid,
    title: row.depends_on.title,
    item: row.depends_on.item,
    schedule_ulid: row.schedule_ulid,
    due: row.depends_on.due_at,
    satisfied: row.satisfied,
})));

const scheduleDependencyUrl = computed(
    () => `${scheduleUrl(props.container.ulid, props.item.ulid, props.schedule.ulid)}/dependencies`,
);

const occurrenceDependencyUrl = computed(() => (open.value === null
    ? ''
    : `${scheduleUrl(props.container.ulid, props.item.ulid, props.schedule.ulid)}/occurrences/${open.value.ulid}/dependencies`));

/*
 * Regelns egna två handlingar (issue 720 § Beslut 2). Pausen och raderingen
 * flyttade hit från resources/js/components/ScheduleListSection.vue, där de
 * står kvar tills issue 227 tar bort dem: schemats sida är regelns yta, och
 * det är hit itemets tab länkar.
 *
 * Båda anropar samma rutt som redigeringen — PATCH med bara `is_active` för
 * pausen, DELETE för raderingen — och servern svarar med en omdirigering
 * tillbaka hit. `pending` är sidans vänteläge: här ritas ETT schema, så en
 * boolean räcker där listan behövde radens ULID. `onFinish` nollställer den
 * även när svaret blev ett fel.
 *
 * Bekräftelsen är webbläsarens egen dialog med serverns mening ur `lang/` —
 * ingen modal och ingen sträng i JavaScript, samma mönster som listan.
 */
const pending = ref(false);

const scheduleActionUrl = scheduleUrl(props.container.ulid, props.item.ulid, props.schedule.ulid);

function toggle() {
    router.patch(scheduleActionUrl, { is_active: ! props.schedule.is_active }, {
        preserveScroll: true,
        onStart: () => { pending.value = true; },
        onFinish: () => { pending.value = false; },
    });
}

function destroy() {
    if (! window.confirm(t('item.schedule.destroy_confirm'))) {
        return;
    }

    router.delete(scheduleActionUrl, {
        preserveScroll: true,
        onStart: () => { pending.value = true; },
        onFinish: () => { pending.value = false; },
    });
}

/*
 * Flytten (issue 243 · [[ADR-0053 Flytt och kopiering]] § 6). Uppgiften
 * flyttas men kopieras aldrig — historiken är svaret på när den gjordes
 * senast, och två uppgifter med samma historik svarar fel (§ 1). Knappen står
 * bakom `can.delete` på itemet, som raderingen intill: en flytt tar bort
 * något från källan.
 *
 * Väljaren (issue 242) lämnar målet; svaret går till schemats sida på det
 * NYA itemet, för uppgiften ligger inte kvar här. Domänfelet — en uppgift med
 * beroenden kan flyttas inom containern men inte till en annan — kommer som
 * ett fältfel på `schedule` och ritas under knappen.
 */
const pickerOpen = ref(false);
const pickerTrigger = ref(null);
const moveError = ref(null);

function openPicker(event) {
    pickerTrigger.value = event.currentTarget;
    moveError.value = null;
    pickerOpen.value = true;
}

function closePicker() {
    pickerOpen.value = false;
}

function chooseTarget(target) {
    router.post(`${scheduleActionUrl}/move`, { target: target.ulid }, {
        preserveScroll: true,
        onStart: () => { pending.value = true; },
        onFinish: () => { pending.value = false; },
        onError: (errors) => { moveError.value = errors.schedule ?? null; },
        onHttpException: (response) => {
            moveError.value = response.status === 403 ? t('error.403') : t('error.generic');

            return false;
        },
    });
}
</script>

<template>
    <ContainerLayout :container="container">
        <Head :title="schedule.title" />

        <h1 class="text-2xl font-semibold">{{ schedule.title }}</h1>

        <div class="mt-2 flex flex-wrap items-center gap-3">
            <p class="text-sm text-slate-600">{{ recurrence }}</p>

            <!-- Pausen är synlig och reversibel (issue 720 § Beslut 3): samma
                 märke och samma mening som listan använder, så att ett pausat
                 schema går att känna igen på båda ytorna. -->
            <span
                v-if="! schedule.is_active"
                class="rounded bg-slate-200 px-2 py-0.5 text-xs font-medium text-slate-700"
            >
                {{ t('item.schedule.paused') }}
            </span>
        </div>

        <p v-if="! schedule.is_active" class="mt-2 text-sm text-slate-600">
            {{ t('item.schedule.paused_note') }}
        </p>

        <p v-if="schedule.notes" class="mt-2 whitespace-pre-line text-slate-900">{{ schedule.notes }}</p>

        <div class="mt-4 flex flex-wrap gap-4 text-sm">
            <Link
                :href="`/containers/${container.ulid}/items/${item.ulid}`"
                class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline"
            >
                {{ t('item.schedule.back') }}
            </Link>

            <Link
                v-if="can.update"
                :href="`/containers/${container.ulid}/items/${item.ulid}/schedules/${schedule.ulid}/edit`"
                class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline"
            >
                {{ t('item.schedule.edit') }}
            </Link>

            <button
                v-if="can.update"
                type="button"
                :disabled="pending"
                class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline"
                @click="toggle"
            >
                {{ pending ? t('common.pending.default') : (schedule.is_active ? t('item.schedule.pause') : t('item.schedule.resume')) }}
            </button>

            <!-- Flytten (issue 243 · [[ADR-0053 Flytt och kopiering]] § 6):
                 bakom `can.delete`, som raderingen intill — en flytt tar bort
                 något från källan. Servern prövar samma grind på nytt. -->
            <button
                v-if="can.delete"
                type="button"
                :disabled="pending"
                class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline"
                @click="openPicker($event)"
            >
                {{ t('item.schedule.move') }}
            </button>

            <button
                v-if="can.delete"
                type="button"
                :disabled="pending"
                class="inline-flex min-h-11 items-center font-medium text-red-700 hover:underline"
                @click="destroy"
            >
                {{ pending ? t('common.pending.default') : t('item.schedule.destroy') }}
            </button>
        </div>

        <!-- Flyttens fel (issue 243): `schedule.has_dependencies` när målet
             ligger i en annan container och uppgiften har beroenden, eller en
             403:a ur grinden. Det står under knappen, och sidan står kvar. -->
        <p v-if="moveError" role="alert" class="mt-2 text-sm text-red-700">
            {{ moveError }}
        </p>

        <section class="mt-8">
            <!-- Den öppna förekomsten med sin avbockning (Beslut 1). En
                 engångsuppgift som redan är klar har ingen öppen rad — då
                 sägs det i stället, och historiken nedanför bär raden. -->
            <OpenOccurrence
                v-if="open"
                :container-ulid="container.ulid"
                :item-ulid="item.ulid"
                :schedule-ulid="schedule.ulid"
                :occurrence="open"
                :container-account="container.account"
                :can="can"
            />

            <p v-else class="text-sm text-slate-600">
                {{ schedule.recurrence_type === 'none'
                    ? t('item.schedule.occurrence.done')
                    : t('item.schedule.occurrence.none') }}
            </p>
        </section>

        <section class="mt-10">
            <h2 class="text-lg font-semibold">{{ t('item.schedule.occurrence.history') }}</h2>

            <p v-if="history.length === 0" class="mt-2 text-sm text-slate-600">
                {{ t('item.schedule.occurrence.history_empty') }}
            </p>

            <ul v-else class="mt-2 flex flex-col gap-2">
                <li
                    v-for="occurrence in history"
                    :key="occurrence.ulid"
                    class="rounded border border-slate-300 bg-white px-4 py-3"
                >
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="rounded px-2 py-0.5 text-xs font-medium" :class="badgeClass(occurrence)">
                            {{ occurrenceStatusLabel(t, occurrence) }}
                        </span>

                        <span class="text-sm text-slate-700">
                            {{ t('item.schedule.occurrence.due', { date: date(occurrence.due_at) }) }}
                        </span>

                        <span v-if="occurrence.completed_at" class="text-sm text-slate-600">
                            {{ t('item.schedule.occurrence.completed_at', { date: formatDate(occurrence.completed_at, locale) }) }}
                        </span>

                        <!-- Kontot är varvet och inte den anställde (Beslut 4):
                             det är det som står i loggen, och det är därför
                             resursen bär `completed_by_account` och aldrig
                             `completed_by_user`. -->
                        <span v-if="occurrence.completed_by_account" class="text-sm text-slate-600">
                            {{ t('item.schedule.occurrence.completed_by', { name: occurrence.completed_by_account.name }) }}
                        </span>
                    </div>

                    <p v-if="occurrence.completion_note" class="mt-2 whitespace-pre-line text-sm text-slate-800">
                        {{ occurrence.completion_note }}
                    </p>
                </li>
            </ul>
        </section>

        <!--
            Beroendena, i två sektioner och aldrig en (63c § Beslut 2).
            Schemanivån är REGELN som ärvs av varje ny förekomst; förekomstnivån
            är UNDANTAGET som bara gäller den här gången. Rubrikerna bär
            skillnaden — en gemensam lista med en typkolumn hade krävt att
            användaren först förstod modellen ([[ADR-0005 Schema och
            förekomst]] § Motivering).

            Båda listorna kom med sidan (63c § Beslut 1): ingen av dem har en
            egen rutt, och ingen av dem hämtas av vyn.
        -->
        <ScheduleDependencySection
            level="schedule"
            :container-ulid="container.ulid"
            :item-ulid="item.ulid"
            :rows="scheduleRows"
            :url="scheduleDependencyUrl"
            :counterparts="counterparts.schedule"
            :can="can"
        />

        <ScheduleDependencySection
            level="occurrence"
            :container-ulid="container.ulid"
            :item-ulid="item.ulid"
            :rows="occurrenceRows"
            :url="occurrenceDependencyUrl"
            :counterparts="counterparts.occurrence"
            :active="hasOpenOccurrence"
            :can="can"
        />

        <!-- Målväljaren (issue 242 och 243): målet är ett item, och
             `excludeItem` är itemet uppgiften står på — att flytta den till
             sig själv är inget mål. Rubriken är uppgiftens egen mening, för
             den som väljer ser vad som flyttas. -->
        <ItemTargetPicker
            :open="pickerOpen"
            :trigger="pickerTrigger"
            :exclude-item="item.ulid"
            :heading="t('item.schedule.move_heading')"
            @choose="chooseTarget"
            @close="closePicker"
        />
    </ContainerLayout>
</template>
