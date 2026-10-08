<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
import DocumentFilterBar from '../../components/DocumentFilterBar.vue';
import DocumentFilterColumn from '../../components/DocumentFilterColumn.vue';
import ItemTargetPicker from '../../components/ItemTargetPicker.vue';
import ItemViewSwitch from '../../components/ItemViewSwitch.vue';
import StorageBar from '../../components/StorageBar.vue';
import UiBadge from '../../components/UiBadge.vue';
import UiCard from '../../components/UiCard.vue';
import UiInput from '../../components/UiInput.vue';
import UiSelect from '../../components/UiSelect.vue';
import { attachmentPreview, formatByteSize } from '../../components/attachmentPresentation.js';
import { documentFilterQuery } from '../../components/documentFilter.js';
import { useRelativeDate } from '../../composables/useRelativeDate.js';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Containerns dokumentflik — se issue 178 · [[ADR-0050 Desktopdesignen]]
 * § 12–15 och `docs/Design/dokument.png`.
 *
 * Sidan ligger i ContainerLayout och bär den prop layouten kräver: `container`
 * ur App\Http\Resources\ContainerResource — samma kontrakt som översikten,
 * itemlistan, uppgiftsfliken, kostnaderna och historiken. Hjälten är `compact`
 * som på varje flik utom översikten (issue 170), och `can` bär hjältens
 * *Redigera container*.
 *
 * **Sidan räknar ingenting** ([[ADR-0024 Tunna controllers och actions]]).
 * Listan kommer färdig ur App\Actions\Attachment\ListContainerAttachments —
 * filtrerad, sorterad och paginerad — och lagringsstapelns två tal ur
 * `usage_counter` och planen. Vyn ritar, formaterar och länkar.
 *
 * **Filtret ritas ur `filter`-proppen, och växelns länkar ur samma propp.**
 * Servern har redan läst querysträngen en gång, och vyn läser den inte igen:
 * en länk byggd på adressens råtext hade kunnat bära med sig ett värde servern
 * kastade (`?kind=video`), och då pekar fältet på ett filter listan inte
 * tillämpade. Det är samma konstruktion som filterraden i itemlistan
 * (issue 59a § Beslut 1 och 3).
 *
 * **Lista eller rutnät är ett KLIENTLÄGE** (Beslut 2). `?view=grid` står i
 * adressen — så läget går att länka till, och en omladdning landar i samma
 * vy — men servern läser den inte: samma rader svaras ut oavsett, och den
 * enda skillnaden är hur de ritas. Det är därför `view` läses ur
 * `page.url` här och inte kommer som en propp, till skillnad från `filter`:
 * ett läge som ändrar SVARET hör på servern, ett som bara ändrar ritningen hör
 * i klienten. Växelns komponent (ItemViewSwitch, issue 154) får det lästa
 * läget som `current` och läser aldrig adressen själv.
 *
 * **Ett läge behåller filtret, och ett filter behåller läget.** Växelns länkar
 * bär `filter`-proppen, och sidnumreringen bär båda — en `?page=2` som tyst
 * bytte fråga mitt i en listning är samma fel som `?page=2` utan period på
 * kostnadsfliken (issue 176).
 *
 * **`variants` och `inlineEnabled` ritar förhandsvisningen.** Rutnätet ritar
 * en miniatyr bara när `thumb`-varianten FINNS — en `<img>` mot en bilaga utan
 * derivat är 404 (issue 19a § Beslut 5) — och varken miniatyr eller filram
 * när filerna levereras som `attachment` (issue 61a § Beslut 2). Regeln bor i
 * `attachmentPreview()` i attachmentPresentation.js, samma funktion som
 * itemets bilagesektion använder: två grenar för samma sak glider isär, och
 * den ena hade ritat en trasig bild.
 *
 * **Ingen uppladdning sker här** (Beslut 5). *Lägg till dokument* väljer
 * itemet först och leder till itemets bilageflik `?tab=attachments`, där
 * uppladdningen redan finns (issue 60). Är `items` tom ritas ingen knapp: en
 * meny utan rader är en död yta (issue 174 § Beslut 5).
 *
 * **Rubrikraden bär ikon, rubrik och underrad; lagringsstapeln och knappen
 * står till höger om den** (M24, testarnas fynd 2026-10-03 ·
 * `docs/Design/dokument.png`). Underraden namnger containern, och ikonen är
 * dekor. Sorteringen bor i verktygsraden över listan — den är en fråga om
 * LISTAN och inte ett filter på den — och ett val ställer samma fråga som
 * sidnumreringen, utan `page`. *Senast öppnade* är ett kort med miniatyrer,
 * och en miniatyr ritas bara när `hasThumb` är sann (servern har prövat att
 * `thumb`-varianten finns); annars filikonen, aldrig en trasig bild.
 *
 * **Filterkolumnen och sökfältet (M24 · issue 217, Beslut 1–6).** Över `lg:` står
 * filterkolumnen till vänster (DocumentFilterColumn) och listan till höger;
 * under `lg:` är kolumnen gömd och filterbaren från issue 178 står kvar orörd
 * — mobilen behåller enkelvalet. Sökfältet ligger i verktygsraden över listan,
 * och Enter ställer samma fråga som ett kryss i kolumnen: en GET mot samma
 * rutt, utan `page`. Tomtexten räknar också `q` (issue 217 § Beslut 6), så en
 * träff säger `empty_filtered`.
 *
 * **Ingen sträng i JavaScript** (issue 52 · [[ADR-0013 Språk och i18n]]):
 * rubriken, flikens namn i webbläsaren, filterfältets ord, tabellrubrikerna,
 * tomtexterna, sidnumreringen och lagringsstapelns meningar kommer ur `t()`.
 * Typens tre ord är `item.attachment.kind.*` — samma ord som raden bär.
 *
 * **Radens *Move…*, *Copy…* och *Back to Inbox*** (issue 243 och 244 ·
 * [[ADR-0053 Flytt och kopiering]] § 2, [[ADR-0054 Inboxen]] § 6). Här möts
 * rader från flera items, så grinden är radens EGEN: `row.can.delete` ritar
 * flytten — den som får radera bilagans item får flytta den — och kopian står
 * på varje rad, för en kopia rör inte originalet och raden syns bara för den
 * som får läsa itemet. Flaggan kommer ur kontrollern
 * (`ContainerDocumentController`), och rutten prövar samma grind på nytt.
 * Flytten och kopian öppnar ItemTargetPicker (issue 242) och postar valet som
 * `target` i radens kropp; *Back to Inbox* går till användarens egen inbox och
 * har ingen kropp. Ett fel ritas på raden.
 */
