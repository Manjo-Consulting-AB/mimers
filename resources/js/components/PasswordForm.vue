<script setup>
import { useForm } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * Lösenordsbytet, se [[M20 Kontot]] § 140.
 *
 * **ETT formulär och EN PUT mot /settings/security/password, i två lägen.**
 * Läget kommer ur `hasPassword`: ett konto med lösenord byter det, ett konto
 * som bara använt magic link sätter sitt första. Fälten är desamma och rutten
 * är densamma — skillnaden är bara vad knappen och introt säger.
 *
 * **Fältet för det nuvarande lösenordet finns inte längre**, i något av
 * lägena. Fram till issue 140 krävdes det när kontot hade ett lösenord, vilket
 * stängde den enda vägen ut för den som glömt sitt: hon kan logga in med magic
 * link, men kunde sedan inte byta ([[ADR-0011 Autentisering]] § Uppföljning
 * 2026-09-26). Nu begär formuläret bytet och servern skickar en länk till
 * kontots adress — lösenordet byts först när länken öppnas, och därför ber
 * vyn aldrig om ett nuvarande lösenord.
 *
 * **Kodfältet finns bara när tvåfaktorn är på**, och det följer av
 * `totpEnabled` — samma prop och samma villkor (`totp_confirmed_at`) som
 * serverns TwoFactorChallenge::isRequired() läser. Vyn avgör alltså inte om
 * en kod KRÄVS; den visar fältet när kontot har en andra faktor, och servern
 * är den som prövar den. Är kontot utan tvåfaktor avvisas en inskickad kod
 * ingenstans ifrån — fältet finns inte att skicka.
 *
 * **Fälten töms vid lyckad begäran.** `form.reset()` och inte en omladdning:
 * servern svarar `back()` med en flash-kod, och ett lösenord som ligger kvar
 * i fältet efteråt är ett lösenord i klartext på skärmen för nästa person
 * som går förbi. `useForm` skickar också `password_confirmation`, som
 * valideringsregeln `confirmed` kräver.
 *
 * **`autocomplete="new-password"` är satt för lösenordshanterarens skull** och
 * inte för vyn: det är vad en webbläsare behöver för att erbjuda ett nytt
 * lösenord, och `one-time-code` på kodfältet är samma val som inloggningens.
 */
const props = defineProps({
    hasPassword: { type: Boolean, required: true },
    totpEnabled: { type: Boolean, required: true },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

const form = useForm({
    password: '',
    password_confirmation: '',
    code: '',
});

/*
 * Fälten beskrivs av sina egna fel OCH av den mening som gäller dem —
 * samma form som QuietHoursForm:s notesFor(): en skärmläsare ska höra
 * kravet och kodförklaringen när hon kommer till fältet, inte bara felet
 * efteråt. Id:na tillhör <p>-elementen nedanför.
 */
function describedByWith(errorId, note) {
    return [errorId, note].filter(Boolean).join(' ');
}

function submit() {
    form.put('/settings/security/password', {
        onError: focusFirstError,
        onSuccess: () => form.reset('password', 'password_confirmation', 'code'),
    });
}
</script>

<template>
    <section class="mt-8 flex max-w-lg flex-col gap-4">
        <h2 class="text-lg font-semibold">{{ t('settings.security.password.heading') }}</h2>

        <p class="text-sm text-slate-700">
            {{ props.hasPassword
                ? t('settings.security.password.intro')
                : t('settings.security.password.intro_set') }}
        </p>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <FormField
                v-slot="{ describedBy }"
                :label="t('settings.security.password.new_label')"
                id="password"
                :error="form.errors.password"
            >
                <input
                    id="password"
                    v-model="form.password"
                    :aria-describedby="describedByWith(describedBy, 'password_hint')"
                    type="password"
                    name="password"
                    autocomplete="new-password"
                    class="rounded border border-slate-300 bg-white px-3 py-2"
                >
            </FormField>

            <!-- Kraven är samma som registreringens och meningen är
                 registreringens egen (ADR-0034: en enda katalog). -->
            <p id="password_hint" class="text-sm text-slate-600">
                {{ t('auth.register.password_hint') }}
            </p>

            <FormField
                v-slot="{ describedBy }"
                :label="t('settings.security.password.confirm_label')"
                id="password_confirmation"
                :error="form.errors.password_confirmation"
            >
                <input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    :aria-describedby="describedBy"
                    type="password"
                    name="password_confirmation"
                    autocomplete="new-password"
                    class="rounded border border-slate-300 bg-white px-3 py-2"
                >
            </FormField>

            <template v-if="props.totpEnabled">
                <p id="code_note" class="text-sm text-slate-700">
                    {{ t('settings.security.password.code_note') }}
                </p>

                <FormField
                    v-slot="{ describedBy }"
                    :label="t('auth.code.label')"
                    id="code"
                    :error="form.errors.code"
                >
                    <input
                        id="code"
                        v-model="form.code"
                        :aria-describedby="describedByWith(describedBy, 'code_note')"
                        type="text"
                        name="code"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        class="rounded border border-slate-300 bg-white px-3 py-2"
                    >
                </FormField>
            </template>

            <!-- Takgränsens fel, inte ett fältfel: begränsaren är
                 inloggningens och lägger sitt fel på `email`, ett fält det
                 här formuläret inte har. Meningen ritas därför för hela
                 formuläret, ovanför knappen. `id`/`tabindex` följer FormField
                 så att fokus hamnar här — se useErrorFocus.js. -->
            <p
                v-if="form.errors.email"
                id="email-error"
                role="alert"
                tabindex="-1"
                class="rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 outline-none focus:ring-2 focus:ring-focus focus:ring-offset-2"
            >
                {{ form.errors.email }}
            </p>

            <button
                type="submit"
                :disabled="form.processing"
                class="self-start inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
            >
                {{ form.processing
                    ? t('common.pending.default')
                    : (props.hasPassword
                        ? t('settings.security.password.submit')
                        : t('settings.security.password.submit_set')) }}
            </button>
        </form>
    </section>
</template>
