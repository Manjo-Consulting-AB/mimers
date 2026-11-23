<script setup>
import { Link } from '@inertiajs/vue3';
import { formatByteSize } from './attachmentPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Uppdelningen av kontots lagringsutrymme, se issue 459 · M79. Den ritas av
 * både kvotpanelen i toppraden (StorageQuotaPanel.vue) och sidan
 * /settings/storage, så de två visar samma fyra delar: *Files* och *Text*,
 * de största containrarna, de största itemen och de största filerna.
 *
 * Ingenting räknas här. Bytena kommer ur App\Actions\Storage\
 * ReadStorageBreakdown och formateras med `formatByteSize()`; länkarna går
 * till containern, itemet och — för en fil — itemet den hör till.
 *
 * Ingen sträng står i den här filen: allt kommer ur `quota.*`.
 */
const props = defineProps({
    /* `{fileBytes, textBytes, containers, items, files}`, se ReadStorageBreakdown. */
    breakdown: { type: Object, required: true },
});

const { t } = useTranslations();

function itemHref(container, item) {
    return container === null ? null : `/containers/${container.ulid}/items/${item.ulid}`;
}

function fileHref(file) {
    if (file.container === null) {
        return null;
    }

    return file.item === null
        ? `/containers/${file.container.ulid}`
        : `/containers/${file.container.ulid}/items/${file.item.ulid}`;
}
</script>

<template>
    <div class="flex flex-col gap-4 text-meta text-ink">
        <dl class="flex flex-wrap gap-x-6 gap-y-1">
            <div class="flex gap-2">
                <dt class="text-ink-muted">{{ t('quota.files') }}</dt>
                <dd class="font-medium">{{ formatByteSize(props.breakdown.fileBytes) }}</dd>
            </div>
            <div class="flex gap-2">
                <dt class="text-ink-muted">{{ t('quota.text') }}</dt>
                <dd class="font-medium">{{ formatByteSize(props.breakdown.textBytes) }}</dd>
            </div>
        </dl>

        <section>
            <h4 class="font-semibold">{{ t('quota.largest_containers') }}</h4>
            <p v-if="props.breakdown.containers.length === 0" class="text-ink-muted">{{ t('quota.none') }}</p>
            <ul v-else class="mt-1 flex flex-col">
                <li v-for="container in props.breakdown.containers" :key="container.ulid">
                    <Link
                        :href="`/containers/${container.ulid}`"
                        class="flex min-h-11 items-center justify-between gap-3 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-focus"
                    >
                        <span class="min-w-0 truncate">{{ container.name }}</span>
                        <span class="shrink-0 text-ink-muted">{{ formatByteSize(container.bytes) }}</span>
                    </Link>
                </li>
            </ul>
        </section>

        <section>
            <h4 class="font-semibold">{{ t('quota.largest_items') }}</h4>
            <p v-if="props.breakdown.items.length === 0" class="text-ink-muted">{{ t('quota.none') }}</p>
            <ul v-else class="mt-1 flex flex-col">
                <li v-for="item in props.breakdown.items" :key="item.ulid">
                    <component
                        :is="itemHref(item.container, item) === null ? 'div' : Link"
                        :href="itemHref(item.container, item) ?? undefined"
                        class="flex min-h-11 items-center justify-between gap-3 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-focus"
                    >
                        <span class="min-w-0 truncate">
                            {{ item.name }}
                            <span v-if="item.container" class="text-ink-muted">{{ t('quota.item_in', { container: item.container.name }) }}</span>
                        </span>
                        <span class="shrink-0 text-ink-muted">{{ formatByteSize(item.bytes) }}</span>
                    </component>
                </li>
            </ul>
        </section>

        <section>
            <h4 class="font-semibold">{{ t('quota.largest_files') }}</h4>
            <p v-if="props.breakdown.files.length === 0" class="text-ink-muted">{{ t('quota.none') }}</p>
            <ul v-else class="mt-1 flex flex-col">
                <li v-for="file in props.breakdown.files" :key="file.ulid">
                    <component
                        :is="fileHref(file) === null ? 'div' : Link"
                        :href="fileHref(file) ?? undefined"
                        class="flex min-h-11 items-center justify-between gap-3 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-focus"
                    >
                        <span class="min-w-0 truncate">
                            {{ file.filename }}
                            <span v-if="file.inTrash" class="text-ink-muted">· {{ t('quota.in_trash') }}</span>
                        </span>
                        <span class="shrink-0 text-ink-muted">{{ formatByteSize(file.byte_size) }}</span>
                    </component>
                </li>
            </ul>
        </section>
    </div>
</template>
