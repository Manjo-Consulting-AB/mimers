<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
import DocumentFilterBar from '../../components/DocumentFilterBar.vue';
import ItemViewSwitch from '../../components/ItemViewSwitch.vue';
import StorageBar from '../../components/StorageBar.vue';
import UiBadge from '../../components/UiBadge.vue';
import UiCard from '../../components/UiCard.vue';
import UiSelect from '../../components/UiSelect.vue';
import { attachmentPreview, formatByteSize } from '../../components/attachmentPresentation.js';
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
 * **Ingen sträng i JavaScript** (issue 52 · [[ADR-0013 Språk och i18n]]):
 * rubriken, flikens namn i webbläsaren, filterfältets ord, tabellrubrikerna,
 * tomtexterna, sidnumreringen och lagringsstapelns meningar kommer ur `t()`.
 * Typens tre ord är `item.attachment.kind.*` — samma ord som raden bär.
 */
const props = defineProps({
    /* Containern ur App\Http\Resources\ContainerResource. */
    container: { type: Object, required: true },
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
    /* Filtret så som servern tillämpade det: `{kind, item, uploader, from, to, sort}`. */
    filter: { type: Object, required: true },
    /* Filterfältets alternativ: `{items, uploaders}` — allt ur serverns omfång. */
    filterOptions: { type: Object, required: true },
    /* Användarens egna senast öppnade filer i containern, nyast först. */
    recentOpens: { type: Array, required: true },
    /* Lagringsstapelns konto och tal, eller null när inget konto finns. */
    storage: { type: Object, default: null },
    /* Items användaren får skapa en bilaga på, `{ulid, name}`. */
    items: { type: Array, required: true },
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
 * Filtret som querysträng, ur `filter`-proppen och ingenting annat. Ett värde
 * som inte gäller — `null`, och `sort=newest` som är förvalet — lämnas
 * UTANFÖR: en URL utan brus går att läsa och dela.
 */
const filterQuery = computed(() => {
    const params = new URLSearchParams();

    for (const key of ['kind', 'item', 'uploader', 'from', 'to']) {
        if (props.filter[key] !== null && props.filter[key] !== undefined) {
            params.set(key, props.filter[key]);
        }
    }

    if (props.filter.sort !== 'newest') {
        params.set('sort', props.filter.sort);
    }

    return params.toString();
});

/*
 * Växelns två lägen, i ritad ordning: det FÖRSTA är förvalet. Adressen skrivs
 * ut per läge i listan nedan i stället för att byggas av en hjälpare — listan
 * ÄR förteckningen över lägena, och ett läge vars adress byggs någon annanstans
 * är ett läge man inte ser.
 */
const views = computed(() => {
    const query = filterQuery.value;
    const tail = query === '' ? '' : `&${query}`;

    return [
        {
            key: 'list',
            label: t('container.documents.view_list'),
            href: query === '' ? base.value : `${base.value}?${query}`,
        },
        {
            key: 'grid',
            label: t('container.documents.view_grid'),
            href: `${base.value}?view=grid${tail}`,
        },
    ];
});

/*
 * Sidnumreringen. Adressen byggs här och inte ur paginatorns `next_page_url`:
 * den bär varken filtret eller läget, och en sida två hade då tyst bytt fråga
 * — samma skäl och samma grepp som pageUrl() i Costs.vue (issue 176).
 */
const pageUrl = (number) => {
    const params = new URLSearchParams(filterQuery.value);

    if (view.value === 'grid') {
        params.set('view', 'grid');
    }

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
    const params = new URLSearchParams(filterQuery.value);

    if (view.value === 'grid') {
        params.set('view', 'grid');
    }

    if (value === 'newest') {
        params.delete('sort');
    } else {
        params.set('sort', value);
    }

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

const hasFilter = computed(() => filterQuery.value !== '');
</script>

<template>
    <ContainerLayout hero="compact" :container="container" :can="can">
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
        -->
        <DocumentFilterBar
            :container-ulid="container.ulid"
            :items="filterOptions.items"
            :uploaders="filterOptions.uploaders"
            :filter="filter"
        />

        <!--
            Verktygsraden (Beslut 6): sorteringen och lägesväxeln. Sorteringen
            är en fråga om LISTAN och inte ett filter på den, och står därför
            här i stället för i filterfältet. Ett val ställer samma fråga som
            sidnumreringen, utan `page`. Läget kommer ur adressen genom
            `view` — servern läser den inte (Beslut 2 i issue 178).
        -->
        <div class="mt-6 flex flex-wrap items-center justify-end gap-3">
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
                            <a
                                :href="fileUrl(row.ulid)"
                                class="inline-flex min-h-11 items-center text-accent hover:underline"
                            >
                                {{ t('container.documents.download') }}
                            </a>
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
    </ContainerLayout>
</template>
