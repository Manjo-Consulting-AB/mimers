<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Den skyddade exempvyn, se issue 51 § Beslut 10.
 *
 * AVSIKTLIGT TOM PÅ INNEHÅLL. Den finns för att bevisa att en autentiserad
 * sida renderar i layouten — issue 64 ersätter den med todo-vyn, och det är
 * där innehållet hör hemma. Lägg ingenting här.
 *
 * Rubriken är sidans enda text och kommer ur `nav.dashboard` sedan issue 52.
 */
const { t } = useTranslations();
</script>

<template>
    <AppLayout>
        <Head :title="t('nav.dashboard')" />

        <h1 class="text-2xl font-semibold">{{ t('nav.dashboard') }}</h1>
    </AppLayout>
</template>
