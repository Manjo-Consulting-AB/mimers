<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Informationssidorna — /about, /privacy, /terms och /help, se issue 202.
 *
 * EN sida för fyra adresser: rutten skickar vilken sida det är i `page`-
 * proppen, och all text slås upp ur `info.<sida>.title` och
 * `info.placeholder` i lang/{locale}/ui.php. Ingen sträng bor här — samma
 * regel som resten av webben ([[ADR-0021 Frontendteknik]] § Beslut: webbens
 * text formuleras på servern ur lang/).
 *
 * Rubriken är det enda som skiljer sidorna åt; brödtexten är densamma på alla
 * fyra tills de riktiga texterna skrivs. Sidan ritas i AppLayout som
 * Welcome.vue och är publik — både en gäst och en inloggad når den.
 */
defineProps({
    page: { type: String, required: true },
});

const { t } = useTranslations();
</script>

<template>
    <AppLayout>
        <Head :title="t(`info.${page}.title`)" />

        <div class="py-12 text-center">
            <h1 class="text-3xl font-semibold">{{ t(`info.${page}.title`) }}</h1>
            <p class="mt-3 text-slate-600">{{ t('info.placeholder') }}</p>
        </div>
    </AppLayout>
</template>
