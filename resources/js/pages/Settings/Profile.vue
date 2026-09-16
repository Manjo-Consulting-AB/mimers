<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import SettingsLayout from '../../layouts/SettingsLayout.vue';
import FormField from '../../components/FormField.vue';
import { useTranslations } from '../../composables/useTranslations.js';
import { useErrorFocus } from '../Auth/useErrorFocus.js';

/*
 * Profilsidan, se issue 53c § Beslut 2 och 6.
 *
 * ETT formulär och EN PATCH mot /settings/profile. Serverns fel går genom
 * FormField, precis som på inloggningen och säkerhetssidan.
 *
 * De tre inställningarna har var sitt förstaval i formen "Följ kontots språk
 * (svenska)". Det valet postar `null`, och `null` är hela innebörden av
 * kolumnen: användarens värde ÅSIDOSÄTTER kontots, det ärver det när det är
 * tomt ([[Konton och åtkomst]] § user och User::preferredLocale()). Vyn
 * hittar aldrig på ett kontovärde: parentesen kommer ur `accountDefaults`,
 * som servern skickar, och när den är null — användaren är medlem i flera
 * konton och värdet går inte att avgöra — står valet utan parentes.
 *
 * Ingår inte: e-postadressen. Den visas med sin verifieringsstatus och utan
 * inmatningsfält (Beslut 3) — ett byte kräver ett flöde som ingen issue i
 * backloggen beskriver, och en gråmarkerad inmatning hade sett ut som en yta
 * som inte fungerar.
 *
 * Tidszonslistan kommer som prop från kontrollern, samma lista som validerar
 * (`DateTimeZone::listIdentifiers()`). Ingen datafil i resources/js/: två
 * listor blir två sanningar.
 */
const props = defineProps({
    name: { type: String, required: true },
    email: { type: String, required: true },
    emailVerifiedAt: { type: String, default: null },

    // `userLocale`, inte `locale`: den delade propen `locale` bär
    // språkKATALOGEN (`sv`/`en`) och skickas till varje sida — en sidprop med
    // samma namn skuggar den. Se ProfileController::edit().
    userLocale: { type: String, default: null },
    userTimezone: { type: String, default: null },
    userUnitSystem: { type: String, default: null },

    timezones: { type: Array, required: true },
    accountDefaults: { type: Object, default: null },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();

const form = useForm({
    name: props.name,
    locale: props.userLocale,
    timezone: props.userTimezone,
    unit_system: props.userUnitSystem,
});

const locales = computed(() => ['sv_SE', 'en_GB']);
const units = computed(() => ['metric', 'imperial']);

/*
 * Förstavalens etiketter. Den namngivna formen bär kontots gällande värde i
 * parentesen och används bara när servern kunde avgöra det; annars den korta
 * formen. Översättningen av det inbäddade värdet går genom samma `t()` som
 * resten — `settings.locales.sv_SE` är "svenska", `settings.units.metric` är
 * "metriskt". Tidszonen är ett IANA-namn och översätts inte.
 */
const localeFollowLabel = computed(() => (props.accountDefaults?.locale
    ? t('settings.profile.locale_follow', { account: t(`settings.locales.${props.accountDefaults.locale}`) })
    : t('settings.profile.locale_follow_plain')));

const timezoneFollowLabel = computed(() => (props.accountDefaults?.timezone
    ? t('settings.profile.timezone_follow', { timezone: props.accountDefaults.timezone })
    : t('settings.profile.timezone_follow_plain')));

const unitFollowLabel = computed(() => (props.accountDefaults?.unitSystem
    ? t('settings.profile.unit_follow', { unit: t(`settings.units.${props.accountDefaults.unitSystem}`) })
    : t('settings.profile.unit_follow_plain')));

function submit() {
    form.patch('/settings/profile', { onError: focusFirstError });
}
</script>

<template>
    <SettingsLayout>
        <Head :title="t('settings.profile.title')" />

        <h1 class="text-2xl font-semibold">{{ t('settings.profile.heading') }}</h1>

        <form class="mt-8 flex max-w-lg flex-col gap-4" @submit.prevent="submit">
            <FormField
                v-slot="{ describedBy }"
                :label="t('settings.profile.name')"
                id="name"
                :error="form.errors.name"
            >
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

            <!-- E-postadressen: visad, inte redigerbar (Beslut 3). Ingen
                 FormField — det finns inget fält att sätta ett fel på. -->
            <div class="flex flex-col gap-1">
                <p class="text-sm font-medium text-slate-800">{{ t('settings.profile.email') }}</p>
                <p>{{ props.email }}</p>
                <p class="text-sm text-slate-600">
                    {{ props.emailVerifiedAt ? t('settings.profile.email_verified') : t('settings.profile.email_unverified') }}
                </p>
                <p class="text-sm text-slate-600">{{ t('settings.profile.email_no_change') }}</p>
            </div>

            <FormField
                v-slot="{ describedBy }"
                :label="t('settings.profile.locale')"
                id="locale"
                :error="form.errors.locale"
            >
                <select
                    id="locale"
                    v-model="form.locale"
                    :aria-describedby="describedBy"
                    name="locale"
                    class="rounded border border-slate-300 bg-white px-3 py-2"
                >
                    <option :value="null">{{ localeFollowLabel }}</option>
                    <option v-for="locale in locales" :key="locale" :value="locale">
                        {{ t(`settings.locales.${locale}`) }}
                    </option>
                </select>
            </FormField>

            <FormField
                v-slot="{ describedBy }"
                :label="t('settings.profile.timezone')"
                id="timezone"
                :error="form.errors.timezone"
            >
                <select
                    id="timezone"
                    v-model="form.timezone"
                    :aria-describedby="describedBy"
                    name="timezone"
                    class="rounded border border-slate-300 bg-white px-3 py-2"
                >
                    <option :value="null">{{ timezoneFollowLabel }}</option>
                    <option v-for="zone in timezones" :key="zone" :value="zone">{{ zone }}</option>
                </select>
            </FormField>

            <FormField
                v-slot="{ describedBy }"
                :label="t('settings.profile.unit_system')"
                id="unit_system"
                :error="form.errors.unit_system"
            >
                <select
                    id="unit_system"
                    v-model="form.unit_system"
                    :aria-describedby="describedBy"
                    name="unit_system"
                    class="rounded border border-slate-300 bg-white px-3 py-2"
                >
                    <option :value="null">{{ unitFollowLabel }}</option>
                    <option v-for="unit in units" :key="unit" :value="unit">
                        {{ t(`settings.units.${unit}`) }}
                    </option>
                </select>
            </FormField>

            <button
                type="submit"
                :disabled="form.processing"
                class="self-start inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
            >
                {{ form.processing ? t('common.pending.default') : t('settings.profile.submit') }}
            </button>
        </form>
    </SettingsLayout>
</template>
