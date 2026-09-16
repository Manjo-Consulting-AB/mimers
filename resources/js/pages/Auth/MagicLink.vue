<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import FormField from '../../components/FormField.vue';
import { useTranslations } from '../../composables/useTranslations.js';
import { useErrorFocus } from './useErrorFocus.js';

/*
 * Magic link — formuläret som begär länken, se issue 53a § Beslut 5. Samma
 * mönster som Auth/Login.vue; de tre stegen upprepas inte här.
 *
 * Sidan säger INGENTING om huruvida ett mejl gick iväg. Servern svarar
 * likadant för en adress som finns och en som inte finns —
 * MagicLinkRequestController flashar `magic-link-sent` i båda fallen, och
 * App\Support\Auth\MagicLinkBroker::issue() grenas aldrig på — så
 * bekräftelsen är "Om adressen finns hos oss har vi skickat en länk" och
 * aldrig "vi har skickat en länk till dig". Texten kommer ur
 * `flash.magic-link-sent` och renderas av FlashMessage, som alla andra
 * flashkoder; den här filen har ingen egen bekräftelse.
 *
 * En ogiltig eller utgången länk ger abort(403) i MagicLinkLoginController
 * och renderar Error-sidan med status 403. Den här vyn fångar ingenting och
 * skiljer inte på ogiltig och utgången — webben gör det med flit inte, den
 * distinktionen finns bara i API-höljet.
 */
const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

const form = useForm({
    email: '',
});

function submit() {
    form.post('/login/magic-link', {
        onError: focusFirstError,
    });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('auth.magic_link.title')" />

        <h1 class="text-2xl font-semibold">{{ t('auth.magic_link.heading') }}</h1>
        <p class="mt-3 max-w-sm text-sm text-slate-600">{{ t('auth.magic_link.intro') }}</p>

        <form class="mt-6 flex max-w-sm flex-col gap-4" @submit.prevent="submit">
            <FormField v-slot="{ describedBy }" :label="t('form.email')" id="email" :error="form.errors.email">
                <input
                    id="email"
                    v-model="form.email"
                    :aria-describedby="describedBy"
                    type="email"
                    name="email"
                    autocomplete="email"
                    required
                    class="rounded border border-slate-300 bg-white px-3 py-2"
                >
            </FormField>

            <button
                type="submit"
                :disabled="form.processing"
                class="inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
            >
                {{ form.processing ? t('common.pending.default') : t('auth.magic_link.submit') }}
            </button>
        </form>

        <p class="mt-6 max-w-sm text-sm">
            <Link href="/login" class="inline-flex min-h-11 items-center text-blue-700 hover:underline">{{ t('auth.magic_link.login') }}</Link>
        </p>
    </AppLayout>
</template>
