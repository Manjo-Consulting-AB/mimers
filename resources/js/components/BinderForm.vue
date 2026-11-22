<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * Beställningsformuläret för PDF-pärmen, se issue 446 · ADR-0091.
 *
 * Valen sparas med knappen och inte direkt: det är en beställning, inte en
 * inställning. Sektionerna är kryssrutor (allt utom kostnader ikryssat),
 * bilagorna ett radioval (länkar som förval) och en lista över items med
 * bilagor där varje bilaga kryssas för sig — inga ikryssade som förval, och
 * *Select all* per item. Formuläret ritas bara för en Pro-container; sidan
 * visar annars en rad om planen.
 *
 * Domänfelet `export.already_running` är sidans, inte formulärets: det ritas
 * ovanför som ZIP-beställningens, och knappen hålls stängd av `disabled`.
 */
const props = defineProps({
    /* Containerns ULID — rutten är `/containers/{ulid}/binders`. */
    containerUlid: { type: String, required: true },

    /* Items med bilagor: [{ ulid, name, attachments: [{ ulid, filename }] }]. */
    items: { type: Array, required: true },

    /*
     * Itemets ULID (issue 447): sätts formuläret för ett enda item, och
     * beställningen går till `/containers/{ulid}/items/{ulid}/binders`.
     */
    itemUlid: { type: String, default: null },

    /* Stänger knappen medan en annan export packas. */
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['finished', 'created']);

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

const SECTIONS = ['items', 'relations', 'schedules', 'history', 'costs'];

const form = useForm({
    sections: ['items', 'relations', 'schedules', 'history'],
    attachment_mode: 'link',
    attachments: [],
});

function allSelected(item) {
    return item.attachments.every((a) => form.attachments.includes(a.ulid));
}

function toggleAll(item, checked) {
    const own = item.attachments.map((a) => a.ulid);
    const rest = form.attachments.filter((ulid) => ! own.includes(ulid));

    form.attachments = checked ? [...rest, ...own] : rest;
}

/* Laravel nyckelar elementfel som `attachments.3`; ta med dem också. */
const attachmentsError = computed(() => Object.keys(form.errors).some((key) => key === 'attachments' || key.startsWith('attachments.')));

const hasItems = computed(() => props.items.length > 0);

function submit() {
    const url = props.itemUlid === null
        ? `/containers/${props.containerUlid}/binders`
        : `/containers/${props.containerUlid}/items/${props.itemUlid}/binders`;

    form.post(url, {
        preserveScroll: true,
        onSuccess: () => emit('created'),
        onError: focusFirstError,
        onFinish: () => emit('finished'),
    });
}
</script>

<template>
    <form class="mt-4 flex flex-col gap-6" @submit.prevent="submit">
        <fieldset>
            <legend class="text-sm font-semibold">{{ t('export.pdf_binder_sections') }}</legend>

            <div v-for="section in SECTIONS" :key="section" class="flex min-h-11 items-center gap-2 text-sm">
                <input :id="`binder-section-${section}`" v-model="form.sections" type="checkbox" :value="section" class="h-4 w-4" />
                <label :for="`binder-section-${section}`">{{ t(`export.section.${section}`) }}</label>
            </div>
        </fieldset>

        <fieldset>
            <legend class="text-sm font-semibold">{{ t('export.pdf_binder_attachments') }}</legend>

            <div class="flex min-h-11 items-center gap-2 text-sm">
                <input id="binder-mode-link" v-model="form.attachment_mode" type="radio" value="link" class="h-4 w-4" />
                <label for="binder-mode-link">{{ t('export.pdf_binder_mode_link') }}</label>
            </div>
            <div class="flex min-h-11 items-center gap-2 text-sm">
                <input id="binder-mode-merge" v-model="form.attachment_mode" type="radio" value="merge" class="h-4 w-4" />
                <label for="binder-mode-merge">{{ t('export.pdf_binder_mode_merge') }}</label>
            </div>

            <p v-if="! hasItems" class="mt-2 text-sm text-slate-600">{{ t('export.pdf_binder_none') }}</p>

            <div v-else class="mt-3 flex flex-col gap-4">
                <p class="text-sm text-slate-700">{{ t('export.pdf_binder_pick') }}</p>

                <fieldset v-for="item in items" :key="item.ulid" class="rounded border border-slate-300 px-3 py-1">
                    <legend class="px-1 text-sm font-medium">{{ item.name }}</legend>

                    <div class="flex min-h-11 items-center gap-2 text-sm text-slate-700">
                        <input
                            :id="`binder-all-${item.ulid}`"
                            type="checkbox"
                            class="h-4 w-4"
                            :checked="allSelected(item)"
                            :aria-label="t('export.pdf_binder_select_all_for', { item: item.name })"
                            @change="toggleAll(item, $event.target.checked)"
                        />
                        <label :for="`binder-all-${item.ulid}`">{{ t('export.pdf_binder_select_all') }}</label>
                    </div>

                    <div v-for="attachment in item.attachments" :key="attachment.ulid" class="flex min-h-11 items-center gap-2 text-sm">
                        <input :id="`binder-att-${attachment.ulid}`" v-model="form.attachments" type="checkbox" :value="attachment.ulid" class="h-4 w-4" />
                        <label :for="`binder-att-${attachment.ulid}`" class="break-all">{{ attachment.filename }}</label>
                    </div>
                </fieldset>
            </div>
        </fieldset>

        <p v-if="form.errors.sections" id="sections-error" tabindex="-1" role="alert" class="text-sm text-red-700">{{ t('export.pdf_binder_error_sections') }}</p>
        <p v-if="form.errors.attachment_mode" id="attachment_mode-error" tabindex="-1" role="alert" class="text-sm text-red-700">{{ t('export.pdf_binder_error_mode') }}</p>
        <p v-if="attachmentsError" id="attachments-error" tabindex="-1" role="alert" class="text-sm text-red-700">{{ t('export.pdf_binder_error_attachments') }}</p>

        <div>
            <button
                type="submit"
                :disabled="form.processing || disabled"
                class="inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
            >
                {{ form.processing ? t('common.pending.pdf_binder') : t('export.pdf_binder_create') }}
            </button>
        </div>
    </form>
</template>
