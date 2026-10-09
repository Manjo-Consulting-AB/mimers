<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import ItemTargetPicker from './ItemTargetPicker.vue';
import UiBadge from './UiBadge.vue';
import { occurrenceActionUrl, scheduleUrl } from './occurrencePresentation.js';
import { useRelativeDate } from '../composables/useRelativeDate.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * En rad i todo-listan, se issue 64 § Beslut 4 och 5, och M24 · issue 230
 * (docs/Design/tasks-container.png).
 *
 * **Raden har mockupens form** (issue 230): avbockningen är en rund bock till
 * vänster, titeln och kontexten står i mitten, och datumet med kalenderikon och
 * itemets miniatyr står till höger. `UiListRow`-raden för *Done* är borta —
 * samma rad ritas nu av EN komponent, se `isDone` nedan.
 *
 * **Raden leds av uppgiften och inte av itemet** (M24 · issue 719, Beslut 4):
 * första raden är SCHEMats titel — vad som ska göras — och den är en länk till
 * schemats sida (63b). Underraden bär itemet och, när ytan behöver det,
 * containern: "Byt impeller" utan "Motorn" och "Havsörnen" går inte att handla
 * på när man har fyra containers.
 *
 * **Underraden visar platsen, och i inboxen är platsen *Inbox*** (M28 · issue
 * 774, ADR-0054 § 1 och § 7). Är `entry.in_inbox` sann ritas EN länk —
 * `todo.location.inbox` till `/inbox` — i stället för itemet och containern,
 * som båda är dolda för användaren. Titeln ovan rörs inte: den länkar
 * fortfarande till uppgiftens sida.
 *
 * **Etiketten till höger är itemets kategori** (M28 · issue 781, Tonys beslut
 * 2026-10-07). `entry.category` kommer färdig ur `ListTodo::row()` som
 * `{ name }` eller `null` och ritas som ett neutralt `UiBadge` bredvid
 * datumet — mockupens etikett, med den information vi har. Uppgifter har ingen
 * egen tagg- eller kategorimodell: etiketten är itemets kategori och ingenting
 * annat, och den bär ingen färg. Saknar itemet kategori ritas ingen etikett
 * och ingen tom plats.
 *
 * **`showContainer` styr containerlänken** (Beslut 4). På `/tasks` och i
 * dashboardens panel behövs containernamnet — raden står bland andra containers
 * — men på containerns uppgiftsflik står containern redan i hjälten, och fliken
 * skickar `false`. Förvalet är `true`, så de ytor som inte säger något får
 * namnet.
 *
 * **Avbockningen är 63b:s rutt, rakt av** (Beslut 4). Samma `complete`-rutt,
 * samma grind, samma `CompleteOccurrenceRequest` — `back()` landar på todo-vyn,
 * som ritas om ur serverns svar. Ingen ny rutt och ingen egen stängning: den
 * nya förekomstens `due_at` beräknas av App\Actions\Schedule\CloseOccurrence och
 * kan inte gissas här.
 *
 * **Kontot kommer färdigt ur proppen** (Beslut 4). Servern har räknat förvalet —
 * containerns ägarkonto när användaren är medlem i det, annars hennes första
 * konto (63b § Beslut 4) — och vyn skickar bara tillbaka ULID:t. Ingen väljare:
 * raden är en arbetsyta och inte ett formulär, och en lista på hundra rader ska
 * inte bära hundra kontoväljare.
 *
 * **Den runda bockens kant följer serverns fält** (Beslut 1). `entry.overdue`
 * ger `border-danger`, idag — varken `overdue` eller `upcoming` — ger
 * `border-warning`, och allt annat `border-ink-subtle`. Raden jämför inga datum
 * själv: fälten är serverns, precis som grupperingen (issue 64 § Beslut 3), och
 * en klient med fel klocka ska inte kunna färga en uppgift röd.
 *
 * **Bockens ord bor i `aria-label`** (Beslut 1). Den gamla pricken (issue 133)
 * och textknappen *Check off* är borta; den skärmläsartext pricken bar flyttar
 * in i knappens namn: `todo.complete` och, när servern pekat ut ett tillstånd,
 * grupprubrikens eget ord (`todo.group.*`). Samma ord om samma sak — en egen
 * kopia i `lang/` hade varit den andra sanningen om vad gruppen heter.
 *
 * **Knappen ritas bara för den som får bocka av** (Beslut 4). `can.update`
 * räknas på servern med `ItemPolicy::update()`; flaggan är presentation, och
 * postar en `read`-mottagare ändå svarar rutten 403. Saknar hon rätten ritas
 * cirkeln utan knapp (`aria-hidden`). Domänfelet — ett pausat schema, en redan
 * stängd förekomst, ett öppet beroende — formuleras av servern och ritas på
 * raden. På `/tasks` och containerns flik finns blockerade uppgifter aldrig i
 * listan (villkor tre i `scopeTodoFor`), men på itemets flik gör de det med
 * märket nedan, och då är serverfelet vägen till att förklara varför
 * avbockningen nekas.
 *
 * **Två märken är presentation** (M24 · issue 227, Beslut 4). `paused` sätts på
 * en rad vars schema är pausat (`! is_active`), och `blocked` på en rad vars
 * förekomst har ett öppet beroende — båda kommer färdigräknade ur
 * App\Actions\Schedule\ListItemTasks och läses aldrig ur klockan eller ur en
 * egen fråga. Nycklarna finns bara på itemets flik; på `/tasks` och containerns
 * flik är `entry.paused` och `entry.blocked` odefinierade, alltså falska, och
 * inget märke ritas.
 *
 * **En försenad rad har en röd ton** (Beslut 4), `bg-danger/5` — samma roll
 * som cirkelns kant, men svagare. Färgen är aldrig den enda bäraren: datumet
 * står kvar med sina egna ord.
 *
 * **En rad utan datum ritar ingen datumtext** (M26 · issue 234). `due_at` är
 * nullbart sedan [[ADR-0052 Uppgifternas listor och uppgifter utan datum]] § 3,
 * och `dueDate(null)` svarar `null`; raden ritar då ingen `<time>` alls i
 * stället för att skriva "Due " med tomt datum. Hur raden i övrigt ser ut utan
 * datum är issue 236.
 *
 * **En avbockad rad är samma rad** (Beslut 5). Finns `entry.completed_at` ritas
 * en fylld bock (`bg-success`) utan knapp och utan formulär, och datumet till
 * höger är `eventDate()` i stället för `dueDate()`: en avbockning är en
 * HÄNDELSE och inte en förfallodag, och `dueDate()` hade svarat *Om 3 dagar* om
 * något som gjordes i förrgår (issue 104). `Tasks.vue` och
 * `ScheduleListSection.vue` ritar sina *Done*-rader med den här komponenten —
 * två formuleringar av samma rad blir en.
 *
 * **`…`-menyn bär *Move…*** (M28 · issue 784, [[ADR-0053 Flytt och
 * kopiering]] § 6). Raden fick sin första beslutade handling: en uppgift kan
 * flyttas till ett annat item direkt från listan. Menyn är en `<details>` med
 * det egna öppna-tillståndet i webbläsaren — samma grepp som
 * Documents.vue och Inbox/Index.vue — och den ritas bara när ytan bär den
 * (`movable`), när `entry.can.move` är sann, aldrig för en inboxuppgift (den
 * bearbetas på `/inbox`), och aldrig på en avbockad rad.
 *
 * **Flytten är uppgiftens sidas flytt, ord för ord** (Beslut 3). *Move…*
 * öppnar `ItemTargetPicker` med radens item som `excludeItem`, och valet
 * postar till den befintliga rutten `containers.items.schedules.move` med
 * samma väljare som `Schedules/Show.vue` — ingen egen rutt och ingen egen
 * väljare. **En sak skiljer kropparna** (M28 · issue 266 § Beslut 1): raden
 * skickar `return: 'back'`, så svaret landar tillbaka i listan i stället för
 * på uppgiftens nya sida; uppgiftens egen sida skickar inget sådant fält.
 * Domänfelet — en uppgift med beroenden kan flyttas inom containern men inte
 * till en annan — kommer som ett fältfel på `schedule` och ritas i radens
 * ENDA felruta, samma ställe som avbockningens.
 *
 * Färgrollerna kommer ur [[ADR-0042 Designsystemet]] § Beslut — inga råa
 * palettfärger i raden.
 */
