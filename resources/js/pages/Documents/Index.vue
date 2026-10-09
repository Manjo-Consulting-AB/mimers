<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import DocumentScopeFilterColumn from '../../components/DocumentScopeFilterColumn.vue';
import UiBadge from '../../components/UiBadge.vue';
import UiSelect from '../../components/UiSelect.vue';
import { formatByteSize } from '../../components/attachmentPresentation.js';
import { useRelativeDate } from '../../composables/useRelativeDate.js';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Den globala dokumentlistan — `GET /documents`, se M28 (testarnas fynd
 * 2026-10-07) · issue 255 och App\Http\Controllers\DocumentController.
 *
 * **Sidan är containerns dokumentflik över hela kontot** ([[ADR-0050
 * Desktopdesignen]] § 12–15). Fliken visar en containers filer (issue 178);
 * här ser användaren samma sak i varje container hon når OCH i sin egen
 * inbox, på ett ställe. Formen är flikens — filterkolumnen till vänster
 * (DocumentScopeFilterColumn, Beslut 3), tabellen till höger — med EN
 * skillnad: en kolumn för containern (Beslut 2), eftersom dokumentet inte
 * längre visas inne i en container utan bredvid de andra.
 *
 * **Sidan räknar ingenting** ([[ADR-0024 Tunna controllers och actions]]).
 * Listan kommer färdig ur App\Actions\Attachment\ListAccessibleAttachments —
 * filtrerad, sorterad och paginerad — och containrarnas och typernas antal ur
 * samma action. Vyn ritar och länkar.
 *
 * **Filtret ritas ur `filter`-proppen och aldrig ur adressen.** Servern har
 * redan läst querysträngen en gång; en länk byggd på adressens råtext hade
 * kunnat bära med sig ett värde servern kastade. Sidnumreringen bygger
 * därför sin adress ur proppen, så en `?page=2` behåller frågan i stället för
 * att tyst byta den mitt i en listning.
 *
 * **En inboxfil har ingen plats att länka till.** Inboxens item och container
 * lämnar aldrig servern ([[ADR-0054 Inboxen]] § 1, DocumentController::row()),
 * så raden bär `in_inbox` och både item- och containerkolumnen säger *Inbox* —
 * med EN länk till `/inbox` i containerkolumnen. Itemkolumnen är aldrig en
 * länk, och för en inboxfil finns inget item att peka på.
 *
 * **Ingen handling på raden utöver filen** (Beslut 4): filnamnet är länken
 * som öppnar och laddar ner filen (`/files/{ulid}`), och flytt, kopiering och
 * radering finns på containerns flik och i inboxen. Ingen kryssruta och ingen
 * ⋯-meny.
 *
 * **Ingen sträng i filen** ([[ADR-0013 Språk och i18n]]): varje ord kommer ur
 * `lang/en/ui.php` genom `t()`.
 */
const props = defineProps({
    /* Rader i en sida om femtio, ur AttachmentResource med `item`, `container` och `in_inbox` bredvid. */
    attachments: { type: Object, required: true },
    /* Filtret servern tillämpade: `{ container, kind, sort }`. */
    filter: { type: Object, required: true },
    /* Containerväljaren, `{ulid, name?, count}`, med inboxen först när den har filer. */
    containers: { type: Array, required: true },
    /* Antalet filer per typ: `{ image, document, other }`. */
    kinds: { type: Object, required: true },
});

const { t } = useTranslations();
const { eventDate } = useRelativeDate();

/*
 * Sorteringarna, i samma ordning som serverns `SORTS`. Listan är fast och
 * ingen propp, samma skäl som på fliken: `sort` kan bara anta de fyra
 * värdena, och en väljare byggd ur svaret hade tappat ett läge ingen lista
 * använt ännu.
 */
const sorts = ['newest', 'oldest', 'name', 'size'];

/* Vänteläget för sorteringsvalet (issue 68a § Beslut 4 och 5). */
const sortPending = ref(false);

/*
 * Filtret som en querysträng, utan sidnummer. Formen är serverns `filter`-propp
 * och ingenting annat: `container[]` och `kind[]` som listor, `sort` med
 * förvalet `newest` utanför. Sidnumreringen och sorteringen bygger båda sin
 * adress här, så de två inte kan glida isär.
 */
