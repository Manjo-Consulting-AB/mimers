<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import FormField from './FormField.vue';
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
 * **Uppdelningen räknas av servern och inte här.** `accountsToDelete` och
 * `accountsToLeave` kommer ur App\Actions\User\DeleteUser, samma uppdelning
 * som raderingen själv gör. Vyn avgör alltså inte vilka konton som försvinner
 * — den visar vad servern säger, och en avskrift här hade varit en andra
 * sanning om samma sak.
 *
 * **Spärrarna kommer som koder och blir meningar här.** Koden är
 * App\Support\User\DeletionBlocker:s och stavas aldrig i den här filen:
 * meningen väljs på kodens SISTA led (`sole_owner`, `shared_container`,
 * `legal_hold`), och ui.php:s nycklar är döpta efter just det ledet — samma
 * namn som `reason` bär i livscykelns logg. `legal_hold` bär ingen data, så
 * `account` är null och `containers` tom; meningen nämner ingendera, och det
 * är avsiktligt: att ett konto är spärrat är i sig en uppgift om en pågående
 * utredning.
 *
 * **Knappen är avstängd när något spärrar, och `submit()` vaktar också.**
 * En avstängd knapp hindrar ett klick men inte Enter i ett fält, och
 * "begäran går inte att göra" ska vara sant även för den som tabbar sig fram.
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
    totpEnabled: { type: Boolean, required: true },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

const form = useForm({ code: '' });

const blocked = computed(() => props.blockers.length > 0);

/*
 * Meningen för en spärr. Koden delas på sin sista punkt — se docblocket
 * ovanför: nycklarna i ui.php är döpta efter det ledet, och koden själv
 * upprepas aldrig här.
 */
function blockerSentence(blocker) {
    return t(`settings.security.deletion.blocker.${blocker.code.split('.').pop()}`, {
        account: blocker.account,
        containers: blocker.containers.join(', '),
    });
}

function submit() {
    if (blocked.value) {
        return;
    }

    form.post('/settings/delete-user', {
        onError: focusFirstError,
        onSuccess: () => form.reset('code'),
    });
}
</script>

<template>
    <section class="mt-8 flex max-w-lg flex-col gap-4">
        <h2 class="text-lg font-semibold">{{ t('settings.security.deletion.heading') }}</h2>

        <p class="text-sm text-slate-700">{{ t('settings.security.deletion.intro') }}</p>

        <div class="flex flex-col gap-1">
            <h3 class="font-medium">{{ t('settings.security.deletion.accounts_deleted_heading') }}</h3>

            <!-- En <ul> och ingen tabell: listan är namn att läsa, inte
                 värden att jämföra, och en skärmläsare ska höra hur många
                 konton det gäller innan den läser det första. -->
            <ul v-if="props.accountsToDelete.length > 0" class="flex flex-col gap-1 text-sm text-slate-700">
                <li v-for="account in props.accountsToDelete" :key="account.ulid">{{ account.name }}</li>
            </ul>

            <p v-else class="text-sm text-slate-700">{{ t('settings.security.deletion.accounts_none') }}</p>
        </div>

        <div class="flex flex-col gap-1">
            <h3 class="font-medium">{{ t('settings.security.deletion.accounts_left_heading') }}</h3>

            <ul v-if="props.accountsToLeave.length > 0" class="flex flex-col gap-1 text-sm text-slate-700">
                <li v-for="account in props.accountsToLeave" :key="account.ulid">{{ account.name }}</li>
            </ul>

            <p v-else class="text-sm text-slate-700">{{ t('settings.security.deletion.accounts_none') }}</p>
        </div>

        <!-- Spärren står i vyn och läses av skärmläsaren innan knappen
             klickas — inte i en confirm()-dialog, som varken går att
             översätta eller att testa. Samma form som tvåfaktorns varningar
             ovanför. -->
        <div v-if="blocked" class="flex flex-col gap-2 rounded border border-amber-300 bg-amber-50 p-4">
            <h3 class="font-medium text-amber-900">{{ t('settings.security.deletion.blocked_heading') }}</h3>
            <p class="text-sm text-amber-900">{{ t('settings.security.deletion.blocked') }}</p>

            <ul class="flex flex-col gap-1 text-sm text-amber-900">
                <li v-for="(blocker, index) in props.blockers" :key="`${blocker.code}-${index}`">
                    {{ blockerSentence(blocker) }}
                </li>
            </ul>
        </div>

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
                :disabled="blocked || form.processing"
                class="self-start inline-flex min-h-11 items-center rounded bg-red-700 px-4 font-medium text-white disabled:opacity-50"
            >
                {{ blocked
                    ? t('settings.security.deletion.blocked_button')
                    : (form.processing ? t('common.pending.default') : t('settings.security.deletion.submit')) }}
            </button>
        </form>
    </section>
</template>
