<script setup>
import { useForm } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * Adressbytet, se [[M20 Kontot]] § 130.
 *
 * **ETT formulär och EN POST mot /settings/profile/email — och adressen
 * byts inte här.** Formuläret begär bytet: servern skickar en länk till den
 * nya adressen och ett meddelande till den gamla, och `user.email` står kvar
 * orörd. Först när länken öppnas skrivs adressen om, av
 * App\Actions\Account\ConfirmEmailChange. `intro` säger det, för ett
 * formulär som ser ut att byta adressen direkt vore en lögn om vad klicket
 * gör.
 *
 * **Läget kommer ur `hasPassword`.** Ett konto som bara använt magic link har
 * inget lösenord att ange, och kan därför inte begära ett byte — en magic
 * link bevisar bara att hon når den adress hon redan har
 * ([[ADR-0011 Autentisering]] § Motivering). Formuläret ritar då ingen
 * inmatning alls, utan hänvisar till säkerhetssidan där ett lösenord sätts
 * (issue 129). Servern avvisar det ändå — App\Http\Requests\Settings\
 * RequestEmailChangeRequest — och vyn upprepar bara vad servern redan
 * bestämt; den avgör ingenting själv.
 *
 * **Kodfältet följer av `totpEnabled`**, samma prop och samma villkor
 * (`totp_confirmed_at`) som App\Support\Auth\TwoFactorChallenge::isRequired()
 * läser. Vyn visar fältet när kontot har en andra faktor; servern är den som
 * prövar koden.
 *
 * **Fälten töms vid lyckat utskick.** `form.reset()` och inte en omladdning:
 * det nuvarande lösenordet ska inte ligga kvar i klartext på skärmen, och
 * den nya adressen är redan skickad — servern svarar `back()` med en
 * flash-kod.
 *
 * **`autocomplete` är satt för lösenordshanterarens skull:** `current-password`
 * på lösenordsfältet och `one-time-code` på kodfältet, samma val som
 * inloggningen och lösenordsformuläret. `email` på adressfältet är vad en
 * webbläsare vill ha för att erbjuda rätt förslag.
 */
const props = defineProps({
    hasPassword: { type: Boolean, required: true },
    totpEnabled: { type: Boolean, required: true },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

const form = useForm({
    new_email: '',
    current_password: '',
    code: '',
});

/*
 * Fälten beskrivs av sina egna fel OCH av den mening som gäller dem — samma
 * form som PasswordForm:s describedByWith(): en skärmläsare ska höra
 * kodförklaringen när hon kommer till fältet, inte bara felet efteråt.
 * Id:na tillhör <p>-elementen nedanför.
 */
function describedByWith(errorId, note) {
    return [errorId, note].filter(Boolean).join(' ');
}

function submit() {
    form.post('/settings/profile/email', {
        onError: focusFirstError,
        onSuccess: () => form.reset('new_email', 'current_password', 'code'),
    });
}
</script>

<template>
    <section class="mt-8 flex max-w-lg flex-col gap-4">
        <h2 class="text-lg font-semibold">{{ t('settings.profile.email_change.heading') }}</h2>

        <p class="text-sm text-slate-700">{{ t('settings.profile.email_change.intro') }}</p>

        <!-- Utan lösenord finns ingenting att återautentisera med, och bytet
             kan inte begäras. Ingen inmatning alls: ett formulär som servern
             ändå avvisar är värre än en hänvisning till den sida där hindret
             tas bort. -->
        <template v-if="props.hasPassword">
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <FormField
                    v-slot="{ describedBy }"
                    :label="t('settings.profile.email_change.new_label')"
                    id="new_email"
                    :error="form.errors.new_email"
                >
                    <input
                        id="new_email"
                        v-model="form.new_email"
                        :aria-describedby="describedBy"
                        type="email"
                        name="new_email"
                        autocomplete="email"
                        required
                        class="rounded border border-slate-300 bg-white px-3 py-2"
                    >
                </FormField>

                <FormField
                    v-slot="{ describedBy }"
                    :label="t('settings.profile.email_change.current_label')"
                    id="current_password"
                    :error="form.errors.current_password"
                >
                    <input
                        id="current_password"
                        v-model="form.current_password"
                        :aria-describedby="describedBy"
                        type="password"
                        name="current_password"
                        autocomplete="current-password"
                        required
                        class="rounded border border-slate-300 bg-white px-3 py-2"
                    >
                </FormField>

                <template v-if="props.totpEnabled">
                    <p id="code_note" class="text-sm text-slate-700">
                        {{ t('settings.profile.email_change.code_note') }}
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
                     inloggningens och lägger sitt fel på `email`, ett fält
                     det här formuläret inte har. Meningen ritas därför för
                     hela formuläret, ovanför knappen. Samma form som
                     PasswordForm.vue. -->
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
                    {{ form.processing ? t('common.pending.default') : t('settings.profile.email_change.submit') }}
                </button>
            </form>
        </template>

        <p v-else class="text-sm text-slate-700">
            {{ t('settings.profile.email_change.password_first') }}
            <a href="/settings/security" class="underline">{{ t('settings.profile.email_change.password_first_link') }}</a>.
        </p>
    </section>
</template>
