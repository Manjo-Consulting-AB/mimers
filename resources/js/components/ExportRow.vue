<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import UiConfirmDialog from './UiConfirmDialog.vue';
import { formatDate } from './accessPresentation.js';
import { formatByteSize } from './attachmentPresentation.js';
import { canDeleteExport, canDownloadExport, exportStatus, isExpiredExport, remainingLabel } from './exportPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * En rad i exportlistan, se issue 67c § Beslut 3, 4, 5 och 6.
 *
 * Raden bär exakt vad App\Http\Resources\ExportResource svarar med — samma
 * sex nycklar som `/api` — och formulerar dem: statusen i ord, när påsen
 * beställdes, hur stor den blev, hur länge den finns kvar och länken som
 * hämtar den. Ingen egen form av en exportrad, och ingen sträng i den här
 * filen (Beslut 8).
 *
 * **Statusen kommer ur `exportStatus()` och inte ur kolumnen** (Beslut 5): en
 * `ready` rad vars `expires_at` passerat är utgången redan innan gallringen
 * satt kolumnen till `expired`, och läsaren ska se samma sak i båda fallen.
 * Valet av nyckel bor i resources/js/components/exportPresentation.js, för
 * `t()` har ingen pluralisering och en mall går inte att pröva.
 *
 * **Storleken visas när den finns** (Beslut 6). `byte_size` är null tills
 * jobbet packat påsen, och raden utelämnas då helt — aldrig som noll byte.
 * Formateringen är `formatByteSize`, som speglar serverns
 * `Number::fileSize()` rad för rad (issue 60a § Beslut 6): samma fil visar
 * samma storlek här och i en kvotmening.
 *
 * **En misslyckad rad göms inte** (Beslut 3). Den ritas som misslyckad och
 * utan nedladdningslänk; vägen vidare är knappen ovanför listan, som är
 * öppen så fort ingen rad packas.
 *
 * **Nedladdningen är en vanlig `<a>`** till `/exports/{ulid}/download`, den
 * rutt som finns sedan 41b. Den klickas i en webbläsare och lämnar inga
 * JSON-fel — ingen Inertia-visit, ingen ny rutt och ingen kopia av
 * App\Http\Controllers\ExportDownloadController.
 */
const props = defineProps({
    /* En rad ur App\Http\Resources\ExportResource. */
    row: { type: Object, required: true },
    /* Containerns ULID, för raderingsrutten (issue 1564). */
    containerUlid: { type: String, required: true },
});

const { t } = useTranslations();
const page = usePage();

/*
 * Raderingen (issue 1564). Allt utom en rad som packas har knappen, och frågan
 * går genom UiConfirmDialog. En aktiv export tar filen med sig och kan inte
 * återställas, och det säger frågan. Någon papperskorg finns inte (ADR-0008
 * § Uppföljning 2026-09-26). En utgången eller misslyckad rad försvinner bara
 * ur listan.
 */
const confirming = ref(false);
const deleteTrigger = ref(null);
const deleting = ref(false);

const deletable = computed(() => canDeleteExport(props.row));

function openDelete(element) {
    deleteTrigger.value = element;
    confirming.value = true;
}

function destroy() {
    router.delete(`/containers/${props.containerUlid}/exports/${props.row.ulid}`, {
        preserveScroll: true,
        onStart: () => { deleting.value = true; },
        onFinish: () => {
            deleting.value = false;
            confirming.value = false;
        },
    });
}

/* Slaget som etikett; `binder` heter `pdf_binder` i katalogen (SprakTest). */
const kindKey = computed(() => (props.row.kind === 'binder' ? 'export.kind.pdf_binder' : 'export.kind.zip'));

/* En pärm om ett item bär itemets namn (issue 447): *PDF binder · {item}*. */
const kindLabel = computed(() => (props.row.kind === 'binder' && props.row.item
    ? t('export.kind.pdf_binder_item', { item: props.row.item.name })
    : t(kindKey.value)));

const status = computed(() => exportStatus(props.row));

/* Null när jobbet inte packat påsen än — då ritas ingen storleksrad alls. */
const size = computed(() => formatByteSize(props.row.byte_size));

const downloadable = computed(() => canDownloadExport(props.row));

/* Den återstående tiden gäller bara en färdig och ännu giltig rad. */
const showRemaining = computed(() => props.row.status === 'ready' && ! isExpiredExport(props.row));
</script>

<template>
    <li class="flex flex-col gap-1 rounded border px-4 py-2 text-sm" :class="status === 'failed' ? 'border-red-300 bg-red-50' : 'border-slate-300 bg-white'">
        <span class="text-xs font-semibold uppercase text-slate-600">{{ kindLabel }}</span>

        <span class="font-medium text-slate-800">{{ t(`export.status.${status}`) }}</span>

        <span class="text-xs text-slate-600">
            {{ t('export.created_at', { date: formatDate(row.created_at, page.props.locale) }) }}
        </span>

        <span v-if="size !== null" class="text-slate-700">{{ t('export.size', { size }) }}</span>

        <span v-if="showRemaining" class="text-xs text-slate-600">{{ remainingLabel(t, row.expires_at) }}</span>

        <a
            v-if="downloadable"
            :href="`/exports/${row.ulid}/download`"
            class="inline-flex min-h-11 items-center self-start font-medium text-blue-700 hover:underline"
        >
            {{ t('export.download') }}
        </a>

        <button
            v-if="deletable"
            type="button"
            :disabled="deleting"
            class="inline-flex min-h-11 items-center self-start rounded-control border border-danger px-3 text-body font-medium text-danger hover:bg-danger-soft disabled:opacity-50"
            @click="openDelete($event.currentTarget)"
        >
            {{ t('export.delete') }}
        </button>

        <UiConfirmDialog
            v-if="deletable"
            :open="confirming"
            :heading="t('export.delete_heading')"
            :body="downloadable ? t('export.delete_body_active') : t('export.delete_body_inactive')"
            :confirm-label="t('export.delete')"
            tone="danger"
            :pending="deleting"
            :trigger="deleteTrigger"
            @confirm="destroy"
            @cancel="confirming = false"
        />
    </li>
</template>
