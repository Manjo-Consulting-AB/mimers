<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Den globala dokumentlistan — `GET /documents`, se M28 (testarnas fynd
 * 2026-10-07) och App\Http\Controllers\DocumentController.
 *
 * **Sidan är containerns dokumentflik över hela kontot**, och den här filen är
 * den ENKLA formen: en lista över filnamnen med sin plats. Designen kommer i
 * #780 (issue 255), som ritar om sidan mot mockupen. Serverns halva — urvalet
 * över alla containrar och den egna inboxen, filtren, sorteringen och antalen
 * — är byggd och prövad i tests/Feature/Frontend/GlobalaDokumentTest.php.
 *
 * **Sidan räknar ingenting** ([[ADR-0024 Tunna controllers och actions]]).
 * Listan kommer färdig ur App\Actions\Attachment\ListAccessibleAttachments —
 * filtrerad, sorterad och paginerad — och containrarnas och typernas antal ur
 * samma action. Vyn ritar och länkar.
 *
 * **Filtret ritas ur `filter`-proppen och aldrig ur adressen.** Servern har
 * redan läst querysträngen en gång; en länk byggd på adressens råtext hade
 * kunnat bära med sig ett värde servern kastade. Sidnumreringen bygger därför
 * sin adress ur proppen, så en `?page=2` behåller frågan i stället för att
 * tyst byta den mitt i en listning.
 *
 * **Ingen sträng i filen** ([[ADR-0013 Språk och i18n]]): varje ord kommer ur
 * `lang/en/ui.php` genom `t()`.
 */
const props = defineProps({
    /* Rader i en sida om femtio, ur AttachmentResource med `item`, `container` och `in_inbox` bredvid. */
    attachments: { type: Object, required: true },
    /* Filtret servern tillämpade: `{ container, kind, sort }`. */
    filter: { type: Object, required: true },
    /* Containerväljaren, `{ulid, name, count}`, med inboxen först när den har filer. */
    containers: { type: Array, required: true },
    /* Antalet filer per typ: `{ image, document, other }`. */
    kinds: { type: Object, required: true },
});

const { t } = useTranslations();

/*
 * Adressen till en sida av listan, med filtret kvar. `page=1` utelämnas, så
 * den första sidan har samma adress som listan utan sidnummer.
 */
const pageUrl = (page) => {
    const query = new URLSearchParams();

    for (const value of props.filter.container) {
        query.append('container[]', value);
    }

    for (const value of props.filter.kind) {
        query.append('kind[]', value);
    }

    if (props.filter.sort !== 'newest') {
        query.set('sort', props.filter.sort);
    }

    if (page > 1) {
        query.set('page', String(page));
    }

    const text = query.toString();

    return text === '' ? '/documents' : `/documents?${text}`;
};

/*
 * Radens plats: itemets namn, eller *Inbox* för en fil i inboxen. Inboxens
 * item och container lämnar aldrig servern (se DocumentController::row()), så
 * ordet kommer ur `lang/` här och inte ur svaret.
 */
const place = (row) => (row.in_inbox ? t('documents.inbox') : row.item.name);
</script>

<template>
    <AppLayout>
        <Head :title="t('documents.title')" />

        <h1 class="text-2xl font-semibold">{{ t('documents.heading') }}</h1>

        <p v-if="attachments.data.length === 0" class="mt-2 text-sm text-slate-700">
            {{ t('documents.empty') }}
        </p>

        <ul v-else class="mt-6 divide-y divide-slate-200">
            <li v-for="row in attachments.data" :key="row.ulid" class="py-2">
                <span class="text-sm font-medium text-slate-900">{{ row.filename }}</span>
                <span class="ml-2 text-sm text-slate-600">{{ place(row) }}</span>
            </li>
        </ul>

        <nav v-if="attachments.last_page > 1" class="mt-6 flex gap-4 text-sm">
            <Link
                v-if="attachments.current_page > 1"
                :href="pageUrl(attachments.current_page - 1)"
                class="text-slate-700 underline"
            >
                {{ t('documents.previous') }}
            </Link>
            <Link
                v-if="attachments.current_page < attachments.last_page"
                :href="pageUrl(attachments.current_page + 1)"
                class="text-slate-700 underline"
            >
                {{ t('documents.next') }}
            </Link>
        </nav>
    </AppLayout>
</template>
