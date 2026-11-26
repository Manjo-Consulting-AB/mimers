<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import SettingsLayout from '../../layouts/SettingsLayout.vue';
import AccountExportSection from '../../components/AccountExportSection.vue';
import CurrencyField from '../../components/CurrencyField.vue';
import EmailChangeForm from '../../components/EmailChangeForm.vue';
import FormField from '../../components/FormField.vue';
import ImageCropEditor from '../../components/ImageCropEditor.vue';
import ProfileHeroSection from '../../components/ProfileHeroSection.vue';
import UiSheet from '../../components/UiSheet.vue';
import UserAvatar from '../../components/UserAvatar.vue';
import { useTranslations } from '../../composables/useTranslations.js';
import { useErrorFocus } from '../Auth/useErrorFocus.js';

defineOptions({ layout: [AppLayout, SettingsLayout] });

/*
 * Profilsidan, se issue 53c § Beslut 2 och 6.
 *
 * ETT formulär och EN PATCH mot /settings/profile. Serverns fel går genom
 * FormField, precis som på inloggningen och säkerhetssidan.
 *
 * De tre inställningarna visar personens GÄLLANDE värde, ett lager
 * ([[ADR-0085 Profilen bär personkontot]] § 4): servern skickar det redan
 * uträknat (`preferredLocale()`, `preferredTimezone()`, och för enhetssystemet
 * `user.unit_system` med kontots värde som reserv), och sparningen skriver
 * bara till `user`. Valet "Följ kontots …" finns inte längre.
 *
 * Personkontots två fält, namn och valuta, sitter i en egen avdelning i samma
 * formulär och samma PATCH (§ 2 och 5). Säger policyn nej (`canUpdate` är
 * false) visas de som text och skickas inte med.
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

    /* Personens kontoexporter (issue 1702), ur ExportResource. */
    accountExports: { type: Array, default: () => [] },

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

    // Personkontot ({ ulid, name, currency, canUpdate }) eller null.
    personalAccount: { type: Object, default: null },
});

const { t } = useTranslations();
const { focusFirstError } = useErrorFocus();
const page = usePage();

/*
 * Profilbilden, se [[ADR-0076 Profilbilden]] § 6 och 7 och [[M59
 * Profilbilden]] § 381 och § 382.
 *
 * **Bilden läses ur den delade proppen, inte ur en sidpropp.** `avatar` är
 * `{ ulid, has_thumb, focus, zoom }` eller null och kommer ur
 * AuthUserResource (issue 380 och 382); kontrollern för profilsidan skickar
 * ingenting eget, och efter en uppladdning svarar servern `back()` med färska
 * proppar, så cirkeln byter bild utan att vyn laddar om något själv.
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

/* Hela personen, till `UserAvatar` — den läser `name` och `avatar`. */
const currentUser = computed(() => page.props.auth.user);

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

/*
 * Redigeraren för utsnittet, se [[ADR-0076 Profilbilden]] § 7 och
 * [[ADR-0090 Dashboardens hjälte]] § 4.
 *
 * **Redigeraren är `ImageCropEditor` sedan issue 433.** Ytan, masken,
 * reglaget, tangentbordet och de tre vägarna ut bor i
 * resources/js/components/ImageCropEditor.vue, tillsammans med containerns
 * ark. Här bor bara arket (UiSheet: fokusfällan, Esc, trycket utanför och
 * rubriken), adressen och sparningen — `shape="round"` ger profilbildens
 * runda ram.
 *
 * **Allt är lokalt till dess att Save trycks.** Reset sätter mitten och
 * 100 %, Cancel stänger utan att skriva — arket öppnas alltid med de sparade
 * värdena, så en avbruten redigering lämnar ingenting efter sig.
 */
const editorOpen = ref(false);
const editorTrigger = ref(null);
const editorPending = ref(false);
const editorError = ref(null);