const props = defineProps({
    /* En post ur todo-listan: TodoEntryResource plus `account`, `can` och `cover`. */
    entry: { type: Object, required: true },
    /*
     * Ritar containernamnet på underraden. `true` är förvalet — `/tasks` och
     * dashboardens panel behöver det — och containerns uppgiftsflik skickar
     * `false`, för containern står redan i hjälten.
     */
    showContainer: { type: Boolean, default: true },
    /*
     * Ritar raden *Move…*-menyn (M28 · issue 784). `false` är förvalet: bara
     * `/tasks` bär menyn, för det är där en uppgift flyttas i en lista. De
     * andra ytorna — containerns och itemets flikar, dashboardens panel —
     * skickar inget och får ingen meny, utan att någon av dem behöver veta
     * varför.
     */
    movable: { type: Boolean, default: false },
});

const { t } = useTranslations();
const { dueDate, eventDate } = useRelativeDate();

/*
 * Förfallodagen ur datumregeln (issue 104): relativ inom gränsen, absolut
 * bortom den. `entry.overdue` är serverns fält och går in i regeln — raden
 * räknar aldrig försenat själv, och `due.relative` säger att meningen redan
 * bär sin egen preposition ("In 24 days", inte "Due In 24 days").
 */
const due = computed(() => dueDate(props.entry.due_at, props.entry.overdue));

