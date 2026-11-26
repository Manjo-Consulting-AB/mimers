<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import UiButton from './UiButton.vue';
import { useTranslations } from '../composables/useTranslations.js';
import { formatFieldValue, isChecked } from './fields/fieldPresentation.js';

/*
 * Versionen i en dialog, se issue 413 · [[ADR-0088 Versionshistorik för
 * items]] Riktning 5.
 *
 * **Fälten som de var, och de som skiljer sig märkta.** Varje rad är ett av
 * itemets fält — kolumnerna, kategorin, taggarna, omslaget, ikonen och
 * egenfälten — med versionens värde. `Changed` står på de rader som skiljer
 * sig från det nuvarande itemet; märket är ett ord och inte bara en nyans
 * (samma regel som i GenomgangTest § Beslut 7), så den som inte ser färgen
 * ändå läser att fältet skiljer sig.
 *
 * **Restore ritas bara med `canUpdate`.** Den som får se itemet men inte
 * ändra det ser versionen och fälten men ingen återställning; rutten prövar
 * samma grind på servern.
 *
 * **Fokusfällan är webbläsarens.** `<dialog>` öppnas med `showModal()`, Esc
 * fyrar `cancel`, och fokus lämnas tillbaka till raden som öppnade — ingen
 * egen tabbhantering och inget `aria-modal` (issue 68b § Beslut 2).
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    version: { type: Object, default: null },
    /* Det NUvarande itemet ur ItemResource, att jämföra med. */
    item: { type: Object, required: true },
    /* Kategoriernas namn, ULID → namn, med versionernas egna kategorier. */
    categories: { type: Object, required: true },
    /* Omslagsbildens ULID som itemet pekar på NU, eller null. */
    itemCover: { type: String, default: null },
    /* Får anroparen återställa? ItemPolicy::update, prövad på servern. */
    canUpdate: { type: Boolean, default: false },
    /* Sant medan anroparens återställning pågår. */
    pending: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'restore']);

const { t } = useTranslations();

const dialogElement = ref(null);

let opener = null;

watch(
    () => props.open,
    async (open) => {
        if (open) {
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

/* Medan en återställning pågår stängs dialogen inte. */
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
    opener?.focus?.();
}

function empty(value) {
    return value === null || value === '' ? t('item.version.empty') : value;
}

function categoryName(ulid) {
    if (ulid === null) {
        return t('item.form.category_none');
    }

    return props.categories[ulid] ?? ulid;
}

function tagsText(tags) {
    return tags.length === 0
        ? t('item.version.no_tags')
        : tags.map((tag) => tag.name).join(', ');
}

function tagKey(tags) {
    return tags.map((tag) => tag.ulid).sort().join(',');
}

function iconText(version) {
    const parts = [version.icon, version.icon_color].filter(Boolean);

    return parts.length === 0 ? t('item.version.icon_none') : parts.join(' · ');
}

function coverText(version) {
    return version.cover === null
        ? t('item.version.cover_none')
        : t('item.version.cover_set');
}

/*
 * Raderna: itemets egna fält först, sedan versionens egenfält. Etiketterna
 * är appens befintliga ord om samma sak — samma nycklar som formuläret och
 * översikten bär — så en version inte kan kalla ett fält något annat.
 */
const rows = computed(() => {
    const version = props.version;

    if (version === null) {
        return [];
    }

    const currentFields = new Map(
        (props.item.fields ?? []).map((field) => [field.id, field.value]),
    );

    const rows = [
        {
            key: 'name',
            label: t('item.form.name'),
            value: empty(version.name),
            changed: version.name !== props.item.name,
        },
        {
            key: 'description',
            label: t('item.form.description'),
            value: empty(version.description),
            changed: (version.description ?? '') !== (props.item.description ?? ''),
        },
        {
            key: 'notes',
            label: t('item.show.notes'),
            value: empty(version.notes),
            changed: (version.notes ?? '') !== (props.item.notes ?? ''),
        },
        {
            key: 'icon',
            label: t('item.version.icon'),
            value: iconText(version),
            changed: (version.icon ?? null) !== (props.item.icon ?? null)
                || (version.icon_color ?? null) !== (props.item.icon_color ?? null),
        },
        {
            key: 'category',
            label: t('item.form.category'),
            value: categoryName(version.category),
            changed: (version.category ?? null) !== (props.item.category ?? null),
        },
        {
            key: 'tags',
            label: t('item.form.tags'),
            value: tagsText(version.tags ?? []),
            changed: tagKey(version.tags ?? []) !== tagKey(props.item.tags ?? []),
        },
        {
            key: 'cover',
            label: t('item.version.cover'),
            value: coverText(version),
            changed: (version.cover ?? null) !== (props.itemCover ?? null),
        },
    ];

    for (const field of version.fields ?? []) {
        const current = currentFields.get(field.id) ?? null;

        rows.push({
            key: `field:${field.id}`,
            label: field.label,
            value: field.type === 'boolean'
                ? formatFieldValue(field, '', t)
                : empty(field.value),
            changed: field.type === 'boolean'
                ? isChecked(field.value) !== isChecked(current)
                : (field.value ?? null) !== current,
        });
    }

    return rows;
});
</script>

<template>
    <dialog
        ref="dialogElement"
        :aria-label="t('item.version.heading')"
        class="m-0 h-full max-h-none w-full max-w-none bg-transparent p-0 backdrop:bg-ink/50"
        @cancel="onEscape"
        @close="onClosed"
    >
        <div class="flex h-full w-full items-center justify-center p-4">
            <button
                type="button"
                tabindex="-1"
                class="absolute inset-0 min-h-11 cursor-default"
                :aria-label="t('confirm.cancel')"
                @click="close"
            ></button>

            <div class="relative flex max-h-full w-full max-w-lg flex-col gap-3 overflow-y-auto rounded-card border border-border bg-surface p-4">
                <h2 class="text-title font-semibold text-ink">{{ t('item.version.heading') }}</h2>

                <p class="text-meta text-ink-muted">
                    {{ t('item.version.replaced_by', { name: version?.replaced_by?.name ?? t('audit.fallback.user') }) }}
                </p>

                <dl class="flex flex-col gap-2">
                    <div
                        v-for="row in rows"
                        :key="row.key"
                        class="flex flex-wrap items-baseline gap-2 border-b border-border pb-2"
                    >
                        <dt class="text-meta font-medium text-ink-muted">{{ row.label }}</dt>
                        <dd class="min-w-0 flex-1 break-words text-body text-ink">{{ row.value }}</dd>
                        <span
                            v-if="row.changed"
                            class="rounded-control bg-warning-soft px-2 text-meta text-ink"
                        >
                            {{ t('item.version.changed') }}
                        </span>
                    </div>
                </dl>

                <div class="mt-2 flex flex-wrap justify-end gap-2">
                    <UiButton variant="secondary" :disabled="pending" @click="close">
                        {{ t('confirm.cancel') }}
                    </UiButton>

                    <UiButton v-if="canUpdate" :pending="pending" @click="emit('restore')">
                        {{ t('item.version.restore') }}
                    </UiButton>
                </div>
            </div>
        </div>
    </dialog>
</template>
