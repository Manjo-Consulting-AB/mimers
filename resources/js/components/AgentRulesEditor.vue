<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import UiButton from './UiButton.vue';
import UiTextarea from './UiTextarea.vue';
import { useRelativeDate } from '../composables/useRelativeDate.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Rutan för agentregler, se [[ADR-0100 Agentregler]]. Samma ruta för
 * användarens fält (`/settings/agents`) och containerns (`/containers/{c}/
 * agents`): propparna säger vilken adress som tar emot texten och vilka ord
 * som hör till.
 *
 * Texten sparas med *Save*, inte vid varje tecken. *Use this version* fyller
 * bara textarean — den sparar ingenting, och *Save* skapar en ny revision.
 * Gränsen 2 000 tecken prövas på servern (fältet `rules`); räknaren är
 * presentation. Med `readonly` ritas texten som ren text, utan revisioner.
 */
const props = defineProps({
    /* Adressen som tar emot PUT. */
    action: { type: String, required: true },
    /* Prefix för id och nycklar, så två rutor på en sida aldrig delar id. */
    id: { type: String, required: true },
    heading: { type: String, required: true },
    hint: { type: String, required: true },
    /* Nuvarande text, eller null. */
    text: { type: String, default: null },
    /* `{revision, at, author, rules}`, nyast först. */
    revisions: { type: Array, default: () => [] },
    readonly: { type: Boolean, default: false },
});

const MAX = 2000;

const { t } = useTranslations();
const { eventDate } = useRelativeDate();

const form = useForm({ rules: props.text ?? '' });

const count = computed(() => [...(form.rules ?? '')].length);

function submit() {
    form.put(props.action, { preserveScroll: true });
}

function useVersion(revision) {
    form.rules = revision.rules ?? '';
}
</script>

<template>
    <section class="mt-6 rounded border border-slate-300 p-4" :aria-labelledby="`${id}-heading`">
        <h3 :id="`${id}-heading`" class="text-lg font-semibold">{{ heading }}</h3>

        <template v-if="readonly">
            <p class="mt-1 text-sm text-slate-700">{{ hint }}</p>
            <p :id="`${id}-text`" class="mt-3 whitespace-pre-wrap rounded bg-slate-50 p-3 text-sm">{{ text }}</p>
        </template>

        <template v-else>
            <form class="mt-3 flex flex-col gap-3" @submit.prevent="submit">
                <FormField
                    v-slot="{ describedBy }"
                    :label="t('agents.rules.label')"
                    :id="`${id}-rules`"
                    :error="form.errors.rules"
                >
                    <UiTextarea :id="`${id}-rules`" v-model="form.rules" :described-by="describedBy" :rows="6" />
                </FormField>

                <div class="flex flex-wrap items-center gap-3">
                    <UiButton type="submit" :pending="form.processing">
                        {{ form.processing ? t('common.pending.default') : t('agents.rules.save') }}
                    </UiButton>
                    <span class="text-sm text-slate-600" :class="{ 'text-danger': count > MAX }">
                        {{ t('agents.rules.counter', { count, max: MAX }) }}
                    </span>
                    <span v-if="form.recentlySuccessful" class="text-sm text-slate-600" role="status">
                        {{ t('agents.rules.saved') }}
                    </span>
                </div>
            </form>

            <p class="mt-3 text-sm text-slate-700">{{ hint }}</p>

            <details v-if="revisions.length > 0" class="mt-4">
                <summary class="inline-flex min-h-11 cursor-pointer items-center text-sm font-medium">
                    {{ t('agents.rules.versions', { count: revisions.length }) }}
                </summary>
                <ul class="mt-2 flex flex-col gap-3">
                    <li v-for="revision in revisions" :key="revision.revision" class="rounded border border-slate-200 p-3 text-sm">
                        <p class="text-slate-700">
                            <span class="font-medium">{{ t('agents.rules.version', { revision: revision.revision }) }}</span>
                            · {{ eventDate(revision.at).text }}
                            · {{ revision.author ?? t('agents.rules.deleted_user') }}
                        </p>
                        <p class="mt-1 whitespace-pre-wrap">{{ revision.rules ?? t('agents.rules.empty_version') }}</p>
                        <UiButton variant="secondary" size="compact" class="mt-2" @click="useVersion(revision)">
                            {{ t('agents.rules.use_version') }}
                        </UiButton>
                    </li>
                </ul>
            </details>
        </template>
    </section>
</template>
