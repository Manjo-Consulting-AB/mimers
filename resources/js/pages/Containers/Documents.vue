<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import ContainerLayout from '../../layouts/ContainerLayout.vue';
import DocumentFilterBar from '../../components/DocumentFilterBar.vue';
import ItemViewSwitch from '../../components/ItemViewSwitch.vue';
import StorageBar from '../../components/StorageBar.vue';
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

        <div class="flex flex-wrap items-start justify-between gap-4">
            <h1 class="text-heading font-semibold">{{ t('container.documents.heading') }}</h1>

            <!-- Lagringsstapeln (§ 15). Det konto en uppladdning i containern
                 debiteras, med sina tal ur servern. -->
            <StorageBar v-if="storage" class="w-full md:w-96" :storage="storage" />
        </div>

        <!--
            *Lägg till dokument* (Beslut 5). En `<details>` och inget eget
            öppet-tillstånd i JavaScript — samma grepp som *Avancerat* i
            delningsformuläret och *Lägg till kostnad* på kostnadsfliken — och
            listan innehåller de items användaren får SKAPA på. Ett val leder
            till itemets bilageflik, där uppladdningen skrivs.
        -->
        <details v-if="items.length > 0" class="mt-6">
            <summary class="inline-flex min-h-11 cursor-pointer items-center rounded bg-blue-700 px-4 font-medium text-white">
                {{ t('container.documents.add') }}
            </summary>

            <p class="mt-2 text-sm text-slate-700">{{ t('container.documents.add_choose_item') }}</p>

            <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1">
                <li v-for="option in items" :key="option.ulid">
                    <Link
                        :href="`/containers/${container.ulid}/items/${option.ulid}?tab=attachments`"
                        class="inline-flex min-h-11 items-center text-blue-700 hover:underline"
                    >
                        {{ option.name }}
                    </Link>
                </li>
            </ul>
        </details>

        <!--
            *Senast öppnade* (Beslut 3): användarens EGNA öppningar, nyast
            först och högst fem. Panelen ritas bara när det finns något att
            visa — en rubrik över ingenting är ett påstående om att något
            finns. Kortet länkar till itemets bilageflik och inte till filen:
            hela fliken leder dit, och den som kommer tillbaka till en fil vill
            se den bland sina andra.
        -->
        <section v-if="recentOpens.length > 0" class="mt-8">
            <h2 class="text-title font-semibold">{{ t('container.documents.recent') }}</h2>

            <ul class="mt-2 flex flex-col gap-2 md:grid md:grid-cols-3 md:gap-4 lg:grid-cols-5">
                <li
                    v-for="open in recentOpens"
                    :key="open.ulid"
                    class="flex min-h-11 flex-col gap-1 rounded-card border border-border bg-surface p-3"
                >
                    <Link
                        :href="`/containers/${container.ulid}/items/${open.item.ulid}?tab=attachments`"
                        class="inline-flex min-h-11 items-center font-medium text-blue-700 hover:underline"
                    >
                        {{ open.filename }}
                    </Link>

                    <span class="text-meta text-ink-muted">{{ open.item.name }}</span>
                    <time :datetime="open.opened_at" class="text-meta text-ink-subtle">{{ openedAt(open) }}</time>
                </li>
            </ul>
        </section>

        <!--
            Växeln (Beslut 2) och filterfältet (§ 12). Läget kommer ur
            adressen genom `view`, filtret ur `filter`-proppen — serverns
            läsning — så vyn håller ingenting i minnet och en omladdning
            landar i samma läge och samma träfflista.
        -->
        <ItemViewSwitch
            class="mt-8"
            :views="views"
            :current="view"
            :label="t('container.documents.view_label')"
        />

        <DocumentFilterBar
            :container-ulid="container.ulid"
            :items="filterOptions.items"
            :uploaders="filterOptions.uploaders"
            :filter="filter"
        />

        <!-- Två tomma lägen, och de säger olika saker: ett filter som inte
             matchar något är ett svar om FRÅGAN, en tom container ett svar om
             innehållet. -->
        <p v-if="attachments.data.length === 0" class="mt-6 text-sm text-slate-600">
            {{ hasFilter ? t('container.documents.empty_filtered') : t('container.documents.empty') }}
        </p>

        <!--
            Listan. Kolumnrubrikerna är `<th scope="col">`, som i
            kostnadstabellen: en skärmläsare läser då cellen som "Item, Motor"
            i stället för att läsa tolv namn i rad.
        -->
        <table v-else-if="view === 'list'" class="mt-4 w-full border-collapse text-left">
            <thead>
                <tr class="border-b border-border">
                    <th scope="col" class="py-2 pr-4 text-meta font-medium text-ink-subtle">
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
                    <th scope="col" class="py-2 text-meta font-medium text-ink-subtle">
                        {{ t('container.documents.download') }}
                    </th>
                </tr>
            </thead>

            <tbody>
                <tr v-for="row in attachments.data" :key="row.ulid" class="border-b border-border">
                    <td class="py-1 pr-4">
                        <span class="flex items-center gap-2">
                            <!-- Miniatyren bara när varianten finns; annars
                                 ingen bild alls, aldrig en trasig sådan. -->
                            <img
                                v-if="preview(row).display === 'thumb'"
                                :src="preview(row).thumbnail"
                                :alt="row.filename"
                                class="h-8 w-8 shrink-0 rounded object-cover"
                            >

                            <span class="font-medium text-ink">{{ row.filename }}</span>
                        </span>
                    </td>

                    <td class="py-1 pr-4 text-ink-muted">
                        {{ t(`item.attachment.kind.${row.kind}`) }}
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

                    <td class="py-1">
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
