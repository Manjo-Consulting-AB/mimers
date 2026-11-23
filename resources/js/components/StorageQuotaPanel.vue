<script setup>
import { Link } from '@inertiajs/vue3';
import StorageBreakdown from './StorageBreakdown.vue';
import { formatByteSize } from './attachmentPresentation.js';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Panelen bakom det inringade *i* i kvotmodulen, se issue 459 · M79. Den är
 * en popover med NotificationBell-panelens beteende, men stängningen och
 * fokusåtergången ägs av StorageQuota.vue — samma uppdelning som mellan
 * NotificationBell och NotificationPanel.
 *
 * Fyra delar, i den ordningen: *Files* och *Text*, de största containrarna,
 * de största itemen och de största filerna (StorageBreakdown). Därefter
 * personens övriga konton, var sitt rad med *använt av tak*, och sist länken
 * *Manage storage*. Medan `storageBreakdown` laddas står en enkel laddningsrad.
 *
 * Ingenting räknas här och ingen sträng står i filen.
 */
const props = defineProps({
    panelId: { type: String, required: true },
    /* `storageQuota` ur de delade propsen. */
    quota: { type: Object, required: true },
    /* `storageBreakdown`, eller `undefined` medan den hämtas. */
    breakdown: { type: Object, default: undefined },
    /* Raden i mobilmenyn ritar panelen i flödet i stället för som popover. */
    inline: { type: Boolean, default: false },
});

const { t } = useTranslations();

function otherLabel(account) {
    return account.limitBytes === null
        ? t('quota.other_account_unlimited', { name: account.name, used: formatByteSize(account.usedBytes) })
        : t('quota.other_account', {
            name: account.name,
            used: formatByteSize(account.usedBytes),
            limit: formatByteSize(account.limitBytes),
        });
}
</script>

<template>
    <section
        :id="props.panelId"
        :aria-label="t('quota.panel_heading')"
        :class="props.inline
            ? 'mt-2 w-full rounded-card border border-border bg-surface p-4'
            : 'absolute right-0 z-10 mt-1 w-80 max-w-[calc(100vw-2rem)] rounded-card border border-border bg-surface p-4 shadow-lg'"
    >
        <p v-if="props.breakdown === undefined" class="text-meta text-ink-muted">
            {{ t('quota.loading') }}
        </p>

        <StorageBreakdown v-else :breakdown="props.breakdown" />

        <ul v-if="props.quota.otherAccounts.length > 0" class="mt-4 flex flex-col" :aria-label="t('quota.other_accounts')">
            <li v-for="account in props.quota.otherAccounts" :key="account.ulid">
                <Link
                    :href="`/settings/storage?account=${account.ulid}`"
                    class="flex min-h-11 items-center text-meta text-accent outline-none hover:underline focus-visible:ring-2 focus-visible:ring-focus"
                >
                    {{ otherLabel(account) }}
                </Link>
            </li>
        </ul>

        <div class="mt-2 flex justify-end">
            <Link
                :href="`/settings/storage?account=${props.quota.account.ulid}`"
                class="inline-flex min-h-11 items-center text-meta text-accent outline-none hover:underline focus-visible:ring-2 focus-visible:ring-focus"
            >
                {{ t('quota.manage') }}
            </Link>
        </div>
    </section>
</template>
