<script setup>
import { useForm } from '@inertiajs/vue3';
import { useTranslations } from '../composables/useTranslations.js';

/*
 * Verifieringspåminnelsen, se issue 53a § Beslut 7.
 *
 * Samma text och samma knapp på två ställen: bannern i AppLayout och sidan
 * Auth/VerifyEmail. Komponenten finns för att formuleringen ska stå på ett
 * ställe — två kopior av samma mening glider isär, och den ena blir då
 * liggande utan att någon märker det.
 *
 * Knappen postar till den befintliga POST /email/verification-notification
 * (issue 4). Kontrollern svarar `back()->with('status',
 * 'verification-link-sent')`, och FlashMessage renderar koden — den här
 * komponenten visar alltså ingen egen bekräftelse.
 *
 * Ingen egen kontroll av `auth.user.email_verified_at` här: den som
 * renderar komponenten har redan avgjort att adressen är overifierad.
 */
const { t } = useTranslations();

const form = useForm({});

function send() {
    form.post('/email/verification-notification');
}
</script>

<template>
    <div class="flex flex-col items-start gap-3 rounded border border-amber-300 bg-amber-50 px-4 py-3">
        <p class="text-sm text-amber-900">{{ t('auth.verify.body') }}</p>

        <button
            type="button"
            :disabled="form.processing"
            class="rounded border border-amber-400 bg-white px-3 py-1 text-sm font-medium text-amber-900 disabled:opacity-50"
            @click="send"
        >
            {{ t('auth.verify.send') }}
        </button>
    </div>
</template>
