<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Felsidan, se issue 51 § Beslut 6. Den renderas ur bootstrap/app.php:s
 * respond() — aldrig ur en controller — och får statuskoden som enda prop.
 * Texten kommer ur `error` i lang/{locale}/ui.php sedan issue 52, med
 * statuskoden som nyckel (`error.404`) och `:status` i rubriken.
 *
 * Statuskoden visas också, i klartext, så en användare som rapporterar
 * problemet kan säga vilken den var.
 *
 * Ingen reservmening: `respond()` renderar bara 403, 404, 429 och 500, och
 * de fyra har var sin nyckel. Skulle en femte status nå hit ändå visar t()
 * den råa nyckeln, vilket är precis vad som ska hända med en saknad
 * översättning.
 */
const props = defineProps({
    status: { type: Number, required: true },
});

const { t } = useTranslations();

const text = computed(() => t(`error.${props.status}`));
</script>

<template>
    <AppLayout>
        <Head :title="t('error.title', { status })" />

        <div class="py-12 text-center">
            <p class="font-mono text-sm text-slate-600">{{ status }}</p>
            <h1 class="mt-2 text-2xl font-semibold">{{ text }}</h1>

            <Link href="/" class="mt-6 inline-flex min-h-11 items-center text-blue-700 hover:underline">{{ t('common.home') }}</Link>
        </div>
    </AppLayout>
</template>