const props = defineProps({
    /* Containern ur App\Http\Resources\ContainerResource. */
    container: { type: Object, required: true },
    /*
     * Sant när containern är en inbox (M27 · issue 244, ADR-0054 § 1).
     * *Back to Inbox* ritas då inte på raderna — bilagan är redan där, och
     * rutten hade svarat 422 `attachment.already_in_inbox`.
     */
    containerIsInbox: { type: Boolean, default: false },
    /*
     * `{ update }` — hjältens *Redigera container*. Flaggan är serverns svar
     * på samma policyfråga som rutten `PATCH /containers/{container}` ställer.
     */
    can: { type: Object, default: null },
    /*
     * En sida bilagor ur App\Actions\Attachment\ListContainerAttachments,
     * med sitt item bredvid resursens fält. Formen är Laravels paginator, så
     * `data`, `current_page` och `last_page` kommer rakt igenom.
     */
    attachments: { type: Object, required: true },
    /* Bilagans ULID → de derivatvarianter som finns. */
    variants: { type: Object, required: true },
    /* Sant när användarfiler levereras från en egen origin (issue 61a). */
    inlineEnabled: { type: Boolean, required: true },
    /*
     * Filtret så som servern tillämpade det:
     * `{q, kind, item, uploader, from, to, sort}` — listorna är `[]` när
     * gruppen inte filtrerar (issue 216 § Beslut 4).
     */
    filter: { type: Object, required: true },
    /*
     * Filterfältets alternativ: `{items, uploaders, kinds, total}`, allt ur
     * serverns omfång och med antalen (issue 216 § Beslut 6).
     */
    filterOptions: { type: Object, required: true },
    /* Användarens egna senast öppnade filer i containern, nyast först. */
    recentOpens: { type: Array, required: true },
    /* Lagringsstapelns konto och tal, eller null när inget konto finns. */
    storage: { type: Object, default: null },
    /* Items användaren får skapa en bilaga på, `{ulid, name}`. */
    items: { type: Array, required: true },
    /*
     * Plusknappens mål på den här fliken (M28 · testarnas fynd 2026-10-07):
     * en meny med samma items som `items` — ett val leder till itemets
     * bilageflik. Servern bygger den ur samma lista, så samma grindar gäller,
     * och en tom lista ger `null`: ingen meny, ingen knapp.
     */
    create: { type: Object, default: null },
});

