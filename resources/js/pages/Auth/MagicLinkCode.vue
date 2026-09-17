<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import FormField from '../../components/FormField.vue';
import { useTranslations } from '../../composables/useTranslations.js';
import { useErrorFocus } from './useErrorFocus.js';

/*
 * Steg två i magic link-inloggningen — kodsidan, se issue 80 § Beslut 2.
 * Renderas av App\Http\Controllers\Auth\MagicLinkLoginController::__invoke()
 * när kontot har en bekräftad tvåfaktor: länken är då förbrukad och någon
 * inloggning har inte skett.
 *
 * Mönstret är Auth/Login.vues, med en skillnad som är hela poängen: där
 * dyker kodfältet upp EFTER att servern bett om det (`form.errors.code`), för
 * att ett fält i förväg avslöjar att kontot har tvåfaktor. Här finns inget
 * att avslöja — användaren har just klickat på sin egen länk och servern har
 * redan sagt att koden behövs — så fältet står framme från början och
 * sidans hela uppgift är att fråga efter det.
 *
 * Etiketten är `auth.code.label` och nämner båda kodsorterna med flit:
 * servern provar samma inskickade värde som engångskod och som
 * återställningskod och ger samma fel oavsett vilket som misslyckades
 * (App\Support\Auth\TwoFactorChallenge). En vy som frågade efter "kod från
 * appen" och gömde återställningskoden bakom en egen länk skulle återinföra
 * en skillnad servern med flit raderat.
 *
 * Anropet bär bara koden. Vem försöket gäller — och därmed vad
 * takgränsen räknas mot — kommer ur väntetillståndet i sessionen på
 * servern, se App\Support\Auth\ConsumeMagicLinkCodeRequest och
 * App\Support\Auth\BindsMagicLinkCodeThrottleToPendingLogin. Ett
 * `email`-fält i det här anropet hade varit klientstyrt och gått att byta
 * ut mot en ny, orörd hink för varje försök.
 */
const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

const form = useForm({
    code: '',
});

function submit() {
    form.post('/login/magic-link/consume', {
        onError: focusFirstError,
    });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('auth.magic_link.code.title')" />

        <h1 class="text-2xl font-semibold">{{ t('auth.magic_link.code.heading') }}</h1>
        <p class="mt-3 max-w-sm text-sm text-slate-600">{{ t('auth.magic_link.code.intro') }}</p>

        <form class="mt-6 flex max-w-sm flex-col gap-4" @submit.prevent="submit">
            <FormField v-slot="{ describedBy }" :label="t('auth.code.label')" id="code" :error="form.errors.code">
                <input
                    id="code"
                    v-model="form.code"
                    :aria-describedby="describedBy"
                    type="text"
                    name="code"
                    autocomplete="one-time-code"
                    required
                    class="rounded border border-slate-300 bg-white px-3 py-2"
                >
            </FormField>

            <button
                type="submit"
                :disabled="form.processing"
                class="inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
            >
                {{ form.processing ? t('common.pending.default') : t('auth.magic_link.code.submit') }}
            </button>
        </form>

        <p class="mt-6 max-w-sm text-sm">
            <Link href="/login/magic-link" class="inline-flex min-h-11 items-center text-blue-700 hover:underline">{{ t('auth.magic_link.request_again') }}</Link>
        </p>
    </AppLayout>
</template>