function scopeQuery(filter) {
    const params = new URLSearchParams();

    for (const value of filter.container) {
        params.append('container[]', value);
    }

    for (const value of filter.kind) {
        params.append('kind[]', value);
    }

    if (filter.sort !== 'newest') {
        params.set('sort', filter.sort);
    }

    return params.toString();
}

/*
 * Adressen till en sida av listan, med filtret kvar. `page=1` utelämnas, så
 * den första sidan har samma adress som listan utan sidnummer.
 */
const pageUrl = (page) => {
    const query = new URLSearchParams(scopeQuery(props.filter));

    if (page > 1) {
        query.set('page', String(page));
    }

    const text = query.toString();

    return text === '' ? '/documents' : `/documents?${text}`;
};

/*
 * Ett val i sorteringen (Beslut 3): samma fråga som pageUrl() ställer, men
 * utan `page` — ett nytt sorteringsval är en ny ordning och börjar på sida
 * ett. `container` och `kind` bärs med, så en sortering inte tyst rensar
 * filtret.
 */
function changeSort(value) {
    const query = scopeQuery({ ...props.filter, sort: value });

    router.get(query === '' ? '/documents' : `/documents?${query}`, {}, {
        preserveScroll: true,
        onStart: () => { sortPending.value = true; },
        onFinish: () => { sortPending.value = false; },
    });
}

/* Filen själv, genom appens leveransrutt (issue 19a): den öppnar och laddar ner. */
const fileUrl = (ulid) => `/files/${ulid}`;

/* Containerns dokumentflik (issue 178): där filen ligger bland sina egna. */
const containerUrl = (row) => `/containers/${row.container.ulid}/documents`;

/* Datumet genom datumregeln och aldrig genom en egen `Intl`-formatering. */
const uploaded = (row) => eventDate(row.created_at).text;

const size = (row) => formatByteSize(row.byte_size);

/*
 * Tomtexten: ett filter som inte matchar något är ett svar om FRÅGAN, en tom
 * lista ett svar om innehållet. Sorteringen räknas inte — en ordning är ingen
 * fråga.
 */
const filtered = computed(() => props.filter.container.length > 0 || props.filter.kind.length > 0);
</script>