const { t } = useTranslations();
const { eventDate } = useRelativeDate();
const page = usePage();

const base = computed(() => `/containers/${props.container.ulid}/documents`);

/*
 * Läget ur adressen. Bara `grid` är ett eget läge: listan är förvalet och
 * skrivs utan `view`, samma val som *Lista* i itemlistans växel och
 * översiktsfliken gör med `tab` (issue 100 och 102).
 */
const view = computed(() => {
    const url = page.url;
    const query = url.includes('?') ? url.slice(url.indexOf('?') + 1) : '';

    return new URLSearchParams(query).get('view') === 'grid' ? 'grid' : 'list';
});

/*
 * Sökfältet i verktygsraden (issue 217 § Beslut 4). Fältet speglas ur `filter.q` med
 * `watch` och inte bara vid montering: servern är den som avgör vad som
 * gäller, och efter en bakåtknapp eller en omladdning ska fältet visa den
 * fråga listan svarade på. Enter skickar `q` på samma sätt som ett kryss i
 * filterkolumnen (issue 217 § Beslut 3): en GET utan `page`, med läget kvar.
 */
const q = ref('');
const searchPending = ref(false);

watch(
    () => props.filter,
    (filter) => { q.value = filter.q ?? ''; },
    { immediate: true, deep: true },
);

function submitSearch() {
    const params = new URLSearchParams(
        documentFilterQuery({ ...props.filter, q: q.value }, view.value),
    );

    router.get(`${base.value}?${params.toString()}`, {}, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => { searchPending.value = true; },
        onFinish: () => { searchPending.value = false; },
    });
}

/*
 * Växelns två lägen, i ritad ordning: det FÖRSTA är förvalet. Varje läges
 * adress byggs ur samma funktion som resten av sidan, så länkarna bär exakt
 * det filter servern tillämpade.
 */
const views = computed(() => {
    const listQuery = documentFilterQuery(props.filter, null);
    const gridQuery = documentFilterQuery(props.filter, 'grid');

    return [
        {
            key: 'list',
            label: t('container.documents.view_list'),
            href: listQuery === '' ? base.value : `${base.value}?${listQuery}`,
        },
        {
            key: 'grid',
            label: t('container.documents.view_grid'),
            href: `${base.value}?${gridQuery}`,
        },
    ];
});

/*
 * Sidnumreringen. Adressen byggs här och inte ur paginatorns `next_page_url`:
 * den bär varken filtret eller läget, och en sida två hade då tyst bytt fråga
 * — samma skäl och samma grepp som pageUrl() i Costs.vue (issue 176).
 */
const pageUrl = (number) => {
    const params = new URLSearchParams(documentFilterQuery(props.filter, view.value));

    params.set('page', String(number));

    return `${base.value}?${params.toString()}`;
};

/*
 * Sorteringarna, i samma ordning som serverns `SORTS`. Listan är fast och
 * ingen propp: `sort` kan bara anta de fyra värdena, och en väljare byggd ur
 * svaret hade tappat ett läge ingen lista använt ännu — samma skäl som
 * `kinds` i filterfältet.
 */
const sorts = ['newest', 'oldest', 'name', 'size'];

/* Vänteläget för sorteringsvalet (issue 68a § Beslut 4 och 5). */
const sortPending = ref(false);

/*
 * Ett val i sorteringen (Beslut 6). Samma fråga som pageUrl() ställer, men
 * utan `page`: ett nytt sorteringsval är en ny ordning och börjar på sida ett
 * — en kvarvarande `?page=3` hade visat en tom sida för en lista som bara har
 * en. Läget bärs med, så en sortering i rutnätet inte tyst byter till listan,
 * och förvalet `newest` lämnas UTANFÖR strängen: en URL utan brus går att
 * läsa och dela.
 */
function changeSort(value) {
    // Det nya värdet läggs i `filter` innan strängen byggs: `newest` är
    // förvalet och faller då bort av sig själv, som i varje annan adress här.
    const params = new URLSearchParams(
        documentFilterQuery({ ...props.filter, sort: value }, view.value),
    );

    router.get(`${base.value}?${params.toString()}`, {}, {
        preserveScroll: true,
        onStart: () => { sortPending.value = true; },
        onFinish: () => { sortPending.value = false; },
    });
}

