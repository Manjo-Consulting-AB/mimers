<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import SettingsLayout from '../../layouts/SettingsLayout.vue';
import FormField from '../../components/FormField.vue';
import PasswordForm from '../../components/PasswordForm.vue';
import UiListRow from '../../components/UiListRow.vue';
import { useTranslations } from '../../composables/useTranslations.js';
import { useRelativeDate } from '../../composables/useRelativeDate.js';
import { useErrorFocus } from '../Auth/useErrorFocus.js';

/*
 * Säkerhetssidan, se issue 53b.
 *
 * Fyra fristående formulär, ett useForm var — samma mönster som issue 53a:s
 * Auth/Login. Ingen ny rutt tar emot något: varje formulär postar till de
 * rutter som redan finns sedan issue 6a–6c och som redan validerar, flashar
 * och testas. Serverns fel går genom FormField precis som på inloggningen.
 *
 * **Lösenordsformuläret kom med issue 129 och ligger i sin egen komponent**,
 * PasswordForm.vue, överst på sidan: det är kontots första faktor. Det är
 * det enda av sidans formulär som har två lägen (sätt ett lösenord, byt ett)
 * och det enda som behöver veta något om kontot för att ritas rätt —
 * `hasPassword` — så det äger sin egen form och sin egen rutt
 * (PUT /settings/security/password).
 *
 * Sidan har tre lägen, och de följer av propsen (Beslut 5):
 *
 *   1. Ingen TOTP (`totpEnabled` false, inget `totpUri`): en knapp.
 *   2. Hemlighet genererad, inte bekräftad (`totpUri` finns): URI, hemlighet
 *      och ett kodfält som postar /totp/confirm.
 *   3. Bekräftad (`totpEnabled` true): datum, antal koder kvar, knappen som
 *      genererar ett nytt ark och fältet som stänger av.
 *
 * Läge 2 renderas BARA när `totpUri` finns i props. Hemligheten går inte att
 * hämta tillbaka — den flashas en gång av POST /totp och finns sedan bara i
 * användarens app — så en användare som laddar om eller lämnar sidan hamnar i
 * läge 1 igen och börjar om med knappen. Att ha kvar något i en komponent, en
 * cookie, localStorage eller en URL-parameter vore att göra en engångshemlighet
 * beständig, precis det TotpBroker § Beslut 2 och RecoveryCodeBroker
 * § Beslut 1 förhindrar på serversidan.
 *
 * Läge 3 kan aldrig framkalla `abort(422)`: POST /totp vägrar mot ett konto
 * som redan har en bekräftad TOTP (TotpController::store()), och knappen som
 * postar dit finns bara i läge 1.
 *
 * Etiketten på båda kodfälten säger engångskod, inte "engångskod eller
 * återställningskod" som på inloggningen: TotpBroker::confirm() och
 * ::disable() prövar bara TOTP-koden. Återställningskodsgrenen finns uteslutande
 * i LoginRequest::authenticate() (Beslut 8).
 *
 * **Inloggningshistoriken, issue 117.** Under tvåfaktorskortet står
 * användarens tjugo senaste inloggningar — den enda ytan där säkerhetsloggen
 * läses av någon annan än oss ([[ADR-0043 Tre loggar]] § Säkerhetsloggen).
 * Raderna är `logins` ur SecurityController och kommer FÄRDIGA: `device_name`
 * är enhetsnamnet som tolkades när raden skrevs, `succeeded` är utfallet.
 * Vyn tolkar ingenting — den väljer ett ord ur `lang/` och skriver ut
 * datumet med datumregeln (issue 104) — och `ip_group` finns varken här eller
 * i proparna: pseudonymen är vår. Formen är `UiListRow` (issue 99), och
 * `device_name` null möts av `logins.unknown_device`, aldrig av ett tomt
 * fält.
 */
const props = defineProps({
    hasPassword: { type: Boolean, required: true },
    totpEnabled: { type: Boolean, required: true },
    totpConfirmedAt: { type: String, default: null },
    recoveryCodesRemaining: { type: Number, required: true },
    totpUri: { type: String, default: null },
    recoveryCodes: { type: Array, default: null },
    logins: { type: Array, required: true },
});

const { t } = useTranslations();
const { eventDate } = useRelativeDate();
const { focusFirstError } = useErrorFocus();

const setupForm = useForm({});
const confirmForm = useForm({ code: '' });
const disableForm = useForm({ code: '' });
const recoveryForm = useForm({});

const showsSetup = computed(() => !props.totpEnabled && props.totpUri !== null);

