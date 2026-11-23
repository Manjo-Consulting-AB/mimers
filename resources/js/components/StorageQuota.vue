<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import StorageQuotaPanel from './StorageQuotaPanel.vue';
import UiIcon from './UiIcon.vue';
import { formatByteSize } from './attachmentPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Kvotmodulen i toppraden och längst ned i mobilmenyn, se issue 459 · M79.
 * En smal stapel, en rad text under den och en knapp med ett inringat *i*
 * som öppnar StorageQuotaPanel.
 *
 * **Talen är serverns** (`storageQuota`, App\Http\Middleware\
 * HandleInertiaRequests). Bytena formateras med `formatByteSize()` och
 * ingenting räknas: `percent` kommer färdigt. Det enda klienten gör med två
 * tal är att rita containerns segment i stapeln — en bredd, ingen siffra
 * någon läser. Stapeln är `aria-hidden`, som i StorageBar.vue: raden under
 * den säger kvoten en gång.
 *
 * **Kontexten kommer ur rutten**, inte ur sessionen. I containerkontexten
 * (`storageQuota.container`) står containerns andel i texten och ritas som
 * ett eget segment i en starkare ton i kontots stapel.
 *
 * **Färgen går mot varning vid 80 % och mot fel vid 100 %**, med tokens ur
 * designsystemet. Ett obegränsat tak (`limitBytes` är `null`) ger bara texten.
 *
 * **Panelen hämtar först när den öppnas** (`router.reload({ only:
 * ['storageBreakdown'] })`). Escape, ett klick utanför och ett klick på
 * knappen stänger den och fokus går tillbaka till knappen — NotificationBells
 * mönster, med hanteraren på rotens `@keydown`.
 */
const props = defineProps({
    /* `inline` ritar panelen i flödet, för mobilmenyn. */
    inline: { type: Boolean, default: false },
    /* Ett id per instans: modulen sitter i DOM:en två gånger. */
    idSuffix: { type: String, default: 'bar' },
});

const { t } = useTranslations();
const page = usePage();

const quota = computed(() => page.props.storageQuota);
const breakdown = computed(() => page.props.storageBreakdown);
const panelId = computed(() => `storage-quota-panel-${props.idSuffix}`);

const used = computed(() => formatByteSize(quota.value.usedBytes) ?? '');
const limit = computed(() => (quota.value.limitBytes === null ? null : formatByteSize(quota.value.limitBytes)));

const label = computed(() => {
    const unlimited = limit.value === null;

    if (quota.value.container !== null) {
        const container = formatByteSize(quota.value.container.bytes) ?? '';

        return unlimited
            ? t('quota.in_container_unlimited', { container, used: used.value })
            : t('quota.in_container', { container, used: used.value, limit: limit.value });
    }

    return unlimited
        ? t('quota.used_unlimited', { used: used.value })
        : t('quota.used_of', { used: used.value, limit: limit.value });
});

const tone = computed(() => {
    const percent = quota.value.percent ?? 0;

    if (percent >= 100) {
        return 'bg-danger';
    }

    return percent >= 80 ? 'bg-warning' : 'bg-accent';
});

/* Bredden på containerns segment, för ritningen och inget annat. */
const containerWidth = computed(() => {
    if (quota.value.container === null || ! quota.value.limitBytes) {
        return 0;
    }

    return Math.min(100, (quota.value.container.bytes / quota.value.limitBytes) * 100);
});

const isOpen = ref(false);
const root = ref(null);
const button = ref(null);

function toggle() {
    isOpen.value = ! isOpen.value;

    if (isOpen.value) {
        router.reload({ only: ['storageBreakdown'] });
    }
}

function close() {
    isOpen.value = false;
    button.value?.focus();
}

function onKeydown(event) {
    if (event.key !== 'Escape' || ! isOpen.value) {
        return;
    }

    close();
    event.preventDefault();
    event.stopPropagation();
}

function onDocumentClick(event) {
    if (isOpen.value && root.value && ! root.value.contains(event.target)) {
        isOpen.value = false;
    }
}

onMounted(() => document.addEventListener('click', onDocumentClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick));

/* Panelen stängs vid navigering. */
watch(() => page.url, () => {
    isOpen.value = false;
});
</script>

<template>
    <div ref="root" class="relative flex items-center gap-1" @keydown="onKeydown">
        <div class="flex w-full flex-col gap-1 md:w-auto">
            <div
                v-if="quota.limitBytes !== null"
                class="relative h-2 w-full overflow-hidden rounded-pill bg-surface-sunken md:w-24"
                aria-hidden="true"
            >
                <div
                    class="h-2 rounded-pill"
                    :class="[tone, quota.container !== null ? 'opacity-40' : '']"
                    :style="{ width: `${quota.percent}%` }"
                ></div>
                <div
                    v-if="quota.container !== null"
                    class="absolute left-0 top-0 h-2 rounded-pill"
                    :class="tone"
                    :style="{ width: `${containerWidth}%` }"
                ></div>
            </div>

            <p class="text-meta text-ink-muted">{{ label }}</p>
        </div>

        <button
            ref="button"
            type="button"
            class="inline-flex min-h-11 min-w-11 items-center justify-center rounded text-ink-muted outline-none focus-visible:ring-2 focus-visible:ring-focus"
            :aria-label="t('quota.details')"
            :aria-controls="panelId"
            :aria-expanded="isOpen"
            @click="toggle"
        >
            <UiIcon name="info" :size="20" class="h-5 w-5" />
        </button>

        <StorageQuotaPanel
            v-if="isOpen"
            :panel-id="panelId"
            :quota="quota"
            :breakdown="breakdown"
            :inline="props.inline"
        />
    </div>
</template>
