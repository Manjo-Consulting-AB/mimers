<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormField from './FormField.vue';
import UserDeletionSummary from './UserDeletionSummary.vue';
import { useTranslations } from '../composables/useTranslations.js';
import { useErrorFocus } from '../pages/Auth/useErrorFocus.js';

/*
 * Personraderingen, se [[M22 Redo för testare]] § 145 och
 * [[ADR-0045 Radering av konto och person]] § Beslut 3.
 *
 * **ETT formulär och EN POST mot /settings/delete-user.** Formuläret raderar
 * ingenting: det begär en radering, och servern skickar en länk till kontots
 * adress. Personen raderas först när den länken öppnas, och därför står det i
 * introt — en användare som tror att knappen redan raderat kontot stänger
 * mejlet och undrar varför hon fortfarande är inloggad.
 *
 * **Ordet.** Kontot heter *account* i gränssnittet och är något annat än
 * personen: ett konto där någon annan är medlem lämnas, och bara
 * medlemskapet försvinner. Rubriken säger därför inte *Delete account* utan
 * vad som faktiskt raderas, och de två listorna visar samma sak i namn — den
 * som bara läser rubriken och knappen har ändå fått veta vad som händer med
 * de delade kontona.
 *
 * **Listorna och spärrarna ritas av UserDeletionSummary.** Samma underlag
 * visas på bekräftelsesidan bakom mejlets länk
 * (resources/js/pages/Settings/ConfirmUserDeletion.vue), och en avskrift här
 * hade varit en andra sanning om vad raderingen gör. Kvar i den här filen är
 * det som är formulärets: rubriken, inledningen, spärrens rubrik och mening —
 * det är BEGÄRAN som inte kan göras — kodfältet och knappen.
 *
 * **Spärrens mening är formulärets egen.** `blocked` säger att ingen begäran
 * kan göras just nu, och det är bara sant här; bekräftelsesidan säger samma
 * sak om raderingen med sin egen mening. Listan över spärrar är gemensam och
 * kommer ur App\Support\User\DeletionBlocker, vars koder aldrig stavas här.
 *
 * **Knappen är avstängd när något spärrar, och `submit()` vaktar också.**
 * En avstängd knapp hindrar ett klick men inte Enter i ett fält, och
 * "begäran går inte att göra" ska vara sant även för den som tabbar sig fram.
 *
 * **Upplysningen kräver en ikryssad ruta.** En delad container spärrar inte
 * längre ([[ADR-0045 Radering av konto och person]] § Uppföljning 2026-11-14),
 * men personen ska veta att containrarna raderas och att medlemmarna förlorar
 * åtkomsten. Knappen heter *Delete anyway* när upplysningar finns och är
 * avstängd tills rutan är ikryssad; `submit()` vaktar samma villkor, av samma
 * skäl som mot spärren. Fältfelet `confirm_warnings` ritas vid rutan.
 *
 * **Kodfältet finns bara när tvåfaktorn är på**, av samma skäl och med samma
 * villkor som i PasswordForm: `totpEnabled` speglar `totp_confirmed_at`, och
 * serverns TwoFactorChallenge är den som prövar koden. Vyn avgör inte om en
 * kod KRÄVS — den visar fältet när kontot har en andra faktor.
 */
const props = defineProps({
    accountsToDelete: { type: Array, required: true },
    accountsToLeave: { type: Array, required: true },
    blockers: { type: Array, required: true },
    warnings: { type: Array, required: true },
    totpEnabled: { type: Boolean, required: true },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

const form = useForm({ code: '', confirm_warnings: false });

const blocked = computed(() => props.blockers.length > 0);
const hasWarnings = computed(() => props.warnings.length > 0);
const needsConfirmation = computed(() => hasWarnings.value && !form.confirm_warnings);

function submit() {
    if (blocked.value || needsConfirmation.value) {
        return;
    }

    form.post('/settings/delete-user', {
        onError: focusFirstError,
        onSuccess: () => form.reset('code', 'confirm_warnings'),
    });
}
</script>

<template>
    <section class="mt-8 flex max-w-lg flex-col gap-4">
        <h2 class="text-lg font-semibold">{{ t('settings.security.deletion.heading') }}</h2>

        <p class="text-sm text-slate-700">{{ t('settings.security.deletion.intro') }}</p>

        <!-- Listorna och spärrarna, se UserDeletionSummary. Spärrens rubrik
             och mening fylls i här och inte i komponenten: det är BEGÄRAN som
             inte kan göras, och bekräftelsesidan säger samma sak om
             raderingen med sin egen mening. Spärren står i vyn och läses av
             skärmläsaren innan knappen klickas — inte i en confirm()-dialog,
             som varken går att översätta eller att testa. -->
        <UserDeletionSummary
            :accounts-to-delete="props.accountsToDelete"
            :accounts-to-leave="props.accountsToLeave"
            :blockers="props.blockers"
            :warnings="props.warnings"
        >
            <template #blocked>
                <h3 class="font-medium text-amber-900">{{ t('settings.security.deletion.blocked_heading') }}</h3>
                <p class="text-sm text-amber-900">{{ t('settings.security.deletion.blocked') }}</p>
            </template>
        </UserDeletionSummary>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <template v-if="props.totpEnabled">
                <p id="code_note" class="text-sm text-slate-700">
                    {{ t('settings.security.deletion.code_note') }}
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
                        :aria-describedby="describedBy"
                        type="text"
                        name="code"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        class="rounded border border-slate-300 bg-white px-3 py-2"
                    >
                </FormField>
            </template>

            <!-- Upplysningens samtycke. Rutan finns bara när det finns något
                 att upplysas om, och samma villkor stänger av knappen och
                 vakas av submit(). Fältfelet ritas vid rutan, det är där
                 personen kan åtgärda det. -->
            <template v-if="hasWarnings">
                <label for="confirm_warnings" class="flex items-start gap-2 text-sm text-slate-700">
                    <input
                        id="confirm_warnings"
                        v-model="form.confirm_warnings"
                        type="checkbox"
                        name="confirm_warnings"
                        class="mt-1"
                    >
                    <span>{{ t('settings.security.deletion.warning_confirm') }}</span>
                </label>

                <p
                    v-if="form.errors.confirm_warnings"
                    role="alert"
                    class="rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"
                >
                    {{ form.errors.confirm_warnings }}
                </p>
            </template>

            <!-- Takgränsens fel, inte ett fältfel: begränsaren är
                 inloggningens och lägger sitt fel på `email`, ett fält det
                 här formuläret inte har. Samma form som PasswordForm. -->
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
                :disabled="blocked || needsConfirmation || form.processing"
                class="self-start inline-flex min-h-11 items-center rounded bg-red-700 px-4 font-medium text-white disabled:opacity-50"
            >
                {{ blocked
                    ? t('settings.security.deletion.blocked_button')
                    : (hasWarnings
                        ? t('settings.security.deletion.delete_anyway')
                        : (form.processing ? t('common.pending.default') : t('settings.security.deletion.submit'))) }}
            </button>
        </form>
    </section>
</template>
