<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import FormField from '../../components/FormField.vue';
import { useTranslations } from '../../composables/useTranslations.js';
import { useErrorFocus } from '../../composables/useErrorFocus.js';

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
 * Etiketterna och knapptexten kommer ur lang/{locale}/ui.php sedan issue 52 —
 * `form.email` och `auth.login.*`. Serverns valideringsfel är redan översatta
 * när de når hit: `form.errors.email` bär meningen ur validation.php på
 * användarens språk.
 *
 * Issue 53a § Beslut 4 · engångskoden. `code` skickas med i varje anrop men
 * RENDERAS först när `form.errors.code` finns, alltså när servern har bett om
 * den — AuthenticatedSessionController binder TotpRequiredException dit.
 * Fältet får aldrig synas i förväg: att visa det för en besökare som ännu inte
 * angett rätt lösenord avslöjar både att adressen finns och att kontot har
 * tvåfaktor, precis den sidokanal LoginRequest::authenticate() prövar
 * lösenordet före koden för att undvika. Ett felaktigt lösenord sätter
 * `errors.email`, aldrig `errors.code`, och fältet förblir dolt.
 *
 * Etiketten nämner återställningskoden: LoginRequest provar samma inskickade
 * värde som engångskod och som återställningskod och ger samma fel oavsett
 * vilket som misslyckades (tests/Feature/Auth/AterstallningskoderTest.php).
 * En vy som frågade efter "kod från appen" och gömde återställningskoden
 * bakom en egen länk skulle återinföra en skillnad servern med flit raderat.
 *
 * Fältet är `type="text"` utan `inputmode`: engångskoden är sex siffror, men
 * återställningskoden är tio tecken ur `Str::random()`s alfanumeriska alfabet
 * (App\Support\Auth\RecoveryCodeBroker), och en numerisk tangentbordsknapp
 * hade stängt ute den på en telefon.
 */
const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

const form = useForm({
    email: '',
    password: '',
    code: '',
});

const codeRequested = computed(() => Boolean(form.errors.code));
const codeInput = ref(null);

watch(codeRequested, async (requested) => {
    if (!requested) {
        return;
    }

    await nextTick();
    codeInput.value?.focus();
});

function submit() {
    form.post('/login', {
        // Tangentbordsanvändaren ska hamna på felet, inte kvar på knappen —
        // se useErrorFocus.js.
        onError: focusFirstError,

        // Lösenordet töms när svaret kommit. Undantaget är när servern bad om
        // engångskoden: då är lösenordet redan rätt och att tömma det tvingar
        // fram en omskrivning av ett fält användaren just skrev — samma skäl
        // som gör att takgränsen i bootstrap/app.php inte skickar tillbaka
        // lösenordet. Ett felaktigt lösenord sätter `errors.email` och töms.
        onFinish: () => {
            if (!codeRequested.value) {
                form.reset('password');
            }
        },
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

            <FormField
                v-if="codeRequested"
                v-slot="{ describedBy }"
                :label="t('auth.code.label')"
                id="code"
                :error="form.errors.code"
            >
                <input
                    id="code"
                    ref="codeInput"
                    v-model="form.code"
                    :aria-describedby="describedBy"
                    type="text"
                    name="code"
                    autocomplete="one-time-code"
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

        <div class="mt-6 flex max-w-sm flex-col gap-2 text-sm">
            <Link href="/login/magic-link" class="text-blue-700 hover:underline">{{ t('auth.magic_link.link') }}</Link>
            <Link href="/register" class="text-blue-700 hover:underline">{{ t('auth.register.link') }}</Link>
        </div>
    </AppLayout>
</template>