// `secret`-parametern ur otpauth://-URI:n, utan QR-kod (Beslut 4). Alla
// autentiseringsappar har manuell inmatning; att visa hemligheten i grupper
// om fyra går att läsa av och skriva in för hand.
const secret = computed(() => {
    const match = /[?&]secret=([^&]+)/.exec(props.totpUri ?? '');

    return match ? match[1] : null;
});

const secretGroups = computed(() => (secret.value ? secret.value.match(/.{1,4}/g) : []));

const copied = ref(false);

function copyUri() {
    if (!navigator.clipboard) {
        return;
    }

    navigator.clipboard.writeText(props.totpUri).then(() => {
        copied.value = true;
    });
}

function enableTotp() {
    setupForm.post('/totp', { onError: focusFirstError });
}

function confirmTotp() {
    confirmForm.post('/totp/confirm', {
        onError: focusFirstError,
        onSuccess: () => confirmForm.reset('code'),
    });
}

function disableTotp() {
    disableForm.delete('/totp', {
        onError: focusFirstError,
        onSuccess: () => disableForm.reset('code'),
    });
}

// Ingen kod: RecoveryCodeBroker § Beslut 4 — att kräva en TOTP-kod för att
// omgenerera koder som finns till för fallet "appen är borta" vore
// självmotsägande. Asymmetrin mot avstängningen är medveten.
function generateRecoveryCodes() {
    recoveryForm.post('/totp/recovery-codes');
}

// Enheten och utfallet, som ord. Reserven är en nyckel och inte en tom sträng:
// en rad utan enhetsnamn är en rad vi inte kunde namnge — inte en trasig rad.
function deviceLabel(login) {
    return login.device_name ?? t('settings.security.logins.unknown_device');
}

function outcomeLabel(login) {
    return login.succeeded
        ? t('settings.security.logins.succeeded')
        : t('settings.security.logins.failed');
}
</script>

