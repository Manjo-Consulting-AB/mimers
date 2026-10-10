<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import FormField from '../../components/FormField.vue';
import { useTranslations } from '../../composables/useTranslations.js';
import { useErrorFocus } from './useErrorFocus.js';

defineOptions({ layout: AppLayout });

/*
 * Registreringen, se issue 53a § Beslut 8. Samma mönster som Auth/Login.vue —
 * läs den filens kommentar för de tre stegen; den upprepas inte här.
 *
 * Fälten är `name`, `email`, `voucher_code` och `password` — och ingen
 * `password_confirmation`. RegisterRequest validerar dem och har inget
 * bekräftelsefält, så ett sådant här skulle se ut att göra något utan att
 * göra något. Vill någon ha ett är det en ändring i den delade
 * FormRequesten — alltså en fråga i PR:en, inte ett beslut i en vy.
 *
 * Lösenordskravet står som text under fältet och kommer ur lang/, inte ur en
 * räknad regel i JavaScript: `Password::defaults()` kan ändras utan att vyn
 * får veta det, och två formuleringar av samma regel glider isär. Ändras
 * regeln är det `auth.register.password_hint` som ska ändras, på båda
 * språken.
 *
 * `autocomplete="new-password"` får lösenordshanteraren att erbjuda ett nytt
 * lösenord i stället för att fylla i det gamla — se issue 53a § Beslut 10.
 *
 * Issue 263 § Beslut 3 · inbjudningskoden, se [[ADR-0055 Inbjudningskoder
 * och stängd registrering]] § 1, § 2 och § 3. Fältet står ÖVERST — det är
 * det första som avgör om registreringen alls går igenom — och har tre
 * skepnader ur proppen `registration`:
 *
 *   - `invite_only` utan inbjudan: koden krävs, etiketten är *Invite code*
 *     och hjälptexten säger att betan är privat.
 *   - `open`: koden är frivillig och etiketten säger det.
 *   - med en utestående inbjudan i sessionen: ingen kod behövs, och det
 *     står i stället för hjälptexten. Fältet är kvar och frivilligt — en
 *     som HAR en kod får ange den och får då kodens plan (ADR-0055 § 3).
 *
 * Kravet är proppens ord, inte en egen regel här: samma svar kommer ur
 * servern (AdmitRegistration), och en `required` i markupen ger bara
 * tangentbords- och skärmläsarstöd på vägen dit.
 */
const props = defineProps({
    registration: {
        type: Object,
        required: true,
    },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

const voucherRequired = computed(() => props.registration.mode !== 'open' && ! props.registration.invitation);

const voucherLabel = computed(() => (
    props.registration.mode === 'open'
        ? t('auth.register.voucher_code_optional')
        : t('auth.register.voucher_code')
));

const voucherHelp = computed(() => {
    if (props.registration.invitation) {
        return t('auth.register.via_invitation');
    }

    return props.registration.mode === 'open' ? null : t('auth.register.voucher_help');
});

const form = useForm({
    name: '',
    email: '',
    voucher_code: '',
    password: '',
});

function submit() {
    form.post('/register', {
        onError: focusFirstError,
        // Lösenordet töms alltid: det finns inget steg två där det behövs
        // igen, och namnet och adressen står kvar så ett upptaget namn eller
        // en felstavad adress går att rätta utan att skriva om allt.
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head :title="t('auth.register.title')" />

    <h1 class="text-2xl font-semibold">{{ t('auth.register.heading') }}</h1>

    <form class="mt-6 flex max-w-sm flex-col gap-4" @submit.prevent="submit">
        <FormField
            v-slot="{ describedBy }"
            :label="voucherLabel"
            id="voucher_code"
            :error="form.errors.voucher_code"
        >
            <input
                id="voucher_code"
                v-model="form.voucher_code"
                :aria-describedby="describedBy"
                type="text"
                name="voucher_code"
                autocomplete="off"
                :required="voucherRequired"
                class="rounded border border-slate-300 bg-white px-3 py-2"
            >
            <p v-if="voucherHelp" class="text-sm text-slate-600">{{ voucherHelp }}</p>
        </FormField>

        <FormField v-slot="{ describedBy }" :label="t('form.name')" id="name" :error="form.errors.name">
            <input
                id="name"
                v-model="form.name"
                :aria-describedby="describedBy"
                type="text"
                name="name"
                autocomplete="name"
                required
                class="rounded border border-slate-300 bg-white px-3 py-2"
            >
        </FormField>

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

        <FormField
            v-slot="{ describedBy }"
            :label="t('form.password')"
            id="password"
            :error="form.errors.password"
        >
            <input
                id="password"
                v-model="form.password"
                :aria-describedby="describedBy"
                type="password"
                name="password"
                autocomplete="new-password"
                required
                class="rounded border border-slate-300 bg-white px-3 py-2"
            >
            <p class="text-sm text-slate-600">{{ t('auth.register.password_hint') }}</p>
        </FormField>

        <button
            type="submit"
            :disabled="form.processing"
            class="inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
        >
            {{ form.processing ? t('common.pending.default') : t('auth.register.submit') }}
        </button>
    </form>

    <p class="mt-6 max-w-sm text-sm">
        <Link href="/login" class="inline-flex min-h-11 items-center text-blue-700 hover:underline">{{ t('auth.register.login') }}</Link>
    </p>
</template>
