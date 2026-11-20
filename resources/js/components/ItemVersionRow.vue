<script setup>
import { computed } from 'vue';
import { useTranslations } from '../composables/useTranslations.js';
import { useRelativeDate } from '../composables/useRelativeDate.js';

/*
 * En rad i *Earlier versions*, se issue 413 · [[ADR-0088 Versionshistorik
 * för items]] Riktning 5.
 *
 * **Raden är en knapp, för den öppnar versionen.** Formen följer listraden
 * (issue 99), men hela raden är träffytan: `min-h-11` på knappen i `<li>`:n,
 * så den som tabbar når versionen och den som klickar öppnar den. Det finns
 * ingen `<Link>` — versionen har ingen egen sida, bara dialogen.
 *
 * **Meningen är vem och när.** `item.version.replaced_by` bär namnet —
 * agentens när en agent ersatte versionen, annars användarens — och datumet
 * kommer ur `eventDate()`: appens relativa datum, aldrig en egen
 * formatering (issue 104). Saknas både agenten och användaren bär raden
 * `audit.fallback.user`, samma ersättare som historikens rader.
 */
const props = defineProps({
    /* En version ur ItemVersionResource: `{ulid, replaced_at, replaced_by, …}`. */
    version: { type: Object, required: true },
});

const emit = defineEmits(['open']);

const { t } = useTranslations();
const { eventDate } = useRelativeDate();

const replacedByName = computed(
    () => props.version.replaced_by?.name ?? t('audit.fallback.user'),
);

const date = computed(() => eventDate(props.version.replaced_at));
</script>

<template>
    <li>
        <button
            type="button"
            class="flex min-h-11 w-full flex-wrap items-center gap-x-3 gap-y-1 rounded-control py-2 text-left outline-none hover:bg-surface-sunken focus-visible:ring-2 focus-visible:ring-focus"
            @click="emit('open', { version, event: $event })"
        >
            <span class="flex min-w-0 flex-col">
                <span class="text-title text-ink">
                    {{ t('item.version.replaced_by', { name: replacedByName }) }}
                </span>
            </span>

            <span class="ml-auto text-meta text-ink-muted">
                <time :datetime="version.replaced_at">{{ date.text }}</time>
            </span>
        </button>
    </li>
</template>
