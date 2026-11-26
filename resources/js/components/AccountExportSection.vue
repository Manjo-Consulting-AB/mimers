<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import UiConfirmDialog from './UiConfirmDialog.vue';
import { formatDate } from './accessPresentation.js';
import { formatByteSize } from './attachmentPresentation.js';
import { canDeleteExport, canDownloadExport, exportStatus, isExpiredExport, isOpenExport, remainingLabel } from './exportPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Kontoexporten på profilen, se issue 1702 och [[ADR-0098 Kontoexporten]].
 *
 * Avsnittet har tre delar: vad filen innehåller, knappen som beställer den och
 * listan över personens egna kontoexporter. Raderna har samma form som
 * containerns exportsida (ExportRow) och bär samma ord ur `export.*`, men
 * raderingen går mot `/settings/account-exports/{ulid}`.
 *
 * Väntan hanteras som på containerns exportsida: så länge en rad är `pending`
 * eller `running` laddas bara `accountExports`-propen om, med en timer som
 * armar om sig efter varje svar och slutar när raden är klar. Knappen är
 * stängd medan en rad packas — servern svarar annars
 * `account_export.in_progress` (409), som på webben är ett formulärfel på
 * nyckeln `export`.
 *
 * Layouten är en kolumn med radbrytande element, så den ryms på 375 px utan
 * vågrät rullning.
 */
const props = defineProps({
    /* Personens kontoexporter, nyast först, ur App\Http\Resources\ExportResource. */
    exports: { type: Array, required: true },
});

const { t } = useTranslations();
const page = usePage();

const POLL_INTERVAL_MS = 3000;

const form = useForm({});

const hasOpenRow = computed(() => props.exports.some(isOpenExport));

function request() {
    form.post('/settings/account-export', {
        preserveScroll: true,
        onFinish: schedulePoll,
    });
}

let timer = null;
let stopped = false;

function clearPoll() {
    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }
}

function schedulePoll() {
    clearPoll();

    if (stopped || ! hasOpenRow.value) {
        return;
    }

    timer = setTimeout(reloadExports, POLL_INTERVAL_MS);
}

function reloadExports() {
    timer = null;

    router.reload({ only: ['accountExports'], onFinish: schedulePoll });
}

onMounted(schedulePoll);

onUnmounted(() => {
    stopped = true;
    clearPoll();
});

/* Raderingen: en dialog för den rad som valts. */
const target = ref(null);
const deleteTrigger = ref(null);
const deleting = ref(false);

function openDelete(row, element) {
    deleteTrigger.value = element;
    target.value = row;
}

function destroy() {
    router.delete(`/settings/account-exports/${target.value.ulid}`, {
        preserveScroll: true,
        onStart: () => { deleting.value = true; },
        onFinish: () => {
            deleting.value = false;
            target.value = null;
        },
    });
}

const errorText = computed(() => page.props.errors?.export ?? null);
</script>

<template>
    <section class="mt-8 flex max-w-lg flex-col gap-4" aria-labelledby="account-export-heading">
        <h3 id="account-export-heading" class="text-lg font-semibold">{{ t('settings.profile.account_export.heading') }}</h3>

        <p class="text-sm text-slate-700">{{ t('settings.profile.account_export.intro') }}</p>

        <p
            v-if="errorText"
            role="alert"
            class="rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"
        >
            {{ errorText }}
        </p>

        <form class="flex flex-col gap-2" @submit.prevent="request">
            <button
                type="submit"
                :disabled="form.processing || hasOpenRow"
                class="self-start inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
            >
                {{ form.processing ? t('common.pending.export') : t('settings.profile.account_export.create') }}
            </button>

            <p class="text-sm text-slate-600">{{ t('settings.profile.account_export.retention') }}</p>
        </form>

        <div>
            <h4 class="text-base font-semibold">{{ t('settings.profile.account_export.list_heading') }}</h4>

            <p v-if="exports.length === 0" class="mt-2 text-sm text-slate-600">
                {{ t('settings.profile.account_export.empty') }}
            </p>

            <ul v-else class="mt-2 flex flex-col gap-2">
                <li
                    v-for="row in exports"
                    :key="row.ulid"
                    class="flex flex-col gap-1 rounded border px-4 py-2 text-sm"
                    :class="exportStatus(row) === 'failed' ? 'border-red-300 bg-red-50' : 'border-slate-300 bg-white'"
                >
                    <span class="text-xs font-semibold uppercase text-slate-600">{{ t('settings.profile.account_export.kind') }}</span>

                    <span class="font-medium text-slate-800">{{ t(`export.status.${exportStatus(row)}`) }}</span>

                    <span class="text-xs text-slate-600">
                        {{ t('export.created_at', { date: formatDate(row.created_at, page.props.locale) }) }}
                    </span>

                    <span v-if="formatByteSize(row.byte_size) !== null" class="text-slate-700">
                        {{ t('export.size', { size: formatByteSize(row.byte_size) }) }}
                    </span>

                    <span v-if="row.status === 'ready' && ! isExpiredExport(row)" class="text-xs text-slate-600">
                        {{ remainingLabel(t, row.expires_at) }}
                    </span>

                    <a
                        v-if="canDownloadExport(row)"
                        :href="`/exports/${row.ulid}/download`"
                        class="inline-flex min-h-11 items-center self-start font-medium text-blue-700 hover:underline"
                    >
                        {{ t('export.download') }}
                    </a>

                    <button
                        v-if="canDeleteExport(row)"
                        type="button"
                        :disabled="deleting"
                        class="inline-flex min-h-11 items-center self-start rounded-control border border-danger px-3 text-body font-medium text-danger hover:bg-danger-soft disabled:opacity-50"
                        @click="openDelete(row, $event.currentTarget)"
                    >
                        {{ t('export.delete') }}
                    </button>
                </li>
            </ul>
        </div>

        <UiConfirmDialog
            :open="target !== null"
            :heading="t('export.delete_heading')"
            :body="target && canDownloadExport(target) ? t('export.delete_body_active') : t('export.delete_body_inactive')"
            :confirm-label="t('export.delete')"
            tone="danger"
            :pending="deleting"
            :trigger="deleteTrigger"
            @confirm="destroy"
            @cancel="target = null"
        />
    </section>
</template>
