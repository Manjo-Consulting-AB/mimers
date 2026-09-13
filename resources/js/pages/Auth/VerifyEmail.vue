<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import VerifyEmailNotice from '../../components/VerifyEmailNotice.vue';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * /email/verify (`verification.notice`), se issue 53a § Beslut 7.
 *
 * Sidan är samma påminnelse som bannern i AppLayout, med samma komponent och
 * därmed samma text och samma knapp. Den finns för den dag en rutt sätter
 * `verified`-middleware och ramverket skickar hit — ingen rutt gör det i den
 * här issuen, eftersom verifieringskravet gäller att ta emot delning och inte
 * att använda appen.
 *
 * AppLayout döljer sin banner här, så knappen står en gång och inte två.
 */
const { t } = useTranslations();
</script>

<template>
    <AppLayout>
        <Head :title="t('auth.verify.title')" />

        <h1 class="text-2xl font-semibold">{{ t('auth.verify.heading') }}</h1>

        <div class="mt-6 max-w-sm">
            <VerifyEmailNotice />
        </div>
    </AppLayout>
</template>
