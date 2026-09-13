<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';

/*
 * Den skyddade exempvyn, se issue 51 § Beslut 10.
 *
 * AVSIKTLIGT TOM PÅ INNEHÅLL. Den finns för att bevisa att en autentiserad
 * sida renderar i layouten — issue 64 ersätter den med todo-vyn, och det är
 * där innehållet hör hemma. Lägg ingenting här.
 */
</script>

<template>
    <AppLayout>
        <Head title="Översikt" />

        <h1 class="text-2xl font-semibold">Översikt</h1>
    </AppLayout>
</template>