/* Avbockad (Beslut 5): fylld bock, ingen knapp, och ett händelsedatum. */
const isDone = computed(() => Boolean(props.entry.completed_at));

/*
 * Datumet till höger: avbockningens tidsstämpel när raden är klar, annars
 * förfallodagen. En `null`-text — ett datum servern inte kunde läsa, eller en
 * rad utan datum (ADR-0052 § 3) — ritas inte alls.
 */
const completed = computed(() => (isDone.value ? eventDate(props.entry.completed_at) : null));

/* Finns ingen datumtext ritas ingen `<time>`: en tom "Due " säger ingenting. */
const hasDateText = computed(() => (isDone.value ? completed.value.text !== null : due.value.text !== null));

/*
 * En rad utan datum säger *No date* i datumets plats (M26 · issue 236
 * § Beslut 5, ADR-0052 § 3). Ordet står utan kalenderikon och utan röd ton:
 * en uppgift utan datum är varken försenad eller kommande, och färgen får
 * inte låna en roll den inte har. Bara en rad som inte är klar — en avbockad
 * rad har sitt händelsedatum.
 */
const hasNoDate = computed(() => ! isDone.value && due.value.text === null);

/*
 * Cirkelns kant (Beslut 1): serverns fält avgör, aldrig klockan. Idag är
 * varken `overdue` eller `upcoming`, och de två fälten är varandras komplement
 * — en rad som förfaller i dag har alltså ingen av dem.
 */
const circleClass = computed(() => {
    if (props.entry.overdue) {
        return 'border-danger';
    }

    return props.entry.upcoming ? 'border-ink-subtle' : 'border-warning';
});

/*
 * Bockens namn (Beslut 1). Tillståndet läggs till grupprubrikens ord så att en
 * skärmläsare hör *Overdue Check off* och inte bara *Check off* — pricken som
 * bar orden tidigare är borta.
 */
const completeLabel = computed(() => {
    const state = props.entry.overdue
        ? t('todo.group.overdue')
        : props.entry.upcoming
            ? t('todo.group.upcoming')
            : null;

    return state === null ? t('todo.complete') : `${t('todo.complete')}, ${state}`;
});

const itemUrl = computed(
    () => `/containers/${props.entry.container.ulid}/items/${props.entry.item.ulid}`,
);

const scheduleHref = computed(
    () => scheduleUrl(props.entry.container.ulid, props.entry.item.ulid, props.entry.schedule.ulid),
);

const form = useForm({ account: props.entry.account });

function complete() {
    form.post(
        occurrenceActionUrl(
            props.entry.container.ulid,
            props.entry.item.ulid,
            props.entry.schedule.ulid,
            props.entry.ulid,
            'complete',
        ),
        { preserveScroll: true },
    );
}