<template>
    <SettingsLayout>
        <Head :title="t('settings.security.title')" />

        <h1 class="text-2xl font-semibold">{{ t('settings.security.heading') }}</h1>

        <PasswordForm :has-password="props.hasPassword" :totp-enabled="props.totpEnabled" />

        <section class="mt-8 flex max-w-lg flex-col gap-4">
            <h2 class="text-lg font-semibold">{{ t('settings.security.totp.heading') }}</h2>

            <!-- Läge 1 · ingen TOTP. -->
            <template v-if="!props.totpEnabled && !showsSetup">
                <p class="text-sm text-slate-700">{{ t('settings.security.totp.intro') }}</p>

                <form @submit.prevent="enableTotp">
                    <button
                        type="submit"
                        :disabled="setupForm.processing"
                        class="inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
                    >
                        {{ setupForm.processing ? t('common.pending.default') : t('settings.security.totp.enable') }}
                    </button>
                </form>
            </template>

            <!-- Läge 2 · hemlighet genererad, inte bekräftad. -->
            <template v-else-if="showsSetup">
                <p class="text-sm text-slate-700">{{ t('settings.security.totp.setup_intro') }}</p>

                <div class="flex flex-col gap-1">
                    <p class="text-sm font-medium text-slate-800">{{ t('settings.security.totp.uri_label') }}</p>
                    <code class="block break-all rounded border border-slate-300 bg-white px-3 py-2 text-xs">{{ props.totpUri }}</code>
                    <button
                        type="button"
                        class="self-start inline-flex min-h-11 items-center text-sm text-blue-700 hover:underline"
                        @click="copyUri"
                    >
                        {{ copied ? t('settings.security.totp.copied') : t('settings.security.totp.copy') }}
                    </button>
                </div>

                <div class="flex flex-col gap-1">
                    <p class="text-sm font-medium text-slate-800">{{ t('settings.security.totp.secret_label') }}</p>
                    <p class="font-mono text-lg tracking-widest">{{ secretGroups.join(' ') }}</p>
                </div>

                <form class="flex flex-col gap-4" @submit.prevent="confirmTotp">
                    <FormField
                        v-slot="{ describedBy }"
                        :label="t('settings.security.totp.code_label')"
                        id="code"
                        :error="confirmForm.errors.code"
                    >
                        <input
                            id="code"
                            v-model="confirmForm.code"
                            :aria-describedby="describedBy"
                            type="text"
                            name="code"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            class="rounded border border-slate-300 bg-white px-3 py-2"
                        >
                    </FormField>

                    <button
                        type="submit"
                        :disabled="confirmForm.processing"
                        class="self-start inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
                    >
                        {{ confirmForm.processing ? t('common.pending.default') : t('settings.security.totp.confirm') }}
                    </button>
                </form>
            </template>

            <!-- Läge 3 · bekräftad. -->
            <template v-else>
                <p class="text-sm text-slate-700">
                    {{ t('settings.security.totp.confirmed_at', { date: props.totpConfirmedAt }) }}
                </p>

                <div class="flex flex-col gap-2 rounded border border-slate-200 bg-white p-4">
                    <h3 class="font-medium">{{ t('settings.security.totp.recovery_heading') }}</h3>
                    <p class="text-sm text-slate-700">
                        {{ t('settings.security.totp.recovery_remaining', { count: props.recoveryCodesRemaining }) }}
                    </p>

                    <!-- Varningen står i vyn och läses av skärmläsaren innan
                         knappen klickas — inte i en confirm()-dialog, som
                         varken går att översätta eller att testa (Beslut 7).
                         RecoveryCodeBroker § Beslut 3: hela radmängden raderas
                         i en transaktion. -->
                    <p class="text-sm text-amber-800">{{ t('settings.security.totp.recovery_warning') }}</p>

                    <form @submit.prevent="generateRecoveryCodes">
                        <button
                            type="submit"
                            :disabled="recoveryForm.processing"
                            class="inline-flex min-h-11 items-center rounded border border-slate-400 bg-white px-4 font-medium disabled:opacity-50"
                        >
                            {{ recoveryForm.processing ? t('common.pending.default') : t('settings.security.totp.recovery_generate') }}
                        </button>
                    </form>

                    <div v-if="props.recoveryCodes" class="flex flex-col gap-2">
                        <p class="text-sm text-slate-700">{{ t('settings.security.totp.recovery_once') }}</p>
                        <ul class="flex flex-col gap-1 font-mono text-sm">
                            <li v-for="code in props.recoveryCodes" :key="code">{{ code }}</li>
                        </ul>
                    </div>
                </div>

                <div class="flex flex-col gap-4 rounded border border-slate-200 bg-white p-4">
                    <h3 class="font-medium">{{ t('settings.security.totp.disable_heading') }}</h3>

                    <!-- RecoveryCodeBroker § Beslut 5: disable() anropar
                         purge(). Ett nytt ark vid en ny aktivering, aldrig de
                         gamla koderna tillbaka. -->
                    <p class="text-sm text-amber-800">{{ t('settings.security.totp.disable_warning') }}</p>

                    <form class="flex flex-col gap-4" @submit.prevent="disableTotp">
                        <FormField
                            v-slot="{ describedBy }"
                            :label="t('settings.security.totp.code_label')"
                            id="code"
                            :error="disableForm.errors.code"
                        >
                            <input
                                id="code"
                                v-model="disableForm.code"
                                :aria-describedby="describedBy"
                                type="text"
                                name="code"
                                inputmode="numeric"
                                autocomplete="one-time-code"
                                class="rounded border border-slate-300 bg-white px-3 py-2"
                            >
                        </FormField>

                        <button
                            type="submit"
                            :disabled="disableForm.processing"
                            class="self-start inline-flex min-h-11 items-center rounded bg-red-700 px-4 font-medium text-white disabled:opacity-50"
                        >
                            {{ disableForm.processing ? t('common.pending.default') : t('settings.security.totp.disable_submit') }}
                        </button>
                    </form>
                </div>
            </template>
        </section>

        <!--
            Inloggningshistoriken, issue 117. En <ul> med UiListRow i, som
            varje annan lista: en skärmläsare ska höra hur många rader det
            finns innan den läser den första. Ingen rad är klickbar — en
            inloggning går inte att öppna, och en rad som ser klickbar ut utan
            att leda någonstans är värre än en rad som inte gör det.
        -->
        <section class="mt-8 flex max-w-lg flex-col gap-4">
            <h2 class="text-lg font-semibold">{{ t('settings.security.logins.heading') }}</h2>

            <p v-if="props.logins.length === 0" class="text-sm text-slate-700">
                {{ t('settings.security.logins.empty') }}
            </p>

            <ul v-else class="flex flex-col">
                <UiListRow v-for="(login, index) in props.logins" :key="`${login.created_at}-${index}`">
                    <template #title>{{ deviceLabel(login) }}</template>

                    <template #subtitle>{{ outcomeLabel(login) }}</template>

                    <template #meta>
                        <time :datetime="login.created_at">{{ eventDate(login.created_at).text }}</time>
                    </template>
                </UiListRow>
            </ul>
        </section>
    </SettingsLayout>
</template>
