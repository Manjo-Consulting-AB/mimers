<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import BinderForm from './BinderForm.vue';
import UiButton from './UiButton.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Dialogen *Create PDF binder* på itemets sida, se issue 447 · ADR-0091.
 *
 * Samma val som containerns formulär — BinderForm återanvänds — men
 * bilagelistan är bara itemets. När beställningen gått igenom byts formuläret
 * mot *Your binder is being created* och en länk till exportsidan, där pärmen
 * står i listan och kan hämtas. Dialogen är en inbyggd `<dialog>` som
 * UiConfirmDialog; anroparen äger `open`.
 */
const props = defineProps({
    /* Sant medan dialogen är öppen. */
    open: { type: Boolean, default: false },
    /* Containerns ULID. */
    containerUlid: { type: String, required: true },
    /* Itemets ULID, namn och bilagor: { ulid, name, attachments }. */
    item: { type: Object, required: true },
    /* Elementet som öppnade dialogen, dit fokus går tillbaka. */
    trigger: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const { t } = useTranslations();
const page = usePage();

const dialogElement = ref(null);
const created = ref(false);

const items = computed(() => (props.item.attachments.length > 0 ? [props.item] : []));
const exportError = computed(() => page.props.errors?.export ?? null);

watch(
    () => props.open,
    async (open) => {
        if (open) {
            created.value = false;

            await nextTick();

            dialogElement.value?.showModal();

            return;
        }

        if (dialogElement.value?.open) {
            dialogElement.value.close();
        }
    },
);

function close() {
    emit('close');
}

function onEscape(event) {
    event.preventDefault();

    close();
}

function onClosed() {
    props.trigger?.focus?.();
}
</script>

<template>
    <dialog
        ref="dialogElement"
        :aria-label="t('export.pdf_binder_item_heading')"
        class="m-0 h-full max-h-none w-full max-w-none bg-transparent p-0 backdrop:bg-ink/50"
        @cancel="onEscape"
        @close="onClosed"
    >
        <div class="flex h-full w-full items-center justify-center p-4">
            <button type="button" tabindex="-1" class="absolute inset-0 cursor-default" :aria-label="t('export.pdf_binder_item_close')" @click="close"></button>

            <div class="relative flex max-h-full w-full max-w-lg flex-col gap-3 overflow-y-auto rounded-card border border-border bg-surface p-4">
                <h2 class="text-title font-semibold text-ink">{{ t('export.pdf_binder_item_heading') }}</h2>

                <template v-if="created">
                    <p role="status" class="text-body text-ink">{{ t('export.pdf_binder_item_created') }}</p>
                    <p class="text-body text-ink-muted">{{ t('export.pdf_binder_item_where') }}</p>

                    <div class="mt-2 flex flex-wrap justify-end gap-2">
                        <UiButton variant="secondary" @click="close">{{ t('export.pdf_binder_item_close') }}</UiButton>
                        <Link :href="`/containers/${containerUlid}/export`" class="inline-flex min-h-11 items-center underline">
                            {{ t('export.pdf_binder_item_link') }}
                        </Link>
                    </div>
                </template>

                <template v-else>
                    <p class="text-body text-ink-muted">{{ t('export.pdf_binder_item_intro') }}</p>

                    <p v-if="exportError" id="export-error" role="alert" class="rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        {{ exportError }}
                    </p>

                    <BinderForm :container-ulid="containerUlid" :item-ulid="item.ulid" :items="items" @created="created = true" />

                    <div class="flex justify-end">
                        <UiButton variant="secondary" @click="close">{{ t('export.pdf_binder_item_close') }}</UiButton>
                    </div>
                </template>
            </div>
        </div>
    </dialog>
</template>
