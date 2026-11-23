<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { onMounted } from 'vue';
import AppLayout from '../layouts/AppLayout.vue';
import { useTranslations } from '../composables/useTranslations.js';

defineOptions({ layout: AppLayout });

/*
 * Hjälpcentrets startsida, /help — M78 · issue 458, M80 · issue 461 och 462.
 *
 * Sidan är tills vidare en lista med kategorierna: titel, beskrivning och
 * antal frågor, med en länk till `/help/{kategori}`. 465 gör den till en
 * riktig startsida med kategorikort och populära artiklar.
 *
 * **De gamla ankarna överlever här.** Texterna har tidigare legat på en enda
 * sida, så `/help#how-do-i-share-a-container` och `/help#sharing` är adresser
 * som kan stå i ett bokmärke. Fragmentet når aldrig servern och kan därför
 * inte skickas vidare i en omdirigering — i stället skickar servern kartan
 * `anchors`, varje ankare → sin adress, och sidan slår upp `location.hash` i
 * den vid laddning och ber Inertia besöka adressen med `replace: true`
 * ([[ADR-0094 Hjälpcentret]] § 3).
 *
 * Sidans egna ord slås upp ur `help.*` och `info.help.*` i lang/{locale}/ui.php
 * ([[ADR-0021 Frontendteknik]] § Beslut). Kategoriernas titlar och
 * beskrivningar kommer ur innehållet, inte ur lang/.
 */
const props = defineProps({
    categories: { type: Array, required: true },
    anchors: { type: Object, required: true },
});

const { t } = useTranslations();

onMounted(() => {
    const anchor = window.location.hash.slice(1);

    if (!anchor) {
        return;
    }

    /*
     * Bara kartans egna poster. `props.anchors[anchor]` hade annars gett en
     * ärvd funktion för `#constructor`, `#toString` och `#__proto__`, och
     * `router.visit(<funktion>)` kastar i stället för att lämna sidan i fred.
     */
    if (!Object.hasOwn(props.anchors, anchor)) {
        return;
    }

    const address = props.anchors[anchor];

    if (typeof address !== 'string') {
        return;
    }

    router.visit(address, { replace: true });
});

/*
 * Antalet frågor. `t()` pluraliserar inte — translate.js byter bara ut
 * `:param` — så den tvågrenade formen ur `help.questions_count` delas här:
 * första grenen för ett, andra för övriga. Saknas nyckeln returnerar `t()`
 * nyckeln själv, och då finns ingen andra gren att välja.
 */
function questionsLabel(count) {
    const [one, many] = t('help.questions_count').split('|');

    return (count === 1 ? one : (many ?? one)).replace(':count', String(count));
}
</script>

<template>
    <Head :title="t('info.help.title')" />

    <div class="mx-auto max-w-3xl py-12">
        <h1 class="text-3xl font-semibold">{{ t('info.help.title') }}</h1>
        <p class="mt-3 text-slate-600">{{ t('info.help.subtitle') }}</p>

        <ul class="mt-8 space-y-3">
            <li v-for="category in categories" :key="category.id">
                <Link
                    :href="`/help/${category.id}`"
                    class="flex min-h-11 flex-col gap-1 rounded-control border border-border bg-surface p-4 outline-none hover:bg-surface-muted focus-visible:ring-2 focus-visible:ring-focus focus-visible:ring-offset-2"
                >
                    <span class="text-lg font-semibold text-ink">{{ category.title }}</span>
                    <span class="text-sm text-ink-muted">{{ category.description }}</span>
                    <span class="text-meta text-ink-subtle">{{ questionsLabel(category.count) }}</span>
                </Link>
            </li>
        </ul>
    </div>
</template>