/*
 * Adressen redigeraren ritar. `?variant=medium` är skarp nog att beskåda på
 * nära håll; en bild mindre än 1024 px har ingen sådan variant, och då tar
 * miniatyren vid — samma väg tillbaka som `UserAvatar` tar.
 */
const editorSrc = computed(() => (avatar.value === null
    ? null
    : `/files/${avatar.value.ulid}?variant=medium`));

const editorFallbackSrc = computed(() => (avatar.value?.has_thumb
    ? `/files/${avatar.value.ulid}?variant=thumb`
    : null));

function openEditor(event) {
    editorTrigger.value = event.currentTarget ?? null;
    editorError.value = null;
    editorOpen.value = true;
}

function closeEditor() {
    editorOpen.value = false;
}

/*
 * Anroparen sparar. Redigeraren sänder utkastet, och PATCH:en bor här — samma
 * adress som före utbrytningen, med punkt och zoom ur utkastet.
 */
function saveCrop(crop) {
    editorError.value = null;

    router.patch('/settings/profile/avatar/crop', crop, {
        preserveScroll: true,
        onStart: () => {
            editorPending.value = true;
        },
        onFinish: () => {
            editorPending.value = false;
        },
        onSuccess: () => {
            closeEditor();
        },
        onError: (errors) => {
            editorError.value = errors.x ?? errors.y ?? errors.zoom ?? null;
        },
    });
}

const canUpdateAccount = computed(() => props.personalAccount?.canUpdate === true);

const form = useForm({
    name: props.name,
    locale: props.userLocale,
    timezone: props.userTimezone,
    unit_system: props.userUnitSystem,
    ...(canUpdateAccount.value
        ? { account_name: props.personalAccount.name, currency: props.personalAccount.currency }
        : {}),
});

const locales = computed(() => ['sv_SE', 'en_GB']);
const units = computed(() => ['metric', 'imperial']);

function submit() {
    form.patch('/settings/profile', { onError: focusFirstError });
}
</script>

