<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import UiButton from '../UiButton.vue';
import UiConfirmDialog from '../UiConfirmDialog.vue';
import { useTranslations } from '../../composables/useTranslations.js';
import { templateCounts } from './templatePreview.js';

/*
 * En rad i kontots mallista, se issue 422 och
 * [[ADR-0089 Egna fält på items]] § Riktning 6.
 *
 * **Namnet sparas med *Save*, raderingen frågar först.** Tonys regel
 * 2026-11-14 (#1245): ett val sparas direkt, text sparas med en knapp — namnet
 * är text, och en halvskriven rad ska inte bli mallens namn. Raderingen går
 * genom appens egen bekräftelsedialog, som containerns radering.
 *
 * **Borttagningen rör inga containrar.** En mall är en kopia och inte en
 * koppling (ADR-0089 § Riktning 6), och frågan säger det — annars tror
 * läsaren att containrarna försvinner med mallen.
 *
 * `count` räknar mallens innehåll: kategorier och fält, ur samma rena modul
 * som förhandsvisningen använder.
 */
const props = defineProps({
    /* Mallen ur App\Http\Resources\ContainerTemplateResource. */
    template: { type: Object, required: true },
    /* Ägarkontots namn, eller null när användaren bara har ett konto. */
    accountName: { type: String, default: null },
});

const { t } = useTranslations();

const form = useForm({ name: props.template.name });
const deleteForm = useForm({});

/* Serverns svar är sanningen: en omdöpning som landar ritar om raden. */
watch(
    () => props.template.name,
    (name) => {
        form.name = name;
    },
);

const hasAgentRules = computed(() => typeof props.template.content?.agent_rules === 'string' && props.template.content.agent_rules !== '');

const counts = computed(() => templateCounts(props.template.content));

const confirmOpen = ref(false);
const confirmTrigger = ref(null);

function rename() {
    form.patch(`/settings/templates/${props.template.ulid}`, {
        preserveScroll: true,
    });
}

function openConfirm() {
    confirmTrigger.value = document.activeElement;
    confirmOpen.value = true;
}

function destroy() {
    deleteForm.delete(`/settings/templates/${props.template.ulid}`, {
        preserveScroll: true,
        onFinish: () => {
            confirmOpen.value = false;
        },
    });
}
</script>

<template>
    <li class="flex flex-col gap-3 rounded border border-border bg-surface p-3">
        <form class="flex flex-col gap-1" @submit.prevent="rename">
            <label :for="`template-name-${template.ulid}`" class="text-sm font-medium text-ink">
                {{ t('settings.templates.name') }}
            </label>

            <input
                :id="`template-name-${template.ulid}`"
                v-model="form.name"
                type="text"
                name="name"
                required
                maxlength="255"
                class="rounded border border-border bg-surface px-3 py-2"
            >

            <p v-if="form.errors.name" role="alert" class="text-sm text-danger">
                {{ form.errors.name }}
            </p>

            <div class="mt-2 flex flex-wrap items-center gap-3">
                <UiButton type="submit" variant="secondary" :pending="form.processing">
                    {{ form.processing ? t('common.pending.default') : t('settings.templates.save') }}
                </UiButton>

                <button
                    type="button"
                    class="inline-flex min-h-11 items-center px-2 text-sm text-danger hover:underline"
                    @click="openConfirm"
                >
                    {{ t('settings.templates.destroy') }}
                </button>
            </div>
        </form>

        <p class="text-sm text-ink-muted">
            {{ t('settings.templates.count', { categories: counts.categories, fields: counts.fields }) }}
        </p>

        <p v-if="hasAgentRules" class="text-sm text-ink-muted">{{ t('settings.templates.has_agent_rules') }}</p>

        <p v-if="accountName" class="text-sm text-ink-muted">{{ accountName }}</p>

        <UiConfirmDialog
            :open="confirmOpen"
            :heading="t('settings.templates.destroy_heading', { name: template.name })"
            :body="t('settings.templates.destroy_body')"
            :confirm-label="t('settings.templates.destroy_confirm')"
            :pending="deleteForm.processing"
            :trigger="confirmTrigger"
            tone="danger"
            @confirm="destroy"
            @cancel="confirmOpen = false"
        />
    </li>
</template>
