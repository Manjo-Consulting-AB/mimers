<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import SettingsLayout from '../../layouts/SettingsLayout.vue';
import EmailChangeForm from '../../components/EmailChangeForm.vue';
import FormField from '../../components/FormField.vue';
import { useTranslations } from '../../composables/useTranslations.js';
import { useErrorFocus } from '../Auth/useErrorFocus.js';

defineOptions({ layout: [AppLayout, SettingsLayout] });

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
 * E-postadressen har ett eget formulär sedan issue 130. Adressen visas med
 * sin verifieringsstatus i namnformuläret, och bytet begärs i
 * resources/js/components/EmailChangeForm.vue, som postar till en EGEN rutt
 * (`POST /settings/profile/email`) — adressen byts aldrig i samma steg som
 * den begärs, och den här sidans PATCH tar fortfarande inte emot `email`.
 * Vyn skickar bara vidare vad servern redan bestämt: `hasPassword` och
 * `totpEnabled` är kontots tillstånd, och formuläret ritar fälten efter dem.
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

    // E-postformulärets två lägen, se issue 130: ett konto utan lösenord kan
    // inte begära ett byte, och ett konto med bekräftad tvåfaktor behöver
    // koden. Samma proppar och samma namn som säkerhetssidan ger
    // lösenordsformuläret.
    hasPassword: { type: Boolean, required: true },
    totpEnabled: { type: Boolean, required: true },

    timezones: { type: Array, required: true },
    accountDefaults: { type: Object, default: null },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();
const page = usePage();

/*
 * Profilbilden, se [[ADR-0076 Profilbilden]] § 6 och [[M59 Profilbilden]]
 * § 381.
 *
 * **Bilden läses ur den delade proppen, inte ur en sidpropp.** `avatar` är
 * `{ ulid, has_thumb }` eller null och kommer ur AuthUserResource
 * (issue 380); kontrollern för profilsidan skickar ingenting eget, och
 * efter en uppladdning svarar servern `back()` med färska proppar, så cirkeln
 * byter bild utan att vyn laddar om något själv.
 *
 * **Ett val postas direkt.** Filväljaren är den enda kontrollen för
 * uppladdningen — ingen extra knapp — och `forceFormData` är det som gör
 * filen till en multipart-kropp. Formen är ContainerCoverSheets
 * (resources/js/components/ContainerCoverSheet.vue), som är förlagan både
 * för anropet och för felraden.
 *
 * **Fältfelet `file` ritas under avsnittet.** Kvoten, en fil som inte är en
 * bild och `avatar.no_personal_account` kommer från servern som ett
 * valideringsfel på `file`, redan översatt (ApiErrorTranslator). Vyn skriver
 * ingen egen mening för dem.
 */
const avatar = computed(() => page.props.auth.user.avatar ?? null);

const initials = computed(() => {
    const words = (props.name ?? '').trim().split(/\s+/).filter(Boolean);

    if (words.length === 0) {
        return '';
    }

    const first = words[0].charAt(0);
    const last = words.length > 1 ? words[words.length - 1].charAt(0) : '';

    return (first + last).toUpperCase();
});

/*
 * Cirkeln visar bilden när den finns. Miniatyren ritas när derivatet finns,
 * annars originalet: en `<img>` mot `?variant=thumb` som inte är genererad är
 * en trasig bild (coverPresentation.js), och på den här ytan är originalet
 * alltid en giltig adress.
 */
const avatarSrc = computed(() => {
    if (avatar.value === null) {
        return null;
    }

    return avatar.value.has_thumb
        ? `/files/${avatar.value.ulid}?variant=thumb`
        : `/files/${avatar.value.ulid}`;
});

const imageFailed = ref(false);

watch(avatarSrc, () => {
    imageFailed.value = false;
});

const uploading = ref(false);
const removing = ref(false);
const avatarError = ref(null);

const busy = computed(() => uploading.value || removing.value);

