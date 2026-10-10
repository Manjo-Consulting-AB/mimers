<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import { useTranslations } from '../../composables/useTranslations.js';

defineOptions({ layout: AppLayout });

/*
 * En raderingslänk som inte gäller, se [[M22 Redo för testare]] § 145 och
 * [[ADR-0045 Radering av konto och person]] § Uppföljning 2026-09-28,
 * beslut 4.
 *
 * **Sidan renderas med status 404**, av
 * App\Http\Controllers\Settings\UserDeletionController::confirm(), och ersätter
 * den nakna felsidan på just den här vägen. En länk som gått ut eller redan
 * använts är inget brott och inget serverfel — den är ett försent försök, och
 * den som klickar behöver veta vad hon gör i stället.
 *
 * **Sidan säger inte vilket av skälen det är, och inte vems länk det var.**
 * Okänt, utgånget och förbrukat ger alla samma svar, av samma skäl som
 * App\Actions\User\ConfirmUserDeletion::validDeletion() inte skiljer dem åt:
 * ett "finns inte" mot ett "är redan använt" är en orakelyta mot giltiga
 * token. Texten nämner därför båda möjligheterna och ingen av dem som ett
 * faktum.
 */
const { t } = useTranslations();
</script>

<template>
    <Head :title="t('settings.security.deletion_link.invalid_heading')" />

    <div class="py-12 text-center">
        <h1 class="text-2xl font-semibold">{{ t('settings.security.deletion_link.invalid_heading') }}</h1>

        <p class="mx-auto mt-2 max-w-lg text-slate-700">{{ t('settings.security.deletion_link.invalid') }}</p>

        <Link href="/" class="mt-6 inline-flex min-h-11 items-center text-blue-700 hover:underline">{{ t('common.home') }}</Link>
    </div>
</template>