<template>
    <AppLayout>
        <Head :title="t('documents.title')" />

        <!--
            Rubrikraden bär sorteringen (issue 269 § Beslut 3). `sort` är en
            fråga om LISTAN och inte ett filter på den, och den stod förut i en
            egen rad ovanför tabellen — den sköt ned kortet så att det och
            filterkolumnen inte började på samma linje. Etiketten är `sr-only`:
            väljarens värde säger redan vad den gör.
        -->
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-heading font-semibold">{{ t('documents.heading') }}</h1>

            <div class="flex flex-wrap items-center gap-2">
                <label for="documents-sort" class="sr-only">{{ t('documents.sort_label') }}</label>

                <UiSelect
                    id="documents-sort"
                    :model-value="filter.sort"
                    :disabled="sortPending"
                    @update:model-value="changeSort"
                >
                    <option v-for="value in sorts" :key="value" :value="value">
                        {{ t(`documents.sort_${value}`) }}
                    </option>
                </UiSelect>
            </div>
        </div>

        <!--
            Layouten (Beslut 3): filterkolumnen till vänster och listan till
            höger över `lg:`; under `lg:` staplas de, så filtret finns kvar på
            en telefon också. `items-start` så kolumnen inte tänjs ut över en
            lång lista.
        -->
        <div class="mt-6 lg:grid lg:grid-cols-[16rem_1fr] lg:items-start lg:gap-6">
            <DocumentScopeFilterColumn
                :filter="filter"
                :containers="containers"
                :kinds="kinds"
            />

            <div class="mt-6 lg:mt-0">
                <!-- Två tomma lägen, och de säger olika saker: ett filter som
                     inte matchar något är ett svar om frågan, en tom lista ett
                     svar om innehållet. -->
                <p v-if="attachments.data.length === 0" class="mt-0 text-body text-ink-muted">
                    {{ filtered ? t('documents.empty_filtered') : t('documents.empty') }}
                </p>

                <!--
                    Listan (Beslut 2), inramad i ett kort. Kolumnrubrikerna är
                    `<th scope="col">`, som i kostnadstabellen: en skärmläsare
                    läser då cellen som "Container, Förrådet" i stället för att
                    läsa sex namn i rad. Sex kolumner — namn, typ, item,
                    container, uppladdad och storlek — och ingen
                    åtgärdskolumn: filnamnet ÄR länken (Beslut 4).
                -->
                <div v-else class="mt-0 overflow-hidden rounded-card border border-border bg-surface">
                    <table class="w-full border-collapse text-left">
                        <thead>
                            <tr class="border-b border-border">
                                <th scope="col" class="py-2 pl-4 pr-4 text-meta font-medium text-ink-subtle">
                                    {{ t('documents.filename') }}
                                </th>
                                <th scope="col" class="py-2 pr-4 text-meta font-medium text-ink-subtle">
                                    {{ t('documents.type') }}
                                </th>
                                <th scope="col" class="py-2 pr-4 text-meta font-medium text-ink-subtle">
                                    {{ t('documents.item') }}
                                </th>
                                <th scope="col" class="py-2 pr-4 text-meta font-medium text-ink-subtle">
                                    {{ t('documents.container') }}
                                </th>
                                <th scope="col" class="py-2 pr-4 text-meta font-medium text-ink-subtle">
                                    {{ t('documents.uploaded') }}
                                </th>
                                <th scope="col" class="py-2 pr-4 text-meta font-medium text-ink-subtle">
                                    {{ t('documents.size') }}
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr v-for="row in attachments.data" :key="row.ulid" class="border-b border-border last:border-b-0">
                                <td class="py-1 pl-4 pr-4">
                                    <span class="flex items-center gap-2">
                                        <!-- Filikonen är dekor; filnamnet säger
                                             redan vad raden är. -->
                                        <span
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

                                        <!-- Filnamnet är radens ENDA handling
                                             (Beslut 4): den öppnar och laddar
                                             ner filen. -->
                                        <Link
                                            :href="fileUrl(row.ulid)"
                                            class="inline-flex min-h-11 items-center font-medium text-accent hover:underline"
                                        >
                                            {{ row.filename }}
                                        </Link>
                                    </span>
                                </td>

                                <td class="py-1 pr-4">
                                    <UiBadge>{{ t(`item.attachment.kind.${row.kind}`) }}</UiBadge>
                                </td>

                                <!-- Platsen (Beslut 2). Itemkolumnen är ingen
                                     länk; för en inboxfil finns inget item, och
                                     då står *Inbox* i båda kolumnerna. -->
                                <td class="py-1 pr-4 text-ink-muted">
                                    {{ row.in_inbox ? t('documents.inbox') : row.item.name }}
                                </td>

                                <td class="py-1 pr-4">
                                    <!-- Inboxen: EN länk till `/inbox`, aldrig
                                         till det dolda itemet eller containern
                                         ([[ADR-0054 Inboxen]] § 1). -->
                                    <Link
                                        v-if="row.in_inbox"
                                        href="/inbox"
                                        class="inline-flex min-h-11 items-center text-accent hover:underline"
                                    >
                                        {{ t('documents.inbox') }}
                                    </Link>
                                    <Link
                                        v-else
                                        :href="containerUrl(row)"
                                        class="inline-flex min-h-11 items-center text-accent hover:underline"
                                    >
                                        {{ row.container.name }}
                                    </Link>
                                </td>

                                <td class="py-1 pr-4 text-ink-muted">
                                    <time :datetime="row.created_at">{{ uploaded(row) }}</time>
                                </td>

                                <td class="py-1 pr-4 text-ink-muted">{{ size(row) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Bläddringen (Beslut 2). Länkarna bär filtret — se pageUrl(). -->
                <nav v-if="attachments.last_page > 1" class="mt-6 flex items-center gap-4">
                    <Link
                        v-if="attachments.current_page > 1"
                        :href="pageUrl(attachments.current_page - 1)"
                        class="inline-flex min-h-11 items-center text-accent hover:underline"
                    >
                        {{ t('documents.previous') }}
                    </Link>

                    <span class="text-body text-ink-muted">
                        {{ t('documents.page', { page: attachments.current_page, last: attachments.last_page }) }}
                    </span>

                    <Link
                        v-if="attachments.current_page < attachments.last_page"
                        :href="pageUrl(attachments.current_page + 1)"
                        class="inline-flex min-h-11 items-center text-accent hover:underline"
                    >
                        {{ t('documents.next') }}
                    </Link>
                </nav>
            </div>
        </div>
    </AppLayout>
</template>