function onFileSelected(event) {
    const file = event.target.files?.[0] ?? null;

    // Fältet töms så att SAMMA fil går att välja igen efter ett fel, samma
    // skäl som i ContainerCoverSheet.
    event.target.value = '';

    if (file !== null) {
        uploadAvatar(file);
    }
}

function uploadAvatar(file) {
    avatarError.value = null;

    router.post('/settings/profile/avatar', { file }, {
        forceFormData: true,
        preserveScroll: true,
        onStart: () => {
            uploading.value = true;
        },
        onFinish: () => {
            uploading.value = false;
        },
        onError: (errors) => {
            avatarError.value = errors.file ?? null;
        },
    });
}

/*
 * Ingen bekräftelse: bilden laddas lätt upp igen ([[ADR-0047 Containerns
 * bild]] § Beslut, samma linje som för containerns bild).
 */
function removeAvatar() {
    avatarError.value = null;

    router.delete('/settings/profile/avatar', {
        preserveScroll: true,
        onStart: () => {
            removing.value = true;
        },
        onFinish: () => {
            removing.value = false;
        },
    });
}

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

        <!-- E-postadressen: visad här, bytt i sitt eget formulär (issue
             130). Ingen FormField — den här PATCH:en tar inte emot
             fältet, och ett fält här hade sett ut som att det bytte
             adressen i samma steg. -->
        <div class="flex flex-col gap-1">
            <p class="text-sm font-medium text-slate-800">{{ t('settings.profile.email') }}</p>
            <p>{{ props.email }}</p>
            <p class="text-sm text-slate-600">
                {{ props.emailVerifiedAt ? t('settings.profile.email_verified') : t('settings.profile.email_unverified') }}
            </p>
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

    <!--
        Profilbilden, se [[ADR-0076 Profilbilden]] § 6. Cirkeln visar bilden
        när den finns och initialerna annars; *Upload* är filväljaren själv,
        och *Remove* står bara när det finns en bild. Statusraderna
        `avatar-updated` och `avatar-removed` ritas av FlashMessage, som alla
        andra statusrader på sidan.
    -->
    <section class="mt-8 flex max-w-lg flex-col gap-4">
        <h2 class="text-lg font-semibold">{{ t('settings.profile.avatar.heading') }}</h2>

        <div class="flex items-center gap-4">
            <img
                v-if="avatarSrc && !imageFailed"
                :src="avatarSrc"
                alt=""
                class="h-16 w-16 shrink-0 rounded-pill object-cover"
                @error="imageFailed = true"
            >

            <span
                v-else
                class="inline-flex h-16 w-16 shrink-0 items-center justify-center rounded-pill bg-surface-sunken text-title font-semibold text-ink-muted"
            >
                {{ initials }}
            </span>

            <div class="flex flex-col gap-2">
                <label for="avatar-file" class="text-sm font-medium text-slate-800">
                    {{ t('settings.profile.avatar.upload') }}
                </label>

                <input
                    id="avatar-file"
                    type="file"
                    accept="image/*"
                    :disabled="busy"
                    class="text-sm text-slate-700"
                    @change="onFileSelected"
                >

                <button
                    v-if="avatar"
                    type="button"
                    :disabled="busy"
                    class="self-start inline-flex min-h-11 items-center rounded border border-slate-300 bg-white px-4 font-medium text-slate-800 disabled:opacity-50"
                    @click="removeAvatar"
                >
                    {{ t('settings.profile.avatar.remove') }}
                </button>
            </div>
        </div>

        <p v-if="uploading" role="status" class="text-sm text-slate-600">
            {{ t('settings.profile.avatar.uploading') }}
        </p>

        <!-- Serverns färdigöversatta mening: kvoten, en fil som inte är en
             bild eller en person utan personkonto. -->
        <p v-if="avatarError" role="alert" class="text-sm text-danger">{{ avatarError }}</p>
    </section>

    <EmailChangeForm :has-password="props.hasPassword" :totp-enabled="props.totpEnabled" />
</template>
