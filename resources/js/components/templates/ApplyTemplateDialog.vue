<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import UiButton from '../UiButton.vue';
import TemplatePreview from './TemplatePreview.vue';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * *Apply template*, med förhandsvisningen FÖRE skrivningen — se issue 422 och
 * [[ADR-0089 Egna fält på items]] § Riktning 6.
 *
 * **Förhandsvisningen är hela poängen med dialogen.** En mall lägger till
 * kategorier och fält, men ett fält vars rubrik redan finns hoppas över, och
 * användaren ska se vilka innan hon trycker *Apply*. TemplatePreview räknar
 * det ur mallens `content` och containerns befintliga rubriker; serverns svar
 * är det som gäller.
 *
 * Ett `<dialog>` med webbläsarens fokusfälla, samma form som UiConfirmDialog.
 * Anroparen äger `open` och skrivningen; komponenten äger valet och
 * förhandsvisningen.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    /* Elementet som öppnade dialogen, dit fokus går tillbaka. */
    trigger: { type: Object, default: null },
    /* Kontots mallar, ur sidpropen `templates`. */
    templates: { type: Array, required: true },
    /* Rubrikerna som redan syns på containerns items. */
    existingLabels: { type: Array, default: () => [] },
    pending: { type: Boolean, default: false },
    /* Serverns mening om tillämpningen, eller null. */
    error: { type: String, default: null },
});

const emit = defineEmits(['apply', 'close']);

const { t } = useTranslations();

const dialogElement = ref(null);
const chosen = ref('');

/* Elementet som hade fokus när dialogen öppnades, om `trigger` saknas. */
let opener = null;

watch(
    () => props.open,
    async (open) => {
        if (open) {
            chosen.value = '';
            opener = document.activeElement;

            await nextTick();

            dialogElement.value?.showModal();

            return;
        }

        if (dialogElement.value?.open) {
            dialogElement.value.close();
        }
    },
);

const selected = computed(
    () => props.templates.find((template) => template.ulid === chosen.value) ?? null,
);

function close() {
    if (props.pending) {
        return;
    }

    emit('close');
}

function onEscape(event) {
    event.preventDefault();

    close();
}

function onClosed() {
    (props.trigger ?? opener)?.focus?.();
}

function submit() {
    if (selected.value === null) {
        return;
    }

    emit('apply', selected.value.ulid);
}
</script>

<template>
    <dialog
        ref="dialogElement"
        :aria-label="t('container.templates.apply_dialog_heading')"
        class="m-0 h-full max-h-none w-full max-w-none bg-transparent p-0 backdrop:bg-ink/50"
        @cancel="onEscape"
        @close="onClosed"
    >
        <div class="flex h-full w-full items-center justify-center p-4">
            <button
                type="button"
                tabindex="-1"
                class="absolute inset-0 cursor-default"
                :aria-label="t('confirm.cancel')"
                @click="close"
            ></button>

            <form
                class="relative flex max-h-[90vh] w-full max-w-md flex-col gap-3 overflow-y-auto rounded-card border border-border bg-surface p-4"
                @submit.prevent="submit"
            >
                <h2 class="text-title font-semibold text-ink">
                    {{ t('container.templates.apply_dialog_heading') }}
                </h2>

                <p class="text-body text-ink-muted">{{ t('container.templates.apply_description') }}</p>

                <div class="flex flex-col gap-1">
                    <label for="apply-template" class="text-sm font-medium text-ink">
                        {{ t('container.templates.choose') }}
                    </label>

                    <select
                        id="apply-template"
                        v-model="chosen"
                        required
                        class="rounded border border-border bg-surface px-3 py-2"
                    >
                        <option value="" disabled>{{ t('container.templates.choose_empty') }}</option>
                        <option v-for="template in templates" :key="template.ulid" :value="template.ulid">
                            {{ template.name }}
                        </option>
                    </select>
                </div>

                <TemplatePreview
                    v-if="selected"
                    :content="selected.content"
                    :existing-labels="existingLabels"
                />

                <p v-if="error" role="alert" class="text-sm text-danger">{{ error }}</p>

                <div class="mt-2 flex flex-wrap justify-end gap-2">
                    <UiButton variant="secondary" :disabled="pending" @click="close">
                        {{ t('confirm.cancel') }}
                    </UiButton>

                    <UiButton type="submit" :pending="pending" :disabled="selected === null">
                        {{ pending ? t('common.pending.default') : t('container.templates.apply_confirm') }}
                    </UiButton>
                </div>
            </form>
        </div>
    </dialog>
</template>