/* Itemets bilageflik (issue 60): där en bilaga laddas upp och tas bort. */
const itemUrl = (row) => `/containers/${props.container.ulid}/items/${row.item.ulid}?tab=attachments`;

/* Filen själv, genom appens leveransrutt (issue 19a): rutten prövar `view`. */
const fileUrl = (ulid) => `/files/${ulid}`;

/* Radens förhandsvisning, ur samma regel som itemets bilagesektion. */
const preview = (row) => attachmentPreview(row, props.variants, props.inlineEnabled);

const size = (row) => formatByteSize(row.byte_size);

/* Datumet genom datumregeln och aldrig genom en egen `Intl`-formatering. */
const opened = (row) => eventDate(row.created_at).text;
const openedAt = (open) => eventDate(open.opened_at).text;

/*
 * Tomtexten (issue 217 § Beslut 6). `hasFilter` räknar också `q`: en sökning utan träff är
 * ett svar om FRÅGAN och ska säga `empty_filtered`, inte `empty`.
 * Sorteringen räknas INTE — en ordning är ingen fråga, och `sort=oldest` på en
 * tom container ska inte påstå att ett filter gömmer innehållet.
 */
const hasFilter = computed(() =>
    (props.filter.q ?? '') !== ''
    || props.filter.kind.length > 0
    || props.filter.item.length > 0
    || props.filter.uploader.length > 0
    || (props.filter.from ?? '') !== ''
    || (props.filter.to ?? '') !== '',
);

/*
 * Flytten, kopian och *Back to Inbox* (issue 243 och 244). Raden bär sin ULID
 * och sitt verb, och `picked` håller dem kvar till POST:en är skickad —
 * `chooseTarget()` läser dem ur argumentet och aldrig ur en ref som
 * stängningen kan ha nollställt medan anropet är i luften. Verbet är det
 * bokstavliga suffixet i adressen — `move`, `copy` och `inbox` — och står i en
 * tabell, så att en fjärde handling inte kan råka bli `/move`.
 */
const ROW_ACTIONS = { move: '/move', copy: '/copy', inbox: '/inbox' };

const rowActionUrl = (row, action) => `/containers/${props.container.ulid}/items/${row.item.ulid}/attachments/${row.ulid}${ROW_ACTIONS[action]}`;

const pickerOpen = ref(false);
const pickerTrigger = ref(null);
const pickerHeading = ref('');
const picked = ref(null);

/* Radens vänteläge och radens fel — en rad i taget, som raderingen. */
const actionPending = ref(null);
const actionError = ref(null);

function openPicker(row, action, event) {
    picked.value = { row, action };
    pickerTrigger.value = event.currentTarget;
    pickerHeading.value = action === 'copy'
        ? t('item.attachment.copy_heading')
        : t('item.attachment.move_heading');
    pickerOpen.value = true;
}

/* Väljaren stängdes — ett val eller Esc, samma väg. */
function closePicker() {
    pickerOpen.value = false;
    picked.value = null;
}

/*
 * Valet ur väljaren. Målet postas som `target`; svaret är `back()` med
 * färska props, så listan speglar serverns svar och raden flyttar bort ur
 * vyn när den lämnat itemet. Ett kvotfel kommer som fältfel på `attachment`,
 * ett 403 fångas av `onHttpException` — `false` stänger av Inertias egen
 * felruta, så felet hamnar på raden och inte mitt över sidan.
 */
function chooseTarget(target) {
    const { row, action } = picked.value;

    router.post(rowActionUrl(row, action), { target: target.ulid }, {
        preserveScroll: true,
        onStart: () => {
            actionPending.value = row.ulid;
            actionError.value = null;
        },
        onFinish: () => { actionPending.value = null; },
        onError: (errors) => {
            actionError.value = { ulid: row.ulid, message: errors.attachment };
        },
        onHttpException: (response) => {
            actionError.value = {
                ulid: row.ulid,
                message: response.status === 403 ? t('error.403') : t('error.generic'),
            };

            return false;
        },
    });
}

/*
 * *Back to Inbox* (M27 · issue 244, [[ADR-0054 Inboxen]] § 6). Ägaren blir
 * personkontot och dess kvot prövas (ADR-0053 § 3 och 4), så ett kvotfel
 * kommer som ett fältfel på `attachment` och ritas på raden — samma form som
 * flyttens. Kroppen är tom: målet är användarens EGEN inbox och står inte i
 * någon adress.
 */