<template>
    <Head :title="t('settings.profile.title')" />

    <h2 class="text-2xl font-semibold">{{ t('settings.profile.heading') }}</h2>

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
                <option v-for="unit in units" :key="unit" :value="unit">
                    {{ t(`settings.units.${unit}`) }}
                </option>
            </select>
        </FormField>

        <!-- Personkontot, se [[ADR-0085 Profilen bär personkontot]] § 2, 3 och
             5. Kontots valuta är botten i arvet ([[ADR-0037 Valutans arv]]);
             namnet är ett eget fält som andra kan se. Nekar policyn visas
             båda som text. -->
        <fieldset v-if="personalAccount" class="flex flex-col gap-4">
            <legend class="text-lg font-semibold">{{ t('settings.profile.account.heading') }}</legend>

            <template v-if="canUpdateAccount">
                <FormField
                    v-slot="{ describedBy }"
                    :label="t('settings.profile.account.name')"
                    id="account_name"
                    :error="form.errors.account_name"
                >
                    <input
                        id="account_name"
                        v-model="form.account_name"
                        :aria-describedby="[describedBy, 'account_name-hint'].filter(Boolean).join(' ')"
                        type="text"
                        name="account_name"
                        required
                        class="rounded border border-slate-300 bg-white px-3 py-2"
                    >
                    <p id="account_name-hint" class="text-sm text-slate-600">
                        {{ t('settings.profile.account.name_hint') }}
                    </p>
                </FormField>

                <FormField
                    v-slot="{ describedBy }"
                    :label="t('settings.profile.account.currency')"
                    id="currency"
                    :error="form.errors.currency"
                >
                    <CurrencyField
                        id="currency"
                        v-model="form.currency"
                        :described-by="describedBy"
                    />
                </FormField>
            </template>

            <template v-else>
                <div class="flex flex-col gap-1">
                    <p class="text-sm font-medium text-slate-800">{{ t('settings.profile.account.name') }}</p>
                    <p>{{ personalAccount.name }}</p>
                    <p class="text-sm text-slate-600">{{ t('settings.profile.account.name_hint') }}</p>
                </div>
                <div class="flex flex-col gap-1">
                    <p class="text-sm font-medium text-slate-800">{{ t('settings.profile.account.currency') }}</p>
                    <p>{{ personalAccount.currency }}</p>
                </div>
                <p class="text-sm text-slate-600">{{ t('settings.profile.account.read_only') }}</p>
            </template>
        </fieldset>

        <button
            type="submit"
            :disabled="form.processing"
            class="self-start inline-flex min-h-11 items-center rounded bg-blue-700 px-4 font-medium text-white disabled:opacity-50"
        >
            {{ form.processing ? t('common.pending.default') : t('settings.profile.submit') }}
        </button>
    </form>

    <!--
        Profilbilden, se [[ADR-0076 Profilbilden]] § 6 och 7. Cirkeln ritas av
        `UserAvatar` — bilden med sitt utsnitt när den finns, initialerna
        annars — och *Adjust* står bredvid *Remove* när det finns en bild.
        Statusraderna `avatar-updated`, `avatar-removed` och `avatar-cropped`
        ritas av FlashMessage, som alla andra statusrader på sidan.
    -->
    <section class="mt-8 flex max-w-lg flex-col gap-4">
        <h3 class="text-lg font-semibold">{{ t('settings.profile.avatar.heading') }}</h3>

        <div class="flex items-center gap-4">
            <UserAvatar :user="currentUser" :size="64" />

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

                <div class="flex flex-wrap gap-2">
                    <button
                        v-if="avatar"
                        type="button"
                        :disabled="busy"
                        class="self-start inline-flex min-h-11 items-center rounded border border-slate-300 bg-white px-4 font-medium text-slate-800 disabled:opacity-50"
                        @click="openEditor"
                    >
                        {{ t('settings.profile.avatar.adjust') }}
                    </button>

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
        </div>

        <p v-if="uploading" role="status" class="text-sm text-slate-600">
            {{ t('settings.profile.avatar.uploading') }}
        </p>

        <!-- Serverns färdigöversatta mening: kvoten, en fil som inte är en
             bild eller en person utan personkonto. -->
        <p v-if="avatarError" role="alert" class="text-sm text-danger">{{ avatarError }}</p>
    </section>

    <!--
        Hjältebilden, se [[ADR-0090 Dashboardens hjälte]] § 2 och 4 samt issue
        434. Avsnittet *Dashboard image* står under profilbilden och äger sin
        egen uppladdning, borttagning och redigerare — samma tre vägar som
        avsnittet ovan, men bilden hamnar i dashboardens hjälte.
    -->
    <ProfileHeroSection />

    <!--
        Redigeraren, se [[ADR-0076 Profilbilden]] § 7 och [[ADR-0090
        Dashboardens hjälte]] § 4. Arket är UiSheet, som bär fokusfällan, Esc
        och trycket utanför. Ytan och reglaget bor i `ImageCropEditor` med
        `shape="round"` — samma komponent som containerns ark använder, så
        drag, piltangenter, zoom och mask beter sig likadant på båda ytorna.
    -->
    <UiSheet
        :open="editorOpen"
        :heading="t('settings.profile.avatar.crop_heading')"
        :trigger="editorTrigger"
        @close="closeEditor"
    >
        <ImageCropEditor
            v-if="editorOpen"
            :src="editorSrc"
            :fallback-src="editorFallbackSrc"
            :focus="avatar?.focus ?? null"
            :zoom="avatar?.zoom ?? null"
            shape="round"
            :pending="editorPending"
            :error="editorError"
            @save="saveCrop"
            @cancel="closeEditor"
        />
    </UiSheet>

    <EmailChangeForm :has-password="props.hasPassword" :totp-enabled="props.totpEnabled" />

    <!-- Kontoexporten, issue 1702 · [[ADR-0098 Kontoexporten]]. -->
    <AccountExportSection :exports="props.accountExports" />
</template>
