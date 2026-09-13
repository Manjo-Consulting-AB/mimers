<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import FormField from '../../components/FormField.vue';
import { useTranslations } from '../../composables/useTranslations.js';

/*
 * Den arbetade förlagan för varje formulär i M10, se issue 51 § Beslut 9.
 *
 * Mönstret, i tre steg:
 *
 *   1. `useForm({...})` håller värdena, skickar dem och bär serverns svar.
 *   2. `form.post('/login')` postar till den befintliga rutten som redan
 *      validerar. Ingen egen FormRequest, ingen ny rutt som tar emot data,
 *      ingen klientvalidering — skiljer sig valideringen mellan webben och
 *      /api är det en bugg, inte en designfråga ([[ADR-0021
 *      Frontendteknik]]).
 *   3. `form.errors.<fält>` fylls av Inertia ur sessionens felpåse efter en
 *      ValidationException. Ingen delar `errors` — Inertias middleware gör
 *      det redan — och ingen skriver `<p v-if="errors.x">` för hand;
 *      FormField renderar felet och kopplar det till fältet.
 *
 * Sidan ägs av issue 53a efter den här: TOTP-fältet, magic link-fliken och
 * länken till registrering är 53a:s, liksom vart store() skickar användaren
 * efter en lyckad inloggning.
 *
 * Etiketterna och knapptexten kommer ur lang/{locale}/ui.php sedan issue 52 —
 * `form.email` och `auth.login.*`. Serverns valideringsfel är redan översatta
 * när de når hit: `form.errors.email` bär meningen ur validation.php på
 * användarens språk.
 */
const { t } = useTranslations();

const form = useForm({
    email: '',
    password: '',
});

function submit() {
    form.post('/login', {
        // Lösenordet töms när svaret kommit, oavsett utfall. E-postadressen
        // står kvar, så ett felstavat lösenord går att rätta utan att skriva
        // om allt.
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <AppLayout>
        <Head :title="t('auth.login.title')" />

        <h1 class="text-2xl font-semibold">{{ t('auth.login.heading') }}</h1>

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

            <FormField v-slot="{ describedBy }" :label="t('form.password')" id="password" :error="form.errors.password">
                <input
                    id="password"
                    v-model="form.password"
                    :aria-describedby="describedBy"
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    required
                    class="rounded border border-slate-300 bg-white px-3 py-2"
                >
            </FormField>

            <button
                type="submit"
                :disabled="form.processing"
                class="rounded bg-blue-700 px-4 py-2 font-medium text-white disabled:opacity-50"
            >
                {{ t('auth.login.submit') }}
            </button>
        </form>
    </AppLayout>
</template>