function toInbox(row) {
    router.post(rowActionUrl(row, 'inbox'), {}, {
        preserveScroll: true,
        onStart: () => {
            actionPending.value = row.ulid;
            actionError.value = null;
        },
        onFinish: () => { actionPending.value = null; },
        onError: (errors) => {
            actionError.value = { ulid: row.ulid, message: errors.attachment };
        },
        onHttpException: (response) => {
            actionError.value = {
                ulid: row.ulid,
                message: response.status === 403 ? t('error.403') : t('error.generic'),
            };

            return false;
        },
    });
}
</script>

<template>
    <ContainerLayout hero="compact" :container="container" :can="can" :create="create">
        <Head :title="t('container.documents.title')" />

        <!--
            Rubrikraden (Beslut 1): ikonen och rubriken till vänster, lagrings-
            stapeln (§ 15) och *Lägg till dokument* (Beslut 2) till höger.
            Underraden namnger containern, och ikonen är dekor — `aria-hidden`
            — för rubriken säger redan vad ytan är.
        -->
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    class="size-10 shrink-0 text-accent"
                    aria-hidden="true"
                >
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                    <path d="M14 2v6h6" />
                </svg>

                <div>
                    <h1 class="text-heading font-semibold">{{ t('container.documents.heading') }}</h1>
                    <p class="text-body text-ink-muted">
                        {{ t('container.documents.subheading', { container: container.name }) }}
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <StorageBar v-if="storage" class="w-full md:w-80" :storage="storage" />

                <!--
                    *Lägg till dokument* (Beslut 2). En `<details>` och inget
                    eget öppet-tillstånd i JavaScript — samma grepp som *Lägg
                    till kostnad* på kostnadsfliken — och listan innehåller de
                    items användaren får SKAPA på. Ett val leder till itemets
                    bilageflik, där uppladdningen skrivs. Panelen ligger
                    absolut och ritas därför inte i flödet.
                -->
                <details v-if="items.length > 0" class="relative">
                    <summary class="inline-flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-control bg-accent px-4 font-medium text-ink-on-accent">
                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.5"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            class="size-5 shrink-0"
                            aria-hidden="true"
                        >
                            <path d="M12 5v14M5 12h14" />
                        </svg>

                        {{ t('container.documents.add') }}

                        <svg
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.5"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            class="size-5 shrink-0"
                            aria-hidden="true"
                        >
                            <path d="m6 9 6 6 6-6" />
                        </svg>
                    </summary>

                    <div class="absolute right-0 z-10 mt-2 w-64 rounded-card border border-border bg-surface p-3 shadow-sm">
                        <p class="text-meta text-ink-subtle">{{ t('container.documents.add_choose_item') }}</p>

                        <ul class="mt-1 flex flex-col">
                            <li v-for="option in items" :key="option.ulid">
                                <Link
                                    :href="`/containers/${container.ulid}/items/${option.ulid}?tab=attachments`"
                                    class="flex min-h-11 items-center rounded-control px-2 text-body text-ink hover:bg-surface-sunken"
                                >
                                    {{ option.name }}
                                </Link>
                            </li>
                        </ul>
                    </div>
                </details>
            </div>
        </div>

        <!--
            Layouten (issue 217 § Beslut 5). Filterkolumnen till vänster och listan till
            höger, över `lg:`; under `lg:` är kolumnen gömd och filterbaren från
            issue 178 står kvar orörd — mobilen behåller enkelvalet. `items-start`
            så kolumnen inte tänjs ut över en lång lista, och `lg:mt-8` så
            kolumnens överkant möter kortet *Senast öppnade* i stället för
            rubrikraden — högerkolumnens första element bär sin egen `mt-8`.
        -->
        <div class="lg:grid lg:grid-cols-[16rem_1fr] lg:items-start lg:gap-6">
            <DocumentFilterColumn
                class="hidden lg:block lg:mt-8"
                :container-ulid="container.ulid"
                :filter="filter"
                :filter-options="filterOptions"
                :view="view"
            />

            <div>
            <!--
                *Senast öppnade* (Beslut 4): användarens EGNA öppningar, nyast
                först och högst fem, som ett kort med miniatyrer. Kortet ritas bara
                när det finns något att visa — en rubrik över ingenting är ett
                påstående om att något finns.

                Miniatyren ritas bara när servern säger att `thumb`-varianten finns
                (`hasThumb`): en `<img>` mot en bilaga utan derivat är 404 (issue
                19a § Beslut 5), och filerna levereras som `attachment` när
                `inlineEnabled` är falsk (issue 61a § Beslut 2). Annars filikonen,
                aldrig en trasig bild.

                Kortet är EN länk till itemets bilageflik — hela fliken leder dit,
                och den som kommer tillbaka till en fil vill se den bland sina
                andra. Itemnamnet står inte i kortet: fliken är redan itemets, och
                namnet upprepade bara var man är. Ingen *Visa alla* — någon sådan
                vy finns inte.
            -->
            <UiCard v-if="recentOpens.length > 0" class="mt-8">
                <template #heading>{{ t('container.documents.recent') }}</template>

                <ul class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-5">
                    <li v-for="open in recentOpens" :key="open.ulid">
                        <Link
                            :href="`/containers/${container.ulid}/items/${open.item.ulid}?tab=attachments`"
                            class="flex min-h-11 flex-col gap-2"
                        >
                            <img
                                v-if="open.hasThumb && inlineEnabled"
                                :src="`/files/${open.ulid}?variant=thumb`"
                                :alt="open.filename"
                                class="h-24 w-full rounded object-cover"
                            >

                            <span
                                v-else
                                role="img"
                                :aria-label="t('item.attachment.file_icon')"
                                class="flex h-24 w-full items-center justify-center rounded bg-surface-sunken text-ink-subtle"
                            >
                                <svg
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.5"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    class="size-8"
                                    aria-hidden="true"
                                >
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                    <path d="M14 2v6h6" />
                                </svg>
                            </span>

                            <span class="truncate font-medium text-ink">{{ open.filename }}</span>
                            <time :datetime="open.opened_at" class="text-meta text-ink-subtle">
                                {{ t('container.documents.opened', { date: openedAt(open) }) }}
                            </time>
                        </Link>
                    </li>
                </ul>
            </UiCard>

            <!--
                Filterfältet (§ 12). Det står mellan kortet och verktygsraden, och
                filtret kommer ur `filter`-proppen — serverns läsning — så vyn
                håller ingenting i minnet och en omladdning landar i samma
                träfflista.

                Under `lg:` (issue 217 § Beslut 5). Över `lg:` tar filterkolumnen över, och
                den här raden är gömd — mobilen behåller sitt enkelval, och två
                filterytor för samma fråga hade glidit isär.
            -->
            <div class="lg:hidden">
                <DocumentFilterBar
                    :container-ulid="container.ulid"
                    :items="filterOptions.items"
                    :uploaders="filterOptions.uploaders"
                    :filter="filter"
                />
            </div>

            <!--
                Verktygsraden (issue 217 § Beslut 4 och 6): sökfältet till vänster,
                sorteringen och lägesväxeln till höger. Sökningen är ett `q` som
                varje annat filter, och Enter ställer samma fråga som ett kryss i
                kolumnen — en GET utan `page`. Sorteringen är en fråga om LISTAN
                och inte ett filter på den, och står därför här i stället för i
                filterfältet. Läget kommer ur adressen genom `view` — servern
                läser den inte (Beslut 2 i issue 178).
            -->
            <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
                <form role="search" class="w-full md:w-80" @submit.prevent="submitSearch">
                    <label for="document-search" class="sr-only">
                        {{ t('container.documents.search_label') }}
                    </label>

                    <UiInput
                        id="document-search"
                        v-model="q"
                        type="search"
                        :disabled="searchPending"
                        :placeholder="t('container.documents.search_placeholder')"
                        class="w-full"
                    />
                </form>

                <div class="flex flex-wrap items-center gap-3">
                    <label for="document-sort" class="sr-only">{{ t('container.documents.sort_label') }}</label>

                    <UiSelect
                        id="document-sort"
                        :model-value="filter.sort"
                        :disabled="sortPending"
                        @update:model-value="changeSort"
                    >
                        <option v-for="value in sorts" :key="value" :value="value">
                            {{ t(`container.documents.sort_${value}`) }}
                        </option>
                    </UiSelect>

                    <ItemViewSwitch
                        :views="views"
                        :current="view"
                        :label="t('container.documents.view_label')"
                    />
                </div>
            </div>

            <!-- Två tomma lägen, och de säger olika saker: ett filter som inte
                 matchar något är ett svar om FRÅGAN, en tom container ett svar om
                 innehållet. -->
            <p v-if="attachments.data.length === 0" class="mt-6 text-sm text-slate-600">
                {{ hasFilter ? t('container.documents.empty_filtered') : t('container.documents.empty') }}
            </p>

            <!--
                Listan (Beslut 7), inramad i ett kort. Kolumnrubrikerna är
                `<th scope="col">`, som i kostnadstabellen: en skärmläsare läser då
                cellen som "Item, Motor" i stället för att läsa tolv namn i rad.

                Ingen checkboxkolumn och ingen ⋯-meny: det finns inga massåtgärder,
                och raden har bara en åtgärd — nedladdningen, vars kolumnrubrik är
                `sr-only` därför att länkens eget ord redan säger vad den gör.
                Typen ritas som en neutral bricka; UiBadge har inga typfärger, och
                en egen färg per typ hade varit en femte färg vid sidan av tokens
                ([[ADR-0042 Designsystemet]]).
            -->
            <div v-else-if="view === 'list'" class="mt-4 overflow-hidden rounded-card border border-border bg-surface">
                <table class="w-full border-collapse text-left">
                    <thead>
                        <tr class="border-b border-border">
                            <th scope="col" class="py-2 pl-4 pr-4 text-meta font-medium text-ink-subtle">
                                {{ t('container.documents.filename') }}
                            </th>
                            <th scope="col" class="py-2 pr-4 text-meta font-medium text-ink-subtle">
                                {{ t('container.documents.type') }}
                            </th>
                            <th scope="col" class="py-2 pr-4 text-meta font-medium text-ink-subtle">
                                {{ t('container.documents.item') }}
                            </th>
                            <th scope="col" class="py-2 pr-4 text-meta font-medium text-ink-subtle">
                                {{ t('container.documents.date') }}
                            </th>
                            <th scope="col" class="py-2 pr-4 text-meta font-medium text-ink-subtle">
                                {{ t('container.documents.size') }}
                            </th>
                            <th scope="col" class="py-2 pr-4 text-meta font-medium text-ink-subtle">
                                <span class="sr-only">{{ t('container.documents.download') }}</span>
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-for="row in attachments.data" :key="row.ulid" class="border-b border-border last:border-b-0">
                            <td class="py-1 pl-4 pr-4">
                                <span class="flex items-center gap-2">
                                    <!-- Miniatyren bara när varianten finns; annars
                                         en liten filikon, aldrig en trasig bild. -->
                                    <img
                                        v-if="preview(row).display === 'thumb'"
                                        :src="preview(row).thumbnail"
                                        :alt="row.filename"
                                        class="h-8 w-8 shrink-0 rounded object-cover"
                                    >

                                    <span
                                        v-else
                                        role="img"
                                        :aria-label="t('item.attachment.file_icon')"
                                        class="flex size-8 shrink-0 items-center justify-center rounded bg-surface-sunken text-ink-subtle"
                                    >
                                        <svg
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="1.5"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            class="size-4"
                                            aria-hidden="true"
                                        >
                                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                            <path d="M14 2v6h6" />
                                        </svg>
                                    </span>

                                    <span class="font-medium text-ink">{{ row.filename }}</span>
                                </span>
                            </td>

                            <td class="py-1 pr-4">
                                <UiBadge>{{ t(`item.attachment.kind.${row.kind}`) }}</UiBadge>
                            </td>

                            <td class="py-1 pr-4">
                                <Link
                                    :href="itemUrl(row)"
                                    class="inline-flex min-h-11 items-center text-accent hover:underline"
                                >
                                    {{ row.item.name }}
                                </Link>
                            </td>

                            <td class="py-1 pr-4 text-ink-muted">
                                <time :datetime="row.created_at">{{ opened(row) }}</time>
                            </td>

                            <td class="py-1 pr-4 text-ink-muted">{{ size(row) }}</td>

                            <td class="py-1 pr-4">
                                <div class="flex flex-wrap items-center gap-3">
                                    <a
                                        :href="fileUrl(row.ulid)"
                                        class="inline-flex min-h-11 items-center text-accent hover:underline"
                                    >
                                        {{ t('container.documents.download') }}
                                    </a>

                                    <!-- *Move…* bakom radens `can.delete`,
                                         *Copy…* på varje rad (issue 243 ·
                                         [[ADR-0053 Flytt och kopiering]] § 2).
                                         Servern prövar samma grindar. -->
                                    <button
                                        v-if="row.can.delete"
                                        type="button"
                                        :disabled="actionPending === row.ulid"
                                        class="inline-flex min-h-11 items-center text-sm font-medium text-accent hover:underline"
                                        @click="openPicker(row, 'move', $event)"
                                    >
                                        {{ t('item.attachment.move') }}
                                    </button>

                                    <!-- *Back to Inbox* (M27 · issue 244,
                                         ADR-0054 § 6): bakom samma
                                         `row.can.delete` som flytten intill.
                                         Handlingen går alltid till den EGNA
                                         inboxen, och servern prövar samma
                                         grind på nytt. Raden ritas inte när
                                         containern REDAN är en inbox —
                                         bilagan är redan där. -->
                                    <button
                                        v-if="row.can.delete && !containerIsInbox"
                                        type="button"
                                        :disabled="actionPending === row.ulid"
                                        class="inline-flex min-h-11 items-center text-sm font-medium text-accent hover:underline"
                                        @click="toInbox(row)"
                                    >
                                        {{ t('todo.back_to_inbox') }}
                                    </button>

                                    <button
                                        type="button"
                                        :disabled="actionPending === row.ulid"
                                        class="inline-flex min-h-11 items-center text-sm font-medium text-accent hover:underline"
                                        @click="openPicker(row, 'copy', $event)"
                                    >
                                        {{ t('item.attachment.copy') }}
                                    </button>
                                </div>

                                <!-- Radens fel: kvotfelet eller 403:an, ritat
                                     under raden det gäller. -->
                                <p
                                    v-if="actionError && actionError.ulid === row.ulid"
                                    role="alert"
                                    class="text-sm text-danger"
                                >
                                    {{ actionError.message }}
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!--
                Rutnätet (Beslut 2). Samma rader, ritade som kort: miniatyren när
                den finns, filnamnet, itemet och storleken. Kortet är EN länk till
                itemets bilageflik — filen nås genom nedladdningslänken i listan,
                och ett kort med tre länkar är tre träffytor i en ruta.
            -->
            <ul
                v-else
                class="mt-4 flex flex-col gap-4 md:grid md:grid-cols-3 lg:grid-cols-4"
            >
                <li
                    v-for="row in attachments.data"
                    :key="row.ulid"
                    class="flex flex-col gap-2 rounded-card border border-border bg-surface p-3"
                >
                    <img
                        v-if="preview(row).display === 'thumb'"
                        :src="preview(row).thumbnail"
                        :alt="row.filename"
                        class="h-32 w-full rounded object-cover"
                    >
                    <span
                        v-else
                        role="img"
                        :aria-label="t(`item.attachment.kind.${row.kind}`)"
                        class="flex h-32 w-full items-center justify-center rounded bg-surface-sunken text-ink-subtle"
                    >
                        {{ t(`item.attachment.kind.${row.kind}`) }}
                    </span>

                    <Link
                        :href="itemUrl(row)"
                        class="inline-flex min-h-11 items-center font-medium text-accent hover:underline"
                    >
                        {{ row.filename }}
                    </Link>

                    <span class="text-meta text-ink-muted">{{ row.item.name }}</span>
                    <time :datetime="row.created_at" class="text-meta text-ink-subtle">{{ opened(row) }}</time>
                    <span class="text-meta text-ink-subtle">{{ size(row) }}</span>
                </li>
            </ul>

            <!-- Sidnumreringen. Länkarna bär filtret och läget — se pageUrl(). -->
            <nav v-if="attachments.last_page > 1" class="mt-6 flex items-center gap-4">
                <Link
                    v-if="attachments.current_page > 1"
                    :href="pageUrl(attachments.current_page - 1)"
                    class="inline-flex min-h-11 items-center text-blue-700 hover:underline"
                >
                    {{ t('container.documents.previous') }}
                </Link>

                <span class="text-sm text-slate-600">
                    {{ t('container.documents.page', { page: attachments.current_page, last: attachments.last_page }) }}
                </span>

                <Link
                    v-if="attachments.current_page < attachments.last_page"
                    :href="pageUrl(attachments.current_page + 1)"
                    class="inline-flex min-h-11 items-center text-blue-700 hover:underline"
                >
                    {{ t('container.documents.next') }}
                </Link>
            </nav>

            </div>
        </div>

        <!--
            Målväljaren (issue 242 och 243). EN för hela listan: raden som
            öppnade den bär sin ULID och sitt verb, och `excludeItem` är
            radens item, så ett item aldrig erbjuds som mål för sin egen
            bilaga.
        -->
        <ItemTargetPicker
            :open="pickerOpen"
            :trigger="pickerTrigger"
            :exclude-item="picked?.row.item.ulid ?? null"
            :heading="pickerHeading"
            @choose="chooseTarget"
            @close="closePicker"
        />
    </ContainerLayout>
</template>
