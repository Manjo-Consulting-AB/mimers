<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import { useTranslations } from '../../composables/useTranslations.js';

defineOptions({ layout: AppLayout });

/*
 * Låssidan (ADR-0092, issue 449): den inbjudna som saknar tvåfaktor öppnade
 * `/containers/{ulid}`. Sidan bär containerns namn, raden om tvåfaktor och
 * länken till säkerhetsinställningarna — inget annat.
 */
defineProps({
    container: { type: Object, required: true },
});

const { t } = useTranslations();
</script>

<template>
    <Head :title="t('container.locked.title')" />

    <section class="mt-8 flex flex-col items-start gap-3">
        <h1 class="inline-flex items-center gap-2 text-title font-semibold text-ink">
            <span aria-hidden="true" class="shrink-0">🔒</span>
            {{ container.name }}
        </h1>

        <p class="text-ink-subtle">{{ t('container.locked.requires') }}</p>

        <Link href="/settings/security" class="inline-flex min-h-11 items-center text-blue-700 hover:underline">
            {{ t('container.locked.turn_on') }}
        </Link>
    </section>
</template>
