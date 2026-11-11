<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import { useTranslations } from '../../composables/useTranslations.js';
import { useErrorFocus } from './useErrorFocus.js';

defineOptions({ layout: AppLayout });

/*
 * Issue 1054 · Mejllänken landar här. Sidan förbrukar ingenting: tokenet
 * förbrukas först när knappen trycks och formuläret postas till
 * /login/magic-link/confirm. Det är poängen — en länkförhandsgranskare i
 * mejlsystemet (Outlook Safe Links, spamfilter) öppnar länken men trycker
 * aldrig på knappen.
 *
 * Mönstret är Auth/MagicLinkCode.vues. `email` och `token` kommer som props
 * ur adressraden och skickas tillbaka oförändrade; inga fält syns. Sidan
 * laddar inget från en annan värd — tokenet står i adressfältet och skulle
 * annars kunna läcka via Referer.
 */
const props = defineProps({
    email: { type: String, required: true },
    token: { type: String, required: true },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

const form = useForm({
    email: props.email,
    token: props.token,
});

function submit() {
    form.post('/login/magic-link/confirm', {
        onError: focusFirstError,
    });
}
</script>

<template>
    <Head :title="t('auth.magic_link.confirm_heading')" />

    <h1 class="text-2xl font-semibold">{{ t('auth.magic_link.confirm_heading') }}</h1>
    <p class="mt-3 max-w-sm text-sm text-slate-600">{{ t('auth.magic_link.confirm_body', { email: props.email }) }}</p>

    <form class="mt-6 flex max-w-sm flex-col gap-4" @submit.prevent="submit">
        <p v-if="form.errors.email" class="text-sm text-red-700" role="alert">{{ form.errors.email }}</p>

        <button
            type="submit"
            :disabled="form.processing"
            class="inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
        >
            {{ form.processing ? t('common.pending.default') : t('auth.magic_link.confirm_submit') }}
        </button>
    </form>
</template>
