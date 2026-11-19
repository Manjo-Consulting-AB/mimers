<script setup>
import { computed } from 'vue';
import { useTranslations } from '../../composables/useTranslations.js';
import { templatePreview } from './templatePreview.js';

/*
 * Vad en mall lägger till, och vad som hoppas över — se issue 422 och
 * [[ADR-0089 Egna fält på items]] § Riktning 6.
 *
 * Komponenten ritar en FÖRHANDSVISNING och skriver ingenting: både
 * *Start from* i resources/js/pages/Containers/Create.vue och *Apply
 * template* i Edit.vue använder den, så samma innehåll läses likadant på de
 * två ytorna. Räknandet bor i `templatePreview.js` och prövas i node.
 *
 * `existingLabels` är rubrikerna som redan syns på containerns items, ur
 * sidpropen — serverns svar är det som gäller, och ett fält vars rubrik
 * redan finns hoppas över. Texten säger det innan knappen trycks, inte
 * efteråt.
 */
const props = defineProps({
    /* Mallens innehåll: `{categories, fields}`. */
    content: { type: Object, default: null },
    /* Rubrikerna som redan finns på containerns items. */
    existingLabels: { type: Array, default: () => [] },
});

const { t } = useTranslations();

const preview = computed(() => templatePreview(props.content, props.existingLabels));
</script>

<template>
    <section class="mt-4 rounded border border-border bg-surface-sunken p-3">
        <h3 class="text-sm font-medium text-ink">{{ t('container.templates.preview_heading') }}</h3>

        <p class="mt-1 text-sm text-ink-muted">
            {{ t('container.templates.preview_categories', { count: preview.categoryCount }) }}
            ·
            {{ t('container.templates.preview_fields', { count: preview.addedFields.length }) }}
        </p>

        <ul v-if="preview.addedFields.length > 0" class="mt-2 flex flex-col gap-1 text-sm">
            <li v-for="field in preview.addedFields" :key="field.label" class="text-ink">
                {{ field.label }}
            </li>
        </ul>

        <p v-else class="mt-2 text-sm text-ink-muted">{{ t('container.templates.preview_no_fields') }}</p>

        <p v-if="preview.skippedFields.length > 0" class="mt-2 text-sm text-ink-muted">
            {{ t('container.templates.preview_skipped', { labels: preview.skippedFields.join(', ') }) }}
        </p>

        <p v-else class="mt-2 text-sm text-ink-muted">{{ t('container.templates.preview_none_skipped') }}</p>
    </section>
</template>
