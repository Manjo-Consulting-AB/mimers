<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';

/*
 * Felsidan, se issue 51 § Beslut 6. Den renderas ur bootstrap/app.php:s
 * respond() — aldrig ur en controller — och får statuskoden som enda prop.
 *
 * Texten är svensk rakt i komponenten tills issue 52 flyttar den till
 * lang/. Statuskoden visas också, i klartext, så en användare som rapporterar
 * problemet kan säga vilken den var.
 */
const props = defineProps({
    status: { type: Number, required: true },
});

const messages = {
    403: 'Du har inte behörighet till den här sidan.',
    404: 'Sidan finns inte.',
    429: 'Du har gjort för många försök. Vänta en stund och försök igen.',
    500: 'Något gick fel hos oss. Försök igen om en stund.',
};

const text = computed(() => messages[props.status] ?? 'Något gick fel.');
</script>

<template>
    <AppLayout>
        <Head :title="`Fel ${status}`" />

        <div class="py-12 text-center">
            <p class="font-mono text-sm text-slate-500">{{ status }}</p>
            <h1 class="mt-2 text-2xl font-semibold">{{ text }}</h1>

            <Link href="/" class="mt-6 inline-block text-blue-700 hover:underline">Till startsidan</Link>
        </div>
    </AppLayout>
</template>