/*
 * Listan och statusen (M26 · issue 236 § Beslut 4). Två kontroller bakom
 * samma grind som avbockningen — `entry.can.update` — och bara på en rad som
 * inte är *Done*: en stängd förekomst kan inte ändras (ChangeOccurrence nekar
 * den med `occurrence.not_open`), och en väljare som alltid ger samma fel är
 * en fälla.
 *
 * **Egen form och inte `form`.** Avbockningen skickar `account`; de här två
 * skickar bara sitt eget fält, och `transform` gör att kroppen bär exakt det.
 * En delad form hade låtit en PATCH bära avbockningens konto med, eller en
 * avbockning bära en lista.
 *
 * **Rutten är samma PATCH som webben fick i issue 235** — den som
 * App\Http\Controllers\ScheduleOccurrenceController::update() svarar på — och
 * `preserveScroll` håller kvar läsaren där hon var i en lång lista.
 *
 * **De tre listorna är `todo.list.*`-nycklarna**, samma ord som
 * `gtd_list`-kolumnens värden (ADR-0052 § 1). Servern skickar ingen
 * uppräkning att läsa — bara radens eget värde — så ordningen står här och
 * bor i katalogens nycklar, inte i en egen lista i JavaScript. *Inbox* är
 * INTE med: den är en plats och inte en lista (ADR-0054 § 5), och väljaren
 * ritas inte alls för en rad som ligger i inboxen — `gtd_list` är null där,
 * och listan sätts först när uppgiften bearbetas (ProcessInboxTask).
 */
const listForm = useForm({ gtd_list: null, status: null });

const lists = ['next', 'waiting', 'someday'];

const occurrenceUrl = computed(
    () => `${scheduleHref.value}/occurrences/${props.entry.ulid}`,
);

/* Fältets id måste vara unikt i listan — varje rad har samma fältnamn. */
const listFieldId = computed(() => `todo-list-${props.entry.ulid}`);

/* Växelns tillstånd: serverns `status`, aldrig ett eget (ADR-0052 § 1). */
const inProgress = computed(() => props.entry.status === 'in_progress');

/*
 * Ligger raden i inboxen (M28 · issue 774, ADR-0054 § 1 och § 7)? Serverns
 * `entry.in_inbox` är svaret — containern bär `inbox_user_id`, och raden
 * räknar ingenting själv. Listväljaren och listmärket ritas därför inte: en
 * obearbetad uppgift har ingen lista att visa eller byta, och servern nekar en
 * liständring med `occurrence.in_inbox`.
 */
const inInbox = computed(() => Boolean(props.entry.in_inbox));

/*
 * Flytten (M28 · issue 784 · [[ADR-0053 Flytt och kopiering]] § 6). Uppgiften
 * flyttas men kopieras aldrig — historiken är svaret på när den gjordes
 * senast (§ 1) — och knappen står därför bakom `entry.can.move`, som är
 * `delete` på radens item (§ 2). Väljaren (issue 242) lämnar målet; valet
 * postar till uppgiftens sidas EGEN rutt med `target` i kroppen.
 *
 * **Felet ritas på raden** (§ 6), inte mitt över sidan: `onHttpException`
 * svarar `false`, så Inertias egen felruta stängs av och felet hamnar i
 * radens ENDA felruta. Samma mönster som ItemAttachmentSection.vue (issue
 * 243).
 */
const pickerOpen = ref(false);
const pickerTrigger = ref(null);
const moveError = ref(null);
const movePending = ref(false);

function openPicker(event) {
    pickerTrigger.value = event.currentTarget;
    moveError.value = null;
    pickerOpen.value = true;
}

function closePicker() {
    pickerOpen.value = false;
}

function chooseTarget(target) {
    /*
     * `return: 'back'` säger vart svaret ska gå (M28 · issue 266 § Beslut 1):
     * tillbaka till `/tasks` med samma frågesträng, så att servern filtrerar om
     * listan och användaren står kvar i samma vy — samma flik, filter och
     * sortering. Utan fältet hade svaret gått till uppgiftens nya sida.
     * Kontrollern validerar fältet (`nullable`, `in:back`).
     */
    router.post(`${scheduleHref.value}/move`, { target: target.ulid, return: 'back' }, {
        preserveScroll: true,
        onStart: () => {
            movePending.value = true;
            moveError.value = null;
        },
        onFinish: () => { movePending.value = false; },
        onError: (errors) => { moveError.value = errors.schedule ?? null; },
        onHttpException: (response) => {
            moveError.value = response.status === 403 ? t('error.403') : t('error.generic');

            return false;
        },
    });
}

/*
 * Radens ENDA felruta (Beslut 4). Domänfelet ur avbockningen eller bytet
 * (`occurrence.not_open`), valideringsfelet på `gtd_list` eller `status`, och
 * flyttens `schedule.has_dependencies` (M28 · issue 784) ritas på samma
 * ställe: samma förekomst, samma rad, och ett 422 som ingen ser är lika stumt
 * som ett race.
 */
const rowError = computed(
    () => form.errors.occurrence
        || listForm.errors.occurrence
        || listForm.errors.gtd_list
        || listForm.errors.status
        || moveError.value
        || null,
);

/*
 * Väljaren får inte bli stale (issue 236 § Beslut 4): `:value` binder mot
 * serverns `entry.gtd_list`, men ett `<select>` behåller sitt valda alternativ
 * även när PATCH:en nekas. Vid fel — `occurrence.not_open` i ett race eller ett
 * 422 — sätts rutan tillbaka till radens eget värde, annars visar den en lista
 * raden inte ligger i. `event.target` fångas först: händelsen får inte läsas
 * asynkront.
 */
function changeList(event) {
    const select = event.target;

    listForm
        .transform(() => ({ gtd_list: select.value }))
        .patch(occurrenceUrl.value, {
            preserveScroll: true,
            onError: () => {
                select.value = props.entry.gtd_list;
            },
        });
}

function toggleProgress() {
    listForm
        .transform(() => ({ status: inProgress.value ? 'open' : 'in_progress' }))
        .patch(occurrenceUrl.value, { preserveScroll: true });
}
</script>

<template>
    <li
        class="flex flex-wrap items-start gap-x-4 gap-y-2 py-4"
        :class="{ 'bg-danger/5': entry.overdue }"
    >
        <!-- Klar (Beslut 5): en fylld bock, ingen knapp och inget formulär. -->
        <template v-if="isDone">
            <span
                aria-hidden="true"
                class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center"
            >
                <span class="flex h-5 w-5 items-center justify-center rounded-full bg-success">
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="3"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        class="h-3 w-3 text-ink-on-accent"
                    >
                        <path d="m5 13 4 4L19 7" />
                    </svg>
                </span>
            </span>
        </template>

        <template v-else>
            <!-- Den runda bocken (Beslut 1): 44 px träffyta med en ritad
                 cirkel på 20 px. `:disabled` medan svaret är på väg, och
                 `:aria-label` bär både handlingen och tillståndet. -->
            <form v-if="entry.can.update" @submit.prevent="complete">
                <button
                    type="submit"
                    :disabled="form.processing"
                    :aria-label="form.processing ? t('common.pending.complete') : completeLabel"
                    class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-full outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 disabled:opacity-50"
                >
                    <span aria-hidden="true" class="h-5 w-5 rounded-full border-2" :class="circleClass" />
                </button>
            </form>

            <!-- Läsaren får cirkeln utan knapp: samma form, ingen handling. -->
            <span
                v-else
                aria-hidden="true"
                class="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center"
            >
                <span class="h-5 w-5 rounded-full border-2" :class="circleClass" />
            </span>
        </template>

        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-x-2">
                <Link :href="scheduleHref" class="inline-flex min-h-11 items-center font-medium text-accent hover:underline">
                    {{ entry.schedule.title }}
                </Link>

                <!-- Pausad: schemat öppnar inga nya förekomster (Beslut 4), men
                     den öppna raden ligger kvar och märks i stället för att
                     försvinna. -->
                <span
                    v-if="entry.paused"
                    class="rounded-pill border border-border bg-surface-sunken px-2 py-0.5 text-meta font-medium text-ink-muted"
                >
                    {{ t('item.schedule.paused') }}
                </span>

                <!-- Blockerad: förekomsten har ett öppet beroende. Servern
                     nekar avbockningen och formulerar felet på raden. -->
                <span
                    v-if="entry.blocked"
                    class="rounded-pill border border-border bg-surface-sunken px-2 py-0.5 text-meta font-medium text-ink-muted"
                >
                    {{ t('todo.blocked') }}
                </span>

                <!-- Listan och statusen (M26 · issue 236 § Beslut 4): en
                     kompakt väljare och växeln *In progress*, båda bakom
                     samma grind som avbockningen och bara på en rad som inte
                     är *Done*. Den som saknar rätten får listans namn som ett
                     märke i stället — samma ord, ingen kontroll hon inte får
                     använda. Listväljaren ritas inte för en rad i inboxen
                     (ADR-0054 § 5): den har ingen lista än, och servern nekar
                     bytet med `occurrence.in_inbox`. Växeln står kvar — att
                     påbörja en obearbetad uppgift är tillåtet. -->
                <template v-if="! isDone && entry.can.update">
                    <select
                        v-if="! inInbox"
                        :id="listFieldId"
                        :value="entry.gtd_list"
                        :disabled="listForm.processing"
                        :aria-label="t('todo.list.label')"
                        class="min-h-11 rounded-control border border-border bg-surface px-2 text-meta text-ink outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 disabled:opacity-50"
                        @change="changeList"
                    >
                        <option v-for="list in lists" :key="list" :value="list">
                            {{ t(`todo.list.${list}`) }}
                        </option>
                    </select>

                    <button
                        type="button"
                        :aria-pressed="inProgress"
                        :disabled="listForm.processing"
                        class="inline-flex min-h-11 items-center rounded-control border px-3 text-meta font-medium outline-none focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 disabled:opacity-50"
                        :class="inProgress ? 'border-accent bg-accent-soft text-accent' : 'border-border text-ink-muted'"
                        @click="toggleProgress"
                    >
                        {{ t('todo.in_progress') }}
                    </button>
                </template>

                <span
                    v-else-if="! isDone && ! inInbox"
                    class="rounded-pill border border-border bg-surface-sunken px-2 py-0.5 text-meta font-medium text-ink-muted"
                >
                    {{ t(`todo.list.${entry.gtd_list}`) }}
                </span>
            </div>

            <p class="mt-1 flex flex-wrap items-center gap-x-2 text-body text-ink-muted">
                <!-- Platsen (M28 · issue 774, ADR-0054 § 1 och § 7): en rad i
                     inboxen visar EN plats — *Inbox* — och aldrig det dolda
                     itemet och den dolda containern. Titeln ovan länkar
                     fortfarande till uppgiftens sida (Beslut 3). -->
                <template v-if="entry.in_inbox">
                    <Link href="/inbox" class="inline-flex min-h-11 items-center text-accent hover:underline">
                        {{ t('todo.location.inbox') }}
                    </Link>
                </template>

                <template v-else>
                    <Link :href="itemUrl" class="inline-flex min-h-11 items-center text-accent hover:underline">
                        {{ entry.item.name }}
                    </Link>

                    <!-- Containernamnet ritas bara när ytan behöver det (Beslut 4),
                         och det går till ITEMLISTAN (issue 89 · [[ADR-0039
                         Containerns översikt]] § Konsekvenser): uppgiften hör till
                         ett item, och den som följer containern ur todo-vyn letar i
                         listan — inte på en översikt. -->
                    <template v-if="showContainer">
                        <span aria-hidden="true">·</span>

                        <Link :href="`/containers/${entry.container.ulid}/items`" class="inline-flex min-h-11 items-center text-accent hover:underline">
                            {{ entry.container.name }}
                        </Link>
                    </template>
                </template>
            </p>

            <!-- Domänfelet ur avslutsflödet, formulerat av servern och
                 aldrig som en JSON-kropp — samma mönster som OpenOccurrence.
                 Felrutan är radens ENDA: ett domänfel ur list- eller
                 statusbytet (`occurrence.not_open`) ritas på samma ställe som
                 avbockningens, för det är samma förekomst och samma rutt. -->
            <p
                v-if="rowError"
                role="alert"
                tabindex="-1"
                class="mt-2 whitespace-pre-line rounded-control border border-danger bg-danger/10 px-3 py-2 text-body text-danger outline-none"
            >
                {{ rowError }}
            </p>
        </div>

        <!-- Högerdelen (Beslut 3): datumet med kalenderikon, etiketten och
             itemets miniatyr. Under `md:` bryter den till en egen rad under
             mitten, och miniatyren döljs — en telefon bär datumet, inte
             bilden. `md:` är skalets brytpunkt, inte `sm:` (GenomgangTest
             § brytpunkter, [[ADR-0050 Desktopdesignen]] § 6). -->
        <div class="flex w-full items-center gap-3 md:w-auto">
            <time
                v-if="hasDateText"
                :datetime="isDone ? entry.completed_at : entry.due_at"
                class="inline-flex items-center gap-1.5 text-meta"
                :class="!isDone && due.state === 'danger' ? 'text-danger' : 'text-ink-muted'"
            >
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    class="h-4 w-4 shrink-0"
                    aria-hidden="true"
                >
                    <rect x="3" y="5" width="18" height="16" rx="2" />
                    <path d="M8 3v4M16 3v4M3 10h18" />
                </svg>

                {{ isDone ? completed.text : (due.relative ? due.text : t('todo.due', { date: due.text })) }}
            </time>

            <!-- Utan datum står ordet i datumets plats (Beslut 5): ingen
                 kalenderikon och ingen röd ton — en uppgift utan datum är
                 varken försenad eller kommande (ADR-0052 § 3). -->
            <span v-if="hasNoDate" class="inline-flex items-center text-meta text-ink-muted">
                {{ t('todo.no_date') }}
            </span>

            <!-- Etiketten är itemets kategori (M28 · issue 781, Tonys beslut
                 2026-10-07): ett NEUTRALT `UiBadge`, för en kategori har ingen
                 färg — brickan bär ordet, inte en nyans. Saknar itemet
                 kategori ritas ingen etikett och ingen tom plats: `v-if` styr
                 båda. Uppgifter har ingen egen tagg- eller kategorimodell. -->
            <UiBadge v-if="entry.category">{{ entry.category.name }}</UiBadge>

            <img
                v-if="entry.cover?.hasThumb"
                :src="`/files/${entry.cover.ulid}?variant=thumb`"
                alt=""
                class="hidden h-10 w-10 rounded object-cover md:block"
            >

            <!-- Utan derivat ritas samma lådikon som itemlistan
                 (pages/Containers/Items/Index.vue) i stället för en `<img>`
                 mot en variant som inte finns. -->
            <span
                v-else
                aria-hidden="true"
                class="hidden h-10 w-10 shrink-0 items-center justify-center rounded border border-border bg-surface-sunken text-ink-subtle md:flex"
            >
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    class="h-5 w-5"
                >
                    <path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z" />
                    <path d="m3.3 7 8.7 5 8.7-5" />
                    <path d="M12 22V12" />
                </svg>
            </span>
        </div>

        <!-- Radmenyn (M28 · issue 784 · [[ADR-0053 Flytt och kopiering]]
             § 6): en `<details>` med det egna öppna-tillståndet i
             webbläsaren — samma grepp som Documents.vue och Inbox/Index.vue —
             och *Move…* som sin enda rad. Den ritas bara när ytan bär den
             (`movable`), när `entry.can.move` är sann, aldrig för en
             inboxuppgift (den bearbetas på `/inbox`), och aldrig på en
             avbockad rad. Servern prövar samma grind på nytt. -->
        <details
            v-if="movable && entry.can.move && ! entry.in_inbox && ! isDone"
            class="relative shrink-0"
        >
            <summary
                :aria-label="t('todo.row_menu')"
                class="inline-flex min-h-11 cursor-pointer list-none items-center rounded-control px-3 text-meta text-accent outline-none hover:underline focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
            >
                <svg viewBox="0 0 24 24" fill="currentColor" class="size-5 shrink-0" aria-hidden="true">
                    <circle cx="12" cy="5" r="1.6" />
                    <circle cx="12" cy="12" r="1.6" />
                    <circle cx="12" cy="19" r="1.6" />
                </svg>
            </summary>

            <div class="absolute right-0 z-10 mt-2 w-40 rounded-card border border-border bg-surface p-1 shadow-sm">
                <button
                    type="button"
                    :disabled="movePending"
                    class="flex min-h-11 w-full items-center rounded-control px-3 text-left text-body text-ink outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2 disabled:opacity-50"
                    @click="openPicker($event)"
                >
                    {{ t('item.schedule.move') }}
                </button>
            </div>
        </details>

        <!-- Målväljaren (issue 242 och 243, M28 · issue 784): målet är ett
             item, och `excludeItem` är itemet uppgiften står på — att flytta
             den till sig själv är inget mål. Rubriken är uppgiftens egen
             mening, för den som väljer ser vad som flyttas. -->
        <ItemTargetPicker
            :open="pickerOpen"
            :trigger="pickerTrigger"
            :exclude-item="entry.item.ulid"
            :heading="t('item.schedule.move_heading')"
            @choose="chooseTarget"
            @close="closePicker"
        />
    </li>
</template>
